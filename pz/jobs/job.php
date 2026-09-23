<?php

namespace pz\Models;

use pz\Log;
use pz\Model;
use pz\Scheduler;
use pz\Enums\model\AttributeType;

class Job extends Model {
    public static $name = 'job';

    protected function model() {
        $this->attribute('kind', AttributeType::CHAR, true);             // 'scheduled_task' | 'ad_hoc_job'
        $this->attribute('type', AttributeType::CHAR, true);             // e.g. 'marge.isbn_sync', 'cellr.daily_stats'
        $this->attribute('handler_controller', AttributeType::CHAR, true);
        $this->attribute('handler_method', AttributeType::CHAR, true);
        $this->attribute('status', AttributeType::CHAR, true, 'pending'); // pending | running | completed | failed
        $this->attribute('payload', AttributeType::TEXT);                // handler's own state; json_encode/decode by hand
        $this->attribute('total', AttributeType::INT);                   // nullable: not always known up front
        $this->attribute('processed', AttributeType::INT, true, '0');
        $this->attribute('message', AttributeType::CHAR);                // human progress text, e.g. "Book 2/12: The Hobbit"
        $this->attribute('error_message', AttributeType::TEXT);
        $this->attribute('attempts', AttributeType::INT, true, '0');
        $this->attribute('started_at', AttributeType::DATETIME);
        $this->attribute('locked_at', AttributeType::DATETIME);
        $this->attribute('finished_at', AttributeType::DATETIME);
    }

    /**
     * An ad hoc job (unlike a scheduled_task row, which is written already-completed — see
     * pz\Scheduler::saveTaskRun()) needs *something* to actually process it. Waiting for the next
     * cron tick means a real, visible delay (up to a whole tick interval, which some apps
     * configure to whatever suits their recurring tasks — a day, in one case that turned out to be
     * catastrophic for a live-progress-bar mass-sync feature) before anything even starts, which
     * defeats the point of a UI built around watching it happen. So creating one triggers
     * processing immediately, in-process, right after this request's own response has already been
     * sent — the cron-driven Scheduler::runJobs() then only matters as a fallback, for whatever a
     * single request's own time budget didn't finish.
     */
    public function create(null|array $attributes_array = null): null|static {
        $result = parent::create($attributes_array);

        if ($result->isValid() && $result->get('kind') === 'ad_hoc_job') {
            $result->triggerImmediateProcessing();
        }

        return $result;
    }

    /**
     * Only meaningful under php-fpm, serving a real web request: fastcgi_finish_request() sends
     * the response and closes the client connection, but this process keeps running afterward,
     * which is what lets drainPendingJobsNow() happen without the client waiting for it. A CLI
     * context (schedule.php/process_jobs.php, PHPUnit) has no request to finish early and no
     * client waiting on one — those already get their own timely invocation some other way
     * (their own cron entry, or not at all in tests), so this deliberately no-ops there instead of
     * draining jobs synchronously inside whatever CLI script happened to create one.
     */
    private function triggerImmediateProcessing(): void {
        if (PHP_SAPI !== 'fpm-fcgi') {
            return;
        }

        $job_id = (int) $this->getId();
        register_shutdown_function(function () use ($job_id) {
            fastcgi_finish_request();
            try {
                // Passing this job's own id so it gets claimed first (see
                // Scheduler::drainPendingJobsNow()'s doc comment): without it, an older backlog
                // job that keeps getting reclaimed tick after tick (e.g. one stuck retrying a
                // slow/rate-limited external API) would starve the job the current user is
                // actually watching, leaving it at 0 progress indefinitely.
                (new Scheduler())->drainPendingJobsNow($job_id);
            } catch (\Throwable $exception) {
                // The triggering request's response was already sent successfully at this point —
                // this is purely a missed opportunity to get a head start, not a user-visible
                // failure. The cron fallback (Scheduler::runJobs()) will still pick this job up.
                Log::error('Immediate ad hoc job processing failed: ' . $exception->getMessage());
            }
        });
    }
}
