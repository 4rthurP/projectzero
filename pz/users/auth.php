<?php

namespace pz;

use Exception;
use DateInterval;
use DateTime;

use pz\Models\User;
use pz\Nonce;
use pz\database\Query;
use pz\database\Database;

class Auth {
    protected ?User $user;
    protected string $user_model;
    protected string $ip;
    protected string $login_method;

    protected bool $is_valid = true;
    protected $is_logged_in = false;
    protected $is_authenticated = false;

    protected string $session_token;
    protected int $session_token_expiration;

    protected Nonce $nonce;
    protected ?string $nonce_received;

    protected array $request_data;

    protected ?string $error = null;
    protected ?string $error_message = null;

    public ?string $user_id {
        get => $this->user?->getId();
    }

    public function __construct(array $request_data, string $user_model = User::class) {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $this->ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $this->ip = $_SERVER['HTTP_CLIENT_IP'];
        } else {
            $this->ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        
        if(isset($request_data['nonce'])) {
            $this->nonce_received = $request_data['nonce'];
        } elseif(isset($_SESSION['user'])) {
            $this->nonce_received = $_SESSION['user']['nonce'] ?? null;
        } else {
            $this->nonce_received = null;
        } 

        $this->request_data = $request_data;
        
        $this->user_model = $user_model;
        $this->user = $this->newUser();
        $this->login_method = $this->user->getLoginMethod();
    }

    ################################################
    # Login methods
    ################################################
    /**
     * Logs in a user based on data provided by the signin form.
     * Called from the user controller when a login request is made.
     *
     * @return static Returns the current instance of the class for method chaining.
     *
     * The method does the following:
     * - Checks if a login attempt can be made from the given IP address.
     * - Validates the user credentials provided in the form data.
     * - If all checks pass, it calls the loginUser method to set the user session.
     */
    public function loginFromForm(): static {   
        // Checking if the user is already logged in
        if($this->is_logged_in) {
            return $this;
        } 
        
        // Checking security
        if(!$this->checkCanMakeLoginAttempt()) {
            return $this;
        }
        
        // Checking if the user exists  
        $this->findUser();
        if($this->user == null) {
            return $this->failedLoginAttempt('This user does not exist.');
        } 
        
        // Checking password
        if(!isset($this->request_data['password']) || $this->request_data['password'] == '') {
            return $this->failedLoginAttempt('Password is missing', 'missing-password', false);
        }
        if(!password_verify($this->request_data['password'], $this->user->get('password'))) {
            return $this->failedLoginAttempt('Incorrect password');
        } 

        return $this->loginUser();
    }

    /**
     * Loads the authenticated user from the session.
     * Called from the application when a session is detected during request initialization.
     *
     * This method checks if a user is stored in the session. If a user is found,
     * it attempts to retrieve the user from the database using the user model.
     * If the user is successfully retrieved, the authentication state is updated,
     * and the user is logged in.
     *
     * @return static Returns the current instance of the class for method chaining.
     */
    public function loginFromSession(): static {
        if(!isset($_SESSION['user'])) {
            return $this;
        }

        $this->user = $this->user_model::find($_SESSION['user']['id']);
        if($this->user == null) {
            return $this;
        }

        return $this->loginUser();
    }

