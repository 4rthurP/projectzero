<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use pz\Scheduler;
use pz\Config;
use pz\Models\Job;
use pz\database\Database;
use pz\database\Query;
use pz\Test\Ressources\DummyJobHandlerController;

require_once __DIR__ . '/../test_ressources/dummyJobHandlerController.php';

final class schedulerTest extends TestCase
{
    private Database $db;

    public static function setUpBeforeClass(): void
    {
        $_ENV['DB_NAME'] = $_ENV['TEST_DB_NAME'] ?? 'pz_test';
        $_ENV['LOG_LEVEL'] = 'CRITICAL';

        // Self-provisioning: keep the shared `jobs` table in sync with pz\Models\Job so this suite
        // doesn't depend on the test database already having it, or being kept in sync by hand.
        Job::generateTableForModel(false, true);
    }

    protected function setUp(): void
    {
        $this->db = new Database($_ENV['DB_NAME']);
        $this->db->execute('DELETE FROM jobs');

        DummyJobHandlerController::$call_count = 0;
        DummyJobHandlerController::$clock_to_advance = null;
        DummyJobHandlerController::$advance_to = null;
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM jobs');
    }

    ############################
    # claimNextJob()
    ############################

    public function testOnlyOneOfTwoConcurrentClaimsOnTheSameRowSucceeds(): void
    {
        $job = $this->createAdHocJob();

        // Two schedulers standing in for two overlapping cron ticks racing for the same row.
        $scheduler_a = new Scheduler();
        $scheduler_b = new Scheduler();

        $claimed_a = $this->invokePrivate($scheduler_a, 'claimNextJob');
        $claimed_b = $this->invokePrivate($scheduler_b, 'claimNextJob');

        $this->assertInstanceOf(Job::class, $claimed_a);
        $this->assertEquals($job->getId(), $claimed_a->getId());
        $this->assertNull($claimed_b);

        $row = $this->reloadJobRow($job->getId());
        $this->assertSame('running', $row['status']);
        $this->assertNotNull($row['locked_at']);
    }

    public function testAFreshlyLockedJobIsNotReclaimed(): void
    {
        $now = new DateTime('now', Config::tz());
        $this->createAdHocJob([
            'status' => 'running',
            'locked_at' => (clone $now)->modify('-30 seconds')->format('Y-m-d H:i:s'),
        ]);

        $scheduler = new Scheduler();
        $scheduler->setCurrentTime($now);

        $claimed = $this->invokePrivate($scheduler, 'claimNextJob');

        $this->assertNull($claimed);
    }

    public function testAStaleLockedJobIsReclaimed(): void
    {
        $now = new DateTime('now', Config::tz());
        $job = $this->createAdHocJob([
            'status' => 'running',
            'locked_at' => (clone $now)->modify('-3 minutes')->format('Y-m-d H:i:s'),
        ]);

        $scheduler = new Scheduler();
        $scheduler->setCurrentTime($now);

        $claimed = $this->invokePrivate($scheduler, 'claimNextJob');

        $this->assertInstanceOf(Job::class, $claimed);
        $this->assertEquals($job->getId(), $claimed->getId());
    }

    ############################
    # Failure policy / retries
    ############################

    public function testRetryableFailuresGoBackToPendingAndTheThirdPermanentlyFails(): void
    {
        $job = $this->createAdHocJob([
            'handler_method' => 'process_always_throws',
        ]);

        $scheduler = new Scheduler();

        for ($expected_attempts = 1; $expected_attempts <= 2; $expected_attempts++) {
            // Each call stands in for one cron tick.
            $this->invokePrivate($scheduler, 'runPendingJobs', [new DateTime('now', Config::tz())]);

            $row = $this->reloadJobRow($job->getId());
            $this->assertSame('pending', $row['status'], "attempt $expected_attempts should stay retryable");
            $this->assertNull($row['locked_at']);
            $this->assertEquals($expected_attempts, (int) $row['attempts']);
            $this->assertNotNull($row['error_message']);
        }

        // Third tick: the third failure.
        $this->invokePrivate($scheduler, 'runPendingJobs', [new DateTime('now', Config::tz())]);

        $row = $this->reloadJobRow($job->getId());
        $this->assertSame('failed', $row['status']);
        $this->assertEquals(3, (int) $row['attempts']);
        $this->assertNotNull($row['finished_at']);

        // A failed job is never claimed again.
        $claimed = $this->invokePrivate($scheduler, 'claimNextJob');
        $this->assertNull($claimed);

        $this->assertEquals(3, DummyJobHandlerController::$call_count);
    }

    ############################
    # Time budget
    ############################

