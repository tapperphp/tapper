<?php

declare(strict_types=1);

namespace Tapper\Console;

use Tapper\Console\State\AppState;
use Tapper\LogPath;
use Throwable;

/**
 * The TUI runs in raw mode on the terminal's alternate screen, so anything PHP prints
 * through its default error output (warnings, notices, uncaught-exception traces) gets
 * interleaved with rendered frames instead of appearing on a normal scrollback — this is
 * what corrupts the display. `install()` redirects non-fatal errors to a log file and a
 * short-lived Header notice instead of letting PHP print them. Fatal throwables still
 * propagate (see `Application::run()`/`bin/tapper`) so the terminal can be restored via
 * `Application::close()` before anything is printed.
 */
final class ErrorHandler
{
    private const int NOTICE_SECONDS = 5;

    public static function install(AppState $appState): void
    {
        ini_set('display_errors', '0');

        set_error_handler(function (int $severity, string $message, string $file, int $line) use ($appState): bool {
            if (! (error_reporting() & $severity)) {
                return true;
            }

            self::log(sprintf('%s: %s in %s:%d', self::severityLabel($severity), $message, $file, $line));
            self::notify($appState);

            return true;
        });
    }

    public static function logThrowable(Throwable $e): void
    {
        self::log(sprintf(
            "Uncaught %s: %s in %s:%d\n%s",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        ));
    }

    private static function log(string $entry): void
    {
        $line = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $entry, PHP_EOL);

        file_put_contents(LogPath::resolve(), $line, FILE_APPEND);
    }

    private static function notify(AppState $appState): void
    {
        $appState->errorNotice = '⚠ error — see tapper.log';
        $appState->errorNoticeExpiresAt = microtime(true) + self::NOTICE_SECONDS;
    }

    private static function severityLabel(int $severity): string
    {
        return match ($severity) {
            E_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
            default => 'Error',
        };
    }
}
