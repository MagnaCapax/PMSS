<?php
/** Verify raw status-row safety in scripts/lib/update/dpkgRecovery.php without package commands. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class DpkgRecoveryInputSafetyTest extends TestCase
{
    private const QUERY = "dpkg-query -W -f='\${db:Status-Abbrev} \${Package}\\n' 2>/dev/null";

    public function testCorruptRowsCannotBecomeReinstallRequestsAfterTrimming(): void
    {
        foreach ([
            "\0", "\0iUR fixtur01", "iUR fixtur01\0", " \0iUR fixtur01\0 \r",
            "iU\0R fixtur01", "iUR\0 fixtur01", "iUR \0fixtur01",
            "iUR fix\0tur01", "iUR fixtur01\0suffix", "iUR fixtur01 \0",
        ] as $line) {
            // Even all-corrupt output must still let ordinary configuration proceed.
            $this->assertSame([null, [
                ['query', self::QUERY],
                ['step', 'Completing pending dpkg configuration', 'dpkg --force-confdef --force-confold --configure -a'],
            ]], $this->fixture($line."\n", true), bin2hex($line));
        }
    }

    public function testCorruptRowsDoNotHideValidNeighboursOrChangeTheirOrder(): void
    {
        foreach (["\0iUR fixtur03", "iUR fixtur03\0", "iU\0R fixtur03", "iUR fix\0tur03"] as $line) {
            foreach ([
                $line."\niUR fixtur01\niHR fixtur02:amd64\n",
                "iUR fixtur01\n".$line."\niHR fixtur02:amd64\n",
                "iUR fixtur01\niHR fixtur02:amd64\n".$line,
            ] as $status) {
                $this->assertSame([['fixtur01', 'fixtur02:amd64'], [['query', self::QUERY]]],
                    $this->fixture($status), bin2hex($status));
            }
        }
    }

    public function testValidRowsRetainWhitespaceArchitectureAndDuplicateHandling(): void
    {
        $status = " \tiUR\tfixtur01 \r\niHR fixtur02:amd64\niFR libfixtur03+compat.1-2\n"
            ."iUR fixtur01\nii  fixtur04\niU  fixtur05\niUR bad/name\niUR fixtur06 extra\n";
        $this->assertSame([['fixtur01', 'fixtur02:amd64', 'libfixtur03+compat.1-2', 'fixtur01'],
            [['query', self::QUERY]]], $this->fixture($status));
    }

    public function testEmptyUnavailableAndMalformedCapturesRetainEmptyResults(): void
    {
        foreach ([null, false, '', "\n \t\r\n", 'garbage', "iUR\niUR bad/name\niUR fixtur01 extra"] as $status) {
            $this->assertSame([[], [['query', self::QUERY]]], $this->fixture($status));
        }
    }

    public function testOnlyIntactRowsAreReinstalledBeforePendingConfiguration(): void
    {
        $status = "\0iUR fixtur03\niUR fixtur01\niUR fixtur04\0\niHR fixtur02:amd64\n";
        $this->assertSame([null, [
            ['query', self::QUERY],
            ['step', 'Reinstalling packages dpkg marks reinstall-required',
                "apt-get install --reinstall -y 'fixtur01' 'fixtur02:amd64'"],
            ['step', 'Completing pending dpkg configuration', 'dpkg --force-confdef --force-confold --configure -a'],
        ]], $this->fixture($status, true));
    }

    /** Run the real parser or recovery flow with every reachable system command intercepted. */
    private function fixture($status, bool $recover = false): array
    {
        $script = <<<'PHP'
namespace DpkgRecoveryInputFixture;
function shell_exec($command) {
    $GLOBALS['events'][] = ['query', $command];
    return $GLOBALS['status'];
}
function aptCmd($arguments) { return 'apt-get '.$arguments; }
function dpkgCmd($arguments) { return 'dpkg '.$arguments; }
function pmssSystemdRuntimeAvailable() { return false; }
function runStep($description, $command) {
    $GLOBALS['events'][] = ['step', $description, $command];
    return 0;
}
PHP;
        // Library includes define helpers only; command execution stays in this namespace.
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/dpkgRecovery.php', 'DpkgRecoveryInputFixture');
        $script .= '$GLOBALS["status"] = '.var_export($status, true).'; $GLOBALS["events"] = [];';
        $script .= $recover ? 'pmssCompletePendingDpkg(); $result = null;' : '$result = pmssDpkgReinstallRequiredPackages();';
        $script .= 'echo json_encode([$result, $GLOBALS["events"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
