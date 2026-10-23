<?php
/** In-memory compatibility-repair boundaries: never execute package or service commands. */
namespace OpenSslSsh2Trace;

$case = json_decode(getenv('PMSS_OPENSSL_TRACE_CASE'), true);
$trace = [];
$queries = 0;
function logMessage($message) { $GLOBALS['trace'][] = ['log', $message]; }
function pmssEnvFlagEnabled($key) { return !empty($GLOBALS['case']['dry']); }
function pmssDistroVersionFromEnv($version) { return $version ?? 12; }
function pmssAptDpkgEnvPrefix() { return 'ENV'; }
function aptCmd($args) { return 'apt-get '.$args; }
function dpkgCmd($args) { return 'dpkg '.$args; }
function date($format) { return '20000101-000000'; }
function sleep($seconds) { $GLOBALS['trace'][] = ['sleep', $seconds]; }
function mkdir($path, $mode, $recursive) { $GLOBALS['trace'][] = ['mkdir', $path, $mode, $recursive]; return true; }
function is_dir($path) { $GLOBALS['trace'][] = ['is_dir', $path]; return false; }
function is_file($path) { $GLOBALS['trace'][] = ['is_file', $path]; return $path === '/usr/sbin/sshd' && !empty($GLOBALS['case']['binary']); }
function file_exists($path) { $GLOBALS['trace'][] = ['exists', $path]; return empty($GLOBALS['case']['noTemplate']) || $path !== '/etc/seedbox/config/template.sshd_config'; }
function file_get_contents($path) { $GLOBALS['trace'][] = ['read', $path]; return !empty($GLOBALS['case']['legacyMac']) ? 'MACs hmac-ripemd160' : ''; }
function readlink($path) {
    $GLOBALS['trace'][] = ['readlink', $path];
    return $GLOBALS['case']['links'][$path] ?? (!empty($GLOBALS['case']['deleted']) ? '/usr/sbin/sshd (deleted)' : '/usr/sbin/sshd');
}
function glob($pattern) {
    $GLOBALS['trace'][] = ['glob', $pattern];
    $files = $GLOBALS['case']['debs'] ?? ['openssh-server.deb', 'runit-helper.deb', 'openssh-client.deb', 'openssh-sftp-server.deb'];
    return array_map(static function ($file) { return '/fixture/cache/'.$file; }, $files);
}
function pmssCreatePrivateTempDir($prefix) {
    $GLOBALS['trace'][] = ['temp', $prefix];
    return !empty($GLOBALS['case']['noTemp']) ? null : '/fixture/cache';
}
function pmssRemovePrivateTempDir($dir, $prefix, $label) { $GLOBALS['trace'][] = ['cleanup', $dir, $prefix, $label]; }
function runStep($label, $command) {
    $GLOBALS['trace'][] = ['step', $label, $command];
    return isset($GLOBALS['case']['fail']) && strpos($label, $GLOBALS['case']['fail']) !== false ? 1 : 0;
}
function shell_exec($command) {
    $GLOBALS['trace'][] = ['shell', $command];
    if (strpos($command, 'dpkg-query') === 0) {
        if (strpos($command, 'openssh-server') !== false) return '1:9.2p1-2+deb12u8';
        $versions = $GLOBALS['case']['versions'] ?? ['3.0.17-1~deb12u2'];
        return $versions[min($GLOBALS['queries']++, count($versions) - 1)];
    }
    if (strpos($command, 'showhold') !== false) return $GLOBALS['case']['held'] ?? '';
    if (strpos($command, 'MainPID') !== false) return $GLOBALS['case']['mainPid'] ?? (!empty($GLOBALS['case']['fallbackPid']) ? '' : '42');
    if (strpos($command, 'pgrep') === 0) return $GLOBALS['case']['pids'] ?? '42 43';
    return !empty($GLOBALS['case']['noListener']) ? 'no' : 'yes';
}
function exec($command, &$output, &$rc) {
    $GLOBALS['trace'][] = ['exec', $command];
    $output = [];
    $rc = 0;
    if (preg_match("/compare-versions '([^']*)' '([^']*)' '([^']*)'/", $command, $m)) {
        $rc = \version_compare($m[1], $m[3], $m[2]) ? 0 : 1;
    } elseif (!empty($GLOBALS['case']['invalidConfig'])) {
        $rc = 1;
        $output = ['invalid configuration'];
    }
}

// Load exactly the production definitions, substituting only their I/O boundaries above.
// Stripping dependency loads prevents the fixture from reaching any real service helpers.
$root = dirname(__DIR__, 2).'/update';
$files = array_merge([$root.'/opensslSsh2Compat.php'], \glob($root.'/opensslSsh2Compat/*.php') ?: []);
foreach ($files as $file) {
    $source = preg_replace('/^require_once .*;\R/m', '', substr(\file_get_contents($file), 5));
    eval('namespace OpenSslSsh2Trace; use \\RuntimeException;'.$source);
}
try {
    $function = __NAMESPACE__.'\\'.$case['function'];
    $function($case['distro'] ?? null);
} catch (\Throwable $error) {
    $trace[] = ['exception', get_class($error), $error->getMessage()];
}
echo json_encode($trace, JSON_UNESCAPED_SLASHES);
