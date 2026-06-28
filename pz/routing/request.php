<?php

namespace pz\Routing;

use Exception;
use pz\Auth;
use pz\Enums\Routing\Method;
use pz\Log;
use pz\Routing\DataHandler;

class Request
{
    use DataHandler;

    protected ?string $action;
    protected ?string $success_location;
    protected ?string $error_location;
    protected ?Method $method;

    public ?array $data;

    protected ?Auth $auth;

    public function __construct(?Method $method = null, ?array $data = null, ?string $action = null)
    {
        $this->method = $method;
        $this->action = $action;
        $this->data = $data ?? [];
        $this->success_location = null;
        $this->error_location = null;
        $this->auth = null;
    }

    // ####################################
    // User and Authentication
    // ####################################
    public function authenticateUser()
    {
        if ($this->auth == null) {
            return false;
        }

        $this->auth->authenticate();

        return $this->auth->isAuthenticated();
    }

    public function isLoggedIn()
    {
        if ($this->auth == null) {
            Log::error('Trying to check if user is logged in, but auth is not set for the request.');
            return false;
        }
        return $this->auth->isLoggedIn();
    }

    public function isAuthenticated()
    {
        if ($this->auth == null) {
            return false;
        }
        return $this->auth->isAuthenticated();
    }

    public function setAuth(Auth $auth)
    {
        if (!$auth->isLoggedIn()) {
            throw new Exception('Trying to set auth for request, but user is not logged in.');
        }

        $this->auth = $auth;
        $this->data['user_id'] = $auth->user_id;
        return $this;
    }

    public function getAuth(): ?Auth
    {
        if (!isset($this->auth)) {
            return null;
        }
        return $this->auth;
    }

    /**
     * Checks if a user is associated with the current request.
     *
     * @return bool Returns true if a user is set, false otherwise.
     */
    public function hasUser()
    {
        if (!isset($this->auth)) {
            return false;
        }

        if ($this->auth->getUser() != null) {
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
        if ($this->auth == null) {
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
    public function getFile($file_name): ?array
    {
        if (isset($_FILES[$file_name])) {
            return $_FILES[$file_name];
        }
        return null;
    }

    /**
     * Validates a given location string to ensure it is safe and does not lead to external addresses or contain potentially dangerous characters.
     *
     * @param string|null $location The location string to validate.
     * @return string|null Returns the validated location string if it is safe, or null if the input is null.
     * @throws Exception Throws an exception if the location is invalid, and logs out the user if authentication is set.
     */
    public function validateLocation(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }

        // Checks that the location does not lead to any external address and does not contain any potentially dangerous characters or patterns.
        if (
            !preg_match('/^(https?:\/\/|\/\/|javascript:|data:|file:)/i', $location) && !preg_match('/[<>]/', $location)
        ) {
            return $location;
        } else {
            if ($this->auth != null) {
                $this->auth->logout();
            }
            throw new Exception("Invalid location: $location");
        }
    }

    // ####################################
    // General getters and setters
    // ####################################

    /**
     * Retrieves the current action associated with the request.
     *
     * @return string|null The action name, or null if not set.
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
    public function setAction(string $action)
    {
        $action = $this->validateLocation($action);
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
    public function onSuccess(string $location)
    {
        $location = $this->validateLocation($location);
        $this->success_location = $location;
    }

    /**
     * Sets the error location for the request.
     *
     * @param string $location The location to redirect or handle errors.
     * @return void
     */
    public function onError(string $location)
    {
        $location = $this->validateLocation($location);
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
