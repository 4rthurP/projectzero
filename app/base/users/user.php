<?php

namespace pz\Models;

use Exception;
use pz\Enums\Routing\Privacy;
use pz\Enums\model\AttributeType;
use pz\Model;
use pz\Nonce;
use pz\database\Query;

class User extends Model {

    public static $name = "user";

    private $is_logged_in = false;
    private $primaryLoginMethod;
    private $passwordOptions = [];
    private $passwordAlgorithm = PASSWORD_DEFAULT;

    public function __construct() {
        $this->canView = Privacy::PUBLIC;
        $this->canEdit = Privacy::PROTECTED;
        $this->user(null);
        $this->timeStamps(false);
        $this->email();
        $this->attribute("username", AttributeType::CHAR, true);
        $this->primaryLoginMethod("username");
     
        parent::__construct();
    }

    protected function initialize() {
        parent::initialize();
        if(!$this->attributeExists("password")) {
            $this->password();
        }
        $this->attribute("credential", AttributeType::CHAR);
    }

    private function email(bool $use_email = true, bool $use_email_as_primary_login = true) {
        if($use_email) {
            $this->attribute("email", AttributeType::EMAIL, true);
            $this->primaryLoginMethod = $use_email_as_primary_login ? "email" : (isset($this->primaryLoginMethod) ? $this->primaryLoginMethod : null);
        } elseif($this->attributeExists("email")) {
            $this->unsetAttribute("email");

            #Make sure there is a valid primary login method if email is not used
            if(!isset($this->primaryLoginMethod)) {
                $this->primaryLoginMethod = "username";
            } else {
                if($this->primaryLoginMethod == "email" || $this->primaryLoginMethod == null) {
                    $this->primaryLoginMethod = "username";
                }
            }
        }
    }

    private function fullName() {
        $this->attribute("first_name", AttributeType::CHAR);
        $this->attribute("last_name", AttributeType::CHAR);
    }

    private function password($algorithm = PASSWORD_DEFAULT, Array $options = []) {
        $this->passwordAlgorithm = $algorithm;
        $options = $options === null ? [] : $options;
        $this->passwordOptions = $options;
        $this->attribute("password", AttributeType::CHAR, true);
    }

    public function isLoggedIn(): bool {
        return $this->is_logged_in;
    }

    private function primaryLoginMethod($method) {
        $this->primaryLoginMethod = $method;
    }

    public function login(Array $form_data): User {      
        /////// Checking form validity  
        $found_user = $this->findUser($form_data);
        if(!$found_user) {
            $this->is_valid = false;
            $this->messages[$this->primaryLoginMethod][] = ['error' ,'unknown-user', 'This user does not exist.'];
            return null;
        } 

        if(!isset($form_data['password'])) {
            $this->is_valid = false;
            $this->messages['password'][] = ['error' ,'missing-password', 'The password was not provided.'];
            return $this;
        }

        if(password_verify($form_data['password'], $found_user['password'])) {
            $this->loadFromArray($found_user);
            $this->is_logged_in = true;
            $nonce = Nonce::getOrNew($this->id);
            $this->setUserSession($nonce);
            return $this;
        } 

        #TODO: log number of failed attempts and look if it is no more than a threshold set in config
        $this->is_valid = false;
        $this->messages['password'][] = ['error' ,'wrong-password', 'Incorrect password.'];
        return $this;
    }

    public static function logout() {
        unset($_SESSION['user']);
        setcookie('user_id', '', time() - 3600, '/');
        setcookie('user_name', '', time() - 3600, '/');
        setcookie('user_role', '', time() - 3600, '/');
        setcookie('user_credential', '', time() - 3600, '/');
        setcookie('user_nonce', '', time() - 3600, '/');
        setcookie('user_nonce_expiration', '', time() - 3600, '/');
    }

    public function create(null|array $attributes_array = null): null|self
    {
        parent::create($attributes_array);

        $this->is_logged_in = true;
        $nonce = Nonce::getOrNew($this->id);
        $this->setUserSession($nonce);

        return $this;   
    }

