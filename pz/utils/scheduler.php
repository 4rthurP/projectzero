<?php

namespace pz;

use DateTime;
use DateTimeZone;

use pz\Config;
use pz\database\Database;
use pz\database\Query;
use pz\Enums\database\QueryOperator;
use pz\Enums\database\QueryLink;
use pz\Models\Job;

class Scheduler {
    // How long a claimed job's lock is honored with no owning process checking in before another
    // tick assumes it died abnormally (e.g. OOM-killed) and reclaims the row. A clean exit always
    // releases the lock itself, so the normal case never waits this out.
    private const STALE_LOCK_MINUTES = 2;

    private array $tasks_list = [];
    private bool $is_strict;
    private int $job_time_budget_seconds;
    private ?DateTime $current_time_override = null;

    public function __construct(bool $is_strict = false, int $job_time_budget_seconds = 45) {
        $this->tasks_list = [];
        $this->is_strict = $is_strict;
        $this->job_time_budget_seconds = $job_time_budget_seconds;
    }

    /**
     * Overrides the tick time budget used by runPendingJobs() (default: 45s, set in the
     * constructor). Exposed mainly so tests can exercise the per-tick cutoff without waiting out a
     * real budget.
     */
    public function setJobTimeBudget(int $seconds): static {
        $this->job_time_budget_seconds = $seconds;
        return $this;
    }

    /**
     * Freezes "now" to a fixed DateTime for every subsequent getCurrentDateTime() call, instead of
     * the real wall clock. Pass null to go back to the real clock. Exposed so tests can simulate a
     * tick's elapsed time (budget cutoff, stale lock reclaim) deterministically, by advancing this
     * between assertions instead of sleeping.
     */
    public function setCurrentTime(?DateTime $time): static {
        $this->current_time_override = $time;
        return $this;
    }

    public function addTask($task_controller, $task_method, $task_minute = '*', $task_hour = '*', $task_day = '*', $task_month = '*', $task_weekday = '*') {
        if(!class_exists($task_controller)) {
            throw new \Exception('Controller ' . $task_controller . ' does not exist');
        }

        if(!method_exists($task_controller, $task_method)) {
            throw new \Exception('Method ' . $task_method . ' does not exist in controller ' . $task_controller);
        }

        $this->tasks_list[] = [
            'controller' => $task_controller,
            'method' => $task_method,
            'scheduler' => [
                'minute' => $task_minute,
                'hour' => $task_hour,
                'day' => $task_day,
                'month' => $task_month,
                'weekday' => $task_weekday
            ]
        ];
    }

    public function runScheduler(): void {
        if(!$this->isValidToken(getenv('CRON_TOKEN'))) {
            throw new \Exception('Invalid token');
            return;
        }

        # Because long running tasks could shift the current minutes and hours compared to the time the cron was started, we collect the current time at the start of the cron and pass it to the taskIsDue method.
        $cron_start_time = $this->getCurrentDateTime();
        echo $cron_start_time->format('Y-m-d H:i:s')  . ': Running scheduler' . PHP_EOL;

        foreach($this->tasks_list as $task) {
            if($this->taskIsDue($task, $cron_start_time)) {
                # Appears in the logs
                echo $this->getCurrentDateTime()->format('Y-m-d H:i:s') . ' - task: ' . $task['controller'] . ' ' . $task['method'] . ' ' . $task['scheduler']['minute'] . ' ' . $task['scheduler']['hour'] . ' ' . $task['scheduler']['day'] . ' ' . $task['scheduler']['month'] . ' ' . $task['scheduler']['weekday']. PHP_EOL;

                $controller = new $task['controller']();
                $task_response = $controller->{$task['method']}();
                $this->saveTaskRun($task['controller'], $task['method'], $task_response->isSuccessful());
            }
        }

        // Runs after the recurring-task pass, in the same tick, sharing its time budget: a slow ad
        // hoc job can't starve recurring tasks (it only runs once that pass is done), and recurring
        // tasks keep running exactly as fast as they do today.
        $this->runPendingJobs($cron_start_time);
    }


    ############################
    # Helper methods
    ############################

