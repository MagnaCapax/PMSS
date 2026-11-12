<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** First-run capture faults use memory streams; no Docker command or signal runs. */
class DockerLsioSeedCaptureSafetyTest extends TestCase
{
    public function testCaptureContractsAndOwnedResourceCleanup(): void
    {
        $script = <<<'PHP'
namespace DockerLsioSeedCaptureFixture;
function fault($stage) {
    if (isset($GLOBALS['faults'][$stage])) {
        $GLOBALS['lastFault'] = $GLOBALS['faults'][$stage];
        throw $GLOBALS['lastFault'];
    }
}
function pmssDockerInstallLsioShellCommand($command) { return implode(' ', array_map('escapeshellarg', $command)); }
function proc_open($command, $spec, &$pipes) {
    $GLOBALS['events'][] = 'open';
    $GLOBALS['launch'] = [$command, $spec];
    if ($GLOBALS['case'] === 'launch-failed') return false;
    foreach ([0, 1] as $slot) {
        $pipes[$slot] = $GLOBALS['handles'][$slot] = \fopen('php://memory', 'w+');
        $GLOBALS['names'][(int) $pipes[$slot]] = 'close-'.$slot;
    }
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function fwrite($handle, $input) {
    $GLOBALS['events'][] = 'write';
    $GLOBALS['input'] = $input;
    fault('write');
    return $GLOBALS['case'] === 'write-failed' ? false
        : ($GLOBALS['case'] === 'write-short' ? strlen($input) - 1 : strlen($input));
}
function fclose($handle) {
    $stage = $GLOBALS['names'][(int) $handle];
    $GLOBALS['events'][] = $stage;
    $attempt = $GLOBALS['attempts'][$stage] = ($GLOBALS['attempts'][$stage] ?? 0) + 1;
    fault($stage.'-'.$attempt.'-before');
    \fclose($handle);
    fault($stage.'-'.$attempt.'-after');
    return true;
}
function stream_get_contents($handle) {
    $GLOBALS['events'][] = 'read';
    fault('read');
    return $GLOBALS['case'] === 'read-failed' ? false : "fixture output\0\n";
}
function proc_terminate($process) {
    $GLOBALS['events'][] = 'terminate';
    fault('terminate');
    return $GLOBALS['case'] !== 'terminate-failed';
}
function proc_close($process) {
    $GLOBALS['events'][] = 'reap';
    $attempt = $GLOBALS['attempts']['reap'] = ($GLOBALS['attempts']['reap'] ?? 0) + 1;
    fault('reap-'.$attempt.'-before');
    \fclose($process);
    fault('reap-'.$attempt.'-after');
    return $GLOBALS['case'] === 'exit-failed' ? 7 : 0;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('etc/skel/bin/dockerLsioSeedHttp.php', 'DockerLsioSeedCaptureFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_LSIO_CAPTURE_CLEANUP'), true) as [$case, $keys, $type]) {
    $GLOBALS['case'] = $case;
    $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['handles'] = $GLOBALS['names'] = $GLOBALS['attempts'] = [];
    $GLOBALS['process'] = $GLOBALS['lastFault'] = $GLOBALS['input'] = null;
    foreach ($keys as $key) $GLOBALS['faults'][$key] = new $type($key);
    $result = $caught = null;
    try { $result = pmssDockerLsioSeedCapture(['docker', 'exec', 'fixture-app', 'two words'],
        $case === 'empty-input' ? '' : "fixture-input\n"); }
    catch (\Throwable $error) { $caught = $error; }
    $live = is_resource($GLOBALS['process']);
    foreach ($GLOBALS['handles'] as $handle) $live = $live || is_resource($handle);
    $rows[] = [$result, $caught === $GLOBALS['lastFault'], $live, $GLOBALS['events'],
        $GLOBALS['launch'], $GLOBALS['input']];
    // Observe leaks before releasing the fake resources used by fault injection.
    foreach ($GLOBALS['handles'] as $handle) if (is_resource($handle)) \fclose($handle);
    if (is_resource($GLOBALS['process'])) \fclose($GLOBALS['process']);
}
echo json_encode($rows);
PHP;
        $normal = ['open', 'write', 'close-0', 'read', 'close-1', 'reap'];
        $readFault = ['open', 'write', 'close-0', 'read', 'close-1', 'terminate', 'reap'];
        $cases = [['success', [], '', $normal, "fixture output\0\n"],
            ['empty-input', [], '', ['open', 'close-0', 'read', 'close-1', 'reap'], "fixture output\0\n"],
            ['launch-failed', [], '', ['open'], null]];
        foreach (['write-failed', 'write-short', 'read-failed', 'exit-failed'] as $case) {
            $cases[] = [$case, [], '', $normal, null];
        }
        // Fail before and after release, including combined faults and both throwable families.
        $faultCases = [
            [['write'], ['open', 'write', 'close-0', 'close-1', 'terminate', 'reap']],
            [['close-0-1-before'], ['open', 'write', 'close-0', 'close-0', 'close-1', 'terminate', 'reap']],
            [['close-0-1-after'], ['open', 'write', 'close-0', 'close-1', 'terminate', 'reap']],
            [['read'], $readFault],
            [['close-1-1-before'], ['open', 'write', 'close-0', 'read', 'close-1', 'close-1', 'terminate', 'reap']],
            [['close-1-1-after'], $readFault],
            [['reap-1-before'], array_merge($normal, ['terminate', 'reap'])],
            [['reap-1-after'], $normal],
            [['read', 'close-1-1-after'], $readFault],
            [['read', 'terminate'], $readFault],
            [['read', 'reap-1-after'], $readFault],
            [['write', 'close-0-1-after', 'close-1-1-after', 'terminate', 'reap-1-after'],
                ['open', 'write', 'close-0', 'close-1', 'terminate', 'reap']],
        ];
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach ($faultCases as [$keys, $events]) $cases[] = ['fault', $keys, $type, $events, null];
            $cases[] = ['terminate-failed', ['read'], $type, $readFault, null];
        }
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_LSIO_CAPTURE_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($rows));
        $launch = ["'docker' 'exec' 'fixture-app' 'two words'", [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']]];
        foreach ($cases as $index => [$case, $keys, $type, $events, $result]) {
            $input = in_array($case, ['empty-input', 'launch-failed'], true) ? null : "fixture-input\n";
            $this->assertSame([$result, true, false, $events, $launch, $input], $rows[$index], json_encode($cases[$index]));
        }
    }
}
