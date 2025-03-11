<?php

namespace CalidadFECYT\classes\utils;

class LogUtils
{
    private static $logType = "CLI";

    public static function setLogType($logType) {
        if($logType == "CLI" || $logType == "WEB") {
            LogUtils::$logType = $logType;
        }
    }

    public static function log($message) {
        if(LogUtils::$logType == "CLI") {
            echo $message . "\n";
        } else {
            error_log($message);
        }
    }

    public static function error($message) {
        if(LogUtils::$logType == "CLI") {
            echo "ERROR: " . $message . "\n";
        } else {
            error_log($message);
        }
    }
}