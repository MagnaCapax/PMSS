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

    public function testLibraryKeepsBonusFileOpsOnSharedTrafficHelpers(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/bonusTraffic.php');

        $this->assertStringContainsString('pmssUserGiBSettingCli($argv, [', $source);
        $this->assertStringContainsString("'valueOption'         => 'bonus'", $source);
        $this->assertStringContainsString("'targetModesResolver' => static function", $source);
        $this->pmssAssertStringNotContainsString('function '.'pmssBonusTraffic'.'ReadGiB(', $source);
        $this->pmssAssertStringNotContainsString('function '.'pmssBonusTraffic'.'WriteGiB(', $source);
        $this->pmssAssertStringNotContainsString('function '.'pmssBonusTraffic'.'Remove(', $source);
        $this->pmssAssertStringNotContainsString('pmssParseCliTokens($argv)', $source);
    }

    /**
     * Execute the bonus traffic CLI in a subprocess with hermetic path stubs.
     *
     * @return array{rc:int,stdout:string,file:?string,logs:array<int,array<int,string>>}
     */
    private function runBonusTrafficCli(array $argv, string $existingContents = ''): array
    {
        $repoRoot = dirname(__DIR__, 4);
        $homeDir = $this->pmssMakeTempDir('pmss-bonus-home-').'/alice';
        @mkdir($homeDir, 0755, true);

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

    return ['user' => $userName, 'home' => $GLOBALS['PMSS_BONUS_TEST_HOME']];
}

function pmssUserLog(string $userName, string $message): void
{
    $GLOBALS['PMSS_BONUS_TEST_LOGS'][] = [$userName, $message];
}

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
            ['__HOME_DIR__', '__ARGV__', '__REPO_ROOT__'],
            [var_export($homeDir, true), var_export($argv, true), var_export($repoRoot, true)],
            $script
        );

        return $this->pmssRunInlinePhpJson($script);
    }
}
