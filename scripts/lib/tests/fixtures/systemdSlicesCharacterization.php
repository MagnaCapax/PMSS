<?php
/** In-memory slice setup dependencies: never read host policy or contact systemd. */
namespace SystemdSlicesCharacterization;

function record($event) { $GLOBALS['sliceTrace'][] = $event; }
function option($key, $default = null) { return $GLOBALS['sliceCase'][$key] ?? $default; }
function pmssTestModeEnabled() { return option('testMode', false); }
function pmssCgroupMode() { return option('mode', 'v1'); }
function pmssTotalMemMiB() { record(['memory']); return option('memory', 65536); }
function pmssTotalCpuThreads() { record(['cpu']); return option('cpu', 16); }
function pmssResolvePathFromEnv($key, $default) {
    return ['PMSS_CONFIG_DIR' => '/config', 'PMSS_SYSTEMD_USER_SLICE_DIR' => '/systemd/user-.slice.d',
        'PMSS_SYSTEMD_USER_AT_SERVICE_DIR' => '/systemd/user@.service.d'][$key] ?? $default;
}
function pmssCgroupPolicyLoad($dir) { record(['policy', $dir]); return option('policy', []); }
function pmssUserCgroupSliceDropinsRepair($log, $reload) { record(['repair']); $reload(); }
function is_file($path) {
    if (strpos($path, 'template.') !== false) return !option('unreadableTemplate', false);
    if (strpos($path, '/usr/lib/') === 0 || strpos($path, '/lib/') === 0) return option('legacy', false);
    return substr($path, -13) === '/99-pmss.conf' && option('shadow', false);
}
function file_exists($path) { return !option('missingTemplate', false); }
function file_get_contents($path) {
    record(['read', $path]);
    if (substr($path, -13) === '/99-pmss.conf') return option('shadowContent', false);
    return "[Slice]\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n"
        ."CPUWeight=%%USER_CGROUP_CPU_WEIGHT%%\nIOWeight=%%USER_CGROUP_IO_WEIGHT%%\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n"
        ."CPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n%%USER_CGROUP_IO_DEVICE_LATENCY%%\n";
}
function unlink($path) { record(['unlink', $path]); return !option('unlinkFailure', false); }
function pmssRefreshManagedPathFile($path, $body, $label, $log, $options = [], &$ready = null) {
    record(['write', $path, $body, $label, $options]);
    $ready = strpos($path, (string) option('failedWrite', '!')) === false;
    return $ready;
}
function pmssLogStatus($status, $message, $rc) { record(['status', $status, $message, $rc]); }
function runStep($label, $command) { record(['step', $label, $command]); return 0; }
function runCommand($command, $verbose, $log) {
    record(['command', $command]);
    $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] = strpos($command, 'list-units') !== false
        ? option('units', "user-0.slice loaded\nuser-1001.slice loaded\nuser-1002.slice loaded\nuser-1003.slice loaded\nother.slice\nuser-bad.slice")
        : (strpos($command, '1002') !== false ? 'TasksMax=infinity' : (strpos($command, '1003') !== false ? 'TasksMax=4096' : 'TasksMax=250'));
    return option('commandRc', 0);
}
function pmssCgroupPolicyMountSourceResolve($mount) { record(['mount', $mount]); return option('devices', [])[$mount] ?? ''; }
function pmssCgroupPolicyDeviceTargetIsSafe($device) { return strpos($device, '/dev/') === 0 && strpbrk($device, "\r\n ") === false; }
