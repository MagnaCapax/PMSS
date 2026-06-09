<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class BonusTrafficTest extends TestCase
{
    public function testBonusTrafficCliModesPreserveContracts(): void
    {
        foreach ($this->bonusTrafficCliCases() as $label => $case) {
            $result = $this->runBonusTrafficCli($case['argv'], $case['existing']);

            $this->assertEquals(0, $result['rc'], $label);
            $this->assertEquals($case['stdout'], $result['stdout'], $label);
            $this->assertEquals($case['files'], $result['files'], $label);
            $this->assertEquals($case['logs'], $result['logs'], $label);
        }
    }

    public function testBonusTrafficCliLivesInSharedTrafficLimitLibrary(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/trafficLimit.php', [
            'function pmssUserBonusTrafficCli(array $argv): int',
            'pmssUserGiBSettingCli($argv, [',
            "'valueOption'         => 'bonus'",
            "'targetModesResolver' => static function",
        ]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/user/trafficLimit.php', [
            'function '.'pmssBonusTraffic'.'ReadGiB(',
            'function '.'pmssBonusTraffic'.'WriteGiB(',
            'function '.'pmssBonusTraffic'.'Remove(',
        ]);

        $this->pmssAssertRepoFileContainsString('scripts/lib/user/bonusTraffic.php', "require_once __DIR__.'/trafficLimit.php';");
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/user/bonusTraffic.php', [
            'function pmssUserBonusTrafficCli(array $argv): int',
            'pmssParseCliTokens($argv)',
        ]);
    }

    /**
     * Execute the bonus traffic CLI in a subprocess with hermetic path stubs.
     *
     * @return array{rc:int,stdout:string,files:array<string,?string>,modes:array<string,?int>,logs:array<int,array<int,string>>}
     */
    private function runBonusTrafficCli(array $argv, string $existingContents = ''): array
    {
        return $this->pmssRunUserGiBSettingCliFixture([
            'argv' => $argv,
            'library' => 'scripts/lib/user/bonusTraffic.php',
            'function' => 'pmssUserBonusTrafficCli',
            'homeFile' => '.bonusTraffic',
            'homePrefix' => 'pmss-bonus-home-',
            'homeGlobal' => 'PMSS_BONUS_TEST_HOME',
            'logGlobal' => 'PMSS_BONUS_TEST_LOGS',
            'existingFiles' => $existingContents !== '' ? ['home' => $existingContents] : [],
        ]);
    }

    /** @return array<string,array{argv:array<int,string>,existing:string,stdout:string,files:array<string,?string>,logs:array<int,array<int,string>>}> */
    private function bonusTrafficCliCases(): array
    {
        return [
            'show' => [
                'argv' => ['userBonusTraffic.php', '--user=alice', '--show'],
                'existing' => "15GiB\n",
                'stdout' => "Bonus traffic for alice: 15 GiB\n",
                'files' => ['home' => '15GiB'],
                'logs' => [],
            ],
            'help' => [
                'argv' => ['userBonusTraffic.php', '--help'],
                'existing' => '',
                'stdout' => $this->bonusTrafficUsageText(),
                'files' => ['home' => null],
                'logs' => [],
            ],
            'set' => [
                'argv' => ['userBonusTraffic.php', '--user=alice', '--bonus=20'],
                'existing' => '',
                'stdout' => "Bonus traffic for alice set to 20 GiB\n",
                'files' => ['home' => '20'],
                'logs' => [['alice', 'bonus traffic set to 20 GiB (monthly add-on)']],
            ],
            'unset' => [
                'argv' => ['userBonusTraffic.php', '--user=alice', '--unset'],
                'existing' => "9\n",
                'stdout' => "Bonus traffic for alice set to 0 GiB\n",
                'files' => ['home' => null],
                'logs' => [['alice', 'bonus traffic unset (GiB add-on removed)']],
            ],
        ];
    }

    private function bonusTrafficUsageText(): string
    {
        return "Usage:\n"
            ."  ./userBonusTraffic.php --user=<username> --bonus=<GiB>\n"
            ."  ./userBonusTraffic.php --user=<username> --show\n"
            ."  ./userBonusTraffic.php --user=<username> --unset\n"
            ."  ./userBonusTraffic.php <username> <GiB>\n\n"
            ."Notes:\n"
            ."  - Bonus unit is GiB (monthly quota add-on).\n"
            ."  - Use 0 (or --unset) to remove the bonus.\n";
    }
}