    /**
     * Checks if a task is due based on its scheduler configuration.
     *
     * @param array $task The task to check.
     * @param DateTime $time The time the cron was started at.
     * @return bool Returns true if the task is due, false otherwise.
     */
    private function taskIsDue(array $task, DateTime $time): bool {
        # Generate the schedule which is an array of arrays containing the list of minutes, hours, days, months and weekdays the task should run.
        $task_schedule = $this->parseSchedule($task['scheduler']);
        
        # If the task is due right now, return true.
        if($this->taskIsNow($task_schedule, $time)) {
            return true;
        }
        
        # If the task is strict, and is not due now, return false.
        if($this->is_strict) {
            return false;
        }
        
        # Get the last time the task was run to compare with the schedule.
        $last_task_run = $this->getLastTaskRun($task['controller'], $task['method']);
        
        # If the task has never been run, and we are not in strict mode, return true.
        if($last_task_run === null) {
            return true;
        }
        
        # If the task was due based on the schedule and the last time it was run, return true.
        if($this->taskWasDue($task_schedule, $last_task_run, $time)) {
            return true;
        }
        
        # If the task was not due in the interval between the last time it was run and now, return false.
        return false;
    }

    /**
     * Parses the schedule array from string containing cron like schedules and returns an array with the list of minutes, hours, days, months and weekdays the task should run.
     *
     * @param array $schedule The schedule array to be parsed.
     * @return array The parsed schedule array with the following keys:
     *   - 'minute': The parsed minute schedule part.
     *   - 'hour': The parsed hour schedule part.
     *   - 'day': The parsed day schedule part.
     *   - 'month': The parsed month schedule part.
     *   - 'weekday': The parsed weekday schedule part.
     */
    private function parseSchedule(array $schedule): array {
        return [
            'minute' => $this->parseSchedulePart($schedule['minute'], 0, 59),
            'hour' => $this->parseSchedulePart($schedule['hour'], 0, 23),
            'day' => $this->parseSchedulePart($schedule['day'], 1, 31),
            'month' => $this->parseSchedulePart($schedule['month'], 1, 12),
            'weekday' => $this->parseSchedulePart($schedule['weekday'], 0, 6)
        ];
    }

    /**
     * Parses a schedule cron like string and returns a list of allowed scheduled time based on the min and max time given.
     *
     * @param string $part The schedule part to parse.
     * @param int $min The minimum value for the range..
     * @param int $max The maximum value for the range
     * @return array The array of parsed parts.
     */
    private function parseSchedulePart(String $part, int $min, int $max): array {
        # If the part is '*', return the whole range of allowed time from min to max.
        if($part === '*') {
            return range($min, $max);
        }

        # Split the part by ',' to get the different parts of the schedule.
        $parts = explode(',', $part);
        $parsed_parts = [];
        foreach($parts as $part) {
            if(strpos($part, '/') !== false) {
                # If the part contains a '/', parse the step part.
                $parsed_parts = array_merge($parsed_parts, $this->parseStep($part, $min, $max));
            } else if(strpos($part, '-') !== false) {
                # If the part contains a '-', parse the range part.
                $parsed_parts = array_merge($parsed_parts, $this->parseRange($part));
            } else {
                # If the part is a single value, parse it as an integer and add it to the list of parsed parts.
                $parsed_parts[] = (int)$part;
            }
        }

        return $parsed_parts;
    }

    /**
     * Parses a step value (ie. cron '/') and returns a range of numbers.
     *
     * @param string $part The step value to parse.
     * @param int $min The minimum value of the range.
     * @param int $max The maximum value of the range.
     * @return array The range of numbers based on the step value.
     */
    private function parseStep(String $part, int $min, int $max): array {
        $step_parts = explode('/', $part);
        $step = (int)$step_parts[1];
        $start = $min;
        if(strpos($step_parts[0], '-') !== false) {
            $range_parts = explode('-', $step_parts[0]);
            $start = (int)$range_parts[0];
        }

        return range($start, $max, $step);
    }

    /**
     * Parses a range value (ie. cron '-') and returns a range of numbers.
     *
     * @param string $part The range part to parse, in the format "start-end".
     * @return array An array of values within the specified range.
     */
    private function parseRange(String $part): array {
        $range_parts = explode('-', $part);
        return range((int)$range_parts[0], (int)$range_parts[1]);
    }

    /**
     * Checks if a task is scheduled to run at the given time.
     *
     * @param array $schedule The schedule configuration for the task.
     * @param DateTime $time The time to check against the schedule.
     * @return bool Returns true if the task is scheduled to run at the given time, false otherwise.
     */
    private function taskIsNow(Array $schedule, Datetime $time): bool {
        $now = getdate(strtotime($time->format('Y-m-d H:i:s')));

        return in_array($now['minutes'], $schedule['minute']) &&
               in_array($now['hours'], $schedule['hour']) &&
               in_array($now['mday'], $schedule['day']) &&
               in_array($now['mon'], $schedule['month']) &&
               in_array($now['wday'], $schedule['weekday']);
    }

