<?php
/**
 * Helper to rate limit service restarts.  Each invocation stores a timestamp
 * under /var/run/pmss/restarts for the combination of service and user.  The
 * delay grows exponentially to avoid aggressive restart loops when a service
 * repeatedly fails.  Call {@link restartAllowed()} before restarting; it
 * returns true only when the cooldown period has passed and updates the stored
 * state accordingly.
 *
 * @param string  $service   Name of the service (e.g. 'rtorrent').
 * @param string  $user      Associated user name.
 * @param int     $baseWait  Base wait time in seconds before first retry.
 * @param int     $maxCount  Maximum backoff multiplier.
 * @return bool   True if restart may proceed.
 */
function restartAllowed($service, $user, $baseWait = 30, $maxCount = 5)
{
    $dir = '/var/run/pmss/restarts';
    if (!file_exists($dir)) {
        mkdir($dir, 0700, true);
    }

    $file = "$dir/{$service}_{$user}";
    $data = ['time' => 0, 'count' => 0];
    if (file_exists($file)) {
        $data = @unserialize(file_get_contents($file));
        if (!is_array($data)) {
            $data = ['time' => 0, 'count' => 0];
        }

        $backoff = $baseWait * pow(2, $data['count']);
        if ((time() - $data['time']) < $backoff) {
            return false;
        }
        if ($data['count'] < $maxCount) {
            $data['count']++;
        }
    }

    $data['time'] = time();
    file_put_contents($file, serialize($data));
    return true;
}

