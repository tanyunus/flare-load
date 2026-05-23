<?php

namespace FlareLoad\Util;

defined('ABSPATH') || exit;

class Logger
{
    const LOG_FILE = 'flare-load.log';

    /** 0=Error 1=Warning 2=Notice 3=Info 4=Debug */
    public static function log(int $level, string $message, array $context = []): void {
        $dateTime = '[' . wp_date('Y-m-d H:i:s P') . '] ';
        $message = self::getLevel($level) .': '. $message;

        $updateResult = self::updateLogFile($dateTime . $message);

        if(!$updateResult) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback when plugin log file is not writable; no other reporting channel available.
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
            wp_mkdir_p(dirname($filePath));
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

        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $contents = $wp_filesystem->get_contents( $filePath );
        if ( $contents === false ) {
            return '';
        }

        if ( $fileSize <= $maxBytes ) {
            $raw = $contents;
        } else {
            $raw = substr( $contents, -$maxBytes );

            // Drop the partial first line caused by mid-content cut.
            $firstNewline = strpos( $raw, "\n" );
            if ( $firstNewline !== false ) {
                $raw = substr( $raw, $firstNewline + 1 );
            }

            $truncated = true;
            $skippedKb = round( ( $fileSize - $maxBytes ) / 1024 );
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
        $uploadDir = wp_upload_dir();
        return $uploadDir['basedir'] . '/flare-load/' . self::LOG_FILE;
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
