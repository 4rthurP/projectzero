<?php

namespace pz\Controllers;

use DateTime;

use pz\Config;
use pz\ModelController;
use pz\Services\JobService;
use pz\Enums\Routing\ModelEndpoint;
use pz\Enums\Routing\ResponseCode;
use pz\Routing\Request;
use pz\Routing\Response;

/**
 * Deliberately narrow: only the inherited `get` action is exposed as a route. A job row's
 * handler_controller/handler_method fields determine what code the next cron tick executes, so
 * creation must stay module-specific and server-side (each consuming module writes its own narrow
 * trigger action) rather than going through a generic, client-reachable `create`/`update`/`set`.
 * `cancel` is the one exception: unlike creation, stopping a job is a framework-level concept
 * every ad hoc job shares regardless of what it does, so it's handwritten here once instead of
 * every module reimplementing it.
 */
class JobController extends ModelController {
    static protected ?array $api_endpoints = [
        ModelEndpoint::GET,
    ];

    public function __construct() {
        parent::__construct();
        $this->setService(JobService::class);
    }

    /**
     * Only a pending/running job can be cancelled - one that already finished (completed, failed,
     * or already cancelled) has nothing left to stop. Doesn't touch locked_at: pz\Scheduler's
     * handler contract is one unit of work per call, so a call already in flight right now can't
     * be interrupted regardless (see Scheduler::runPendingJobs()'s own doc comment) - it notices
     * the 'cancelled' status itself once that unit returns, at the same between-units point it
     * already checks the wall clock at.
     */
    public function cancel(Request $request): Response {
        $job = $this->loadModel($request, 'edit');
        if ($job === null) {
            return $this->model_service->makeResponse();
        }

        if (!in_array($job->get('status'), ['pending', 'running'], true)) {
            return new Response(false, ResponseCode::BadRequestContent, 'job-not-cancellable');
        }

        $job->set('status', 'cancelled', true);
        $job->set('finished_at', (new DateTime('now', Config::tz()))->format('Y-m-d H:i:s'), true);

        return new Response(true, ResponseCode::Ok, 'job-cancelled');
    }
}