    /**
     * Logs in a user based on a session cookie.
     * Called from the application when a session cookie is detected during request initialization.
     *
     * @return static Returns the current instance of the class for method chaining.
     *
     * The method performs the following steps:
     * - Validates the format of the session cookie.
     * - Extracts the user ID and token from the session cookie.
     * - Queries the database for a valid session associated with the user ID.
     * - Checks if the session exists and has not expired.
     * - Verifies that the IP address matches the one stored in the session.
     * - Validates the session token using password verification.
     *
     * If any of the validation steps fail, appropriate error messages are logged, and
     * the method handles the failure by either logging out the user or marking the session as invalid.
     *
     */
    public function loginFromSessionToken(string $session_cookie): static {
        // Checks the format of the given session cookie
        $token_match_format = preg_match('/^(\d+)::(.+)$/', $session_cookie, $decode_session_token);
        if (!$token_match_format) {
            // Invalid session cookie format
            return $this->failedLoginAttempt('Invalid session.');
        }
        $user_id = $decode_session_token[1];
        $token = $decode_session_token[2];

        // Finds the user based on the given user ID
        $this->user = User::find($user_id);
        if ($this->user == null) {
            // User does not exist
            return $this->failedLoginAttempt('Invalid session.');
        }
        
        // Finds the latest session for the user
        $latest_session = $this->getLatestSessionToken();

        // If the session is not found, it probably means it expired
        // We do not raise a failed login attempt here, the user might have a valid session that expired, and we don't want to penalize them for that.
        if ($latest_session == null) { 
            $this->is_valid = false;
            $this->error = 'session-expired';
            $this->error_message = 'Session expired.';
            $this->logoutUser();
            return $this;
        }

        if(!password_verify($token, $latest_session['token'])) {
            // Invalid session token
            return $this->failedLoginAttempt('Invalid session.');
        }

        // We now know the session is valid, we can check if it can be renewed
        $this->setSessionToken(
            $latest_session['token'],
            (new DateTime($latest_session['expiration'], Config::tz()))->getTimestamp(),
            (new DateTime($latest_session['issued_at'], Config::tz()))->getTimestamp(),
            true
        );
        

        return $this->loginUser(load_session_token: false);
    }

    /**
     * Internal method to log in the user by setting session and cookie data.
     * 
     * @param bool $load_session_token Optional. Determines whether to load the session token for the user. Defaults to true. 
     *                                 Used when logging in from a session token to avoid creating a new session token.
     * @return static Returns the current instance of the class for method chaining.
     * 
     * This method performs the following actions:
     * - Marks the user as logged in by setting the `$is_logged_in` property to `true`.
     * - Generates or retrieves a nonce for the user and sets it.
     * - Initializes the $_SESSION variable with user data.
     * - Sets cookies for the user's ID and login name
     * - Loads the session token for the user.
     */
    protected function loginUser(bool $load_session_token = true): static {
        $this->is_logged_in = true;

        // Retrieve or create a nonce for the user and set it
        // $nonce = new Nonce($this->user_id);
        // $this->setNonce($nonce->getOrNew());

        // Set session and cookie data for the user
        $cookies_expiration = Config::get('USER_SESSION_LIFETIME');

        $_SESSION['user']['id'] = $this->user_id;
        $_SESSION['user']['name'] = $this->user->getLogin();
        $_SESSION['user']['role'] = 'user';
        $_SESSION['user']['cookie_end'] = time() + $cookies_expiration;

        setcookie('user_id', $this->user_id, time() + $cookies_expiration, '/');
        setcookie('user_name', $this->user->getLogin(), time() + $cookies_expiration, '/');
        
        if($load_session_token) {
            $this->loadSessionToken();
        }

        return $this;
    }

    ################################################
    # Login security methods
    ################################################
    protected function failedLoginAttempt(?string $message = null, ?string $error = null, bool $register_attempt = true): static {
        $this->is_valid = false;
        $this->is_logged_in = false;
        $this->is_authenticated = false;
        $this->error = $error ?? 'login-failed';
        $this->error_message = $message ?? 'Failed login attempts from ' . $this->ip;

        if($register_attempt) {
            Database::insert(
                'login_attempts', 
                ['ip', 'created_at'], 
                [
                    $this->ip, 
                    (new DateTime('now', Config::tz()))->format('Y-m-d H:i:s')
                ]
            );
        }

        $this->logoutUser();
        return $this;
    }

