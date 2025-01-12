<?php

namespace pz\Routing;

use pz\Enums\Routing\{ResponseCode, Privacy, Method, ModelEndpoint};

class Response {
    public bool $success;
    protected ResponseCode $code;
    protected int $http_code;
    public ?String $answer;
    public ?String $message;
    protected ?Array $data = [];
    protected ?Array $form_data;
    protected ?String $header = null;
    protected ?String $redirect;
    protected ?String $nonce = null;
    protected ?String $nonce_expiration = null;
    
    public function __construct(bool $success, ResponseCode $code, ?Array $data = null, ?String $answer = null, ?String $redirect = null) {
        $this->success = $success;
        $this->code = $code;
        $this->http_code = $code->value;
        $this->data = $data;
        $this->answer = $answer;
        $this->redirect = $redirect;
        $this->message = null;
        $this->form_data = null;
    }

    public function isSuccessful() {
        return $this->success;
    }
    
    public function getResponseCode() {
        return $this->code;
    }

    public function hasRedirect() {
        return $this->redirect !== null;
    }

    public function setRedirect(String $redirect) {
        $this->redirect = $redirect;
    }

    public function getRedirect() {
        $params = '';
        if(!$this->success) {
            $params = '?error='.$this->code->toParam();
        } 
        else if ($this->message != '') {
            $params = '?success='.$this->message;
        }
        return 'Location: '.$this->redirect.$params;
    }

    public function getAnswer() {
        return $this->answer;
    }

    public function setAnswer(String $answer): self {
        $this->answer = $answer;
        return $this;
    }

    public function data(?String $key = null) {
        if($key !== null) {
            return $this->data[$key] ?? null;
        }

        return $this->data;
    }

    public function getFormData(?String $key = null) {
        if($this->form_data === null) {
            return null;
        }

        if($key !== null) {
            return $this->form_data[$key] ?? null;
        }

        return $this->form_data;
    }

    public function addFormData(String $element, Array $data): self {
        $this->form_data[$element] = $data;
        return $this;
    }

    public function setFormData(Array $form_data): self {
        $this->form_data = $form_data;
        return $this;
    }

    public function setNonce(?Array $nonce = null): self {
        $this->nonce = $nonce['nonce'] ?? null;
        $this->nonce_expiration = $nonce['nonce_expiration'] ?? null;
        return $this;
    }

    public function toArray() {
        return [
            'success' => $this->success,
            'code' => $this->code->value,
            'answer' => $this->answer,
            'message' => $this->message,
            'data' => $this->data,
            'form_data' => $this->form_data,
            'header' => $this->header,
            'redirect' => $this->redirect,
            'nonce' => $this->nonce,
            'nonce_expiration' => $this->nonce_expiration
        ];
    }
}