<?php

namespace pz\Routing;

use pz\Enums\Routing\Method;
use pz\Auth;
use pz\Log;
use pz\Routing\DataHandler;

class Request
{
    use DataHandler;
    
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
