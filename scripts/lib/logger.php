<?php
/** Simple logging helper shared across cron scripts. */
class Logger {
    private string $log;
    private string $fallback;

    public function __construct(string $script, string $dir = '/var/log/pmss') {
        $base = basename($script, '.php');
        $this->log = rtrim($dir, '/') . '/' . $base . '.log';
        $this->fallback = '/tmp/' . $base . '.log';
    }

    public function msg(string $m): void {
        $ts = date('[Y-m-d H:i:s] ');
        @file_put_contents($this->log, $ts.$m.PHP_EOL, FILE_APPEND|LOCK_EX)
        || @file_put_contents($this->fallback, $ts.$m.PHP_EOL, FILE_APPEND|LOCK_EX);
        echo $m.PHP_EOL;
    }
}

/**
 * Legacy wrapper for older scripts.
 * Usage: require_once '/scripts/lib/logger.php';
 *   $log = new Logger(__FILE__);
 *   $log->msg('text');
 */
function logmsg(string $m): void {
    global $logmsg_default_logger;
    if (!isset($logmsg_default_logger)) {
        $logmsg_default_logger = new Logger($_SERVER['SCRIPT_NAME'] ?? __FILE__);
    }
    $logmsg_default_logger->msg($m);
}
