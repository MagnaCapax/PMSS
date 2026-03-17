<?php
/**
 * Library helper: logger.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Simple logging helper shared across cron scripts. */
class Logger {
    /** @var string */
    private $log;
    /** @var string */
    private $fallback;
    /** @var bool */
    private $writeToStderr;

    public function __construct(
        string $script,
        string $dir = '/var/log/pmss',
        string $fallbackDir = '/tmp',
        ?string $baseName = null,
        bool $writeToStderr = false
    ) {
        $base = $baseName !== null && $baseName !== '' ? $baseName : basename($script, '.php');
        $this->log = rtrim($dir, '/') . '/' . $base . '.log';
        $this->fallback = rtrim($fallbackDir, '/') . '/' . $base . '.log';
        $this->writeToStderr = $writeToStderr;
    }

    public function msg(string $m): void {
        $ts = date('[Y-m-d H:i:s] ');
        @file_put_contents($this->log, $ts.$m.PHP_EOL, FILE_APPEND|LOCK_EX)
        || @file_put_contents($this->fallback, $ts.$m.PHP_EOL, FILE_APPEND|LOCK_EX);
        if ($this->writeToStderr) {
            fwrite(STDERR, $m.PHP_EOL);
            return;
        }
        echo $m.PHP_EOL;
    }
}

/**
 * Load the shared `logmsg()` compatibility wrapper after the Logger class so
 * standalone scripts can either instantiate Logger directly or keep using the
 * historic helper name.
 */
require_once __DIR__.'/log.php';
