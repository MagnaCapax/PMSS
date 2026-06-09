<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/trafficLimit.php';

final class TrafficLimitCliWrapperCharacterizationTest extends TestCase
{
    public function testUsageTextKeepsSupportedFormsAndNotes(): void
    {
        foreach ([
            ['userTrafficLimit.php', 'limit', 'Limit unit is GiB (monthly quota).', 'Use 0 (or --unset) to remove a limit.'],
            ['userBonusTraffic.php', 'bonus', 'Bonus unit is GiB (monthly quota add-on).', 'Use 0 (or --unset) to remove the bonus.'],
        ] as [$script, $option, $unitNote, $removeNote]) {
            $this->assertOrderedStrings(
                [
                    'Usage:',
                    "  ./{$script} --user=<username> --{$option}=<GiB>",
                    "  ./{$script} --user=<username> --show",
                    "  ./{$script} --user=<username> --unset",
                    "  ./{$script} <username> <GiB>",
                    'Notes:',
                    '  - '.$unitNote,
                    '  - '.$removeNote,
                ],
                \pmssUserGiBSettingUsageText($script, $option, $unitNote, $removeNote),
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
}
