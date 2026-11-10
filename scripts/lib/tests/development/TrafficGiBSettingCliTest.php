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
        $definitions = $this->gibSettingDefinitions();
        $definition = $definitions['traffic-limit'];
        $trafficCases = [
            'traffic-limit show' => $this->gibSettingCliCase('traffic', $definition, ['--show'], "Traffic limit for fixtur01: 15 GiB\n", ['runtime' => "15GiB\n"], ['home' => null, 'runtime' => '15GiB']),
            'traffic-limit help' => $this->gibSettingCliCase('traffic', $definition, ['--help'], implode("\n", $this->gibSettingUsageLines($definition))."\n", [], ['home' => null, 'runtime' => null]),
            'traffic-limit set' => $this->gibSettingCliCase('traffic', $definition, ['--limit=20'], "Traffic limit for fixtur01 set at 20 GiB\n", [], ['home' => '20', 'runtime' => '20'], ['home' => 0664, 'runtime' => 0600], [['fixtur01', 'traffic limit set to 20 GiB (monthly quota)']]),
            'traffic-limit unset' => $this->gibSettingCliCase('traffic', $definition, ['--unset'], "Traffic limit for fixtur01 set at 0 GiB\n", ['runtime' => "8\n", 'home' => "8\n"], ['home' => null, 'runtime' => null], [], [['fixtur01', 'traffic limit unset (GiB quota removed)']]),
            'traffic-limit zero' => $this->gibSettingCliCase('traffic', $definition, ['--limit=0'], "Traffic limit for fixtur01 set at 0 GiB\n", ['runtime' => "8\n", 'home' => "8\n"], ['home' => null, 'runtime' => null], [], [['fixtur01', 'traffic limit unset (GiB quota removed)']]),
        ];

        $definition = $definitions['bonus'];
        // The root store holds the bonus; the home file is its display copy and is never read (ADR 0213).
        $bonusCases = [
            'bonus show' => $this->gibSettingCliCase('bonus', $definition, ['--show'], "Bonus traffic for fixtur01: 15 GiB\n", ['home' => "900\n"], ['home' => '900']) + ['existingStore' => 15, 'store' => 15],
            'bonus help' => $this->gibSettingCliCase('bonus', $definition, ['--help'], implode("\n", $this->gibSettingUsageLines($definition))."\n", [], ['home' => null]),
            'bonus set' => $this->gibSettingCliCase('bonus', $definition, ['--bonus=20'], "Bonus traffic for fixtur01 set to 20 GiB\n", [], ['home' => '20'], [], [['fixtur01', 'bonus traffic set to 20 GiB (monthly add-on)']]) + ['store' => 20],
            'bonus unset' => $this->gibSettingCliCase('bonus', $definition, ['--unset'], "Bonus traffic for fixtur01 set to 0 GiB\n", ['home' => "9\n"], ['home' => null], [], [['fixtur01', 'bonus traffic unset (GiB add-on removed)']]) + ['existingStore' => 9, 'store' => null],
            'bonus zero' => $this->gibSettingCliCase('bonus', $definition, ['--bonus=0'], "Bonus traffic for fixtur01 set to 0 GiB\n", ['home' => "9\n"], ['home' => null], [], [['fixtur01', 'bonus traffic unset (GiB add-on removed)']]) + ['existingStore' => 9, 'store' => null],
        ];
        foreach ($bonusCases as &$case) {
            $case['fixture']['storeKey'] = 'bonusTrafficGiB';
            if (array_key_exists('existingStore', $case)) $case['fixture']['existingStore'] = $case['existingStore'];
            unset($case['existingStore']);
        }
        unset($case);

        foreach (array_merge($trafficCases, $bonusCases) as $label => $case) {
            $result = $this->pmssRunUserGiBSettingCliFixture($case['fixture']);

            $this->assertEquals(0, $result['rc'], $label);
            $this->assertEquals($case['stdout'], $result['stdout'], $label);
            $this->assertEquals($case['files'], $result['files'], $label);
            foreach ($case['modes'] as $key => $expectedMode) {
                $this->assertEquals($expectedMode, $result['modes'][$key], $label.' '.$key.' mode');
            }
            $this->assertEquals($case['logs'], $result['logs'], $label);
            if (array_key_exists('store', $case)) $this->assertSame($case['store'], $result['store'], $label.' store');
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
                    'pmssIntegerSettingFileWrite($target, $trafficLimit)',
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
        foreach ([
            'trafficLimit.php' => ['pmssTrafficLimitPersistTargetModes'],
            'trafficLimitCli.php' => ['pmssUserGiBSettingCli', 'pmssUserGiBSettingUsageText', 'pmssUserTrafficCliBootstrap'],
            'trafficLimitCommands.php' => ['pmssTrafficLimitCliTargetModes', 'pmssUserTrafficLimitCli', 'pmssUserBonusTrafficCli'],
        ] as $file => $functions) {
            foreach ($functions as $function) {
                $this->assertSame($this->pmssRepoPath('scripts/lib/user/'.$file), (new \ReflectionFunction($function))->getFileName());
            }
        }
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/trafficLimit.php' => ['forbidden' => [
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
                    'pmssIntegerSettingFileWrite($bonusFile',
                ],
            ],
        ]);
    }

    public function testHomeArtifactReconcileRestoresMissingTrafficLimitFromRuntime(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-home-');
        $runtimeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-runtime-');
        @mkdir($homeRoot.'/fixtur01', 0755, true);
        @mkdir($runtimeRoot.'/trafficLimits', 0700, true);
        $this->pmssWriteFile($runtimeRoot.'/trafficLimits/fixtur01', "750GiB\n");
        $logs = array();

        $this->assertTrue(\pmssTrafficLimitHomeArtifactReconcile(
            'fixtur01',
            $homeRoot,
            $runtimeRoot,
            $this->pmssMakeArrayLogger($logs)
        ));

        $this->assertSame('750', trim((string) @file_get_contents($homeRoot.'/fixtur01/.trafficLimit')));
        $this->assertSame(0664, @fileperms($homeRoot.'/fixtur01/.trafficLimit') & 0777);
        $this->assertSame(array('traffic limit home artifact reconciled from runtime limit'), $logs);
    }

    public function testHomeArtifactReconcileDoesNotOverwriteExistingTrafficLimit(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-home-');
        $runtimeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-runtime-');
        @mkdir($homeRoot.'/fixtur01', 0755, true);
        @mkdir($runtimeRoot.'/trafficLimits', 0700, true);
        $this->pmssWriteFile($homeRoot.'/fixtur01/.trafficLimit', "25\n");
        $this->pmssWriteFile($runtimeRoot.'/trafficLimits/fixtur01', "750\n");

        $this->assertTrue(\pmssTrafficLimitHomeArtifactReconcile('fixtur01', $homeRoot, $runtimeRoot));
        $this->assertSame('25', trim((string) @file_get_contents($homeRoot.'/fixtur01/.trafficLimit')));
    }

    public function testHomeArtifactReconcileRejectsInvalidRuntimeTrafficLimit(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-home-');
        $runtimeRoot = $this->pmssMakeTempDir('pmss-traffic-reconcile-runtime-');
        @mkdir($homeRoot.'/fixtur01', 0755, true);
        @mkdir($runtimeRoot.'/trafficLimits', 0700, true);
        $this->pmssWriteFile($runtimeRoot.'/trafficLimits/fixtur01', "-1\n");
        $logs = array();

        $this->assertFalse(\pmssTrafficLimitHomeArtifactReconcile(
            'fixtur01',
            $homeRoot,
            $runtimeRoot,
            $this->pmssMakeArrayLogger($logs)
        ));

        $this->assertFalse(file_exists($homeRoot.'/fixtur01/.trafficLimit'));
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
                'runtimeFile' => 'fixtur01',
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
                'user' => 'fixtur01',
                'argv' => in_array('--help', $args, true)
                    ? array_merge([$definition['script']], $args)
                    : array_merge([$definition['script'], '--user=fixtur01'], $args),
                'existingFiles' => $existingFiles,
            ], $fixtures[$kind]),
            'stdout' => $stdout,
            'files' => $files,
            'modes' => $modes,
            'logs' => $logs,
        ];
    }

}
