<?php

namespace FlarePress\Util;

defined('ABSPATH') || exit;

class Logger
{
    const LOG_FILE = 'flare-press.log';

    /** 0=Error 1=Warning 2=Notice 3=Info 4=Debug */
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

        if (!file_exists($filePath)) {
            self::createLogFile();
        }

        return boolval(file_put_contents($filePath, $data . PHP_EOL, FILE_APPEND | LOCK_EX));
    }

    private static function createLogFile(): void {
        $filePath = self::getLogFilePath();
        if (!file_exists($filePath)) {
            file_put_contents($filePath, '');
            chmod($filePath, 0644);
        }
    }

    public static function getLogFile(): string {
        $filePath = self::getLogFilePath();
        if (!file_exists($filePath)) {
            return '';
        }

        $maxBytes = 100 * 1024;
        $fileSize = filesize($filePath);

        if ($fileSize <= $maxBytes) {
            return file_get_contents($filePath) ?: '';
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return '';
        }

        fseek($handle, -$maxBytes, SEEK_END);
        $content = fread($handle, $maxBytes);
        fclose($handle);

        // Drop the partial first line caused by mid-line seek.
        $firstNewline = strpos($content, "\n");
        if ($firstNewline !== false) {
            $content = substr($content, $firstNewline + 1);
        }

        $skippedKb = round(($fileSize - $maxBytes) / 1024);
        return "[Showing last 100 KB — {$skippedKb} KB of older entries not shown]\n" . $content;
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
