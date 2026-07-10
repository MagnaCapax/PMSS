<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/trafficLimit.php';

final class TrafficGiBSettingCliTest extends TestCase
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

    public function testGiBSettingCliModesPreserveContracts(): void
    {
        foreach ($this->gibSettingCliCases() as $label => $case) {
            $result = $this->pmssRunUserGiBSettingCliFixture($case['fixture']);

            $this->assertEquals(0, $result['rc'], $label);
            $this->assertEquals($case['stdout'], $result['stdout'], $label);
            $this->assertEquals($case['files'], $result['files'], $label);
            foreach ($case['modes'] as $key => $expectedMode) {
                $this->assertEquals($expectedMode, $result['modes'][$key], $label.' '.$key.' mode');
            }
            $this->assertEquals($case['logs'], $result['logs'], $label);
        }
    }

    public function testUsageTextKeepsSupportedFormsAndNotes(): void
    {
        foreach ($this->gibSettingDefinitions() as $definition) {
            $this->assertOrderedStrings(
                array_values(array_filter($this->gibSettingUsageLines($definition), 'strlen')),
                \pmssUserGiBSettingUsageText(
                    $definition['script'],
                    $definition['option'],
                    $definition['unitNote'],
                    $definition['removeNote']
                ),
                'Missing usage line: ',
                'Usage line order changed at: '
            );
        }
    }

    public function testUtilityWrapperKeepsUsageTextButDelegatesExecution(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/userTrafficLimit.php' => [
                'required' => [
                    "require_once __DIR__.'/../lib/runtime.php';",
                    "require_once __DIR__.'/../lib/user/trafficLimit.php';",
                    "pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserTrafficLimitCli');",
                ],
                'forbidden' => [
                    'pmssParseCliTokens($argv',
                    'pmssTrafficLimitWriteGiBFile($target, $trafficLimit)',
                    '  ./userTrafficLimit.php --user=<username> --limit=<GiB>',
                ],
            ],
            'scripts/util/userBonusTraffic.php' => [
                'required' => [
                    "require_once __DIR__.'/../lib/user/trafficLimit.php';",
                    "pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserBonusTrafficCli');",
                ],
                'forbidden' => [
                    "require_once '/scripts/lib/user/bonusTraffic.php';",
                    'pmssParseCliTokens($argv',
                    '  ./userBonusTraffic.php --user=<username> --bonus=<GiB>',
                ],
            ],
        ]);
    }

    public function testLibraryOwnsTheGiBSettingCliImplementations(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/trafficLimit.php' => ['required' => [
                'function pmssUserGiBSettingCli(array $argv, array $spec): int',
                'function pmssUserGiBSettingUsageText(',
                'function pmssTrafficLimitCliTargetModes(string $userName, string $homeDir): array',
                'function pmssTrafficLimitPersistTargetModes(array $targetModes, int $value, ?string &$error = null): bool',
                'function pmssUserTrafficCliBootstrap(): bool',
                'function pmssUserTrafficLimitCli(array $argv, ?string $usage = null): int',
                'function pmssUserBonusTrafficCli(array $argv): int',
                "'targetModesResolver' => 'pmssTrafficLimitCliTargetModes'",
                "'targetModesResolver' => static function",
                'traffic limit set to %d GiB (monthly quota)',
                'bonus traffic set to %d GiB (monthly add-on)',
            ], 'forbidden' => [
                'function '.'pmssBonusTraffic'.'ReadGiB(',
                'function '.'pmssBonusTraffic'.'WriteGiB(',
                'function '.'pmssBonusTraffic'.'Remove(',
            ]],
            'scripts/lib/user/bonusTraffic.php' => [
                'required' => [
                    'Backward-compatible bonus traffic entrypoint.',
                    "require_once __DIR__.'/trafficLimit.php';",
                ],
                'forbidden' => [
                    'function pmssUserBonusTrafficCli(array $argv): int',
                    'pmssParseCliTokens($argv)',
                    'pmssTrafficLimitWriteGiBFile($bonusFile',
                ],
            ],
        ]);
    }

    public function testHomeArtifactReconcileRestoresMissingTrafficLimitFromRuntime(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-home-');
        $runtimeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-runtime-');
        @mkdir($homeRoot.'/alice', 0755, true);
        @mkdir($runtimeRoot.'/trafficLimits', 0700, true);
        $this->pmssWriteFile($runtimeRoot.'/trafficLimits/alice', "750GiB\n");
        $logs = array();

        $this->assertTrue(\pmssTrafficLimitHomeArtifactReconcile(
            'alice',
            $homeRoot,
            $runtimeRoot,
            static function (string $message) use (&$logs): void {
                $logs[] = $message;
            }
        ));

        $this->assertSame('750', trim((string) @file_get_contents($homeRoot.'/alice/.trafficLimit')));
        $this->assertSame(0664, @fileperms($homeRoot.'/alice/.trafficLimit') & 0777);
        $this->assertSame(array('traffic limit home artifact reconciled from runtime limit'), $logs);
    }

    public function testHomeArtifactReconcileDoesNotOverwriteExistingTrafficLimit(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-home-');
        $runtimeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-runtime-');
        @mkdir($homeRoot.'/alice', 0755, true);
        @mkdir($runtimeRoot.'/trafficLimits', 0700, true);
        $this->pmssWriteFile($homeRoot.'/alice/.trafficLimit', "25\n");
        $this->pmssWriteFile($runtimeRoot.'/trafficLimits/alice', "750\n");

        $this->assertTrue(\pmssTrafficLimitHomeArtifactReconcile('alice', $homeRoot, $runtimeRoot));
        $this->assertSame('25', trim((string) @file_get_contents($homeRoot.'/alice/.trafficLimit')));
    }

    public function testHomeArtifactReconcileRejectsInvalidRuntimeTrafficLimit(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-home-');
        $runtimeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-runtime-');
        @mkdir($homeRoot.'/alice', 0755, true);
        @mkdir($runtimeRoot.'/trafficLimits', 0700, true);
        $this->pmssWriteFile($runtimeRoot.'/trafficLimits/alice', "-1\n");
        $logs = array();

        $this->assertFalse(\pmssTrafficLimitHomeArtifactReconcile(
            'alice',
            $homeRoot,
            $runtimeRoot,
            static function (string $message) use (&$logs): void {
                $logs[] = $message;
            }
        ));

        $this->assertFalse(file_exists($homeRoot.'/alice/.trafficLimit'));
        $this->assertSame(array('traffic limit home artifact skipped: invalid runtime limit (invalid format)'), $logs);
    }

    /** @return array<string,array<string,string>> */
    private function gibSettingDefinitions(): array
    {
        return [
            'traffic-limit' => [
                'script' => 'userTrafficLimit.php',
                'option' => 'limit',
                'unitNote' => 'Limit unit is GiB (monthly quota).',
                'removeNote' => 'Use 0 (or --unset) to remove a limit.',
            ],
            'bonus' => [
                'script' => 'userBonusTraffic.php',
                'option' => 'bonus',
                'unitNote' => 'Bonus unit is GiB (monthly quota add-on).',
                'removeNote' => 'Use 0 (or --unset) to remove the bonus.',
            ],
        ];
    }

    /** @param array<string,string> $definition @return array<int,string> */
    private function gibSettingUsageLines(array $definition): array
    {
        return [
            'Usage:',
            "  ./{$definition['script']} --user=<username> --{$definition['option']}=<GiB>",
            "  ./{$definition['script']} --user=<username> --show",
            "  ./{$definition['script']} --user=<username> --unset",
            "  ./{$definition['script']} <username> <GiB>",
            '',
            'Notes:',
            '  - '.$definition['unitNote'],
            '  - '.$definition['removeNote'],
        ];
    }

    /** @param array<string,string> $definition */
    private function gibSettingUsageText(array $definition): string
    {
        return implode("\n", $this->gibSettingUsageLines($definition))."\n";
    }

    /**
     * @return array<string,array{fixture:array<string,mixed>,stdout:string,files:array<string,?string>,modes:array<string,int>,logs:array<int,array<int,string>>}>
     */
    private function gibSettingCliCases(): array
    {
        $definitions = $this->gibSettingDefinitions();
        return array_merge(
            $this->trafficLimitCliCases($definitions['traffic-limit']),
            $this->bonusTrafficCliCases($definitions['bonus'])
        );
    }

    /** @param array<string,string> $definition @return array<string,array<string,mixed>> */
    private function trafficLimitCliCases(array $definition): array
    {
        return [
            'traffic-limit show' => $this->gibSettingCliCase('traffic', $definition, ['--show'], "Traffic limit for alice: 15 GiB\n", ['runtime' => "15GiB\n"], ['home' => null, 'runtime' => '15GiB']),
            'traffic-limit help' => $this->gibSettingCliCase('traffic', $definition, ['--help'], $this->gibSettingUsageText($definition), [], ['home' => null, 'runtime' => null]),
            'traffic-limit set' => $this->gibSettingCliCase('traffic', $definition, ['--limit=20'], "Traffic limit for alice set at 20 GiB\n", [], ['home' => '20', 'runtime' => '20'], ['home' => 0664, 'runtime' => 0600], [['alice', 'traffic limit set to 20 GiB (monthly quota)']]),
            'traffic-limit unset' => $this->gibSettingCliCase('traffic', $definition, ['--unset'], "Traffic limit for alice set at 0 GiB\n", ['runtime' => "8\n", 'home' => "8\n"], ['home' => null, 'runtime' => null], [], [['alice', 'traffic limit unset (GiB quota removed)']]),
        ];
    }

    /** @param array<string,string> $definition @return array<string,array<string,mixed>> */
    private function bonusTrafficCliCases(array $definition): array
    {
        return [
            'bonus show' => $this->gibSettingCliCase('bonus', $definition, ['--show'], "Bonus traffic for alice: 15 GiB\n", ['home' => "15GiB\n"], ['home' => '15GiB']),
            'bonus help' => $this->gibSettingCliCase('bonus', $definition, ['--help'], $this->gibSettingUsageText($definition), [], ['home' => null]),
            'bonus set' => $this->gibSettingCliCase('bonus', $definition, ['--bonus=20'], "Bonus traffic for alice set to 20 GiB\n", [], ['home' => '20'], [], [['alice', 'bonus traffic set to 20 GiB (monthly add-on)']]),
            'bonus unset' => $this->gibSettingCliCase('bonus', $definition, ['--unset'], "Bonus traffic for alice set to 0 GiB\n", ['home' => "9\n"], ['home' => null], [], [['alice', 'bonus traffic unset (GiB add-on removed)']]),
        ];
    }

    /** Build one shared GiB-setting CLI fixture. */
    private function gibSettingCliCase(
        string $kind,
        array $definition,
        array $args,
        string $stdout,
        array $existingFiles,
        array $files,
        array $modes = [],
        array $logs = []
    ): array {
        $fixtures = [
            'traffic' => [
                'library' => 'scripts/lib/user/trafficLimit.php',
                'function' => 'pmssUserTrafficLimitCli',
                'homeFile' => '.trafficLimit',
                'runtimeFile' => 'alice',
                'homePrefix' => 'pmss-traffic-home-',
                'runtimePrefix' => 'pmss-traffic-runtime-',
                'homeGlobal' => 'PMSS_TRAFFIC_LIMIT_TEST_HOME',
                'runtimeGlobal' => 'PMSS_TRAFFIC_LIMIT_TEST_RUNTIME',
                'logGlobal' => 'PMSS_TRAFFIC_LIMIT_TEST_LOGS',
            ],
            'bonus' => [
                'library' => 'scripts/lib/user/bonusTraffic.php',
                'function' => 'pmssUserBonusTrafficCli',
                'homeFile' => '.bonusTraffic',
                'homePrefix' => 'pmss-bonus-home-',
                'homeGlobal' => 'PMSS_BONUS_TEST_HOME',
                'logGlobal' => 'PMSS_BONUS_TEST_LOGS',
            ],
        ];
        $this->assertTrue(isset($fixtures[$kind]), 'Unknown GiB setting fixture kind: '.$kind);

        return [
            'fixture' => array_merge([
                'argv' => $this->gibSettingArgv($definition['script'], $args),
                'existingFiles' => $existingFiles,
            ], $fixtures[$kind]),
            'stdout' => $stdout,
            'files' => $files,
            'modes' => $modes,
            'logs' => $logs,
        ];
    }

    /** @param array<int,string> $args @return array<int,string> */
    private function gibSettingArgv(string $script, array $args): array
    {
        return in_array('--help', $args, true)
            ? array_merge([$script], $args)
            : array_merge([$script, '--user=alice'], $args);
    }
}
