<?php
/** Verify bounded target Eternals hold retries and diagnostics without account commands. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserTransferEternalHoldTest extends TestCase
{
    public function testSuccessfulHoldStopsRetriesImmediately(): void
    {
        foreach ([[0], [1, 0], [1, 1, 0]] as $codes) {
            $result = $this->hold($codes);
            $this->assertSame(0, $result['rc']);
            $this->assertSame(count($codes) * 2 - 1, count($result['calls']));
            $this->assertSame(count($codes) - 1, count($result['logs']));
            $holdCommand = $result['calls'][0][1];
            foreach ($result['calls'] as $i => [$label, $command]) {
                if ($i % 2 === 0) {
                    $this->assertSame($holdCommand, $command);
                    $this->assertStringContainsString('attempt '.(int) ($i / 2 + 1).'/3', $label);
                    $this->assertStringContainsString('eternal hold --until 1800000000', $command);
                } else {
                    $this->assertStringContainsString('/usr/bin/timeout --kill-after=2s 5s', $command);
                    $this->assertStringContainsString('eternal list', $command);
                }
                $this->assertStringContainsString('account:fixtur01 ', $command);
            }
        }
    }

    public function testExhaustedRetriesReturnTheLastHoldFailure(): void
    {
        foreach ([1, 124, 255] as $lastRc) {
            $result = $this->hold([1, 1, $lastRc]);
            $this->assertSame($lastRc, $result['rc']);
            $this->assertSame(6, count($result['calls']));
            $this->assertSame(3, count($result['logs']));
            $this->assertStringContainsString('attempt 3/3 failed (rc='.$lastRc.', list rc=0)', $result['logs'][2]);
        }
    }

    public function testDiagnosticFailuresDoNotMaskHoldResultsOrPreventRetries(): void
    {
        foreach ([1, 124, 137] as $listRc) {
            foreach ([[1, 0], [1, 1, 255]] as $codes) {
                $result = $this->hold($codes, $listRc, '', 'list unavailable');
                $this->assertSame(end($codes), $result['rc']);
                $this->assertStringContainsString('list rc='.$listRc.'): list unavailable', $result['logs'][0]);
            }
        }
    }

    public function testDiagnosticsCaptureBothStreamsAndBoundLargeOrEmptyOutput(): void
    {
        $result = $this->hold([1, 0], 0, "pm1 stopping\npm2 stopped", 'list warning');
        $this->assertStringContainsString('pm1 stopping pm2 stopped list warning', $result['logs'][0]);
        $result = $this->hold([1, 0], 0, '', '');
        $this->assertStringContainsString('(no output)', $result['logs'][0]);
        $result = $this->hold([1, 0], 0, str_repeat('x', 8192).'omitted');
        $this->assertStringContainsString(str_repeat('x', 8192), $result['logs'][0]);
        $this->assertFalse(strpos($result['logs'][0], 'omitted') !== false);
    }

    public function testCallerKeepsFailureFatalBeforeFinalPassAndArmsCleanup(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/userTransfer.php');
        $held = strpos($source, '$targetHeld = true;');
        $hold = strpos($source, 'if (pmssUserTransferTargetEternalHold(');
        $failure = strpos($source, "throw new RuntimeException('Could not hold target Eternals before final transfer', 1);");
        $final = strpos($source, "\$lastFinalRc = pmssUserTransferRunPasses('Pulling volatile data'");
        $this->assertTrue($held !== false && $hold !== false && $failure !== false && $final !== false);
        $this->assertTrue($held < $hold && $hold < $failure && $failure < $final);
    }

    /** Run the real helper with intercepted account command construction, execution and logs. */
    private function hold(array $codes, int $listRc = 0, string $stdout = 'pm1 stopped', string $stderr = ''): array
    {
        $script = <<<'PHP'
namespace TransferEternalHoldFixture;
function pmssBuildUserShellNoPamCommand($user, $command) { return 'account:'.$user.' '.$command; }
function pmssLogWhitespaceCollapse($text) { return preg_replace('/\s+/', ' ', $text); }
function logMessage($message) { $GLOBALS['logs'][] = $message; }
function runStep($label, $command) {
    $GLOBALS['calls'][] = [$label, $command];
    if (strpos($command, 'eternal list') !== false) {
        $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => $GLOBALS['stdout'], 'stderr' => $GLOBALS['stderr']];
        return $GLOBALS['listRc'];
    }
    if (!$GLOBALS['codes']) throw new \LogicException('Unexpected hold attempt');
    return array_shift($GLOBALS['codes']);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/userTransfer/eternalHold.php', 'TransferEternalHoldFixture');
        $script .= '$codes = '.var_export($codes, true).'; $listRc = '.$listRc.';'
            .'$stdout = '.var_export($stdout, true).'; $stderr = '.var_export($stderr, true).';'
            .'$calls = $logs = []; $rc = pmssUserTransferTargetEternalHold("fixtur01", 1800000000);'
            .'echo json_encode(["rc" => $rc, "calls" => $calls, "logs" => $logs]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
