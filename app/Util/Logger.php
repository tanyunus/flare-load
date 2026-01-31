<?php

namespace FlarePress\Util;

use DateTime;

class Logger
{
    const LOG_FILE = 'flare-press.log';

    public static function log(int $level, string $message, array $context = []): void {
        $dateTime = '[' . wp_date('Y-m-d H:i:s P') . '] ';
        $message = self::getLevel($level) .': '. $message;

        $updateResult = self::updateLogFile($dateTime . $message);

        if(!$updateResult) {
            error_log('Unable to update log file.');
        }
    }

    private static function updateLogFile(string $data): bool {
        $filePath = self::getLogFilePath();

        return boolval(file_put_contents($filePath, $data . PHP_EOL, FILE_APPEND | LOCK_EX));
    }

    private static function createLogFile(): void {

    }

    public static function getLogFile(): string {
        return file_get_contents(self::getLogFilePath());
    }

    private static function getLogFilePath(): string {
        return WP_PLUGIN_DIR . '/flare-press/' . self::LOG_FILE;
    }

    private static function getLevel(int $level): string {
        $levels = [
            0 => "Error",
            1 => "Warning",
            2 => "Notice",
            3 => "Info",
            4 => "Debug",
        ];

        return $levels[$level];
    }
}