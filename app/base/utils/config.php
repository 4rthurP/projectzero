<?php

namespace pz;

use DateTimeZone;
use Monolog\Level;
use Dotenv\Dotenv;
use Exception;

class Config {
    private static ?Config $instance = null;
    private array $config;

    private static array $pz_default_values = [
        'ENV' => 'PROD',
        'APP_PATH' => __DIR__ . '/../',
        'MODULES_PATH' => 'modules/',
        'LATTE_PATH' => 'app/latte/',
        'TZ' => 'UTC',
        'LOG_LEVEL' => 'INFO',
        'DB_HOST' => null,
        'DB_NAME' => null,
        'DB_USER' => null,
        'DB_PASSWORD' => null,
        'DB_PORT' => 3306,
        'DB_DRIVER' => 'mysql',
        'USER_SESSION_LIFETIME' => 600000,
        'USER_SESSION_RENEWAL' => 300000,
        'USER_SESSION_RENEWAL_ENABLED' => true,
        'USER_SESSION_RENEWAL_MAX' => 3,
        'USER_BAN_TIME' => 3600,
        'USER_ATTEMPS_THRESHOLD' => 5,
        'USER_RECENT_ATTEMPT_TIME' => 5,
    ];

    private function __construct() {
        $dotenv = Dotenv::createImmutable(__DIR__, '../../../.env');
        $dotenv->load();
        $this->config = $_ENV;
    }

    public static function getInstance(): Config {
        if (self::$instance === null) {
            self::$instance = new Config();
        }
        return self::$instance;
    }

    /**
     * Retrieves a configuration value by its key.
     *
     * This method fetches the value associated with the specified key
     * from the configuration. If the key does not exist, a default value
     * can be returned instead.
     *
     * @param string $key The configuration key to retrieve.
     * @param mixed $default The default value to return if the key does not exist. Defaults to null.
     * @return mixed The configuration value associated with the key, or the default value if the key is not found.
     */
    public static function get(string $key, mixed $default = 'PZ_DEFAULT_VALUE'): mixed {
        if(isset(self::getInstance()->config[$key])) {
            $value = self::getInstance()->config[$key];
        } else {
            $value = null;
        }

        // If the requested env var does not exist we check if we have a default value
        if($value === null)
        {
            // If the default value is 'PZ_DEFAULT_VALUE' we check if we have a default value in the static array
            if($default === 'PZ_DEFAULT_VALUE') {
                $value = self::$pz_default_values[$key] ?? null;
            } 
            // Otherwise, the user provided a default value that we use
            else {
                $value = $default;
            }
        }

        return $value;
    }

    #########################################################
    #       Utilities for common configuration values       #
    #########################################################

    /**
     * Retrieves the current environment setting.
     *
     * This method fetches the value of the 'ENV' configuration key. If the key
     * is not set, it defaults to 'PROD'.
     *
     * @return string|null The environment setting, or null if not available.
     */
    public static function env(): ?string {
        return self::get('ENV');
    }

    public static function app_path(): ?string {
        $app_path = self::get('APP_PATH');
        // Ensure the path ends with a slash
        if (substr($app_path, -1) !== '/') {
            $app_path .= '/';
        }
        
        return $app_path;
    }

    public static function modules_path(): string {
        $modules_path = self::get('MODULES_PATH', 'modules/');

        // Ensure the path ends with a slash
        if (substr($modules_path, -1) !== '/') {
            $modules_path .= '/';
        }

        return Config::app_path() . $modules_path;
    }

    public static function latte_path(): string {
        $latte_path = self::get('LATTE_PATH', 'app/latte/');

        // Ensure the path ends with a slash
        if (substr($latte_path, -1) !== '/') {
            $latte_path .= '/';
        }

        return Config::app_path() . $latte_path;
    }

    public static function tz(): ?DateTimeZone {
        $tz = self::get('TZ');

        if ($tz === null) {
            Log::error('Timezone not set in configuration.');
            return null;
        }
        if (!in_array($tz, timezone_identifiers_list())) {
            Log::error('Invalid timezone set in configuration.');
            throw new Exception('Invalid timezone set in configuration.');
        }

        return new DateTimeZone($tz);
    }

    public static function log_level(): ?Level {
        $level = self::get('LOG_LEVEL');
        $LOG_LEVELS = [
            'DEBUG' => Level::Debug,
            'INFO' => Level::Info,
            'NOTICE' => Level::Notice,
            'WARNING' => Level::Warning,
            'ERROR' => Level::Error,
            'CRITICAL' => Level::Critical,
            'ALERT' => Level::Alert,
            'EMERGENCY' => Level::Emergency
        ];

        return $LOG_LEVELS[$level] ?? Level::Info;
    }
}