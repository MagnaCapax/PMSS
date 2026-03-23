<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

function pmssTestRemoveTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
            continue;
        }

        @unlink($item->getPathname());
    }

    @rmdir($path);
}

$suiteRoot = sys_get_temp_dir().'/pmss-tests-'.bin2hex(random_bytes(6));
if (!is_dir($suiteRoot)) {
    @mkdir($suiteRoot, 0755, true);
}

$versionDir = $suiteRoot.'/version';
@mkdir($versionDir, 0755, true);
putenv('PMSS_VERSION_DIR='.$versionDir);
putenv('PMSS_TEST_TEMP_ROOT='.$suiteRoot);
// Propagate test mode to child processes invoked via shell_exec
putenv('PMSS_TEST_MODE=1');
putenv('PMSS_JSON_LOG');
putenv('PMSS_PROFILE_OUTPUT');

$testRoot    = $suiteRoot.'/root';
$skelDir     = $testRoot.'/skel';
$networkCfg  = $testRoot.'/network.php';
$localnetCfg = $testRoot.'/localnet';
$fireqosTpl  = $testRoot.'/fireqos.tpl';
$aptKeyring  = $testRoot.'/apt-keyrings';

register_shutdown_function(static function () use ($suiteRoot): void {
    pmssTestRemoveTree($suiteRoot);
});

if (!is_dir($skelDir.'/www/rutorrent/plugins/unpack')) {
    @mkdir($skelDir.'/www/rutorrent/plugins/unpack', 0755, true);
    @mkdir($skelDir.'/www/rutorrent/plugins/theme/themes', 0755, true);
}
if (!is_dir($skelDir.'/.irssi')) {
    @mkdir($skelDir.'/.irssi', 0755, true);
}
@file_put_contents($skelDir.'/.irssi/config', 'test');
@file_put_contents($skelDir.'/.rtorrent.rc.custom', 'test');

@mkdir($testRoot, 0755, true);
@file_put_contents($networkCfg, "<?php return ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]];");
@file_put_contents($localnetCfg, "185.148.0.0/22\n");
@file_put_contents($fireqosTpl, "interface ##INTERFACE\nrate ##SPEED\n##LOCALNETWORK\n##USERMATCHES\n");

putenv('PMSS_SKEL_DIR='.$skelDir);
putenv('PMSS_NETWORK_CONFIG='.$networkCfg);
putenv('PMSS_LOCALNET_FILE='.$localnetCfg);
putenv('PMSS_FIREQOS_TEMPLATE='.$fireqosTpl);
putenv('PMSS_APT_KEYRING_DIR='.$aptKeyring);

define('PMSS_TEST_MODE', true);
require_once dirname(__DIR__, 3).'/update.php';

$testFiles = glob(__DIR__.'/*Test.php') ?: [];
$repoRoot = dirname(__DIR__, 4);
$listed = trim((string) @shell_exec('git -C '.escapeshellarg($repoRoot).' ls-files -- '.escapeshellarg(':(glob)scripts/lib/tests/development/*Test.php').' 2>/dev/null'));
if ($listed !== '' && ($tracked = array_values(array_filter(array_map(static function (string $path) use ($repoRoot): string { return $repoRoot.'/'.$path; }, preg_split('/\r?\n/', $listed) ?: []), 'is_file'))) !== []) {
    $testFiles = $tracked;
}

foreach ($testFiles as $testFile) {
    require_once $testFile;
}

$classes = array_filter(get_declared_classes(), static function ($class) {
    return is_subclass_of($class, TestCase::class) && !(new \ReflectionClass($class))->isAbstract();
});

$total = 0;
$failures = 0;
$skips = 0;
foreach ($classes as $class) {
    /** @var TestCase $instance */
    $instance = new $class();
    foreach ($instance->run() as [$status, $method, $message]) {
        $total++;
        if ($status === true) {
            echo "[PASS] {$class}::{$method}\n";
        } elseif ($status === 'skip') {
            $skips++;
            echo "[SKIP] {$class}::{$method} - {$message}\n";
        } else {
            $failures++;
            echo "[FAIL] {$class}::{$method} - {$message}\n";
        }
    }
}

echo "\nTests: {$total}, Failures: {$failures}, Skipped: {$skips}\n";
exit($failures === 0 ? 0 : 1);
