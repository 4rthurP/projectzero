<?php

namespace pz;

use Exception;
use pz\Enums\Routing\ResponseCode;
use pz\Routing\Response;

class Service
{
    public ?string $error_message = null;
    public ?ResponseCode $error_code = null;

    protected function error(string $error_message, ResponseCode $error_code = ResponseCode::BadRequestContent): null
    {
        if ($this->error_message !== null || $this->error_code !== null) {
            return null;
        }

        $this->error_message = $error_message;
        $this->error_code = $error_code;

        if(Config::env() === 'DEV') {
            throw new Exception("Service error: $error_message", $error_code->value);
        }

        return null;
    }

    public function hasError(): bool
    {
        return $this->error_message !== null && $this->error_code !== null;
    }

    public function isSuccessful(): bool
    {
        return !$this->hasError();
    }

    public function makeResponse(
        ?string $success_message = null,
        ?array $data = null,
        ?array $error_data = null,
    ): Response {
        if ($this->hasError()) {
            return new Response(false, $this->error_code, $this->error_message, null, $error_data);
        }
        return new Response(true, ResponseCode::Ok, $success_message ?? 'success', null, $data);
    }

    public function resetState()
    {
        $this->error_message = null;
        $this->error_code = null;
    }
}