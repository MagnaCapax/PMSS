<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** The docker stop scan treats "account runs no processes" as a clean scan and still fails on a real ps error. */
class UserDockerStopScanNoProcessesTest extends TestCase
{
    public function testEmptyPsResultIsCleanAndErrorsStillFail(): void
    {
        $script = <<<'PHP'
namespace DockerStopScanFixture;
function pmssUserLog($user, $message) { $GLOBALS['logs'][] = $message; }
function pmssCliExitWithStderr($message, $code) { throw new \RuntimeException(trim($message)); }
function exec($command, &$lines = null, &$rc = null) {
    $GLOBALS['commands'][] = $command;
    $lines = $GLOBALS['psLines'];
    $rc = $GLOBALS['psRc'];
    return '';
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/docker/stop.php', 'DockerStopScanFixture');
        $script .= <<<'PHP'
$cases = [
    'no-processes' => [1, []],
    'ps-error' => [1, ['error: user name does not exist']],
    'other-failure' => [2, []],
    'running' => [0, ['  4242 dockerd   dockerd --rootless', '  4243 rootlesskit rootlesskit dockerd']],
];
$results = [];
foreach ($cases as $name => [$rc, $lines]) {
    $GLOBALS['psRc'] = $rc;
    $GLOBALS['psLines'] = $lines;
    $GLOBALS['commands'] = $GLOBALS['logs'] = [];
    try {
        $results[$name] = ['ok', userDockerStopScan('alice', 1001)];
    } catch (\RuntimeException $e) {
        $results[$name] = ['fail', $e->getMessage()];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(['ok', ['dockerd' => [], 'rootlesskit' => [], 'wrapper' => []]], $results['no-processes'], 'no processes');
        $this->assertSame('fail', $results['ps-error'][0], 'ps error with output');
        $this->assertSame('fail', $results['other-failure'][0], 'non-1 exit');
        $this->assertSame('ok', $results['running'][0], 'running daemon');
        $this->assertSame([4242], $results['running'][1]['dockerd'], 'dockerd pid');
        $this->assertSame([4243], $results['running'][1]['rootlesskit'], 'rootlesskit pid');
    }
}
