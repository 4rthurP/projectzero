<?php 

namespace pz\Routing;

use pz\Enums\Routing\Method;

class Request {

    protected ?String $nonce;
    protected ?String $action;
    protected ?String $success_location;
    protected ?String $error_location;
    protected ?Method $method;
    public ?Array $data;

    public function __construct(?Method $method = null, ?Array $data = null, ?String $nonce = null, ?String $action = null) {
        $this->method = $method;
        $this->nonce = $nonce;
        $this->action = $action;
        $this->data = $data ?? [];
        $this->success_location = null;
        $this->error_location = null;
    }
    
    public function hasAuth() {
        if($this->hasUser()) {
            return $this->credential() !== null || $this->nonce() !== null;
        }
        return false;
    }
    
    public function hasUser() {
        return isset($_SESSION['user']);
    }
    
    public function user() {
        if(isset($_SESSION['user'])) {
            return $_SESSION['user']['id'];
        }
    }

    public function credential() {
        if(isset($_SESSION['user'])) {
            return $_SESSION['user']['credential'];
        }
    }

    public function nonce() {
        return $this->nonce;
    }

    public function setNonce(String $nonce) {
        $this->nonce = $nonce;
    }

    public function hasNonce() {
        return $this->nonce !== null;
    }

    public function data() {
        return $this->data;
    }

    public function setData(Array $data) {
        if(isset($data['nonce'])) {
            $this->nonce = $data['nonce'];
            unset($data['nonce']);
        }

        $this->data = array_merge($this->data, $data);
    }

    public function addData(String $key, $value) {
        $this->data[$key] = $value;
    }

    public function getData(String $key) {
        if(!isset($this->data[$key])) {
            return null;
        }
        return $this->data[$key];
    }

    public function hasData(String $key) {
        if(!isset($this->data[$key])) {
            return false;
        }
        return $this->data[$key] !== null;
    }

    public function hasOrSetData(String $key, $value) {
        if(!isset($this->data[$key])) {
            $this->data[$key] = $value;
            return true;
        }
        return false;
    }

    public function getAction() {
        return $this->action;
    }

    public function setAction(String $action) {
        $this->action = $action;
    }

    public function getMethod() {
        return $this->method;
    }

    public function setMethod(Method $method) {
        $this->method = $method;
    }

    public function onSuccess(String $location) {
        $this->success_location = $location;
    }

    public function onError(String $location) {
        $this->error_location = $location;
    }

    public function successLocation() {
        return $this->success_location;
    }

    public function errorLocation() {
        return $this->error_location;
    }    

}
?>