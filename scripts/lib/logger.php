<?php
/**
 * Library helper: logger.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/log.php';

/** Simple logging helper shared across cron scripts. */
class Logger {
    /** @var string */ private $log;
    /** @var string */ private $fallback;
    /** @var bool */ private $writeToStderr;

    public function __construct(string $script, string $dir = '/var/log/pmss', string $fallbackDir = '/tmp', ?string $baseName = null, bool $writeToStderr = false)
    {
        $base = $baseName !== null && $baseName !== '' ? $baseName : basename($script, '.php');
        $this->log = rtrim($dir, '/') . '/' . $base . '.log';
        $this->fallback = rtrim($fallbackDir, '/') . '/' . $base . '.log';
        $this->writeToStderr = $writeToStderr;
    }

    public function msg(string $m): void
    {
        pmssLogWriteMessage($this->log, $this->fallback, $m, $this->writeToStderr);
    }
}
