<?php
/** Hermetic CLI fixture: every process, identity, filesystem and clock observation is injected. */
namespace UserDockerLifecycleFixture;

require_once dirname(__DIR__, 2).'/runtime/environment.php';

$GLOBALS['case'] = json_decode($argv[1], true);
$GLOBALS['events'] = $GLOBALS['errors'] = [];
$GLOBALS['clock'] = 0.0;
$GLOBALS['scans'] = 0;
$argv = $GLOBALS['case']['argv'];
ob_start();
register_shutdown_function(static function (): void {
    echo json_encode(['stdout' => ob_get_clean(), 'stderr' => $GLOBALS['errors'],
        'events' => $GLOBALS['events'], 'scans' => $GLOBALS['scans'], 'fatal' => error_get_last()]);
});

function pmssRequireCli() {}
function pmssRequireRelativeFiles($base, $paths) {}
function pmssCliArgvDebugSplit($args) { return [in_array('--debug', $args, true), array_values(array_diff($args, ['--debug']))]; }
function pmssCliExitWithStderr($message, $rc = 1) { fwrite(STDERR, $message); exit($rc); }
function fwrite($stream, $bytes) { $GLOBALS['errors'][] = $bytes; return strlen($bytes); }
function pmssValidateUsername($user) { return preg_match('/^[a-z][a-z0-9]{2,7}$/D', $user) === 1; }
function pmssUserAccountLookup($user) {
    return !empty($GLOBALS['case']['unknown']) ? null : ['uid' => $GLOBALS['case']['uid'] ?? 1001, 'gid' => 1002, 'dir' => '/srv/accounts/alice'];
}
function pmssPasswdEntryPositiveUid($entry) { return $entry['uid'] > 0 ? $entry['uid'] : null; }
function pmssLogSafeLabel($label) { return $label; }
function posix_geteuid() { return $GLOBALS['case']['euid'] ?? 0; }
function file_exists($path) { return !empty($GLOBALS['case']['socket']); }
function is_file($path) { return strpos($path, 'timeout') !== false || !empty($GLOBALS['case']['unit']); }
function is_executable($path) { return true; }
function is_dir($path) { return empty($GLOBALS['case']['runtime_missing']); }
function fileowner($path) { return $GLOBALS['case']['owner'] ?? 1001; }
function posix_kill($pid, $signal) { $GLOBALS['events'][] = ['signal', $pid, $signal]; return true; }
function microtime($float = false) { return $GLOBALS['clock']; }
function time() { return (int) $GLOBALS['clock']; }
function usleep($usec) { $GLOBALS['clock'] += $usec / 1000000; }
function sleep($seconds) { $GLOBALS['clock'] += $seconds; }
function pmssUserLog($user, $message) { $GLOBALS['events'][] = ['log', $user, $message]; }
class UserConfigStore {}
function pmssUserDockerEnabled($user, $store) { return empty($GLOBALS['case']['disabled']); }
function pmssUserDockerMinRamMiB() { return 245; }
function pmssCgroupModeWithDefault($default) { return $default; }
function pmssUserDockerContainerIdsValid($ids) {
    return count($ids) > 0 && count(array_filter($ids, static function ($id) { return preg_match('/^[a-f0-9]{64}$/D', $id); })) === count($ids);
}
function pmssUserDockerLingerEnsureCommands($user, $uid) { return ['enable-linger', 'start-user-manager']; }
function runCommand($command, $verbose, $logger) { $GLOBALS['events'][] = ['linger', $command]; $GLOBALS['case']['runtime_missing'] = false; }
function pmssBuildUserShellCommand($user, $cmd) { return 'account-shell '.$user.' '.$cmd; }
function pmssBuildUserServiceShellCommand($user, $cmd) { return 'slice-shell '.$user.' '.$cmd; }
function pmssUserWatchdogProcessPids($user, $pattern, $options, &$rc, &$out) {
    $rc = !empty($GLOBALS['case']['pgrep_missing']) ? 127 : (empty($GLOBALS['case']['running']) ? 1 : 0);
    $out = $rc === 0 ? ['41', '39'] : [];
    return $rc === 0 ? [41, 39] : [];
}
function exec($command, &$out, &$rc) {
    $out = [];
    $rc = 0;
    if (strpos($command, 'ps -u ') === 0) {
        $GLOBALS['scans']++;
        $rc = ($GLOBALS['case']['scan_failure'] ?? 0) === $GLOBALS['scans'] ? 1 : 0;
        if (strpos($command, 'comm=') === false) {
            $rc = !empty($GLOBALS['case']['ps_failure']) ? 1 : 0;
            $out = !empty($GLOBALS['case']['ps_running']) ? ['39 dockerd --rootless', '77 unrelated'] : [];
        } elseif (!empty($GLOBALS['case']['survivors']) || $GLOBALS['scans'] === 1) {
            $out = [' 39 dockerd dockerd --rootless', '40 rootlesskit rootlesskit dockerd',
                '41 bash /bin/bash /usr/bin/dockerd-rootless.sh', '77 bash bash unrelated'];
        }
        return '';
    }
    $GLOBALS['events'][] = ['command', $command];
    if (strpos($command, 'systemctl --user') !== false) $rc = $GLOBALS['case']['unit_rc'] ?? 0;
    elseif (strpos($command, 'info >') !== false) $rc = !empty($GLOBALS['case']['not_ready']) ? 1 : 0;
    elseif (strpos($command, ' docker start ') !== false) { $rc = $GLOBALS['case']['container_rc'] ?? 0; $out = ['started']; }
    elseif (strpos($command, ' docker ps ') !== false) $out = ['running', 'exited', 'running', 'malformed value'];
    return '';
}

// Evaluate the real source in this namespace; dependencies are replaced above, never executed.
$root = dirname(__DIR__, 4);
$paths = array_merge(glob($root.'/scripts/lib/user/docker/*.php') ?: [], [$root.'/scripts/util/userDocker.php']);
foreach ($paths as $path) {
    $source = \file_get_contents($path);
    $source = substr($source, strpos($source, '<?php') + 5);
    $source = preg_replace('/^require_once .*;\R?/m', '', $source);
    eval('namespace UserDockerLifecycleFixture;'.$source);
}
