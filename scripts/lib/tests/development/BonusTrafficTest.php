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
        $this->assertEquals('15GiB', $result['file']);
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
        $this->assertEquals(null, $result['file']);
        $this->assertEquals([], $result['logs']);
    }

    public function testSetModeWritesBonusAndLogsChange(): void
    {
        $result = $this->runBonusTrafficCli(['userBonusTraffic.php', '--user=alice', '--bonus=20']);

        $this->assertEquals(0, $result['rc']);
        $this->assertEquals("Bonus traffic for alice set to 20 GiB\n", $result['stdout']);
        $this->assertEquals('20', $result['file']);
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
        $this->assertEquals(null, $result['file']);
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
     * @return array{rc:int,stdout:string,file:?string,logs:array<int,array<int,string>>}
     */
    private function runBonusTrafficCli(array $argv, string $existingContents = ''): array
    {
        $repoRoot = $this->pmssRepoRoot();
        $homeDir = $this->pmssEnsureDir($this->pmssMakeTempDir('pmss-bonus-home-').'/alice');

        $bonusFile = $homeDir.'/.bonusTraffic';
        if ($existingContents !== '') {
            file_put_contents($bonusFile, $existingContents);
        }

        $script = <<<'PHP'
$homeDir = __HOME_DIR__;
$argv = __ARGV__;
$repoRoot = __REPO_ROOT__;
$GLOBALS['PMSS_BONUS_TEST_HOME'] = $homeDir;
$GLOBALS['PMSS_BONUS_TEST_LOGS'] = [];
__TRAFFIC_CLI_SHIMS__

require $repoRoot.'/scripts/lib/user/bonusTraffic.php';

ob_start();
$rc = pmssUserBonusTrafficCli($argv);
$stdout = ob_get_clean();
$bonusFile = $homeDir.'/.bonusTraffic';

echo json_encode([
    'rc' => $rc,
    'stdout' => $stdout,
    'file' => is_file($bonusFile) ? trim((string) file_get_contents($bonusFile)) : null,
    'logs' => $GLOBALS['PMSS_BONUS_TEST_LOGS'],
]);
PHP;

        $script = str_replace(
            ['__HOME_DIR__', '__ARGV__', '__REPO_ROOT__', '__TRAFFIC_CLI_SHIMS__'],
            [
                var_export($homeDir, true),
                var_export($argv, true),
                var_export($repoRoot, true),
                $this->pmssInlinePhpTrafficCliShims('PMSS_BONUS_TEST_HOME', 'PMSS_BONUS_TEST_LOGS'),
            ],
            $script
        );

        return $this->pmssRunInlinePhpJson($script);
    }
}
