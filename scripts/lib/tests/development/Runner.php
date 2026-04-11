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
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
            continue;
        }

        if ($item->isDir()) {
            @rmdir($item->getPathname());
            continue;
        }
    }

    @rmdir($path);
}

/**
 * Capture PMSS-prefixed temp artifacts so the suite can remove only what it created.
 *
 * Some older tests still allocate fixtures directly under sys_get_temp_dir()
 * instead of PMSS_TEST_TEMP_ROOT. Track those paths at the runner boundary so
 * the suite does not leak directories or files across repeated runs.
 *
 * @return array<int, string>
 */
function pmssTestListTempArtifacts(): array
{
    $paths = glob(sys_get_temp_dir().'/pmss-*');
    if ($paths === false) {
        return [];
    }

    sort($paths);
    return $paths;
}

/**
 * Remove PMSS-prefixed temp artifacts that were created during the current suite run.
 *
 * @param array<int, string> $before
 */
function pmssTestCleanupNewTempArtifacts(array $before): void
{
    $beforeMap = array_fill_keys($before, true);
    foreach (pmssTestListTempArtifacts() as $path) {
        if (isset($beforeMap[$path])) {
            continue;
        }

        pmssTestRemoveTree($path);
    }
}

$tempArtifactsBefore = pmssTestListTempArtifacts();
$suiteRoot = sys_get_temp_dir().'/pmss-tests-'.bin2hex(random_bytes(6));
$versionDir = $suiteRoot.'/version';
$testRoot    = $suiteRoot.'/root';
$skelDir     = $testRoot.'/skel';
$networkCfg  = $testRoot.'/network.php';
$localnetCfg = $testRoot.'/localnet';
$fireqosTpl  = $testRoot.'/fireqos.tpl';
$aptKeyring  = $testRoot.'/apt-keyrings';

foreach ([$suiteRoot, $versionDir, $testRoot, $aptKeyring] as $dir) {
    @mkdir($dir, 0755, true);
}

foreach ([
    'PMSS_VERSION_DIR' => $versionDir,
    'PMSS_TEST_TEMP_ROOT' => $suiteRoot,
    'PMSS_TEST_MODE' => '1',
    'PMSS_SKEL_DIR' => $skelDir,
    'PMSS_NETWORK_CONFIG' => $networkCfg,
    'PMSS_LOCALNET_FILE' => $localnetCfg,
    'PMSS_FIREQOS_TEMPLATE' => $fireqosTpl,
    'PMSS_APT_KEYRING_DIR' => $aptKeyring,
] as $key => $value) {
    putenv($key.'='.$value);
}

foreach (['PMSS_JSON_LOG', 'PMSS_PROFILE_OUTPUT'] as $key) {
    putenv($key);
}

register_shutdown_function(static function () use ($suiteRoot, $tempArtifactsBefore): void {
    pmssTestRemoveTree($suiteRoot);
    pmssTestCleanupNewTempArtifacts($tempArtifactsBefore);
});

foreach ([
    $skelDir.'/www/rutorrent/plugins/unpack',
    $skelDir.'/www/rutorrent/plugins/theme/themes',
    $skelDir.'/.irssi',
] as $dir) {
    @mkdir($dir, 0755, true);
}

foreach ([
    $skelDir.'/.irssi/config' => 'test',
    $skelDir.'/.rtorrent.rc.custom' => 'test',
    $networkCfg => "<?php return ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]];",
    $localnetCfg => "185.148.0.0/22\n",
    $fireqosTpl => "interface ##INTERFACE\nrate ##SPEED\n##LOCALNETWORK\n##USERMATCHES\n",
] as $path => $contents) {
    @file_put_contents($path, $contents);
}

define('PMSS_TEST_MODE', true);
require_once __DIR__.'/../common/updateBootstrapShim.php';

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