    /**
     * Checks if a task was due between two times based on the schedule configuration.
     *
     * @param array $schedule The schedule for the task.
     * @param array $last_run The last run time of the task.
     * @param DateTime $time The current time.
     * @return bool Returns true if the task is due, false otherwise.
     */
    private function taskWasDue(array $schedule, array $last_run, DateTime $time): bool {
        $last_run_date = getdate(strtotime($last_run['run_time']));
        $current_date = getdate(strtotime($time->format('Y-m-d H:i:s')));

        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $last_run_date['mon'], $last_run_date['year']);

        $minutes_diff = range(0, 59);
        $hours_diff = range(0, 23);
        $days_diff = range(1, $days_in_month);
        $weekdays_diff = range(0, 6);
        $months_diff = $this->makeDiffRanges($last_run_date['mon'], $current_date['mon'], 12);

        # The last condition is here because if we have the same day in two different months we now for sure we can keep the whole range of values of days, hours and minutes
        if($current_date['mon'] == $last_run_date['mon'] || ($current_date['mon'] - $last_run_date['mon'] <= 1 && $current_date['mday'] != $last_run_date['mday'])) {
            $days_diff = $this->makeDiffRanges($last_run_date['mday'], $current_date['mday'], $days_in_month);
            $weekdays_diff = $this->makeDiffRanges($last_run_date['wday'], $current_date['wday'], 6);

            if($current_date['mday'] == $last_run_date['mday'] || ($current_date['mday'] - $last_run_date['mday'] <= 1 && $current_date['hours'] != $last_run_date['hours'])) {
                $hours_diff = $this->makeDiffRanges($last_run_date['hours'], $current_date['hours'], 23);

                if($current_date['hours'] == $last_run_date['hours'] || ($current_date['hours'] - $last_run_date['hours'] <= 1 && $current_date['minutes'] != $last_run_date['minutes'])) {
                    $minutes_diff = $this->makeDiffRanges($last_run_date['minutes'], $current_date['minutes'], 59);
                }
            }
        } 

