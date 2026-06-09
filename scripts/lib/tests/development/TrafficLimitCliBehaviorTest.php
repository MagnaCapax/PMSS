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

    public function testTrafficLimitCliModesPreserveContracts(): void
    {
        foreach ($this->trafficLimitCliCases() as $label => $case) {
            $result = $this->runTrafficLimitCli($case['argv'], $case['existing']);

            $this->assertEquals(0, $result['rc'], $label);
            $this->assertEquals($case['stdout'], $result['stdout'], $label);
            $this->assertEquals($case['files'], $result['files'], $label);
            foreach ($case['modes'] as $key => $expectedMode) {
                $this->assertEquals($expectedMode, $result['modes'][$key], $label.' '.$key.' mode');
            }
            $this->assertEquals($case['logs'], $result['logs'], $label);
        }
    }

    /**
     * Execute the traffic limit CLI with hermetic runtime and home targets.
     *
     * @param array<string,string> $existingFiles
     * @return array{rc:int,stdout:string,files:array<string,?string>,modes:array<string,?int>,logs:array<int,array<int,string>>}
     */
    private function runTrafficLimitCli(array $argv, array $existingFiles = []): array
    {
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
        ]);
    }

    /** @return array<string,array{argv:array<int,string>,existing:array<string,string>,stdout:string,files:array<string,?string>,modes:array<string,int>,logs:array<int,array<int,string>>}> */
    private function trafficLimitCliCases(): array
    {
        return [
            'show' => [
                'argv' => ['userTrafficLimit.php', '--user=alice', '--show'],
                'existing' => ['runtime' => "15GiB\n"],
                'stdout' => "Traffic limit for alice: 15 GiB\n",
                'files' => ['home' => null, 'runtime' => '15GiB'],
                'modes' => [],
                'logs' => [],
            ],
            'help' => [
                'argv' => ['userTrafficLimit.php', '--help'],
                'existing' => [],
                'stdout' => $this->trafficLimitUsageText(),
                'files' => ['home' => null, 'runtime' => null],
                'modes' => [],
                'logs' => [],
            ],
            'set' => [
                'argv' => ['userTrafficLimit.php', '--user=alice', '--limit=20'],
                'existing' => [],
                'stdout' => "Traffic limit for alice set at 20 GiB\n",
                'files' => ['home' => '20', 'runtime' => '20'],
                'modes' => ['home' => 0664, 'runtime' => 0600],
                'logs' => [['alice', 'traffic limit set to 20 GiB (monthly quota)']],
            ],
            'unset' => [
                'argv' => ['userTrafficLimit.php', '--user=alice', '--unset'],
                'existing' => ['runtime' => "8\n", 'home' => "8\n"],
                'stdout' => "Traffic limit for alice set at 0 GiB\n",
                'files' => ['home' => null, 'runtime' => null],
                'modes' => [],
                'logs' => [['alice', 'traffic limit unset (GiB quota removed)']],
            ],
        ];
    }

    private function trafficLimitUsageText(): string
    {
        return "Usage:\n"
            ."  ./userTrafficLimit.php --user=<username> --limit=<GiB>\n"
            ."  ./userTrafficLimit.php --user=<username> --show\n"
            ."  ./userTrafficLimit.php --user=<username> --unset\n"
            ."  ./userTrafficLimit.php <username> <GiB>\n\n"
            ."Notes:\n"
            ."  - Limit unit is GiB (monthly quota).\n"
            ."  - Use 0 (or --unset) to remove a limit.\n";
    }
}
