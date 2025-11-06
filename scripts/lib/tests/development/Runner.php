<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

$versionDir = sys_get_temp_dir().'/pmss-tests-version';
if (!is_dir($versionDir)) {
    @mkdir($versionDir, 0755, true);
}
putenv('PMSS_VERSION_DIR='.$versionDir);
putenv('PMSS_JSON_LOG');
putenv('PMSS_PROFILE_OUTPUT');

$testRoot    = sys_get_temp_dir().'/pmss-tests-root';
$skelDir     = $testRoot.'/skel';
$networkCfg  = $testRoot.'/network.php';
$localnetCfg = $testRoot.'/localnet';
$fireqosTpl  = $testRoot.'/fireqos.tpl';
$usersDb     = $testRoot.'/users.json';
$aptKeyring  = $testRoot.'/apt-keyrings';

if (!is_dir($skelDir.'/www/rutorrent/plugins/unpack')) {
    @mkdir($skelDir.'/www/rutorrent/plugins/unpack', 0755, true);
    @mkdir($skelDir.'/www/rutorrent/plugins/theme/themes', 0755, true);
}
@file_put_contents($skelDir.'/.rtorrent.rc.custom', 'test');

@mkdir($testRoot, 0755, true);
@file_put_contents($networkCfg, "<?php return ['interface' => 'eth0', 'speed' => 1000, 'throttle' => ['max' => 100]];");
@file_put_contents($localnetCfg, "185.148.0.0/22\n");
@file_put_contents($fireqosTpl, "interface ##INTERFACE\nrate ##SPEED\n##LOCALNETWORK\n##USERMATCHES\n");

putenv('PMSS_SKEL_DIR='.$skelDir);
putenv('PMSS_NETWORK_CONFIG='.$networkCfg);
putenv('PMSS_LOCALNET_FILE='.$localnetCfg);
putenv('PMSS_FIREQOS_TEMPLATE='.$fireqosTpl);
putenv('PMSS_USERS_DB_FILE='.$usersDb);
putenv('PMSS_APT_KEYRING_DIR='.$aptKeyring);

define('PMSS_TEST_MODE', true);
require_once dirname(__DIR__, 3).'/update.php';

foreach (glob(__DIR__.'/*Test.php') as $testFile) {
    require_once $testFile;
}

$classes = array_filter(get_declared_classes(), static function ($class) {
    return is_subclass_of($class, TestCase::class);
});

$total = 0;
$failures = 0;
foreach ($classes as $class) {
    /** @var TestCase $instance */
    $instance = new $class();
    foreach ($instance->run() as [$status, $method, $message]) {
        $total++;
        if ($status) {
            echo "[PASS] {$class}::{$method}\n";
        } else {
            $failures++;
            echo "[FAIL] {$class}::{$method} - {$message}\n";
        }
    }
}

echo "\nTests: {$total}, Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
