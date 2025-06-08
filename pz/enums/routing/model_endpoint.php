<?php

namespace pz\Enums\Routing;

use pz\Enums\Routing\Method;

enum ModelEndpoint: string {
    case LIST = 'list';
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case GET = 'get';
    case SET = 'set';

    public function getMethod() {
        switch($this) {
            case ModelEndpoint::LIST:
                return Method::GET;
            case ModelEndpoint::CREATE:
                return Method::POST;
            case ModelEndpoint::UPDATE:
                return Method::POST;
            case ModelEndpoint::DELETE:
                return Method::POST;
            case ModelEndpoint::GET:
                return Method::GET;
            case ModelEndpoint::SET:
                return Method::POST;
        }
    }
}

?>