    public function checkForm(Array $attributes_array): null|self
    {
        $attributes_array['password'] = password_hash($attributes_array['password'], $this->passwordAlgorithm, $this->passwordOptions);

        $attributes_array['credential'] = $this->generateCredential();

        $found_user = $this->findUser($attributes_array);
        if($found_user != null) {
            $this->is_valid = false;
            $this->messages['all'][] = ['error', 'user-exists', 'This user already exists'];
        }

        parent::checkForm($attributes_array);
        
        if($this->is_valid) {
            return $this;
        }

        return null;
    }

    public static function authentificate($user_id, $nonce_received, $credential = null) {
        $user = User::load($user_id);
        $user->is_logged_in = false;

        if($credential !== null && $user->get('credential') == $credential) {
            $user->is_logged_in = true;
            return $user;
        }
        
        if($nonce_received == null) {
            $user->is_valid = false;
            $user->messages['all'][] = ['error', 'missing-nonce', 'A nonce is required to perform this action.'];
            return $user;
        }
        
        $nonce = new Nonce($user->id);
        $nonce_check = $nonce->checkNonce($nonce_received);
        if($nonce_check['success']) {
            $user->is_logged_in = true;
            $user->getNewNonce();
            return $user;
        }
        
        $user->is_valid = false;
        $user->messages['all'][] = ['error', $nonce_check['error'], $nonce_check['message']];
        return $user;
    }

    public function getUserInfos(bool $get_nonce = true) {
        if(!$this->is_logged_in) {
            return null;
        }

        $data = $this->toArray();

        if($get_nonce) {
            $data['nonce'] = $this->getNonce();
        }

        return $data;        
    }

    public function getNonce() {
        if($this->is_logged_in) {
            $nonce = Nonce::getOrNew($this->id);
            $this->setUserSession($nonce);
            return $nonce;
        }
        
        return ['success' => false, 'error' => 'not-logged-in', 'message' => 'User is not logged in'];
    }

    public function getNewNonce($credential) {
        if($credential !== null && $this->attributes['credential']->getAttributeValue() == $credential) {
            $this->is_logged_in = true;
            $nonce = new Nonce($this->id);
            return $nonce->getNewNonce();
        }

        return ['success' => false, 'error' => 'invalid-credentials', 'message' => 'Invalid credentials'];
    }

    private function generateCredential() {
        $randomFactor = random_bytes(16);
        $appKey = getenv('APP_KEY');
        $credential = hash_hmac('sha256', $randomFactor, $appKey);
        return $credential;
    }

    private function findUser(Array|String $login) {
        if(is_array($login)) {
            if(!isset($login[$this->primaryLoginMethod])) {
                $this->is_valid = false;
                $this->messages[$this->primaryLoginMethod][] = ['error' ,'missing-login', 'The login is missing.'];
                return null;
            }

            $login = $login[$this->primaryLoginMethod];
        }
        $found_user = Query::from($this->table)->where($this->primaryLoginMethod, $login)->first();

        return $found_user;
    }

    private function setUserSession($nonce) {
        $_SESSION['user'] = ['id' => $this->id, 'name' => $this->attributes[$this->primaryLoginMethod]->getAttributeValue(), 'role' => 'user', 'credential' => $this->attributes['credential']->getAttributeValue(), 'nonce' => $nonce['nonce'], 'nonce_expiration' => $nonce['expiration'], 'cookie_end' => time() + 7*24*3600];

        //Moved to pz/app -> delete 
        // setcookie('user_id', $_SESSION['user']['id'], $_SESSION['user']['cookie_end'], '/');
        // setcookie('user_name', $_SESSION['user']['name'], $_SESSION['user']['cookie_end'], '/');
        // setcookie('user_role', $_SESSION['user']['role'], $_SESSION['user']['cookie_end'], '/');
        // setcookie('user_credential', $_SESSION['user']['credential'], $_SESSION['user']['cookie_end'], '/');
        // setcookie('user_nonce', $_SESSION['user']['nonce'], $_SESSION['user']['cookie_end'], '/');
        // setcookie('user_nonce_expiration', $_SESSION['user']['nonce_expiration'], $_SESSION['user']['cookie_end'], '/');

    }
}