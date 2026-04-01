<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class TrafficLimitCliBehaviorTest extends TestCase
{
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
    private function runTrafficLimitCli(array $argv, array $existingFiles = []): array
    {
        $repoRoot = dirname(__DIR__, 4);
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

        $script = <<<'PHP'
$runtimeDir = __RUNTIME_DIR__;
$homeDir = __HOME_DIR__;
$usage = __USAGE__;
$argv = __ARGV__;
$repoRoot = __REPO_ROOT__;
$GLOBALS['PMSS_TRAFFIC_LIMIT_TEST_LOGS'] = [];

function pmssRequireCli(string $message, $exitCode = null): bool
{
    return true;
}

function pmssTrafficLimitResolveCliUserHome($rawUserName, string $usage, ?int &$exitCode = null): ?array
{
    $exitCode = null;
    $userName = strtolower(trim((string) $rawUserName));
    if ($userName === '') {
        fwrite(STDERR, "Error: missing username.\n".$usage."\n");
        $exitCode = 2;
        return null;
    }

    return ['user' => $userName, 'home' => $GLOBALS['PMSS_TRAFFIC_LIMIT_TEST_HOME']];
}

function pmssTrafficLimitCliTargetModes(string $userName, string $homeDir): array
{
    return [
        $GLOBALS['PMSS_TRAFFIC_LIMIT_TEST_RUNTIME'].'/'.$userName => 0600,
        $homeDir.'/.trafficLimit' => 0664,
    ];
}

function pmssUserLog(string $userName, string $message): void
{
    $GLOBALS['PMSS_TRAFFIC_LIMIT_TEST_LOGS'][] = [$userName, $message];
}

$GLOBALS['PMSS_TRAFFIC_LIMIT_TEST_RUNTIME'] = $runtimeDir;
$GLOBALS['PMSS_TRAFFIC_LIMIT_TEST_HOME'] = $homeDir;

require $repoRoot.'/scripts/lib/user/trafficLimit.php';

ob_start();
$rc = pmssUserTrafficLimitCli($argv, $usage);
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
            ['__RUNTIME_DIR__', '__HOME_DIR__', '__USAGE__', '__ARGV__', '__REPO_ROOT__'],
            [
                var_export($runtimeDir, true),
                var_export($homeDir, true),
                var_export($usage, true),
                var_export($argv, true),
                var_export($repoRoot, true),
            ],
            $script
        );

        return $this->pmssRunInlinePhpJson($script);
    }
}
