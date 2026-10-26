<?php
/** In-memory allocator dependencies: no listeners, assignments, locks, or user logs. */
namespace PortManagerCharacterization;

require_once dirname(__DIR__, 2).'/portManager.php';

$GLOBALS['events'] = [];
$GLOBALS['stderr'] = '';
$GLOBALS['present'] = true;
$GLOBALS['scenario'] = '';
$GLOBALS['contexts'] = 0;

function pmssValidateUsername($user) { return $user === 'alice'; }
function pmssPortManagerServiceNameIsValid($service) { return in_array($service, ['lighttpd', 'rclone'], true); }
function pmssPortManagerAssignmentContext($user, $service, &$status = null) {
    $phase = ++$GLOBALS['contexts'];
    $GLOBALS['events'][] = 'context';
    $status = 'ok';
    if ($GLOBALS['scenario'] === 'directory' || $GLOBALS['scenario'] === 'unsafe'
        || ($phase === 2 && in_array($GLOBALS['scenario'], ['unsafe_after_lock', 'directory_after_lock'], true))) {
        $status = strpos($GLOBALS['scenario'], 'directory') === 0 ? 'port_dir_unavailable' : 'unsafe_existing_assignment';
        return null;
    }
    return ['dir' => '/fixture/ports', 'file' => '/fixture/ports/'.$service.'-'.$user, 'present' => $GLOBALS['present']];
}
function pmssRuntimeLockPath($leaf) { return $leaf; }
function pmssLockFileAcquire($path) {
    $GLOBALS['events'][] = 'lock';
    if ($GLOBALS['scenario'] === 'lock') return false;
    if ($GLOBALS['scenario'] === 'appeared') $GLOBALS['present'] = true;
    if ($GLOBALS['scenario'] === 'disappeared') $GLOBALS['present'] = false;
    return 'fixture-lock';
}
function pmssLockHandleRelease($handle) { $GLOBALS['events'][] = 'unlock'; }
function pmssReadRegularFileNetworkPort($path) {
    $GLOBALS['events'][] = 'read';
    if ($GLOBALS['scenario'] === 'throw') throw new \RuntimeException('fixture read failed');
    return $GLOBALS['scenario'] === 'malformed' ? null : 24567;
}
function pmssPortManagerAssignmentPathIsSafe($dir, $file) {
    $GLOBALS['events'][] = 'safe';
    return $GLOBALS['scenario'] !== 'unsafe_release';
}
function unlink($path) {
    $GLOBALS['events'][] = 'unlink';
    if ($GLOBALS['scenario'] === 'unlink_throw') throw new \RuntimeException('fixture unlink failed');
    return $GLOBALS['scenario'] !== 'unlink';
}
function pmssResolvePathFromEnv($key, $default) { return $default; }
function pmssPortManagerDefaultPath($leaf, $path) { return $path; }
function pmssPortManagerUsedPorts($dir, $legacy, &$complete = null) {
    $GLOBALS['events'][] = 'scan';
    $complete = $GLOBALS['scenario'] !== 'scan';
    return $GLOBALS['scenario'] === 'exhausted' ? [24567 => true] : [];
}
function shuffle(&$ports) { $ports = [24567]; return true; }
function pmssNetworkPortInRange($port, $min, $max) { return $port >= $min && $port <= $max; }
function stream_socket_server($address, &$errno, &$error, $flags) {
    $GLOBALS['events'][] = $address;
    return \tmpfile();
}
function pmssPortManagerWriteAssignedPort($dir, $file, $port) {
    $GLOBALS['events'][] = 'write';
    return $GLOBALS['scenario'] !== 'write';
}
function pmssUserBaseContext($domain, $action, $user, $details) { return [$domain, $action, $user, $details]; }
function pmssUserWriteLogs($context) { $GLOBALS['events'][] = $context; }
function fwrite($stream, $text) { $GLOBALS['stderr'] .= $text; return strlen($text); }
