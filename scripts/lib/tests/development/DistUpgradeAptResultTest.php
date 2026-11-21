<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/distUpgrade.php';

/**
 * The apt phase of a dist-upgrade must report failure when apt fails, so update.php stops with
 * EXIT_DIST instead of logging "Dist-upgrade complete" on a host that never upgraded.
 */
class DistUpgradeAptResultTest extends TestCase
{
    public function testOnlyTheFullUpgradeResultDecidesTheAptPhase(): void
    {
        foreach ([
            'all succeed' => [[], true],
            'upgrade recovered' => [['ENV apt-get upgrade' => [1, 0]], true],
            // full-upgrade can resolve what upgrade could not
            'upgrade still failing, full-upgrade ok' => [['ENV apt-get upgrade' => [1, 1]], true],
            'full-upgrade recovered' => [['ENV apt-get full-upgrade' => [1, 0]], true],
            'full-upgrade still failing' => [['ENV apt-get full-upgrade' => [100, 100]], false],
            'full-upgrade lock timeout' => [['ENV apt-get full-upgrade' => [null]], false],
            'recovery lock timeout' => [['ENV apt-get full-upgrade' => [1], 'ENV apt-get -f install -y' => [null]], false],
            'initial update lock timeout' => [['ENV apt-get update' => [null]], false],
        ] as $scenario => [$rcs, $expected]) {
            $run = $this->runAptPhase($rcs);
            $this->assertSame($expected, $run['result'], $scenario);
            $failed = in_array('[ERROR] dist-upgrade: full-upgrade still failing after recovery', $run['logs'], true);
            $this->assertSame(!$expected && $scenario !== 'initial update lock timeout', $failed, $scenario.': error line');
        }
    }

    public function testFailedFullUpgradeSkipsAutoremove(): void
    {
        $run = $this->runAptPhase(['ENV apt-get full-upgrade' => [100, 100]]);
        $this->assertSame(2, count(array_keys($run['commands'], 'ENV apt-get full-upgrade', true)));
        $this->assertFalse(in_array('ENV apt-get autoremove -y', $run['commands'], true));
    }

    public function testDistUpgradeChecksTheReachedReleaseBeforeContinuing(): void
    {
        // os-release is cached per run; the check must read the file again after apt has replaced it.
        $this->pmssAssertRepoFileMatches(
            'scripts/lib/update/distUpgrade.php',
            '~\$aptOk = pmssExecuteUpgrade\(\);\s*pmssResetOsReleaseCache\(\);\s*\$reached = getDistroVersion\(\);\s*if \(!\$aptOk \|\| \$reached !== \$next\) \{\s*//[^\n]*\n\s*pmssEnsureInitrdAfterDistUpgrade\(\);.*?return 1;\s*\}\s*pmssEnsureInitrdAfterDistUpgrade\(\);~s'
        );
    }

    public function testAptStepsGetTheirOwnDeadlineAndRestoreTheCallersOne(): void
    {
        foreach ([
            // [PMSS_DIST_UPGRADE_APT_TIMEOUT, caller's PMSS_COMMAND_TIMEOUT, deadline seen by the command]
            'default two hours, caller unset' => [null, null, '7200'],
            'default two hours, caller set' => [null, '900', '7200'],
            'override' => ['14400', '900', '14400'],
            'no deadline' => ['0', null, '0'],
        ] as $scenario => [$aptTimeout, $callerTimeout, $seen]) {
            $run = $this->runLockedCommand($aptTimeout, $callerTimeout, false);
            $this->assertSame(['rc' => 0, 'seen' => $seen, 'after' => $callerTimeout ?? 'unset'], $run, $scenario);
        }
        // A step that throws still restores the caller's deadline.
        $this->assertSame(['rc' => 'threw', 'seen' => '7200', 'after' => '900'], $this->runLockedCommand(null, '900', true));
    }

    /** Run the real locked-command wrapper with a runCommand stub that records the deadline it sees. */
    private function runLockedCommand(?string $aptTimeout, ?string $callerTimeout, bool $throw): array
    {
        $script = 'namespace DistUpgradeAptDeadline;'
            .($aptTimeout === null ? 'putenv("PMSS_DIST_UPGRADE_APT_TIMEOUT");' : 'putenv("PMSS_DIST_UPGRADE_APT_TIMEOUT='.$aptTimeout.'");')
            .($callerTimeout === null ? 'putenv("PMSS_COMMAND_TIMEOUT");' : 'putenv("PMSS_COMMAND_TIMEOUT='.$callerTimeout.'");')
            .'$GLOBALS["seen"] = null; $GLOBALS["throw"] = '.var_export($throw, true).';'
            .'function pmssEnvReadDigits($key) { return (($v = getenv($key)) !== false && ctype_digit($v)) ? (int) $v : null; }'
            .'function pmssDistUpgradeWaitForLocksOrLog($message) { return true; }'
            .'function runCommand($command, $verbose, $logger, $tty) { $GLOBALS["seen"] = (string) getenv("PMSS_COMMAND_TIMEOUT"); if ($GLOBALS["throw"]) { throw new \RuntimeException("x"); } return 0; }';
        foreach (['pmssDistUpgradeAptTimeoutSeconds', 'pmssDistUpgradeRunLockedCommand'] as $name) {
            $script .= \pmssTestingFunctionExport($name, 'DistUpgradeAptDeadline');
        }
        $script .= 'try { $rc = pmssDistUpgradeRunLockedCommand("apt-get full-upgrade", "lock"); } catch (\RuntimeException $e) { $rc = "threw"; }'
            .'$after = getenv("PMSS_COMMAND_TIMEOUT");'
            .'echo json_encode(["rc" => $rc, "seen" => $GLOBALS["seen"], "after" => $after === false ? "unset" : $after]);';
        return $this->pmssRunInlinePhpJson($script);
    }

    /** Run the real apt-phase functions against scripted command results. */
    private function runAptPhase(array $rcs): array
    {
        $script = 'namespace DistUpgradeAptResult;'
            .'$GLOBALS["rcs"] = '.var_export($rcs, true).'; $GLOBALS["commands"] = []; $GLOBALS["logs"] = [];'
            .'function pmssDistUpgradeAptEnv() { return ["ENV", false]; }'
            .'function pmssDistUpgradeAptCommand($env, $action) { return $env." apt-get ".$action; }'
            .'function logMessage($message) { $GLOBALS["logs"][] = $message; }'
            .'function pmssDistUpgradeRunLockedCommand($command, $lockMessage, $inheritTty = false) {'
            .'  $GLOBALS["commands"][] = $command;'
            .'  if (!empty($GLOBALS["rcs"][$command])) { return array_shift($GLOBALS["rcs"][$command]); }'
            .'  return 0; }';
        foreach (['pmssExecuteUpgrade', 'pmssRunUpgradeWithRecovery'] as $name) {
            $script .= \pmssTestingFunctionExport($name, 'DistUpgradeAptResult');
        }
        $script .= 'echo json_encode(["result" => pmssExecuteUpgrade(), "commands" => $GLOBALS["commands"], "logs" => $GLOBALS["logs"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
