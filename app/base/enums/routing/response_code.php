<?php

namespace pz\Enums\Routing;

use pz\Routing\Response;

enum ResponseCode: int {
    case InvalidRequestMethod = 400;
    case Unauthorized = 401;
    case Forbidden = 403;
    case NotFound = 404;
    case MethodNotAllowed = 405;
    case BadRequestContent = 406;
    case InternalServerError = 500;
    case NotImplemented = 501;
    case ServiceUnavailable = 503;
    case GatewayTimeout = 504;
    case UnknownError = 520;
    case Ok = 200;
    case Redirect = 302;

    public function toParam(): string {
        return match($this) {
            ResponseCode::InvalidRequestMethod => 'invalid_request_method',
            ResponseCode::Unauthorized => 'unauthorized',
            ResponseCode::Forbidden => 'forbidden',
            ResponseCode::NotFound => 'not_found',
            ResponseCode::MethodNotAllowed => 'method_not_allowed',
            ResponseCode::InternalServerError => 'internal_server_error',
            ResponseCode::NotImplemented => 'not_implemented',
            ResponseCode::ServiceUnavailable => 'service_unavailable',
            ResponseCode::GatewayTimeout => 'gateway_timeout',
            ResponseCode::UnknownError => 'unknown_error',
            ResponseCode::BadRequestContent => 'bad_request_content',
            ResponseCode::Ok => 'ok',
            ResponseCode::Redirect => 'redirect',
        };
    }
}

?>