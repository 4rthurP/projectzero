<?php

namespace pz\Routing;

use DateTime;
use pz\Enums\Routing\Method;
use pz\Models\User;
use pz\Auth;
use pz\Log;
use function PHPUnit\Framework\isInstanceOf;

class Request
{
    protected ?String $action;
    protected ?String $success_location;
    protected ?String $error_location;
    protected ?Method $method;
    
    public ?array $data;
    
    protected ?Auth $auth;

    public function __construct(
        ?Method $method = null,
        ?array $data = null,
        ?String $action = null
    ) {
        $this->method = $method;
        $this->action = $action;
        $this->data = $data ?? [];
        $this->success_location = null;
        $this->error_location = null;
        $this->auth = null;
    }


    #####################################
    # User and Authentication
    #####################################
    public function authentificateUser() {
        if($this->auth == null) {
            return false;
        }

        $this->auth->authentificate();

        return $this->auth->isAuthenticated();
    }

    public function isLoggedIn()
    {
        if($this->auth == null) {
            return false;
        }
        return $this->auth->isLoggedIn();
    }

    public function isAuthenticated()
    {
        if($this->auth == null) {
            return false;
        }
        return $this->auth->isAuthenticated();
    }

    public function setAuth(Auth $auth)
    {
        Log::info('Request setting auth');
        $this->auth = $auth;
        if($auth->isLoggedIn()) {
            $this->data['user_id'] = $auth->getUserId();
        }
        return $this;
    }

    /**
     * Checks if a user is associated with the current request.
     *
     * @return bool Returns true if a user is set, false otherwise.
     */
    public function hasUser()
    {
        if(!isset($this->auth)) {
            return false;
        }
        
        if($this->auth->getUser() != null) {
            return true;
        }
        return false;
    }

    /**
     * Retrieves the user associated with the current request.
     *
     * @return mixed|null Returns the user if it is set; otherwise, returns null.
     */
    public function user()
    {
        if($this->auth == null) {
            return null;
        }
        
        return $this->auth->getUser();
    }

    /**
     * Retrieves the nonce value associated with the current request.
     *
     * @return mixed The nonce value.
     */
    public function nonce()
    {
        return $this->auth->getNonce();
    }

    public function nonceExpiration()
    {
        return $this->auth->getNonceExpiration();
    }

    /**
     * Checks if the current request contains a nonce.
     *
     * @return bool Returns true if a nonce is present, otherwise false.
     */
    public function hasNonce()
    {
        return $this->auth->getNonce() !== null;
    }


    #####################################
    # Request Data
    #####################################

    /**
     * Retrieves the data associated with the current request.
     *
     * @return mixed The data of the request.
     */
    public function data()
    {
        return $this->data;
    }

    /**
     * Sets the data for the request, optionally extracting and storing a nonce value.
     *
     * This method updates the internal data array by merging the provided data
     * with the existing data. If the provided data contains a 'nonce' key, its
     * value is stored separately in the `$nonce` property, and the key is removed
     * from the data array before merging.
     *
     * @param array $data An associative array of data to set. If it contains a 'nonce'
     *                    key, the value will be extracted and stored in the `$nonce`
     *                    property, and the key will be removed from the array.
     */
    public function setData(array $data)
    {
        # The nonce is not stored in the data array for security reasons
        if (isset($data['nonce'])) {
            unset($data['nonce']);
        }

        # Merge the new data with the existing data
        $this->data = array_merge($this->data, $data);
    }

    /**
     * Adds a key-value pair to the request data.
     *
     * @param string $key The key to associate with the value.
     * @param mixed $value The value to store.
     * @return static Returns the current instance for method chaining.
     */
    public function addData(String $key, $value): static
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Retrieves the value associated with the specified key from the request data.
     *
     * @param string $key The key to look for in the request data.
     * @return mixed|null The value associated with the key, or null if the key does not exist.
     */
    public function getData(String $key)
    {
        if (!isset($this->data[$key])) {
            return null;
        }
        return $this->data[$key];
    }

    /**
     * Checks if the specified key exists in the data array and is not an empty string.
     *
     * @param string $key The key to check in the data array.
     * @return bool Returns true if the key exists and its value is not an empty string, otherwise false.
     */
    public function hasData(String $key): bool
    {
        if (!isset($this->data[$key])) {
            return false;
        }
        return $this->data[$key] != '';
    }

    /**
     * Checks if a specific key exists in the data. If the key does not exist, 
     * it sets the key with the provided value. Returns the current instance 
     * for method chaining.
     *
     * @param string $key The key to check or set in the data.
     * @param mixed $value The value to set if the key does not exist.
     * @return static Returns the current instance for method chaining.
     */
    public function hasOrSetData(String $key, $value): static
    {
        if (!$this->hasData($key)) {
            $this->addData($key, $value);
        }
        return $this;
    }

    /**
     * Retrieves the uploaded file information from the $_FILES superglobal.
     *
     * @param string $file_name The name of the file input field to retrieve.
     * @return array|null Returns the file information as an associative array if the file exists,
     *                    or null if the file is not found in the $_FILES superglobal.
     */
    public function getFile($file_name): ?array {
        if (isset($_FILES[$file_name])) {
            return $_FILES[$file_name];
        }
        return null;
    }

    #####################################
    # General getters and setters
    #####################################

    /**
     * Retrieves the current action associated with the request.
     *
     * @return string The action name.
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Sets the action for the current request.
     *
     * @param string $action The name of the action to set.
     * @return void
     */
    public function setAction(String $action)
    {
        $this->action = $action;
    }

    /**
     * Retrieves the HTTP method of the current request.
     *
     * @return ?Method The HTTP method (e.g., 'GET', 'POST', 'PUT', 'DELETE').
     */
    public function getMethod(): ?Method
    {
        return $this->method;
    }

    /**
     * Sets the HTTP method for the current request.
     *
     * @param Method $method The HTTP method to set (e.g., GET, POST, PUT, DELETE).
     * @return void
     */
    public function setMethod(Method $method)
    {
        $this->method = $method;
    }

    /**
     * Sets the success location for the request.
     *
     * @param string $location The location to be set as the success location.
     * @return void
     */
    public function onSuccess(String $location)
    {
        $this->success_location = $location;
    }

    /**
     * Sets the error location for the request.
     *
     * @param string $location The location to redirect or handle errors.
     * @return void
     */
    public function onError(String $location)
    {
        $this->error_location = $location;
    }

    /**
     * Retrieves the success location.
     *
     * @return string The success location.
     */
    public function successLocation()
    {
        return $this->success_location;
    }

    /**
     * Retrieves the location of the error.
     *
     * @return string The error location.
     */
    public function errorLocation()
    {
        return $this->error_location;
    }
}
