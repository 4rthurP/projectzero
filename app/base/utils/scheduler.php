<?php

namespace pz;

use DateTime;
use DateTimeZone;

use pz\Config;
use pz\database\Database;
use pz\database\Query;
class Scheduler {
    private array $tasks_list = [];
    private bool $is_strict;

    public function __construct(bool $is_strict = false) {
        $this->tasks_list = [];
        $this->is_strict = $is_strict;
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
     * @param string $controller The controller name.
     * @param string $method The method name.
     * @return mixed The last task run, or null if not found.
     */
    private function getLastTaskRun(string $controller, string $method): ?array {
        $last_run = Query::from('task_runs')
                         ->where('controller', $controller)
                         ->where('method', $method)
                         ->order('run_time', false)
                         ->first();
        return $last_run;
    }

    /**
     * Saves the task run information to the database.
     *
     * @param string $controller The controller name.
     * @param string $method The method name.
     * @param bool $task_successful Indicates whether the task was successful or not.
     * @return int Returns the new line's id.
     */
    private function saveTaskRun(string $controller, string $method, bool $task_successful) {
        $current_datime = new DateTime("now", Config::tz());

        return Database::execute('INSERT INTO task_runs (controller, method, run_time, success) VALUES (?, ?, ?, ?)', 'ssss', $controller, $method, $current_datime->format('Y-m-d H:i:s'), $task_successful);
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
     * Returns the current date and time.
     *
     * @return DateTime The current date and time.
     */
    private function getCurrentDateTime() {
        return new DateTime("now", Config::tz());
    }

}