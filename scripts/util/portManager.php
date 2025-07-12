<?php
/**
 * Port allocation manager.
 *
 * Provides random ports for services while attempting to avoid
 * conflicts with existing sockets. If required utilities are
 * missing the checks gracefully degrade and still return a port.
 */
class portManager {
    /**
     * Allocate a port within the given range.
     */
    public static function allocate($type, $rangeStart = 2000, $rangeEnd = 65000) {
        $base = '/var/run/pmss/ports';
        $dir = "$base/$type";

        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $dir = sys_get_temp_dir() . "/pmss_ports/$type";
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return random_int($rangeStart, $rangeEnd);
            }
        }

        $attempts = 0;
        $maxAttempts = 100;
        do {
            $port = random_int($rangeStart, $rangeEnd);
            $file = "$dir/$port";
            $attempts++;
        } while (
            $attempts < $maxAttempts &&
            (file_exists($file) || self::portInUse($port))
        );

        if ($attempts >= $maxAttempts) {
            return $rangeStart;
        }

        touch($file);
        return $port;
    }

    /**
     * Determine if a port is listening.
     */
    public static function portInUse($port) {
        $cmd = trim(shell_exec('command -v ss'));
        if ($cmd) {
            exec("$cmd -lntu sport = :$port 2>/dev/null", $out, $code);
            return $code === 0 && count($out) > 1;
        }

        $cmd = trim(shell_exec('command -v netstat'));
        if ($cmd) {
            exec("$cmd -lntu | grep -w ':$port' 2>/dev/null", $out, $code);
            return $code === 0 && !empty($out);
        }

        return false; // cannot determine
    }
}

