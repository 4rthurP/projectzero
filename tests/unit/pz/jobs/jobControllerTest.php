<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use pz\Config;
use pz\Enums\Routing\Method;
use pz\Enums\Routing\ModelEndpoint;
use pz\Enums\Routing\ResponseCode;
use pz\Controllers\JobController;
use pz\Services\JobService;
use pz\Models\Job;
use pz\Models\User;
use pz\Routing\Request;
use pz\Test\Ressources\DummyAuth;
use pz\Test\Ressources\FastHashUser;
use pz\database\Database;
use pz\database\Query;

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
        // cancel() tests log in real fixture users (loadModel()'s own ownership tests use an
        // in-memory-only User, but a real Auth/loginFromForm() round trip needs an actual row).
        $this->db->execute('DELETE FROM `users`');
        $this->db->execute('DELETE FROM `user_sessions`');
        $this->db->execute('DELETE FROM `nonces`');
        $this->db->execute('DELETE FROM `login_attempts`');
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

    // ---- cancel() --------------------------------------------------------------------------

    public function testCancelMarksAPendingJobCancelled(): void
    {
        $owner = $this->createRealUser();
        $job = $this->createAdHocJob($owner->getId());
        $request = $this->makeAuthenticatedRequest($owner, ['id' => $job->getId()]);

        $response = (new JobController())->cancel($request);

        $this->assertTrue($response->success);
        $row = Query::from('jobs')->where('id', $job->getId())->first();
        $this->assertSame('cancelled', $row['status']);
        $this->assertNotNull($row['finished_at']);
    }

    public function testCancelRefusesAJobThatAlreadyFinished(): void
    {
        $owner = $this->createRealUser();
        $job = $this->createAdHocJob($owner->getId());
        $job->set('status', 'completed', true);
        $request = $this->makeAuthenticatedRequest($owner, ['id' => $job->getId()]);

        $response = (new JobController())->cancel($request);

        $this->assertFalse($response->success);
        $row = Query::from('jobs')->where('id', $job->getId())->first();
        $this->assertSame('completed', $row['status']); // untouched
    }

    public function testCancelRefusesAJobBelongingToADifferentUser(): void
    {
        $owner = $this->createRealUser();
        $other = $this->createRealUser();
        $job = $this->createAdHocJob($owner->getId());
        $request = $this->makeAuthenticatedRequest($other, ['id' => $job->getId()]);

        try {
            $response = (new JobController())->cancel($request);
        } catch (\Exception $exception) {
            // Same "either refused gracefully or rethrown in DEV" nuance as
            // testLoadModelRefusesAJobBelongingToADifferentUser() above - either way is a refusal.
            $this->assertStringContainsString('invalid-id', $exception->getMessage());
            return;
        }

        $this->assertFalse($response->success);
    }

    /**
     * Unlike makeInMemoryUser() below, this inserts a real row: a genuine Auth/loginFromForm()
     * round trip (needed to build a real Request via makeAuthenticatedRequest()) authenticates
     * against the database, not an in-memory object.
     */
    private function createRealUser(): User
    {
        $username = 'job_test_' . bin2hex(random_bytes(6));
        $user = new FastHashUser();
        $user->create([
            'username' => $username,
            'password' => 'testpassword123',
            'email' => $username . '@example.com',
        ]);
        if (!$user->isValid()) {
            $this->fail('Failed to create fixture user: ' . json_encode($user->getFormMessages()));
        }
        return $user;
    }

    private function makeAuthenticatedRequest(User $user, array $data = []): Request
    {
        $auth = new DummyAuth(['username' => $user->get('username'), 'password' => 'testpassword123']);
        $auth->loginFromForm();

        $request = (new Request(Method::POST, $data))->setAuth($auth);
        // Model::findOrCreate()/startQuery() for a PROTECTED model reads this directly rather than
        // going through a Request - see pz/models/model.php.
        $_SESSION['user']['id'] = (int) $user->getId();

        return $request;
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
