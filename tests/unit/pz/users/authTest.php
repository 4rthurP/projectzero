<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use pz\Config;
use pz\database\Database;
use pz\database\Query;
use pz\Models\User;
use pz\Test\Ressources\DummyAuth;
use pz\Test\Ressources\FastHashUser;

final class authTest extends TestCase
{
    private Database $db;
    private array $user_data;
    private User $added_user;
    private DummyAuth $auth;
    private DummyAuth $incorrect_auth;

    public static function incorrectCredentialsProvider(): array
    {
        return [
            // Throttling is per user: only a wrong password for an existing user is recorded.
            'wrong username' => [['username' => 'wronguser', 'password' => 'testpassword'], 0],
            'wrong password' => [['username' => 'testuser', 'password' => 'wrongpassword'], 1],
            'empty credentials' => [['username' => '', 'password' => ''], 0],
        ];
    }

    public function testUserRegistration(): void
    {
        $this->AssertInstanceOf(User::class, $this->added_user);
        $this->assertTrue($this->added_user->isValid());

        // Check the registration was successful by retrieving the user from the database
        $user_in_db = Query::from('users')->where('username', $this->user_data['username'])->first();

        $this->assertNotNull($user_in_db);
        $this->assertEquals($this->user_data['username'], $user_in_db['username']);
        $this->assertEquals($this->user_data['email'], $user_in_db['email']);
        $this->assertTrue(password_verify($this->user_data['password'], $user_in_db['password']));
    }

    public function testUserLogin(): void
    {
        $this->auth->loginFromForm();

        $this->assertTrue($this->auth->isLoggedIn());
        $this->assertEquals($this->added_user->getId(), $this->auth->user_id);

        // Test that the correct session infos are set
        $this->assertIsArray($_SESSION['user']);
        $this->assertEquals($this->added_user->getId(), $_SESSION['user']['id']);
        $this->assertEquals($this->user_data['username'], $_SESSION['user']['name']);
        $this->assertNotNull($_SESSION['user']['role']);
        $this->assertNotNull($_SESSION['user']['session_token']);
        $this->assertNotNull($_SESSION['user']['session_expiration']);
        $this->assertNotNull($_SESSION['user']['session_token_issued']);

        // Test that the correct cooke infos are set
        $this->assertNotNull($_SESSION['user']['cookie_end']);

        // $this->assertEquals($_COOKIE['user_id'], $this->added_user->getId());
        // $this->assertEquals($_COOKIE['user_name'], $this->user_data['username']);
        // $this->assertNotNull($_COOKIE['user_session_token']);
        // $this->assertEquals($_COOKIE['user_session_token'], $this->added_user->getId() . '::' . $_SESSION['user']['session_token']);
    }

    /** Regression: loginFromSession() used to mint a new token (bcrypt + INSERT) on every request. */
    public function testLoginFromSessionReusesTheSessionToken(): void
    {
        $this->auth->loginFromForm();
        $token = $_SESSION['user']['session_token'];

        for ($i = 0; $i < 3; $i++) {
            $auth = new DummyAuth([]);
            $auth->loginFromSession();
            $this->assertTrue($auth->isLoggedIn());
        }

        $this->assertSame($token, $_SESSION['user']['session_token']);
        $this->assertSame(1, Query::from('user_sessions')->where('user_id', $this->added_user->getId())->count());
    }

    public function testLoginFromSessionWithoutAStoredSessionIdMintsOneTokenThenReusesIt(): void
    {
        $this->auth->loginFromForm();
        unset($_SESSION['user']['session_id']);

        (new DummyAuth([]))->loginFromSession();
        (new DummyAuth([]))->loginFromSession();

        $this->assertSame(2, Query::from('user_sessions')->where('user_id', $this->added_user->getId())->count());
    }

    public function testExpiredSessionLogsOut(): void
    {
        $this->auth->loginFromForm();
        $_SESSION['user']['session_expiration'] = time() - 1;

        $auth = new DummyAuth([]);
        $auth->loginFromSession();

        $this->assertFalse($auth->isLoggedIn());
        $this->assertSame('expired-session', $auth->getError());
    }

    public function testSessionFarFromExpiringIsNotRenewed(): void
    {
        $this->auth->loginFromForm();
        $expiration = $_SESSION['user']['session_expiration'];

        (new DummyAuth([]))->loginFromSession();

        $this->assertSame($expiration, $_SESSION['user']['session_expiration']);
    }

    public function testSessionNearExpirationIsRenewedByOneRenewalPeriod(): void
    {
        $this->auth->loginFromForm();
        $lifetime = (int) Config::get('USER_SESSION_LIFETIME');
        $renewal = (int) Config::get('USER_SESSION_RENEWAL');
        $expiration = time() + 100;
        $_SESSION['user']['session_expiration'] = $expiration;
        $_SESSION['user']['session_token_issued'] = $expiration - $lifetime;

        (new DummyAuth([]))->loginFromSession();

        $this->assertSame($expiration + $renewal, $_SESSION['user']['session_expiration']);
        $in_db = Query::from('user_sessions')->where('id', $_SESSION['user']['session_id'])->first();
        $this->assertSame($expiration + $renewal, strtotime($in_db['expiration']));
    }