    /**
     * Checks if a login attempt can be made from the given IP address.
     *
     * This method verifies two conditions:
     * 1. Whether the IP address is currently banned from making login attempts.
     * 2. Whether the IP address has made a recent login attempt.
     *
     * @return bool Returns true if the IP address is allowed to make a login attempt, 
     *              false otherwise.
     */
    protected function checkCanMakeLoginAttempt() {
        $has_attempt_ban = $this->checkIfIpIsBanned();
        if($has_attempt_ban) {
            return false;
        }
        
        $has_recent_attempt = $this->checkIfIpHasRecentAttempt();
        if($has_recent_attempt) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Checks if the given IP address is banned due to too many login attempts.
     *
     * This method retrieves the number of login attempts made from the specified IP
     * address within a time frame defined by the configuration. If the number of
     * attempts exceeds the threshold, the IP is considered banned, and an error
     * message is added to the login messages.
     *
     * @return bool Returns true if the IP is banned, false otherwise.
     */
    protected function checkIfIpIsBanned() {
        $ban_time = Config::get('USER_BAN_TIME');
        $ban_threshold = Config::get('USER_ATTEMPS_THRESHOLD');
        $current_time = new DateTime('now', Config::tz());
        
        $attempts = Query::from('login_attempts')
            ->where('ip', $this->ip)
            ->where('created_at', '>', $current_time->sub(new DateInterval('PT' . $ban_time . 'S'))->format('Y-m-d H:i:s'))
            ->count();

        if($attempts >= $ban_threshold) {
            $this->failedLoginAttempt('You made too many login attemps, comme back latter.', 'unauthorized-login');
            return true;
        }
        
        return false;
    }
    
    /**
     * Checks if the given IP address has made a recent login attempt within the configured time frame.
     *
     * This method queries the `login_attempts` table to determine if there are any login attempts
     * from the specified IP address that occurred within the time period defined by the 
     * `USER_RECENT_ATTEMPT_TIME` configuration value. If such attempts are found, the method
     * marks the current instance as invalid and adds an error message to the messages array.
     *
     * @return bool Returns true if a recent login attempt is found, otherwise false.
     */
    protected function checkIfIpHasRecentAttempt() {
        $recent_attempt_time = Config::get('USER_RECENT_ATTEMPT_TIME');
        $current_time = new DateTime('now', Config::tz());
        
        $attempts = Query::from('login_attempts')
            ->where('ip', $this->ip)
            ->where('created_at', '>', $current_time->sub(new DateInterval('PT' . $recent_attempt_time . 'S'))->format('Y-m-d H:i:s'))
            ->count();

        if($attempts > 0) {
            $this->failedLoginAttempt('You made a login attempt too recently.', 'unauthorized-login');
            return true;
        }

        return false;
    }

    ################################################
    # Logout methods
    ################################################
    /**
     * Logs out the current user by performing the following actions:
     * - Unsets the 'user' session variable.
     * - Deletes cookies related to the user's session
     * 
     * @return void
     */
    public static function logout() {
        unset($_SESSION['user']);
        setcookie('user_id', '', time() - 3600, '/');
        setcookie('user_name', '', time() - 3600, '/');
        setcookie('user_role', '', time() - 3600, '/');
        setcookie('user_session_token', '', time() - 3600, '/');
        setcookie('user_nonce', '', time() - 3600, '/');
        setcookie('user_nonce_expiration', '', time() - 3600, '/');
        setcookie('PHPSESSID', '', time() - 3600, '/');
    }

    /**
     * Internal method to log out the user.
     * 
     * This method should be preferred over the static logout method
     * as it handles unsetting the user object and its properties from the current instance.
     * 
     * @return void
     */
    protected function logoutUser() {
        $this->is_logged_in = false;
        $this->is_authenticated = false;
        $this->session_token = '';
        $this->session_token_expiration = 0;

        Auth::logout();
    }

    ################################################
    # Session management methods
    ################################################
    /**
     * Loads the current session token for the user during the login process.
     *
     * If no session token exists, a new one is created. 
     * If a session token is found, it is set as the current session token.
     *
     * @return static Returns the current instance of the class.
     */
    protected function loadSessionToken(): static {
        if(!$this->is_logged_in) {
            throw new Exception('Tried to check session for a user that is not logged in');
        }

        // Load the latest session infos when available to avoid unnecessary database calls
        if(
            isset($_SESSION['user']['session_token']) && 
            isset($_SESSION['user']['session_expiration']) && 
            isset($_SESSION['user']['session_token_issued'])
            && $_SESSION['user']['session_expiration'] > time()
        ) {
            $this->setSessionToken(
                $_SESSION['user']['session_token'],
                intval($_SESSION['user']['session_expiration']),
                intval($_SESSION['user']['session_token_issued']), 
                true
            );
            return $this;

        } 

        // Need to create a new session token since no valid session token was found
        $this->createSessionToken();
        return $this;
    }

    /**
     * Retrieves the latest valid session token for the current user and IP address to check its validity.
     *
     * @return array|null Returns the latest session as an associative array if found
     *                    and valid, or null if no valid session exists.
     */
    protected function getLatestSessionToken(): ?array {
        $latest_session = Query::from('user_sessions')
            ->where('user_id', $this->user->getId())
            ->where('ip', $this->ip)
            ->order('issued_at', false)
            ->first();

        if(!$latest_session) {
            return null;
        }

        if(new DateTime($latest_session['expiration'], Config::tz()) < new DateTime('now', Config::tz())) {
            return null;
        }

        return $latest_session;
    }

    /**
     * Creates a new session token for the authenticated user.
     *
     * @return void
     *
     * This method does the following:
     * - Generates the token
     * - Saves the token in the database
     * - Sets the object's session token and expiration properties
     * - Calls the setUserSessionCookie helper to set the cookie
     */
    protected function createSessionToken(): void {
        $rand_string = bin2hex(random_bytes(16));
        $expiration_delay = Config::get('USER_SESSION_LIFETIME');
        $expiration_date = time() + $expiration_delay;

        Database::insert(
            'user_sessions', 
            ['user_id', 'token', 'issued_at','expiration', 'ip'], 
            [
                $this->user->getId(), 
                password_hash($rand_string, PASSWORD_DEFAULT), 
                (new DateTime('now', Config::tz()))->format('Y-m-d H:i:s'),
                (new DateTime('now', Config::tz()))->createFromTimestamp($expiration_date)->format('Y-m-d H:i:s'),
                $this->ip,
            ],
        );

        $this->setSessionToken(
            $rand_string, 
            $expiration_date, 
            time(),
            false
        );
        $this->setUserSessionCookie();
    }


    /**
     * Sets a given session token for the user.
     *
     * @param string $token The session token to be set for the user.
     * @param int $expiration The expiration timestamp for the session token.
     * @param int $issued_at The timestamp when the session token was issued.
     * @param bool $renew_if_possible Optional. Determines whether to attempt renewing the session 
     *                                 if possible. Defaults to true.
     *
     * @return static Returns the current instance of the class for method chaining.
     * 
     * This method does the following:
     * - Sets the session token and expiration properties of the current instance.
     * - Optionally attempts to renew the session if possible.
     * - Updates the session data in the $_SESSION superglobal.
     * - Uses the setUserSessionCookie helper to set the session cookie.
     */
    protected function setSessionToken(string $token, int $expiration, int $issued_at, bool $renew_if_possible = true): static {
        $this->session_token = $token;
        $this->session_token_expiration = $expiration;

        if($renew_if_possible) {
            $this->checkIfSessionCanBeRenewed($token, $expiration, $issued_at);
        }
        
        $_SESSION['user']['session_token'] = $this->session_token;
        $_SESSION['user']['session_expiration'] = $this->session_token_expiration;
        $_SESSION['user']['session_token_issued'] = $issued_at;

        $this->setUserSessionCookie();

        return $this;
    }

    /**
     * Sets the user session cookie with the session token.
     * This is only a helper method to set the cookie, 
     * it relies on the session token and expiration properties already being set.
     * 
     * @return void
     */
    protected function setUserSessionCookie(): void {
        setcookie(
            'user_session_token', 
            $this->user->getId() . '::' . $this->session_token, 
            $this->session_token_expiration, 
            '/'
        );
    }


    /**
     * Checks if the current user session can be renewed and renews it if necessary.
     *
     * This method evaluates whether the user's session is nearing expiration and 
     * determines if it can be extended based on the configured session renewal settings.
     * If renewal is possible, the session expiration time is updated in the database.
     *
     * @param string $token The session token to be set for the user.
     * @param int $expiration The expiration timestamp for the session token.
     * @param int $issued_at The timestamp when the session token was issued.
     *
     * @return bool Returns true if the session was renewed, false otherwise.
     *
     * Configuration keys used:
     * - USER_SESSION_RENEWAL_ENABLED (bool): Determines if session renewal is enabled.
     * - USER_SESSION_LIFETIME (int): The base lifetime of a session in seconds.
     * - USER_SESSION_RENEWAL (int): The duration of each session renewal in seconds.
     * - USER_SESSION_RENEWAL_MAX (int): The maximum number of times a session can be renewed.
     *
     * Database interaction:
     * Updates the `expiration` field of the `user_sessions` table for the given session ID.
     *
     * Conditions for renewal:
     * - Session renewal must be enabled.
     * - The current session duration must not exceed the maximum allowable session time.
     * - The session must be nearing expiration (based on the configured renewal time).
     */
    protected function checkIfSessionCanBeRenewed(string $token, int $expiration, int $issued_at): bool {
        if(!Config::get('USER_SESSION_RENEWAL_ENABLED')) {
            return false;
        }
        $session_lifetime = Config::get('USER_SESSION_LIFETIME');
        $session_renewal_time = Config::get('USER_SESSION_RENEWAL');
        $session_renewal_max = Config::get('USER_SESSION_RENEWAL_MAX');

        $maximum_session_time = $session_lifetime + $session_renewal_time * $session_renewal_max;

        $current_session_time = time() - $issued_at;
        $current_session_duration = $expiration - $issued_at;

        // The current session is already set to expire at the maximum time
        if($current_session_duration >= $maximum_session_time) {
            return false;
        }

        // The current session does not expire before half of the renewal time
        if($current_session_time < $session_lifetime / 2) {
            return false;
        }
        
        // Same for each of the renewal times, check if the session is not about to expire (ie less than half of the renewal time)
        for($i = 1; $i <= $session_renewal_max; $i++) {
            if($current_session_time < $session_lifetime + $session_renewal_time * ($i - 1) + ($session_renewal_time  / 2)) {
                return false;
            }
        }

        // The session is about to expire, we need to renew it
        $new_expiration = time() + $session_renewal_time;
        Database::execute(
            "UPDATE user_sessions SET expiration = ? WHERE token = ?",
            "si",
            (new DateTime('now', Config::tz()))->createFromTimestamp($new_expiration)->format('Y-m-d H:i:s'),
            password_hash($token, PASSWORD_DEFAULT),
        );

        $this->session_token = $token;
        $this->session_token_expiration = $new_expiration;

        return true;
    }

    ################################################
    # Nonce methods
    ################################################
    /**
     * Logs in a user based on a nonce received.
     *
     * @return $this Returns the current instance of the user object.
     *
     * This method sets the user's login status based on the validity of the provided nonce.
     * If the nonce is missing or invalid, it sets the user as not logged in and adds an error message.
     * If the nonce is valid, it sets the user as logged in and generates a new nonce.
     *
     */
    public function authentificate() {
        if(!$this->is_logged_in) {
            $this->failedLoginAttempt($_SERVER['REMOTE_ADDR'], 'Using nonce while not logged in');
        }

        if($this->is_authenticated) {
            # If we are using a nonce different from the one we have, this could be a problem
            if($this->nonce_received != $this->nonce()) {
                return $this->failedLoginAttempt($_SERVER['REMOTE_ADDR'], 'Using another nonce while already authenticated');
            }
            
            # Otherwise it is not a problem if it happens, we simple log it and return the user without further processing
            Log::warning('Used nonce on already authenticated user ' . $this->user->getId());
            return $this;
        }

        if($this->nonce_received == null) {
            return $this->failedLoginAttempt($_SERVER['REMOTE_ADDR'], 'Missing nonce');
        }
            
        # Check the nonce given
        $nonce = new Nonce($this->user->getId());
        $nonce->checkNonce($this->nonce_received);
        $this->setNonce($nonce); 

        if($nonce->isValid()) {
            $this->is_authenticated = true;
            return $this;
        }
        
        return $this->failedLoginAttempt($_SERVER['REMOTE_ADDR'], $nonce->wasExpired() ? 'Expired nonce' : 'Invalid nonce');   
    }

    protected function setNonce(Nonce $nonce) {
        $this->nonce = $nonce;
        $this->nonce_received = $nonce->nonce();
        $_SESSION['user']['nonce'] = $nonce->nonce();
        $_SESSION['user']['nonce_expiration'] = $nonce->expiration();

        $cookies_expiration = Config::get('USER_SESSION_LIFETIME');
        setcookie('user_nonce', $nonce->nonce(), time() + $cookies_expiration, '/');
        setcookie('user_nonce_expiration', $nonce->expiration()->format('Y-m-d H:i:s'), time() + $cookies_expiration, '/');
    }
    
    public function nonce() {
        if($this->nonce == null) {
            return null;
        }
        return $this->nonce->nonce();
    }
    
    /**
     * Retrieves the previous nonce associated with the current nonce.
     *
     * Used only for internal purposes, once a nonce is used, it is not valid anymore.
     *
     * @return mixed|null The previous nonce if it exists, or null if the current nonce is null.
     */
    protected function previousNonce() {
        if($this->nonce == null) {
            return null;
        }
        return $this->nonce->previousNonce();
    }

    public function nonceExpiration() {
        if($this->nonce == null) {
            return null;
        }
        return $this->nonce->expiration();
    }
    
    ################################################
    # Helper methods
    ################################################
    /**
     * Finds a user from the given login.
     * 
     * @return User|null The found user or null if not found.
     */
    private function findUser(): ?User {
        # We need to check if the login method is present
        if(!isset($this->request_data[$this->login_method])) {
            $this->is_valid = false;
            $this->error = 'missing-login';
            $this->error_message = 'The login is missing.';
            return null;
        }
        
        $user_class = $this->user_model;
        $found_user = $user_class::query(
            [$this->login_method => $this->request_data[$this->login_method]]
        );
        if($found_user == null || count($found_user) == 0) {
            return null;
        }

        $this->user = $found_user[0];
        return $this->user;
    }
    
    protected function newUser() {
        $user_model = $this->user_model;
        return new $user_model();
    }

    ################################################
    # Getters and Setters
    ################################################
    public function isValid(): bool {
        return $this->is_valid;
    }

    public function isLoggedIn(): bool {
        # Only a valid auth can be logged in
        if($this->is_valid) {
            return $this->is_logged_in;
        }

        return false;
    }

    public function isAuthenticated(): bool {
        # Only a valid auth can be authenticated
        if($this->is_valid) {
            return $this->is_authenticated;
        }

        return false;
    }

    public function getUser(): User {
        return $this->user;
    }
    
    public function getNonce(): ?string {
        return $this->nonce?->nonce();
    }

    public function getNonceExpiration(): ?Datetime {
        return $this->nonce?->expiration();
    }

    public function getError(): ?string {
        return $this->error;
    }

    public function getErrorMessage(): ?string {
        return $this->error_message;
    }
}