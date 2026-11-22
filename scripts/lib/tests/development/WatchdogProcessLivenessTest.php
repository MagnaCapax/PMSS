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
];
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
        $this->assertSame(18, count($results));
        foreach ($results as $name => [$expected, $running, $reads, $commands]) {
            $this->assertSame($expected, $running, $name);
            $this->assertSame(["pgrep -u 'fixtur01' 'deluged' 2>/dev/null"], $commands, $name);
        }
        $this->assertSame(['/proc/17/status', '/proc/9/status'], $results['normalized-pids'][2]);
        foreach (['no-matches', 'empty-success', 'failed-probe-with-pid', 'invalid-pids'] as $name) {
            $this->assertSame([], $results[$name][2], $name);
        }
    }
}
