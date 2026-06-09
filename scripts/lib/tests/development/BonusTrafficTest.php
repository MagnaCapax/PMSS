<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class BonusTrafficTest extends TestCase
{
    public function testShowModeReadsBonusThroughSharedTrafficHelper(): void
    {
        $result = $this->runBonusTrafficCli(
            ['userBonusTraffic.php', '--user=alice', '--show'],
            "15GiB\n"
        );

        $this->assertEquals(0, $result['rc']);
        $this->assertEquals("Bonus traffic for alice: 15 GiB\n", $result['stdout']);
        $this->assertEquals('15GiB', $result['files']['home']);
        $this->assertEquals([], $result['logs']);
    }

    public function testHelpModePrintsCanonicalUsageText(): void
    {
        $result = $this->runBonusTrafficCli(['userBonusTraffic.php', '--help']);

        $this->assertEquals(0, $result['rc']);
        $this->assertEquals(
            "Usage:\n"
            ."  ./userBonusTraffic.php --user=<username> --bonus=<GiB>\n"
            ."  ./userBonusTraffic.php --user=<username> --show\n"
            ."  ./userBonusTraffic.php --user=<username> --unset\n"
            ."  ./userBonusTraffic.php <username> <GiB>\n\n"
            ."Notes:\n"
            ."  - Bonus unit is GiB (monthly quota add-on).\n"
            ."  - Use 0 (or --unset) to remove the bonus.\n",
            $result['stdout']
        );
        $this->assertEquals(null, $result['files']['home']);
        $this->assertEquals([], $result['logs']);
    }

    public function testSetModeWritesBonusAndLogsChange(): void
    {
        $result = $this->runBonusTrafficCli(['userBonusTraffic.php', '--user=alice', '--bonus=20']);

        $this->assertEquals(0, $result['rc']);
        $this->assertEquals("Bonus traffic for alice set to 20 GiB\n", $result['stdout']);
        $this->assertEquals('20', $result['files']['home']);
        $this->assertEquals([['alice', 'bonus traffic set to 20 GiB (monthly add-on)']], $result['logs']);
    }

    public function testUnsetModeRemovesBonusAndLogsChange(): void
    {
        $result = $this->runBonusTrafficCli(
            ['userBonusTraffic.php', '--user=alice', '--unset'],
            "9\n"
        );

        $this->assertEquals(0, $result['rc']);
        $this->assertEquals("Bonus traffic for alice set to 0 GiB\n", $result['stdout']);
        $this->assertEquals(null, $result['files']['home']);
        $this->assertEquals([['alice', 'bonus traffic unset (GiB add-on removed)']], $result['logs']);
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
}
