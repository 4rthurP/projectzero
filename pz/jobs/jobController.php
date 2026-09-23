<?php

namespace pz\Controllers;

use pz\ModelController;
use pz\Services\JobService;
use pz\Enums\Routing\ModelEndpoint;

/**
 * Deliberately narrow: only the inherited `get` action is exposed as a route. A job row's
 * handler_controller/handler_method fields determine what code the next cron tick executes, so
 * creation must stay module-specific and server-side (each consuming module writes its own narrow
 * trigger action) rather than going through a generic, client-reachable `create`/`update`/`set`.
 */
class JobController extends ModelController {
    static protected ?array $api_endpoints = [
        ModelEndpoint::GET,
    ];

    public function __construct() {
        parent::__construct();
        $this->setService(JobService::class);
    }
}
