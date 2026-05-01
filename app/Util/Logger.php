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
        }
    }

    public static function getLogFile(): string {
        $filePath = self::getLogFilePath();
        if (!file_exists($filePath)) {
            return '';
        }

        $maxBytes = 100 * 1024;
        $fileSize = filesize($filePath);
        $truncated = false;

        if ($fileSize <= $maxBytes) {
            $raw = file_get_contents($filePath) ?: '';
        } else {
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                return '';
            }

            fseek($handle, -$maxBytes, SEEK_END);
            $raw = fread($handle, $maxBytes);
            fclose($handle);

            // Drop the partial first line caused by mid-line seek.
            $firstNewline = strpos($raw, "\n");
            if ($firstNewline !== false) {
                $raw = substr($raw, $firstNewline + 1);
            }

            $truncated = true;
            $skippedKb = round(($fileSize - $maxBytes) / 1024);
        }

        $lines = array_filter(explode("\n", rtrim($raw)));
        $lines = array_reverse($lines);
        $output = implode("\n", $lines);

        if ($truncated) {
            $output .= "\n[{$skippedKb} KB of older entries not shown]";
        }

        return $output;
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
