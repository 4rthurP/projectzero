<?php
require_once 'dependencies.php';

use pz\Scheduler;

// Its own, independent cron entry (see Dockerfile's JOB_CRON_SCHEDULE) — deliberately separate
// from schedule.php's recurring-task cron so ad hoc job responsiveness is never held hostage to
// whatever interval an app configures for its recurring tasks. This is only the fallback path
// anyway: creating an ad hoc job already triggers immediate, in-process processing on its own
// (see pz\Models\Job::create()) — this just picks up whatever a single request's own time budget
// didn't finish, or a job whose triggering request never reached that point.
$scheduler = new Scheduler();
$scheduler->runJobs();
?>
