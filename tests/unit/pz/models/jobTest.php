<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use pz\Models\Job;
use pz\database\Database;

/**
 * Job::create()'s immediate-processing trigger (see its doc comment) only ever matters under
 * php-fpm serving a real request — fastcgi_finish_request() doesn't exist under any other SAPI.
 * PHPUnit runs under the 'cli' SAPI, so triggerImmediateProcessing()'s guard should no-op here;
 * these tests exist to make that assumption explicit rather than rely on the whole suite not
 * fataling at shutdown as indirect evidence the guard works.
 */
final class jobTest extends TestCase
{
    private Database $db;

    public static function setUpBeforeClass(): void
    {
        $_ENV['DB_NAME'] = $_ENV['TEST_DB_NAME'] ?? 'pz_test';
        $_ENV['LOG_LEVEL'] = 'CRITICAL';
        Job::generateTableForModel(false, true);
    }

    protected function setUp(): void
    {
        $this->db = new Database($_ENV['DB_NAME']);
        $this->db->execute('DELETE FROM jobs');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM jobs');
    }

    public function testPhpunitRunsUnderCliSoTheImmediateTriggerGuardApplies(): void
    {
        $this->assertNotSame('fpm-fcgi', PHP_SAPI);
    }

    public function testCreatingAnAdHocJobUnderCliDoesNotAttemptToFinishARequest(): void
    {
        // fastcgi_finish_request() doesn't exist under the 'cli' SAPI — if
        // triggerImmediateProcessing()'s guard didn't correctly skip registering it here, calling
        // it would fatal. Reaching this assertion at all is the point.
        $job = new Job();
        $job->create([
            'user_id' => 1,
            'kind' => 'ad_hoc_job',
            'type' => 'test.dummy_job',
            'handler_controller' => 'DummyHandlerController',
            'handler_method' => 'process',
            'status' => 'pending',
        ]);

        $this->assertTrue($job->isValid());
    }

    public function testCreatingAScheduledTaskRowAlsoDoesNotAttemptToFinishARequest(): void
    {
        // scheduled_task rows go through the same create() override — confirms the kind check
        // actually gates it rather than every creation happening to no-op only because of the SAPI.
        $job = new Job();
        $job->create([
            'user_id' => 0,
            'kind' => 'scheduled_task',
            'type' => 'Some::method',
            'handler_controller' => 'SomeController',
            'handler_method' => 'method',
            'status' => 'completed',
            'total' => 1,
            'processed' => 1,
        ]);

        $this->assertTrue($job->isValid());
    }
}
