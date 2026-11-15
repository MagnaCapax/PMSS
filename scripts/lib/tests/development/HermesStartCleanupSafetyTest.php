<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/pmssHermesRuntime.php';

/** PID capture in etc/skel/bin/pmssHermesRuntime.php never launches a real process here. */
class HermesStartCleanupSafetyTest extends TestCase
{
    public function testLaunchFaultsAttemptAllPipeClosesAndReaping(): void
    {
        $script = <<<'PHP'
namespace HermesStartCleanupFixture;
class RuntimeException extends \RuntimeException {}
function fault($key) {
    if (isset($GLOBALS['faults'][$key])) {
        $GLOBALS['lastFault'] = $GLOBALS['faults'][$key];
        throw $GLOBALS['lastFault'];
    }
}
function is_file($path) { return true; }
function is_link($path) { return false; }
function is_dir($path) { return true; }
function pmssAgentReservedPort($path) { return 24000; }
function pmssHermesPublicUrl($home) { return 'https://panel.example.test/user-fixtur01/hermes/'; }
function pmssHermesRunningPid($paths) { return null; }
function pmssHermesEnv($paths, $url) { return []; }
function proc_open($command, $descriptor, &$pipes, $cwd, $env) {
    $GLOBALS['events'][] = 'open';
    $GLOBALS['launch'] = [$command, $descriptor, $cwd, $env];
    fault('open');
    if ($GLOBALS['case'] === 'launch-failed') return false;
    foreach ([0, 1, 2] as $index) {
        $pipes[$index] = $GLOBALS['handles'][$index] = \fopen('php://memory', 'w+');
        $GLOBALS['names'][(int) $pipes[$index]] = 'close-'.$index;
    }
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function fclose($handle) {
    $phase = $GLOBALS['names'][(int) $handle];
    $GLOBALS['events'][] = $phase;
    $attempt = $GLOBALS['attempts'][$phase] = ($GLOBALS['attempts'][$phase] ?? 0) + 1;
    fault($phase.'-'.$attempt.'-before');
    \fclose($handle);
    fault($phase.'-'.$attempt.'-after');
    return true;
}
function stream_get_contents($handle) {
    $GLOBALS['events'][] = 'read';
    fault('read');
    return $GLOBALS['case'] === 'read-failed' ? false
        : ($GLOBALS['case'] === 'invalid-pid' ? "0\n" : " 12345\n");
}
function proc_close($process) {
    $GLOBALS['events'][] = 'reap';
    $attempt = $GLOBALS['attempts']['reap'] = ($GLOBALS['attempts']['reap'] ?? 0) + 1;
    fault('reap-'.$attempt.'-before');
    \fclose($process);
    fault('reap-'.$attempt.'-after');
    return 7; // Launcher status remains ignored, as before.
}
function file_put_contents($path, $payload) {
    $GLOBALS['writes'][] = [$path, $payload];
    $GLOBALS['events'][] = count($GLOBALS['writes']) === 1 ? 'write-pid' : 'write-port';
    return strlen($payload);
}
function pmssHermesBackendReady($paths, $port) {
    $GLOBALS['events'][] = 'ready';
    fault('ready');
    return true;
}
function pmssHermesRelayStart(...$args) { $GLOBALS['events'][] = 'relay'; return true; }
PHP;
        foreach (['pmssHermesStartCommand', 'pmssHermesStart'] as $function) {
            $script .= \pmssTestingFunctionExport($function, 'HermesStartCleanupFixture');
        }
        $paths = \pmssHermesPaths('/fixture/fixtur01');
        $script .= '$paths = '.var_export($paths, true).';';
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_HERMES_START_CLEANUP'), true) as [$case, $keys, $type]) {
    $GLOBALS['case'] = $case;
    $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['handles'] = $GLOBALS['names'] = [];
    $GLOBALS['attempts'] = $GLOBALS['writes'] = [];
    $GLOBALS['process'] = $GLOBALS['lastFault'] = null;
    foreach ($keys as $key) $GLOBALS['faults'][$key] = new $type($key);
    $caught = null;
    try { pmssHermesStart($paths); } catch (\Throwable $error) { $caught = $error; }
    $live = is_resource($GLOBALS['process']);
    foreach ($GLOBALS['handles'] as $handle) $live = $live || is_resource($handle);
    $rows[] = [$caught === null ? null : $caught->getMessage(),
        $GLOBALS['lastFault'] === null || $caught === $GLOBALS['lastFault'], $live,
        $GLOBALS['events'], $GLOBALS['writes'], $GLOBALS['launch']];
    // Observe leaks before releasing fake resources from a failing implementation.
    foreach ($GLOBALS['handles'] as $handle) if (is_resource($handle)) \fclose($handle);
    if (is_resource($GLOBALS['process'])) \fclose($GLOBALS['process']);
}
echo json_encode($rows);
PHP;
        $capture = ['open', 'close-0', 'read', 'close-1', 'close-2', 'reap'];
        $success = array_merge($capture, ['write-pid', 'write-port', 'ready', 'relay']);
        $cases = [
            ['success', [], '', $success, null],
            ['launch-failed', [], '', ['open'], 'Dashboard could not launch'],
            ['read-failed', [], '', $capture, 'Dashboard did not return a PID'],
            ['invalid-pid', [], '', $capture, 'Dashboard did not return a PID'],
        ];
        // Single faults preserve identity; combined faults retain normal finally precedence.
        $faultCases = [
            [['open'], ['open']],
            [['close-0-1-before'], ['open', 'close-0', 'close-0', 'close-1', 'close-2', 'reap']],
            [['close-0-1-after'], ['open', 'close-0', 'close-1', 'close-2', 'reap']],
            [['read'], $capture],
            [['close-1-1-before'], ['open', 'close-0', 'read', 'close-1', 'close-1', 'close-2', 'reap']],
            [['close-1-1-after'], $capture],
            [['close-2-1-before'], ['open', 'close-0', 'read', 'close-1', 'close-2', 'close-2', 'reap']],
            [['close-2-1-after'], $capture],
            [['reap-1-before'], array_merge($capture, ['reap'])],
            [['reap-1-after'], $capture],
            [['read', 'close-1-1-after'], $capture],
            [['read', 'close-1-1-after', 'close-2-1-after', 'reap-1-after'], $capture],
            [['close-0-1-before', 'close-0-2-after'], ['open', 'close-0', 'close-0', 'close-1', 'close-2', 'reap']],
            [['ready'], array_merge($capture, ['write-pid', 'write-port', 'ready'])],
        ];
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach ($faultCases as [$keys, $events]) $cases[] = ['fault', $keys, $type, $events, end($keys)];
        }
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_HERMES_START_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($rows));
        $command = \pmssHermesStartCommand($paths, 'https://panel.example.test/user-fixtur01/hermes/',
            24000, $paths['base'].'/logs/pmss-dashboard.log');
        $launch = [$command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], null, []];
        foreach ($cases as $index => [$case, $keys, $type, $events, $message]) {
            $writes = in_array('write-pid', $events, true)
                ? [[$paths['pid'], "12345\n"], [$paths['port'], "24000\n"]] : [];
            $this->assertSame([$message, true, false, $events, $writes, $launch], $rows[$index], json_encode($cases[$index]));
        }
    }
}
