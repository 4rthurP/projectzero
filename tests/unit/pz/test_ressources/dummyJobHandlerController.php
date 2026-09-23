<?php

namespace pz\Test\Ressources;

use pz\Scheduler;
use pz\Models\Job;
use pz\database\Database;

/**
 * Ad hoc job handler fixture for Scheduler tests. Each method follows the handler contract:
 * process exactly one unit of work per call, mutate the job via set(), and return whether more
 * work remains.
 */
class DummyJobHandlerController
{
    public static int $call_count = 0;

    /**
     * When set, process_forever() pushes this scheduler's overridden clock to $advance_to after
     * running - lets a test simulate a handler call eating into the tick's time budget without a
     * real sleep(), by making the *next* budget check (before a second handler call) see time as
     * having moved on.
     */
    public static ?Scheduler $clock_to_advance = null;
    public static ?\DateTime $advance_to = null;

    /**
     * When true, process_forever() writes 'cancelled' straight to the row (bypassing $job's own
     * in-memory state entirely) right after its first call - simulating a JobController::cancel()
     * request landing, from a separate process, in between two of this same job's units.
     */
    public static bool $cancel_after_first_call = false;

    /**
     * Processes one unit, advancing `processed` by one, until it reaches `total`.
     */
    public function process_one_unit(Job $job): bool
    {
        self::$call_count++;

        $processed = (int) $job->get('processed') + 1;
        $job->set('processed', $processed, true);

        return $processed < (int) $job->get('total');
    }

    /**
     * Always claims there is more work left, so the caller can run it up against a time budget
     * without the job ever completing on its own.
     */
    public function process_forever(Job $job): bool
    {
        self::$call_count++;

        $job->set('processed', (int) $job->get('processed') + 1, true);

        if (self::$clock_to_advance !== null && self::$advance_to !== null) {
            self::$clock_to_advance->setCurrentTime(self::$advance_to);
        }

        if (self::$cancel_after_first_call && self::$call_count === 1) {
            Database::execute("UPDATE jobs SET status = 'cancelled' WHERE id = ?", 'i', $job->getId());
        }

        return true;
    }

    /**
     * Always throws, to exercise the failure/retry policy.
     */
    public function process_always_throws(Job $job): bool
    {
        self::$call_count++;

        throw new \RuntimeException('DummyJobHandlerController: simulated handler failure');
    }
}