        return count(array_intersect($minutes_diff, $schedule['minute'])) > 0 &&
               count(array_intersect($hours_diff, $schedule['hour'])) > 0 &&
               count(array_intersect($days_diff, $schedule['day'])) > 0 &&
               count(array_intersect($months_diff, $schedule['month'])) > 0 &&
               count(array_intersect($weekdays_diff, $schedule['weekday'])) > 0;
    }

    /**
     * Retrieves the last run of a specific task in the scheduler.
     *
     * Reads from the shared `jobs` table (kind = 'scheduled_task'), which replaced the old
     * task_runs table so both scheduled and ad hoc work share one tracking entity. `created_at` is
     * aliased to `run_time` so taskWasDue(), which is otherwise untouched, keeps working unchanged.
     *
     * @param string $controller The controller name.
     * @param string $method The method name.
     * @return mixed The last task run, or null if not found.
     */
    private function getLastTaskRun(string $controller, string $method): ?array {
        $last_run = Query::from('jobs')
                         ->get('created_at AS run_time')
                         ->where('kind', 'scheduled_task')
                         ->where('handler_controller', $controller)
                         ->where('handler_method', $method)
                         ->order('run_time', false)
                         ->first();
        return $last_run;
    }

    /**
     * Saves the task run information to the database.
     *
     * Writes a `jobs` row (kind = 'scheduled_task') instead of a task_runs row: it's created
     * already completed/failed with processed = total = 1, mirroring exactly what task_runs used
     * to record for a synchronous, no-argument recurring task run.
     *
     * user_id is required on every Job (per-owner privacy for ad hoc jobs relies on it), but a
     * scheduled_task row has no real owner - it's seeded with a fixed sentinel account
     * (SYSTEM_USER_ID config, default 0). These rows are never read through the user-facing
     * JobController::get() action, so the sentinel is inert.
     *
     * @param string $controller The controller name.
     * @param string $method The method name.
     * @param bool $task_successful Indicates whether the task was successful or not.
     * @return int|string|null Returns the new row's id.
     */
    private function saveTaskRun(string $controller, string $method, bool $task_successful) {
        $now = $this->getCurrentDateTime()->format('Y-m-d H:i:s');

        $job = new Job();
        $job->create([
            'user_id' => Config::get('SYSTEM_USER_ID'),
            'kind' => 'scheduled_task',
            'type' => $controller . '::' . $method,
            'handler_controller' => $controller,
            'handler_method' => $method,
            'status' => $task_successful ? 'completed' : 'failed',
            'total' => 1,
            'processed' => 1,
            'started_at' => $now,
            'finished_at' => $now,
        ]);

        if (!$job->isValid()) {
            throw new \Exception('Failed to save the scheduled task run as a job: ' . json_encode($job->getFormMessages()));
        }

        return $job->getId();
    }

    /**
     * Generates an array of numbers within a given range, accounting for cases where the range wraps around.
     *
     * @param int $range_start The starting value of the range.
     * @param int $range_end The ending value of the range.
     * @param int $range_max The maximum value of the range.
     * @return array The array of numbers within the specified range.
     */
    private function makeDiffRanges(int $range_start, int $range_end, int $range_max): array {
        if($range_start <= $range_end) {
            return range($range_start, $range_end);
        }

        $range1 = range($range_start, $range_max);
        $range2 = range(0, $range_end);
        return array_merge($range1, $range2);
    }

    /**
     * Checks if the provided token is valid.
     *
     * @param string $token The token to be validated.
     * @return bool Returns true if the token is valid, false otherwise.
     */
    private function isValidToken(string $token): bool {
        $valid_token = $_ENV['SCHEDULER_TOKEN'] ?? '';
        return $token === $valid_token;
    }

    /**
     * Returns the current date and time, or the fixed time set via setCurrentTime() if one is set.
     *
     * @return DateTime The current date and time.
     */
    private function getCurrentDateTime() {
        return $this->current_time_override ?? new DateTime("now", Config::tz());
    }

    ############################
    # Ad hoc job processing
    ############################

    /**
     * Runs ad hoc jobs (kind = 'ad_hoc_job') for whatever time budget is left in this tick, after
     * the recurring-task pass above. The handler contract is one unit of work per call (one book,
     * one row - whatever the smallest meaningful chunk is for that job type); this method owns the
     * loop and checks the wall clock before every call it makes, so a handler can never decide on
     * its own to run long. This bounds the runner's own worst-case overrun to roughly one unit's
     * own worst-case duration past the budget, not an open-ended "however long the handler felt
     * like running".
     *
     * @param DateTime $tick_start The time this tick started, captured once in runScheduler().
     * @return void
     */
    private function runPendingJobs(DateTime $tick_start): void {
        while ($this->secondsSince($tick_start) < $this->job_time_budget_seconds) {
            $job = $this->claimNextJob();
            if ($job === null) {
                return; // nothing eligible left this tick
            }

            while ($this->secondsSince($tick_start) < $this->job_time_budget_seconds) {
                try {
                    $controller = new ($job->get('handler_controller'))();
                    $method = $job->get('handler_method');
                    $more_work_remains = $controller->$method($job); // handler: ONE unit, mutates $job via set(), returns bool
                } catch (\Throwable $exception) {
                    // Stop this tick's job processing entirely rather than continue 2 (claim
                    // another job): a retryable failure puts the job straight back to 'pending'
                    // with no lock, so continuing here could immediately re-claim and re-fail it
                    // in a tight loop within the same tick, burning all 3 attempts in an instant.
                    // "No backoff curve - the tick granularity already spaces retries out" only
                    // holds if a retry actually waits for the next real tick, which this ensures.
                    $this->handleJobFailure($job, $exception);
                    return;
                }

                if (!$more_work_remains) {
                    $job->set('status', 'completed', true);
                    $job->set('finished_at', $this->getCurrentDateTime()->format('Y-m-d H:i:s'), true);
                    $job->set('locked_at', null, true); // a clean exit always releases the lock
                    continue 2; // budget may remain - go claim another job
                }
                $job->set('locked_at', $this->getCurrentDateTime()->format('Y-m-d H:i:s'), true); // refresh the lock
            }

            // Budget exhausted mid-job (not done): release the lock cleanly so the very next tick
            // can resume immediately, instead of waiting out the staleness window.
            $job->set('locked_at', null, true);
            return;
        }
    }

    /**
     * Applies the bounded, unsophisticated failure policy for an ad hoc job's handler call: no
     * backoff curve (the minute-level tick granularity already spaces retries out), and no
     * automatic retry once a job is permanently failed - a manual re-trigger (the module's own
     * start() action again, creating a fresh row) is the way to retry at this scale.
     *
     * @param Job $job The job whose handler call just threw.
     * @param \Throwable $exception The exception raised by the handler call.
     * @return void
     */
    private function handleJobFailure(Job $job, \Throwable $exception): void {
        $attempts = (int) $job->get('attempts') + 1;
        $job->set('attempts', $attempts, true);
        $job->set('error_message', $exception->getMessage(), true);

        if ($attempts < 3) {
            // Release the lock and make it eligible again so a later tick retries it.
            $job->set('status', 'pending', true);
            $job->set('locked_at', null, true);
            return;
        }

        $job->set('status', 'failed', true);
        $job->set('finished_at', $this->getCurrentDateTime()->format('Y-m-d H:i:s'), true);
        $job->set('locked_at', null, true);
    }

    /**
     * Atomically claims the next eligible ad hoc job, so two overlapping cron ticks (e.g. tick N's
     * handler call still running past a minute while tick N+1 starts) can't both grab the same row.
     * Model::update() only ever builds `UPDATE table SET ... WHERE id = ?`, with no way to add the
     * extra `AND status = ... AND locked_at ...` condition claiming needs, so this one step is a raw
     * conditional UPDATE via Database::execute(), checked for affected-row count - the one
     * deliberate exception to "always go through the Job model" in this class.
     *
     * A job is eligible if it's pending/running and either never locked or locked long enough ago
     * (STALE_LOCK_MINUTES) to assume its owning process died abnormally (e.g. OOM-killed) without
     * releasing its lock. A clean exit (budget exhausted, or job completed/failed) always releases
     * the lock itself, so the normal case never waits out that window.
     *
     * @return Job|null The claimed job, or null if there was nothing eligible or another tick won
     *                   the race for the candidate row.
     */
    private function claimNextJob(): ?Job {
        $stale_threshold = (clone $this->getCurrentDateTime())
            ->modify('-' . self::STALE_LOCK_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');

        // Passing a bare `null` (rather than QueryOperator::IS_NULL) for the "locked_at IS NULL"
        // half of this group is intentional, not a style choice: WhereGroup::addQuery() forwards a
        // 2-element ['column', $operator] array's group link into WhereClause's *value* slot when
        // $operator is itself a QueryOperator instance, so ['locked_at', QueryOperator::IS_NULL]
        // ends up trying to use the QueryLink as the compared value and throws a TypeError.
        // ['locked_at', null] avoids the bug: WhereClause already renders a plain null value as
        // "IS NULL" via its EQUALS-with-null shortcut, taking the same code path where the group
        // link is threaded through correctly.
        $candidate = Query::from('jobs')
            ->where('kind', 'ad_hoc_job')
            ->whereIn('status', ['pending', 'running'])
            ->whereGroup([
                ['locked_at', null],
                ['locked_at', QueryOperator::LESS_THAN, $stale_threshold],
            ], QueryLink::OR)
            ->order('created_at')
            ->first();

        if ($candidate === null) {
            return null;
        }

        $now = $this->getCurrentDateTime()->format('Y-m-d H:i:s');
        $claimed = Database::execute(
            "UPDATE jobs SET status = 'running', locked_at = ?, started_at = COALESCE(started_at, ?)
             WHERE id = ? AND status IN ('pending','running')
               AND (locked_at IS NULL OR locked_at < ?)",
            'ssis',
            $now,
            $now,
            $candidate['id'],
            $stale_threshold,
        );

        if ($claimed !== 1) {
            return null; // lost the race to another tick
        }

        // Hydrated from the pre-claim SELECT above rather than Job::find(): Job keeps the standard
        // PROTECTED privacy (per-owner access for JobController::get()), and Model::find() always
        // routes through startQuery(), which requires a logged-in $_SESSION['user']['id'] for a
        // PROTECTED model - there is none in a cron run, so Job::find() would throw here.
        // loadFromArray() skips that privacy-scoped SELECT entirely, which is safe: none of the
        // columns the UPDATE just touched (status/locked_at/started_at) are read back from this
        // object anywhere below, only written.
        $job = new Job();
        $job->loadFromArray($candidate);
        return $job;
    }

    /**
     * @param DateTime $start
     * @return int Whole seconds elapsed between $start and the current time.
     */
    private function secondsSince(DateTime $start): int {
        return $this->getCurrentDateTime()->getTimestamp() - $start->getTimestamp();
    }

}