    public function testTimeBudgetStopsClaimingFurtherJobsOnceExceeded(): void
    {
        $job = $this->createAdHocJob();

        $tick_start = new DateTime('now', Config::tz());
        $scheduler = new Scheduler();
        $scheduler->setJobTimeBudget(5);
        // The clock is already past the budget before runPendingJobs() makes its first check.
        $scheduler->setCurrentTime((clone $tick_start)->modify('+10 seconds'));

        $this->invokePrivate($scheduler, 'runPendingJobs', [$tick_start]);

        $this->assertEquals(0, DummyJobHandlerController::$call_count);

        $row = $this->reloadJobRow($job->getId());
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['locked_at']);
    }

    public function testAJobPausedMidwayByTheBudgetKeepsRunningStatusWithLockCleared(): void
    {
        $job = $this->createAdHocJob([
            'handler_method' => 'process_forever', // never finishes on its own
        ]);

        $tick_start = new DateTime('now', Config::tz());
        $scheduler = new Scheduler();
        $scheduler->setJobTimeBudget(30);
        $scheduler->setCurrentTime($tick_start);

        // Simulate the budget running out *during* the handler call: right after the handler runs
        // once, jump the scheduler's own clock past the budget, so the next check (before a second
        // handler call) stops the loop instead of letting it run unbounded.
        DummyJobHandlerController::$clock_to_advance = $scheduler;
        DummyJobHandlerController::$advance_to = (clone $tick_start)->modify('+60 seconds');

        $this->invokePrivate($scheduler, 'runPendingJobs', [$tick_start]);

        $this->assertEquals(1, DummyJobHandlerController::$call_count);

        $row = $this->reloadJobRow($job->getId());
        $this->assertSame('running', $row['status']);
        $this->assertNull($row['locked_at']);
        $this->assertEquals(1, (int) $row['processed']);
    }

    public function testAJobThatFinishesWithinBudgetIsMarkedCompleted(): void
    {
        $job = $this->createAdHocJob([
            'handler_method' => 'process_one_unit',
            'total' => 1,
            'processed' => 0,
        ]);

        $scheduler = new Scheduler();
        $this->invokePrivate($scheduler, 'runPendingJobs', [new DateTime('now', Config::tz())]);

        $row = $this->reloadJobRow($job->getId());
        $this->assertSame('completed', $row['status']);
        $this->assertNull($row['locked_at']);
        $this->assertNotNull($row['finished_at']);
    }

    ############################
    # Scheduled task rows share the same table
    ############################

    public function testSaveTaskRunWritesAScheduledTaskRowReadableByGetLastTaskRun(): void
    {
        $scheduler = new Scheduler();

        $job_id = $this->invokePrivate($scheduler, 'saveTaskRun', ['DummyScheduledController', 'run', true]);
        $this->assertNotNull($job_id);

        $row = $this->reloadJobRow($job_id);
        $this->assertSame('scheduled_task', $row['kind']);
        $this->assertSame('DummyScheduledController', $row['handler_controller']);
        $this->assertSame('run', $row['handler_method']);
        $this->assertSame('completed', $row['status']);
        $this->assertEquals(1, (int) $row['processed']);
        $this->assertEquals(1, (int) $row['total']);
        $this->assertEquals((string) Config::get('SYSTEM_USER_ID'), (string) $row['user_id']);

        $last_run = $this->invokePrivate($scheduler, 'getLastTaskRun', ['DummyScheduledController', 'run']);
        $this->assertNotNull($last_run);
        $this->assertArrayHasKey('run_time', $last_run);

        // taskWasDue() itself is untouched; this just confirms it still works fed the run_time
        // alias coming back from the jobs table.
        $schedule = $this->invokePrivate($scheduler, 'parseSchedule', [[
            'minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*',
        ]]);
        $was_due = $this->invokePrivate($scheduler, 'taskWasDue', [$schedule, $last_run, new DateTime('+1 hour')]);
        $this->assertIsBool($was_due);
    }

    public function testSaveTaskRunRecordsAFailedRun(): void
    {
        $scheduler = new Scheduler();
        $job_id = $this->invokePrivate($scheduler, 'saveTaskRun', ['DummyScheduledController', 'run', false]);

        $row = $this->reloadJobRow($job_id);
        $this->assertSame('failed', $row['status']);
    }

    ############################
    # Test helpers
    ############################

    private function createAdHocJob(array $overrides = []): Job
    {
        $job = new Job();
        $job->create(array_merge([
            'user_id' => 1,
            'kind' => 'ad_hoc_job',
            'type' => 'test.dummy_job',
            'handler_controller' => DummyJobHandlerController::class,
            'handler_method' => 'process_one_unit',
            'status' => 'pending',
            'total' => 3,
            'processed' => 0,
        ], $overrides));

        if (!$job->isValid()) {
            $this->fail('Failed to create fixture ad hoc job: ' . json_encode($job->getFormMessages()));
        }

        return $job;
    }

    private function reloadJobRow($id): ?array
    {
        return Query::from('jobs')->where('id', $id)->first();
    }

    private function invokePrivate(object $object, string $method, array $args = [])
    {
        $reflection = new ReflectionClass($object);
        $method_reflection = $reflection->getMethod($method);
        $method_reflection->setAccessible(true);
        return $method_reflection->invokeArgs($object, $args);
    }
}
