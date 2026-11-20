<?php
/** Verify monthly IOPS enforcement reads the resource writer's runtime cache. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class IopsLimitUsagePathTest extends TestCase
{
    /** Observe the real enforcement loop with account I/O and commands intercepted. */
    private function observe(?string $runtime, int $usage): array
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/iopsLimitEnforcer.php');
        $source = substr($source, strpos($source, 'function pmssIopsLimitBuildThrottleCommand'));
        $script = 'namespace IopsLimitUsageFixture; require_once '
            .var_export(dirname(__DIR__, 2).'/user/iopsLimit.php', true).';';
        $script .= <<<'PHP'
class UserConfigStore {}
function pmssListManagedUsersResult($path) { return []; }
function pmssListManagedUsersFromResult($result) { return ['fixtur01']; }
function pmssIntegerSettingFileRead($path, $parser) {
    $GLOBALS['observed']['limit'] = $path;
    return 1000;
}
function pmssReadUserMonthlyIopsUsage($path) {
    $GLOBALS['observed']['usage'] = $path;
    return $GLOBALS['usage'];
}
function pmssRegularFilePathIsReadable($path) {
    $GLOBALS['observed']['marker'] = $path;
    return false;
}
function pmssBuildCommand($binary, $args) { return $binary.' '.implode(' ', $args); }
function runStep($message, $command) {
    $GLOBALS['observed']['commands'][] = $command;
    // Refuse application so the loop never publishes markers or account logs.
    return 1;
}
PHP;
        // Retain real path resolvers and enforcement policy, replacing only I/O.
        $script .= 'eval('.var_export('namespace IopsLimitUsageFixture;'.$source, true).');';
        $script .= $runtime === null ? 'putenv("PMSS_RUNTIME_DIR");'
            : 'putenv('.var_export('PMSS_RUNTIME_DIR='.$runtime, true).');';
        $script .= '$GLOBALS["usage"] = '.var_export($usage, true).';'
            .'$GLOBALS["observed"] = ["commands" => []];'
            .'$GLOBALS["observed"]["rc"] = pmssIopsLimitsRun();'
            .'echo json_encode($GLOBALS["observed"]);';
        return $this->pmssRunInlinePhpJson($script);
    }

    public function testUsageUsesWriterDefaultAndOverrideWithoutMovingSettingBuckets(): void
    {
        foreach ([
            [null, '/var/run/pmss', '/etc/seedbox/runtime'],
            ['', '/var/run/pmss', '/etc/seedbox/runtime'],
            ['/fixture/runtime', '/fixture/runtime', '/fixture/runtime'],
            ['/fixture/runtime///', '/fixture/runtime', '/fixture/runtime'],
            ['/etc/seedbox/runtime', '/etc/seedbox/runtime', '/etc/seedbox/runtime'],
        ] as [$runtime, $resourceRoot, $settingRoot]) {
            $result = $this->observe($runtime, 2000);
            $this->assertSame($resourceRoot.'/resourceStats/fixtur01', $result['usage']);
            $this->assertSame($settingRoot.'/iopsLimits/fixtur01', $result['limit']);
            $this->assertSame($settingRoot.'/iopsLimitEnforced/fixtur01', $result['marker']);
            $this->assertSame(1, count($result['commands']));
            $this->assertSame(0, $result['rc']);
        }
    }

    public function testAlignedUsageRetainsStrictMonthlyLimitBoundary(): void
    {
        foreach ([[0, 0], [999, 0], [1000, 0], [1001, 1]] as [$usage, $commands]) {
            $result = $this->observe(null, $usage);
            $this->assertSame('/var/run/pmss/resourceStats/fixtur01', $result['usage']);
            $this->assertSame($commands, count($result['commands']));
            $this->assertSame(0, $result['rc']);
        }
    }
}
