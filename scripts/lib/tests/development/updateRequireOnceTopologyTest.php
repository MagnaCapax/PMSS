<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateRequireOnceTopologyTest extends TestCase
{
    public function testUpdaterHelperModulesLoadTogetherViaRequireOnce(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $script = <<<'PHP'
$repoRoot = __REPO_ROOT__;
putenv('PMSS_TEST_MODE=1');

require_once $repoRoot.'/scripts/lib/update/repositories.php';
require_once $repoRoot.'/scripts/lib/update.php';
require_once $repoRoot.'/scripts/lib/update/networking.php';
require_once $repoRoot.'/scripts/lib/update/environment.php';
require_once $repoRoot.'/scripts/lib/update/distro.php';
require_once $repoRoot.'/scripts/lib/update/osRelease.php';

$functions = [
    'getOsReleaseData',
    'getDistroName',
    'getDistroVersion',
    'pmssResetOsReleaseCache',
    'getDistroCodename',
    'pmssDebianCodenameFromMajor',
    'pmssDetectDistro',
    'pmssVersionFromCodename',
    'pmssEnsureNetworkTemplate',
    'pmssPruneLegacyMediaArea',
    'pmssConfigureAptNonInteractive',
    'pmssCompletePendingDpkg',
    'pmssSelectDpkgSelectionsBaseline',
    'pmssApplyDpkgSelections',
    'pmssMigrateLegacyLocalnet',
    'pmssEnsureMediaareaRepository',
    'pmssEnsureSonarrKey',
    'pmssEnsureDockerRepository',
    'pmssRepositoryUpdatePlan',
    'pmssRefreshRepositories',
];

foreach ($functions as $functionName) {
    if (!function_exists($functionName)) {
        fwrite(STDERR, 'missing '.$functionName);
        exit(1);
    }
}

echo 'ok';
PHP;

        $script = str_replace('__REPO_ROOT__', var_export($repoRoot, true), $script);
        $command = 'PMSS_TEST_MODE=1 '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' 2>&1';

        $this->assertEquals('ok', trim((string) @shell_exec($command)));
    }
}
