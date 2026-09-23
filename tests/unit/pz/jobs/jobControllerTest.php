<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use pz\Config;
use pz\Enums\Routing\ModelEndpoint;
use pz\Enums\Routing\ResponseCode;
use pz\Controllers\JobController;
use pz\Services\JobService;
use pz\Models\Job;
use pz\Models\User;
use pz\database\Database;

/**
 * These tests exercise JobService::loadModel() directly rather than going through
 * JobController::get()'s Request/Auth plumbing: that plumbing is pre-existing, unmodified
 * framework code, and constructing a real logged-in Request needs a full Auth login round trip
 * that isn't specific to the job system. loadModel() is exactly what JobController::get()
 * delegates to for the ownership check, so this still covers the behavior in question.
 */
final class jobControllerTest extends TestCase
{
    private Database $db;

    public static function setUpBeforeClass(): void
    {
        $_ENV['DB_NAME'] = $_ENV['TEST_DB_NAME'] ?? 'pz_test';
        $_ENV['LOG_LEVEL'] = 'CRITICAL';

        // Self-provisioning: keep the shared `jobs` table in sync with pz\Models\Job so this suite
        // doesn't depend on the test database already having it.
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
        unset($_SESSION['user']);
    }

    public function testOnlyGetIsRegisteredAsAnApiEndpoint(): void
    {
        $this->assertSame([ModelEndpoint::GET], JobController::getApiEndpoints());
    }

    public function testLoadModelReturnsTheJobToItsOwner(): void
    {
        $owner_id = 4001;
        $owner = $this->makeInMemoryUser($owner_id);
        $job = $this->createAdHocJob($owner_id);

        $_SESSION['user']['id'] = $owner_id;

        $service = new JobService();
        $loaded = $service->loadModel($job->getId(), $owner, false, 'view');

        $this->assertInstanceOf(Job::class, $loaded);
        $this->assertFalse($service->hasError());
        $this->assertEquals($job->getId(), $loaded->getId());
    }

    public function testLoadModelRefusesAJobBelongingToADifferentUser(): void
    {
        $owner_id = 4002;
        $other_id = 4003;
        $other = $this->makeInMemoryUser($other_id);
        $job = $this->createAdHocJob($owner_id);

        // The requesting session belongs to a different user than the job's owner.
        $_SESSION['user']['id'] = $other_id;

        $service = new JobService();

        try {
            $loaded = $service->loadModel($job->getId(), $other, false, 'view');
        } catch (\Exception $exception) {
            // Service::error() rethrows in DEV env instead of returning gracefully; either way,
            // access must be refused rather than the job loading.
            $this->assertStringContainsString('invalid-id', $exception->getMessage());
            return;
        }

        $this->assertNull($loaded);
        $this->assertTrue($service->hasError());
        $this->assertEquals(ResponseCode::NotFound, $service->error_code);
    }

    /**
     * Builds a User purely in memory (no DB round trip): loadFromArray() only ever does local
     * set() calls for non-link attributes, so this is enough to exercise
     * checkUserRights()/getId() without needing a `users` table fixture.
     */
    private function makeInMemoryUser(int $id): User
    {
        $user = new User();
        $user->loadFromArray([
            'id' => $id,
            'email' => "user{$id}@test.local",
            'username' => "user{$id}",
            'password' => 'not-a-real-hash',
        ]);
        return $user;
    }

    private function createAdHocJob(int $user_id): Job
    {
        $job = new Job();
        $job->create([
            'user_id' => $user_id,
            'kind' => 'ad_hoc_job',
            'type' => 'test.dummy_job',
            'handler_controller' => 'DummyHandlerController',
            'handler_method' => 'process',
            'status' => 'pending',
        ]);

        if (!$job->isValid()) {
            $this->fail('Failed to create fixture ad hoc job: ' . json_encode($job->getFormMessages()));
        }

        return $job;
    }
}
