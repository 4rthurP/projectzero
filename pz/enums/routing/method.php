<?php

namespace pz\Enums\Routing;

enum Method: string {
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case DELETE = 'DELETE';
    case MODEL = 'MODEL';
}

?>