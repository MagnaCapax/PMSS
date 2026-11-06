<?php
namespace PMSS\Tests;

/** Run the real cron, allocator, guarded writer and audit against memory-only I/O. */
trait DynamicIopsEnforceFixture
{
    private function enforceCycle(array $options = [], array $args = []): array
    {
        $script = <<<'PHP'
namespace DynamicIopsEnforceFixture;
use \Throwable;
function openlog(...$args) { return true; }
function syslog($level, $message) { $GLOBALS['messages'][] = $message; return true; }
function closelog() {
    $output = ob_get_clean();
    echo json_encode(['kernel' => $GLOBALS['kernel'], 'writes' => $GLOBALS['writes'],
        'logs' => $GLOBALS['logs'], 'messages' => $GLOBALS['messages'], 'output' => $output]);
    return true;
}
function posix_geteuid() { return 0; }
function posix_getpwnam($user) { return ['uid' => 1001]; }
function glob($pattern) {
    $paths = ['/etc/seedbox/config/users/fixture.json'];
    if ($GLOBALS['options']['badConfig'] ?? false) $paths[] = '/etc/seedbox/config/users/broken.json';
    return $paths;
}
function is_dir($path) { return true; }
function is_file($path) { return isset($GLOBALS['kernel'][$path]); }
function is_writable($path) { return true; }
function file_get_contents($path) { return $GLOBALS['kernel'][$path] ?? ''; }
function file_put_contents($path, $data) {
    $GLOBALS['writes'][] = [$path, $data]; $GLOBALS['kernel'][$path] = $data; return strlen($data);
}
function pmssPathTargetIsSafe(...$args) { return false; }
function pmssCgroupPolicyLoad() { return $GLOBALS['policy']; }
function pmssCgroupPolicyMountSourceResolve($mount) { return $GLOBALS['options']['md'] ? '/dev/md0' : '/dev/sda'; }
function pmssCgroupPolicyDeviceMajorMinorResolve($device) { return '8:0'; }
function pmssCgroupPolicyHomeDeviceIsMdBacked($device) { return $GLOBALS['options']['md']; }
function pmssCgroupPolicyHomeStorageClassResolve($device) { return 'hdd'; }
function pmssIoCeilingPublishedReadIops($path) { return $GLOBALS['options']['capacity']; }
function pmssJsonFileReadAssoc($path, $safe = false) {
    if (substr($path, -11) === 'broken.json') return null;
    if (substr($path, -12) === 'fixture.json') return $GLOBALS['options']['source'];
    return ['computed_at' => gmdate('c')];
}
function pmssDynamicIopsPressure($samples, $now) {
    if ($GLOBALS['options']['throw'] ?? false) throw new \RuntimeException('fixture failure');
    return $GLOBALS['options']['pressure'];
}
function pmssRunSnapshotLogTask($script, $env, $path, $callback) {
    if ($GLOBALS['options']['logFailure'] && $env === 'PMSS_DYNAMIC_IOPS_LOG') return 1;
    $handle = fopen('php://memory', 'w+'); $rc = $callback($handle); rewind($handle);
    $GLOBALS['logs'][$env][] = json_decode(stream_get_contents($handle), true); fclose($handle); return $rc;
}
PHP;
        $options += ['mode' => 'enforce', 'md' => false, 'capacity' => 500.0, 'logFailure' => false,
            'pressure' => ['blend' => 15.0, 'iowait_blend' => 80.0], 'settings' => [],
            'source' => ['quota' => 1000, 'IOWeight' => 100, 'IOReadIOPS' => '/home:1000000', 'IOWriteIOPS' => '/home:90']];
        $script .= '$GLOBALS["options"] = '.var_export($options, true).';';
        $script .= '$GLOBALS["policy"] = ["cgroup" => ["dynamicIops" => $GLOBALS["options"]["settings"] + ["mode" => $GLOBALS["options"]["mode"]]]];';
        $script .= '$GLOBALS["writes"] = $GLOBALS["logs"] = $GLOBALS["messages"] = [];';
        $kernel = $options['kernel'] ?? [
            '/sys/fs/cgroup/blkio/user.slice/user-1001.slice/blkio.throttle.read_iops_device' => '8:0 123',
            '/sys/fs/cgroup/blkio/user.slice/user-1001.slice/blkio.throttle.write_iops_device' => '8:0 90'];
        $script .= '$GLOBALS["kernel"] = '.var_export($kernel, true).';';
        // Libraries execute in a namespace so every OS interaction above is intercepted.
        foreach (['directApply.php', 'dynamicIopsPolicy.php', 'dynamicIopsAllocation.php', 'dynamicIopsContention.php', 'dynamicIopsShadow.php'] as $file) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/'.$file, 'DynamicIopsEnforceFixture');
        }
        $path = $this->pmssRepoPath('scripts/cron/cgroupIopsLimitApply.php');
        $cron = (string) file_get_contents($path);
        $cron = substr($cron, strpos($cron, '<?php') + 5);
        $cron = str_replace(['declare(strict_types=1);', '__DIR__', '__FILE__'],
            ['', var_export(dirname($path), true), var_export($path, true)], $cron);
        $script .= '$argv = '.var_export(array_merge(['fixture.php'], $args), true).'; ob_start();';
        $script .= 'eval('.var_export('namespace DynamicIopsEnforceFixture; use \\Throwable;'.$cron, true).');';
        return $this->pmssRunInlinePhpJson($script, ['PMSS_DRY_RUN' => $options['envDryRun'] ?? '0']);
    }
}
