<?php

namespace pz;

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
        ?string $succcess_message = null,
        ?array $data = null,
    ): Response {
        if ($this->hasError()) {
            return new Response(false, $this->error_code, $this->error_message);
        }
        return new Response(true, ResponseCode::Ok, $succcess_message ?? 'success', null, $data);
    }
}