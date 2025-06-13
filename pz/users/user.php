<?php

namespace pz\Models;

use Exception;
use PhpOption\None;
use pz\Config;
use pz\database\Database;
use pz\Log;
use pz\Enums\Routing\Privacy;
use pz\Enums\model\AttributeType;
use pz\Model;
use pz\Nonce;
use pz\database\Query;

class User extends Model {

    public static $name = "user";

    protected $primaryLoginMethod;
    private $passwordOptions = [];
    private $passwordAlgorithm = PASSWORD_DEFAULT; #TODO: make this configurable

    public function __construct() {
        $this->canView = Privacy::PUBLIC;
        $this->canEdit = Privacy::PROTECTED;
        $this->timestamps(false);
        $this->user(null);
        $this->email();
        $this->attribute("username", AttributeType::CHAR, true);
        $this->primaryLoginMethod("username");
     
        parent::__construct();
    }

    ###### User attributes ######
    protected function initialize() {
        parent::initialize();
        if(!$this->attributeExists("password")) {
            $this->password();
        }
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

    protected function primaryLoginMethod($method) {
        $this->primaryLoginMethod = $method;
    }

    public function getLoginMethod(): string {
        return $this->primaryLoginMethod;
    }
    
    public function getLogin() {
        return $this->get($this->primaryLoginMethod);
    }

    ###### Parent methods ######
    /**
     * Supercharges the parent method to add additional checks
     * - Check if the user already exists (gives an error if it does)
     * - Hashes the password for storage
     * 
     */
    public function checkForm(Array $attributes_array, bool $is_update = false): null|static
    {
        $attributes_array['password'] = password_hash($attributes_array['password'], $this->passwordAlgorithm, $this->passwordOptions);

        if(!isset($attributes_array[$this->primaryLoginMethod])) {
            $this->is_valid = false;
            $this->messages[$this->primaryLoginMethod][] = ['error' ,'missing-login', 'The login is missing.'];
            return null;
        }

        $found_user = Query::from($this->table)
            ->where($this->primaryLoginMethod, $attributes_array[$this->primaryLoginMethod])
            ->first();

        if($found_user != null) {
            $this->is_valid = false;
            $this->messages['all'][] = ['error', 'user-exists', 'This user already exists'];
        }

        parent::checkForm($attributes_array, $is_update);

        unset($this->form_data['password']);
        
        if($this->is_valid) {
            return $this;
        }

        return null;
    }

    /**
     * Supercharges the toArray method to remove the password from the array
     * @return array
     */
    public function toArray(): Array {
        $user = parent::toArray();
        unset($user['password']);
        return $user;
    }
}