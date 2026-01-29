#!/usr/bin/env php
<?php
/**
 * Port assignment helper for per-user services.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

$usage = "Usage: portManager.php [view|assign|release] USER [SERVICE]\n";
if ($argc < 3) die($usage);
$action = strtolower($argv[1]);
$user   = $argv[2];
$service = isset($argv[3]) ? $argv[3] : 'lighttpd';
$portDir = '/etc/seedbox/runtime/ports';
if (!file_exists($portDir)) mkdir($portDir, 0755, true);
$portFile = "$portDir/{$service}-{$user}";

$lifecyclePath = __DIR__.'/../lib/userLifecycle.php';
if (is_file($lifecyclePath)) {
    require_once $lifecyclePath;
}

/**
 * Write a port assignment event to the shared user logs when available.
 */
function pmssPortManagerLog(string $user, string $action, string $service, ?int $port, string $status, string $message): void
{
    if (!function_exists('pmssUserWriteLogs') || !function_exists('pmssUserBaseContext')) {
        return;
    }

    pmssUserWriteLogs(
        pmssUserBaseContext(
            'port',
            $action,
            $user,
            array(
                'status'  => $status,
                'service' => $service,
                'port'    => $port,
                'message' => $message,
            )
        )
    );
}

if ($action === 'assign' || $action === 'release') {
    $lockBase = is_dir('/run/lock') ? '/run/lock' : '/tmp';
    $lockPath = $lockBase.'/pmss-portManager-'.$service.'.lock';
    $lockHandle = @fopen($lockPath, 'c');
    if ($lockHandle === false || !@flock($lockHandle, LOCK_EX)) {
        pmssPortManagerLog($user, $action, $service, null, 'WARN', 'lock_failed');
    }
}

switch ($action) {
    case 'view':
        if (file_exists($portFile)) {
            echo trim(file_get_contents($portFile)) . "\n";
        } else echo "No port assigned\n";
        break;

    case 'assign':
        if (file_exists($portFile)) {
            $existing = (int) trim((string) file_get_contents($portFile));
            echo $existing . "\n";
            pmssPortManagerLog($user, 'assign', $service, $existing, 'SKIP', 'already_assigned');
            break;
        }
        $used = [];
        foreach (glob("$portDir/{$service}-*") as $f) {
            $p = (int) trim(@file_get_contents($f));
            if ($p) $used[$p] = true;
        }
        do {
            $port = rand(2000, 38000);
        } while (isset($used[$port]));
        file_put_contents($portFile, $port);
        chmod($portFile, 0640);
        echo $port . "\n";
        pmssPortManagerLog($user, 'assign', $service, $port, 'OK', 'assigned');
        break;

    case 'release':
        if (file_exists($portFile)) {
            unlink($portFile);
            echo "Port released\n";
            pmssPortManagerLog($user, 'release', $service, null, 'OK', 'released');
        } else echo "No port assigned\n";
        break;
    default:
        die($usage);
}
?>
