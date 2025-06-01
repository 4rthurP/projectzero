<?php
require_once 'dependencies.php';

use pz\Scheduler;

$scheduler = new Scheduler();
$scheduler->addTask('pz\Controllers\CellrTasks', 'run_cellr_stats', '0', '0', '*', '*', '*');
$scheduler->runScheduler();
?>