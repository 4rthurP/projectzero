<?php

namespace pz;

use Dotenv\Util\Str;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;

class Log {
    private static ?Logger $logger = null;

    private static function getLogger(): Logger {
        if (self::$logger === null) {
            self::$logger = new Logger('pz');
            $logFilePath = __DIR__ . '/../../logs/pz.log';
            self::$logger->pushHandler(new StreamHandler($logFilePath, Level::Debug));
            self::$logger->pushHandler(new StreamHandler('php://stdout', Config::log_level()));
        }
        return self::$logger;
    }

    public static function log(string $level, string $message): void {
        self::getLogger()->log($level, $message);
    }

    public static function debug(string $message): void {
        self::getLogger()->debug($message);
    }

    public static function info(string $message): void {
        self::getLogger()->info($message);
    }

    public static function notice(string $message): void {
        self::getLogger()->notice($message);
    }

    public static function warning(string $message): void {
        self::getLogger()->warning($message);
    }

    public static function error(string $message): void {
        self::getLogger()->error($message);
    }

    public static function critical(string $message): void {
        self::getLogger()->critical($message);
    }
    
    public static function alert(string $message): void {
        self::getLogger()->alert($message);
    }

    public static function emergency(string $message): void {
        self::getLogger()->emergency($message);
    }

}