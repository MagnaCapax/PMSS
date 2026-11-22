<?php
/** Verify watchdog liveness excludes zombies using intercepted process observations. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class WatchdogProcessLivenessTest extends TestCase
{
    public function testZombieMatchesCannotSuppressRecoveryWhileLiveAndUnknownMatchesDo(): void
    {
        $script = 'namespace WatchdogLivenessFixture; require_once '.var_export($this->pmssRepoPath('scripts/lib/user/identity.php'), true).';';
        $script .= <<<'PHP'
function exec($command, &$output, &$rc) {
    $GLOBALS['commands'][] = $command;
    $output = $GLOBALS['case'][0];
    $rc = $GLOBALS['case'][1];
}
function file_get_contents($path) {
    $GLOBALS['reads'][] = $path;
    if (!array_key_exists($path, $GLOBALS['case'][2])) throw new \RuntimeException('Unexpected proc read');
    return $GLOBALS['case'][2][$path];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/watchdogProcess.php', 'WatchdogLivenessFixture');
        $script .= <<<'PHP'
$zombie = "Name:\tdeluged\nState:\tZ (zombie)\n";
$sleeping = "Name:\tdeluged\nState:\tS (sleeping)\n";
$cases = [
    'zombie' => [['17'], 0, ['/proc/17/status' => $zombie], false],
    'all-zombies' => [['17', '9'], 0, ['/proc/17/status' => $zombie, '/proc/9/status' => $zombie], false],
    'zombie-then-live' => [['17', '9'], 0, ['/proc/17/status' => $zombie, '/proc/9/status' => $sleeping], true],
    'live-then-zombie' => [['17', '9'], 0, ['/proc/17/status' => $sleeping], true],
    'unreadable' => [['17'], 0, ['/proc/17/status' => false], true],
    'empty' => [['17'], 0, ['/proc/17/status' => ''], true],
    'unrecognized' => [['17'], 0, ['/proc/17/status' => "Name:\tZ\n"], true],
    'no-matches' => [[], 1, [], false],
    'empty-success' => [[], 0, [], false],
    'failed-probe-with-pid' => [['17'], 2, [], false],
    'invalid-pids' => [['0', '-1', 'bad', PHP_INT_MAX.'0'], 0, [], false],
    'normalized-pids' => [['0017', '17', '9'], 0, ['/proc/17/status' => $zombie, '/proc/9/status' => $sleeping], true],
    'zombie-then-corrupt' => [['17', '9'], 0, ['/proc/17/status' => $zombie, '/proc/9/status' => $zombie."\0"], true],
    'corrupt-then-zombie' => [['17', '9'], 0, ['/proc/17/status' => "\0".$zombie], true],
    'corrupt-pid-then-live' => [["17\0", '9'], 0, ['/proc/9/status' => $sleeping], true],
];
// Corruption outside the matched state field must not certify a zombie either.
foreach (["\0".$zombie, "Name:\0deluged\nState:\tZ (zombie)\n",
    "Name:\tdeluged\nState:\tZ\0 (zombie)\n", $zombie."\0", $zombie."Uid:\t0\0\n"] as $index => $status) {
    $cases['corrupt-status-'.$index] = [['17'], 0, ['/proc/17/status' => $status], true];
}
foreach (["\0".'17', '1'."\0".'7', "17\0", " \t17\0\n", "\0".'17'."\0"] as $index => $pid) {
    $cases['corrupt-pid-'.$index] = [[$pid], 0, [], false];
}
foreach (['R', 'S', 'D', 'T', 't', 'I'] as $state) {
    $cases['state-'.$state] = [['17'], 0, ['/proc/17/status' => "State:\t".$state."\n"], true];
}
$results = [];
foreach ($cases as $name => $case) {
    $GLOBALS['case'] = $case;
    $GLOBALS['reads'] = $GLOBALS['commands'] = [];
    $running = pmssUserWatchdogProcessRunning('fixtur01', 'deluged');
    $results[$name] = [$case[3], $running, $GLOBALS['reads'], $GLOBALS['commands']];
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(31, count($results));
        foreach ($results as $name => [$expected, $running, $reads, $commands]) {
            $this->assertSame($expected, $running, $name);
            $this->assertSame(["pgrep -u 'fixtur01' 'deluged' 2>/dev/null"], $commands, $name);
        }
        $this->assertSame(['/proc/17/status', '/proc/9/status'], $results['normalized-pids'][2]);
        foreach (['no-matches', 'empty-success', 'failed-probe-with-pid', 'invalid-pids'] as $name) {
            $this->assertSame([], $results[$name][2], $name);
        }
        foreach (range(0, 4) as $index) {
            $this->assertSame([], $results['corrupt-pid-'.$index][2]);
            $this->assertSame(['/proc/17/status'], $results['corrupt-status-'.$index][2]);
        }
        $this->assertSame(['/proc/17/status', '/proc/9/status'], $results['zombie-then-corrupt'][2]);
        $this->assertSame(['/proc/17/status'], $results['corrupt-then-zombie'][2]);
        $this->assertSame(['/proc/9/status'], $results['corrupt-pid-then-live'][2]);
    }
}
