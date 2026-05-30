<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class TrafficLimitCliBehaviorTest extends TestCase
{
    public function testCliFallbackHelpersWorkWithoutUserLifecycleBootstrap(): void
    {
        $script = <<<'PHP'
require __REPO_FILE__;

echo json_encode([
    'normalizeBefore' => function_exists('pmssUsernameNormalizeIfValid'),
    'lookupBefore' => function_exists('pmssUserAccountLookup'),
    'normalizedUser' => pmssTrafficLimitCliUsernameNormalize(' User1 '),
    'invalidUser' => pmssTrafficLimitCliUsernameNormalize('user-name'),
    'lookupRootDir' => (string) (pmssTrafficLimitCliUserAccountLookup('root')['dir'] ?? ''),
]);
PHP;

        $script = str_replace(
            '__REPO_FILE__',
            var_export($this->pmssRepoPath('scripts/lib/user/trafficLimit.php'), true),
            $script
        );
        $result = $this->pmssRunInlinePhpJson($script);

        $this->assertFalse($result['normalizeBefore']);
        $this->assertFalse($result['lookupBefore']);
        $this->assertSame('user1', $result['normalizedUser']);
        $this->assertSame(null, $result['invalidUser']);
        $this->assertTrue($result['lookupRootDir'] !== '');
    }

    public function testShowModeReadsRuntimeTargetFirst(): void
    {
        $result = $this->runTrafficLimitCli(
            ['userTrafficLimit.php', '--user=alice', '--show'],
            ['runtime' => "15GiB\n"]
        );

        $this->assertEquals(0, $result['rc']);
        $this->assertEquals("Traffic limit for alice: 15 GiB\n", $result['stdout']);
        $this->assertEquals('15GiB', $result['runtimeFile']);
        $this->assertEquals(null, $result['homeFile']);
        $this->assertEquals([], $result['logs']);
    }

    public function testHelpModePrintsCanonicalUsageText(): void
    {
        $result = $this->runTrafficLimitCli(['userTrafficLimit.php', '--help'], [], null);

        $this->assertEquals(0, $result['rc']);
        $this->assertEquals(
            "Usage:\n"
            ."  ./userTrafficLimit.php --user=<username> --limit=<GiB>\n"
            ."  ./userTrafficLimit.php --user=<username> --show\n"
            ."  ./userTrafficLimit.php --user=<username> --unset\n"
            ."  ./userTrafficLimit.php <username> <GiB>\n\n"
            ."Notes:\n"
            ."  - Limit unit is GiB (monthly quota).\n"
            ."  - Use 0 (or --unset) to remove a limit.\n",
            $result['stdout']
        );
        $this->assertEquals(null, $result['runtimeFile']);
        $this->assertEquals(null, $result['homeFile']);
        $this->assertEquals([], $result['logs']);
    }

    public function testSetModeWritesBothTargetsAndLogsChange(): void
    {
        $result = $this->runTrafficLimitCli(['userTrafficLimit.php', '--user=alice', '--limit=20']);

        $this->assertEquals(0, $result['rc']);
        $this->assertEquals("Traffic limit for alice set at 20 GiB\n", $result['stdout']);
        $this->assertEquals('20', $result['runtimeFile']);
        $this->assertEquals('20', $result['homeFile']);
        $this->assertEquals(0600, $result['runtimeMode']);
        $this->assertEquals(0664, $result['homeMode']);
        $this->assertEquals([['alice', 'traffic limit set to 20 GiB (monthly quota)']], $result['logs']);
    }

    public function testUnsetModeRemovesBothTargetsAndLogsChange(): void
    {
        $result = $this->runTrafficLimitCli(
            ['userTrafficLimit.php', '--user=alice', '--unset'],
            ['runtime' => "8\n", 'home' => "8\n"]
        );

        $this->assertEquals(0, $result['rc']);
        $this->assertEquals("Traffic limit for alice set at 0 GiB\n", $result['stdout']);
        $this->assertEquals(null, $result['runtimeFile']);
        $this->assertEquals(null, $result['homeFile']);
        $this->assertEquals([['alice', 'traffic limit unset (GiB quota removed)']], $result['logs']);
    }

    /**
     * Execute the traffic limit CLI with hermetic runtime and home targets.
     *
     * @param array<string,string> $existingFiles
     * @return array{rc:int,stdout:string,runtimeFile:?string,homeFile:?string,runtimeMode:?int,homeMode:?int,logs:array<int,array<int,string>>}
     */
    private function runTrafficLimitCli(array $argv, array $existingFiles = [], ?string $usage = ''): array
    {
        $repoRoot = $this->pmssRepoRoot();
        $runtimeDir = $this->pmssMakeTempDir('pmss-traffic-runtime-').'/trafficLimits';
        $homeDir = $this->pmssMakeTempDir('pmss-traffic-home-').'/alice';
        @mkdir($runtimeDir, 0755, true);
        @mkdir($homeDir, 0755, true);

        if (isset($existingFiles['runtime'])) {
            file_put_contents($runtimeDir.'/alice', $existingFiles['runtime']);
        }
        if (isset($existingFiles['home'])) {
            file_put_contents($homeDir.'/.trafficLimit', $existingFiles['home']);
        }

        if ($usage === '') {
            $usage = rtrim(<<<'TEXT'
Usage:
  ./userTrafficLimit.php --user=<username> --limit=<GiB>
  ./userTrafficLimit.php --user=<username> --show
  ./userTrafficLimit.php --user=<username> --unset
  ./userTrafficLimit.php <username> <GiB>

Notes:
  - Limit unit is GiB (monthly quota).
  - Use 0 (or --unset) to remove a limit.
TEXT
            );
        }

        $script = <<<'PHP'
$runtimeDir = __RUNTIME_DIR__;
$homeDir = __HOME_DIR__;
$usage = __USAGE__;
$argv = __ARGV__;
$repoRoot = __REPO_ROOT__;
$GLOBALS['PMSS_TRAFFIC_LIMIT_TEST_LOGS'] = [];
__TRAFFIC_CLI_SHIMS__

$GLOBALS['PMSS_TRAFFIC_LIMIT_TEST_RUNTIME'] = $runtimeDir;
$GLOBALS['PMSS_TRAFFIC_LIMIT_TEST_HOME'] = $homeDir;

require $repoRoot.'/scripts/lib/user/trafficLimit.php';

ob_start();
$rc = ($usage === null) ? pmssUserTrafficLimitCli($argv) : pmssUserTrafficLimitCli($argv, $usage);
$stdout = ob_get_clean();
$runtimeFile = $runtimeDir.'/alice';
$homeFile = $homeDir.'/.trafficLimit';

echo json_encode([
    'rc' => $rc,
    'stdout' => $stdout,
    'runtimeFile' => is_file($runtimeFile) ? trim((string) file_get_contents($runtimeFile)) : null,
    'homeFile' => is_file($homeFile) ? trim((string) file_get_contents($homeFile)) : null,
    'runtimeMode' => is_file($runtimeFile) ? (fileperms($runtimeFile) & 0777) : null,
    'homeMode' => is_file($homeFile) ? (fileperms($homeFile) & 0777) : null,
    'logs' => $GLOBALS['PMSS_TRAFFIC_LIMIT_TEST_LOGS'],
]);
PHP;

        $script = str_replace(
            ['__RUNTIME_DIR__', '__HOME_DIR__', '__USAGE__', '__ARGV__', '__REPO_ROOT__', '__TRAFFIC_CLI_SHIMS__'],
            [
                var_export($runtimeDir, true),
                var_export($homeDir, true),
                var_export($usage, true),
                var_export($argv, true),
                var_export($repoRoot, true),
                $this->pmssInlinePhpTrafficCliShims(
                    'PMSS_TRAFFIC_LIMIT_TEST_HOME',
                    'PMSS_TRAFFIC_LIMIT_TEST_LOGS',
                    'PMSS_TRAFFIC_LIMIT_TEST_RUNTIME'
                ),
            ],
            $script
        );

        return $this->pmssRunInlinePhpJson($script);
    }
}