    public function testRenewalIsCappedAtTheMaximumSessionTime(): void
    {
        $this->auth->loginFromForm();
        $maximum = (int) Config::get('USER_SESSION_LIFETIME')
            + (int) Config::get('USER_SESSION_RENEWAL') * (int) Config::get('USER_SESSION_RENEWAL_MAX');

        // Near expiration, 1000s short of the cap: the renewal only reaches the cap.
        $_SESSION['user']['session_expiration'] = time() + 100;
        $_SESSION['user']['session_token_issued'] = time() + 1000 - $maximum;
        (new DummyAuth([]))->loginFromSession();
        $capped = $_SESSION['user']['session_expiration'];
        $this->assertSame($_SESSION['user']['session_token_issued'] + $maximum, $capped);

        // Already at the cap: no further renewal, the session just runs out.
        $_SESSION['user']['session_expiration'] = time() + 100;
        $_SESSION['user']['session_token_issued'] = time() + 100 - $maximum;
        (new DummyAuth([]))->loginFromSession();
        $this->assertSame(time() + 100, $_SESSION['user']['session_expiration']);
    }

    public function testUserLoginCreatedSessionInDatabase(): void
    {
        $this->markTestIncomplete('This test has not been implemented yet.');

        // $this->auth->loginFromForm();
        //
        // $session_in_db = Query::from('user_sessions')
        //     ->where('user_id', $this->added_user->getId())
        //     ->where('ip', '1.1.1.1')
        //     ->first();
        //
        // $this->assertNotNull($session_in_db);
        // $this->assertTrue(password_verify($_SESSION['user']['session_token'], $session_in_db['session_token']));
        // $this->assertNotNull($session_in_db['expiration']);
        // $this->assertEquals($_SESSION['user']['session_expiration'], $session_in_db['expiration']);
    }

    #[DataProvider('incorrectCredentialsProvider')]
    public function testIncorrectCredentialsDoesNotWork(array $credentials, int $recorded_attempts): void
    {
        $auth = new DummyAuth($credentials);
        $auth->loginFromForm();

        $this->assertFalse($auth->isValid());
        $this->assertFalse($auth->isLoggedIn());
        $this->assertFalse($auth->isAuthenticated());
        $this->assertNull($auth->user_id);
        $this->assertEquals('login-failed', $auth->getError());

        $attempts = Query::from('login_attempts')->where('user_id', $this->added_user->getId())->fetch();
        $this->assertCount($recorded_attempts, $attempts);
    }

    public function testTooRapidLoginAttemptsAreBlocked(): void
    {
        $this->incorrect_auth->loginFromForm();
        $this->incorrect_auth->loginFromForm();
        $this->auth->loginFromForm();

        $this->markTestIncomplete('This test has not been implemented yet.');

        // $this->assertFalse($this->auth->isValid());
        // $this->assertFalse($this->auth->isLoggedIn());
        // $this->assertFalse($this->auth->isAuthenticated());
        // $this->assertNull($this->auth->user_id);
        // $this->assertEquals('login-failed', $this->auth->getError());
        // // Check that a failed login attempt was registered
        // $attempts = Query::from('login_attempts')
        //     ->where('user_id', $this->added_user->getId())
        //     ->fetch();
        // $this->assertCount(2, $attempts);
    }

    public function testTooManyLoginAttemptsAreBlocked(): void
    {
        // Simulate too many login attempts
        $attempts_limit = (int) Config::get('USER_ATTEMPTS_THRESHOLD', 5);

        for ($i = 0; $i < ($attempts_limit + 1); $i++) {
            $this->incorrect_auth->loginFromForm();
            sleep((int) Config::get('USER_RECENT_ATTEMPT_TIME')); // Simulate rapid attempts
        }

        $this->assertFalse($this->incorrect_auth->isValid());
        $this->assertFalse($this->incorrect_auth->isLoggedIn());
        $this->assertFalse($this->incorrect_auth->isAuthenticated());
        $this->assertNull($this->incorrect_auth->user_id);
        $this->assertEquals('unauthorized-login', $this->incorrect_auth->getError());

        // Check that a failed login attempt was registered
        $attempts = Query::from('login_attempts')->where('user_id', $this->added_user->getId())->fetch();
        $this->assertTrue(count($attempts) >= $attempts_limit);
    }

    protected function setUp(): void
    {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__, '../../../../.env');
        $dotenv->load();
        // Force the environment to use the test database since we cannot otherwise choose which database is used by models
        $_ENV['DB_NAME'] = 'pz_test';
        $_ENV['LOG_LEVEL'] = 'CRITICAL';

        $this->db = new Database($_ENV['DB_NAME']);
        $this->cleanDB();

        $this->user_data = [
            'username' => 'testuser',
            'password' => 'testpassword',
            'email' => 'tets@mail.com',
        ];

        // FastHashUser: bcrypt at production cost (10) makes every hash/verify ~150ms; cost 4 is
        // still a real bcrypt round-trip but negligible, and password_verify() reads the cost
        // from the hash itself so nothing downstream needs to know the difference.
        $user = new FastHashUser();
        $user->create($this->user_data);
        $this->added_user = $user;

        $this->auth = new DummyAuth($this->user_data);

        // An existing user with a wrong password: throttling is per user, so a nonexistent
        // username never accumulates attempts.
        $this->incorrect_auth = new DummyAuth([
            'username' => $this->user_data['username'],
            'password' => 'wrongpassword',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanDB();
    }

    protected function cleanDB(): void
    {
        // DELETE, not TRUNCATE: TRUNCATE is DDL (drops/recreates the table) and costs ~70ms per
        // table regardless of row count, vs single-digit ms for DELETE on these small tables —
        // measured directly, see the cellr test harness investigation. FOREIGN_KEY_CHECKS aren't
        // in play here (no FK-ordering issue across these four tables), so a plain DELETE is safe.
        $this->db->execute('DELETE FROM `users`');
        $this->db->execute('DELETE FROM `user_sessions`');
        $this->db->execute('DELETE FROM `nonces`');
        $this->db->execute('DELETE FROM `login_attempts`');
    }
}
