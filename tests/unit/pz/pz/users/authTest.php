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
            'wrong username' => [['username' => 'wronguser', 'password' => 'testpassword']],
            'wrong password' => [['username' => 'testuser', 'password' => 'wrongpassword']],
            'empty credentials' => [['username' => '', 'password' => '']],
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

    public function testUserLoginCreatedSessionInDatabase(): void
    {
        $this->auth->loginFromForm();

        $session_in_db = Query::from('user_sessions')
            ->where('user_id', $this->added_user->getId())
            ->where('ip', '1.1.1.1')
            ->first();

        $this->markTestIncomplete('This test has not been implemented yet.');

        // $this->assertNotNull($session_in_db);
        // $this->assertTrue(password_verify($_SESSION['user']['session_token'], $session_in_db['session_token']));
        // $this->assertNotNull($session_in_db['expiration']);
        // $this->assertEquals($_SESSION['user']['session_expiration'], $session_in_db['expiration']);
    }

    #[DataProvider('incorrectCredentialsProvider')]
    public function testIncorrectCredentialsDoesNotWork(): void
    {
        $this->incorrect_auth->loginFromForm();

        $this->assertFalse($this->incorrect_auth->isValid());
        $this->assertFalse($this->incorrect_auth->isLoggedIn());
        $this->assertFalse($this->incorrect_auth->isAuthenticated());
        $this->assertNull($this->incorrect_auth->user_id);
        $this->assertEquals('login-failed', $this->incorrect_auth->getError());

        // Check that a failed login attempt was registered
        $attempts = Query::from('login_attempts')->where('ip', '1.1.1.2')->fetch();
        $this->assertCount(1, $attempts);
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
        //     ->where('ip', '1.1.1.2')
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
        $attempts = Query::from('login_attempts')->where('ip', '1.1.1.2')->fetch();
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
        $this->auth->setIp('1.1.1.1');

        $this->incorrect_auth = new DummyAuth([
            'username' => 'wronguser',
            'password' => 'wrongpassword',
        ]);
        $this->incorrect_auth->setIp('1.1.1.2');
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
