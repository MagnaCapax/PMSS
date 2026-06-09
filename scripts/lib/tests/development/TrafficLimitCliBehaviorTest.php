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
        $this->assertEquals('15GiB', $result['files']['runtime']);
        $this->assertEquals(null, $result['files']['home']);
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
        $this->assertEquals(null, $result['files']['runtime']);
        $this->assertEquals(null, $result['files']['home']);
        $this->assertEquals([], $result['logs']);
    }

    public function testSetModeWritesBothTargetsAndLogsChange(): void
    {
        $result = $this->runTrafficLimitCli(['userTrafficLimit.php', '--user=alice', '--limit=20']);

        $this->assertEquals(0, $result['rc']);
        $this->assertEquals("Traffic limit for alice set at 20 GiB\n", $result['stdout']);
        $this->assertEquals('20', $result['files']['runtime']);
        $this->assertEquals('20', $result['files']['home']);
        $this->assertEquals(0600, $result['modes']['runtime']);
        $this->assertEquals(0664, $result['modes']['home']);
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
        $this->assertEquals(null, $result['files']['runtime']);
        $this->assertEquals(null, $result['files']['home']);
        $this->assertEquals([['alice', 'traffic limit unset (GiB quota removed)']], $result['logs']);
    }

    /**
     * Execute the traffic limit CLI with hermetic runtime and home targets.
     *
     * @param array<string,string> $existingFiles
     * @return array{rc:int,stdout:string,files:array<string,?string>,modes:array<string,?int>,logs:array<int,array<int,string>>}
     */
    private function runTrafficLimitCli(array $argv, array $existingFiles = [], ?string $usage = ''): array
    {
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

        return $this->pmssRunUserGiBSettingCliFixture([
            'argv' => $argv,
            'library' => 'scripts/lib/user/trafficLimit.php',
            'function' => 'pmssUserTrafficLimitCli',
            'homeFile' => '.trafficLimit',
            'runtimeFile' => 'alice',
            'homePrefix' => 'pmss-traffic-home-',
            'runtimePrefix' => 'pmss-traffic-runtime-',
            'homeGlobal' => 'PMSS_TRAFFIC_LIMIT_TEST_HOME',
            'runtimeGlobal' => 'PMSS_TRAFFIC_LIMIT_TEST_RUNTIME',
            'logGlobal' => 'PMSS_TRAFFIC_LIMIT_TEST_LOGS',
            'existingFiles' => $existingFiles,
            'usage' => $usage,
            'passUsage' => true,
        ]);
    }
}
