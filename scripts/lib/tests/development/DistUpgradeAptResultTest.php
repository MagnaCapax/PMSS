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
            $function = new \ReflectionFunction($name);
            $source = implode('', array_slice(file($function->getFileName()), $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1));
            $script .= 'eval('.var_export('namespace DistUpgradeAptResult; '.$source, true).');';
        }
        $script .= 'echo json_encode(["result" => pmssExecuteUpgrade(), "commands" => $GLOBALS["commands"], "logs" => $GLOBALS["logs"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
