<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Final cleanup in scripts/restartUser.php uses memory streams; no account services run. */
class RestartUserFinalCleanupSafetyTest extends TestCase
{
    public function testAuditAndCloseFaultsStillAttemptEveryOwnedCleanupStage(): void
    {
        $source = $this->pmssReadRepoFile('scripts/restartUser.php');
        $start = strpos($source, '$startedAt = microtime(true);');
        $end = strpos($source, 'exit($exitCode);');
        $this->assertTrue($start !== false && $end !== false && $end > $start);
        // Keep the real CLI body, replacing only its terminal exit with a return for observation.
        $body = substr($source, $start, $end - $start).'return $exitCode;';
        $script = <<<'PHP'
namespace RestartUserFinalCleanupFixture;
use Throwable;
const PMSS_UPDATE_LOCK_FDS_ENV = 'PMSS_TEST_RESTART_FDS';
function observe($step) {
    $GLOBALS['events'][] = $step;
    if (isset($GLOBALS['faults'][$step])) throw $GLOBALS['faults'][$step];
    return $GLOBALS['falseAt'] !== $step;
}
function microtime($asFloat) { return ++$GLOBALS['ticks'] * 0.125; }
function date($format) { return '2026-11-16T00:00:00+00:00'; }
function pmssUsernameNormalizeIfValid($user) { return $GLOBALS['mode'] === 'invalid' ? null : $user; }
function function_exists($name) { return $name === 'pmssUserLog' && $GLOBALS['mode'] !== 'no-user-log'; }
function pmssRestartUserRun($argc, $user, &$record, &$locks) {
    $GLOBALS['arguments'] = [$argc, $user];
    $GLOBALS['events'][] = 'run';
    if (in_array($GLOBALS['mode'], ['refused', 'invalid'], true)) return ['account refused', 1];
    foreach (['restart', 'recreate'] as $name) $locks[$name] = $GLOBALS['handles'][$name] = \fopen('php://memory', 'w+');
    if ($GLOBALS['mode'] === 'body') throw $GLOBALS['bodyFault'];
    $record['outcome'] = 'success';
    return ['restart completed', 0];
}
function pmssRestartUserLogWrite($record) {
    $GLOBALS['record'] = $record;
    $GLOBALS['auditLive'] = array_map('is_resource', $GLOBALS['handles']);
    return observe('audit');
}
function pmssUserLog($user, $message) {
    $GLOBALS['userLog'] = [$user, $message, array_map('is_resource', $GLOBALS['handles'])];
    observe('user-log');
}
function fclose($handle) {
    $step = $handle === $GLOBALS['handles']['restart'] ? 'close-restart' : 'close-recreate';
    // Faults on either side of native close distinguish attempts from guaranteed closure.
    if ($GLOBALS['beforeClose']) observe($step);
    \fclose($handle);
    return $GLOBALS['beforeClose'] ? true : observe($step);
}
function putenv($assignment) { $GLOBALS['unset'] = $assignment; return observe('unset'); }
function fwrite($stream, $message) {
    $GLOBALS['output'] = [$stream === STDOUT ? 'stdout' : 'stderr', $message];
    return strlen($message);
}
PHP;
        $script .= 'function runCli() { $argc = 2; $argv = ["restartUser.php", "fixtur01"];'.$body.'}';
        $script .= <<<'PHP'
$rows = [];
$cases = [
    ['success', '', []], ['refused', '', []], ['invalid', '', []], ['body', '', []],
    ['no-user-log', '', []], ['success', 'audit', []], ['body', 'audit', []],
    ['success', 'close-restart', []], ['success', 'close-recreate', []], ['success', 'unset', []],
    ['success', '', ['audit']], ['success', '', ['user-log']], ['refused', '', ['audit']],
    ['invalid', '', ['audit']], ['body', '', ['audit']], ['body', '', ['user-log']],
    ['success', '', ['close-restart']], ['success', '', ['close-recreate']], ['success', '', ['unset']],
    ['success', '', ['audit', 'close-restart']], ['success', '', ['user-log', 'close-recreate']],
    ['success', '', ['close-restart', 'close-recreate']], ['success', '', ['close-recreate', 'unset']],
    ['success', '', ['audit', 'close-restart', 'close-recreate', 'unset']],
];
foreach ($cases as [$mode, $falseAt, $stages]) {
    foreach (['RuntimeException', 'Error'] as $kind) {
        foreach ([false, true] as $beforeClose) {
            $GLOBALS['mode'] = $mode; $GLOBALS['falseAt'] = $falseAt; $GLOBALS['beforeClose'] = $beforeClose;
            $GLOBALS['events'] = $GLOBALS['handles'] = $GLOBALS['faults'] = [];
            $GLOBALS['ticks'] = 0; $GLOBALS['record'] = $GLOBALS['userLog'] = $GLOBALS['output'] = $GLOBALS['unset'] = null;
            $GLOBALS['bodyFault'] = new $kind('body');
            foreach ($stages as $stage) $GLOBALS['faults'][$stage] = new $kind($stage);
            $result = $caught = null;
            try { $result = runCli(); } catch (\Throwable $error) { $caught = $error; }
            $expectedFault = $stages === [] ? null : $GLOBALS['faults'][end($stages)];
            $rows[] = [$mode, $falseAt, $stages, $beforeClose, $kind, $result, $caught === $expectedFault,
                $GLOBALS['events'], array_map('is_resource', $GLOBALS['handles']), $GLOBALS['auditLive'],
                $GLOBALS['record'], $GLOBALS['userLog'], $GLOBALS['output'], $GLOBALS['unset'], $GLOBALS['arguments']];
            // Observe leaks before fixture teardown, without retaining memory streams between cases.
            foreach ($GLOBALS['handles'] as $handle) if (is_resource($handle)) \fclose($handle);
        }
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(96, count($rows));
        foreach ($rows as [$mode, $falseAt, $stages, $beforeClose, $kind, $result, $sameFault,
            $events, $live, $auditLive, $record, $userLog, $output, $unset, $arguments]) {
            $label = json_encode([$mode, $falseAt, $stages, $beforeClose, $kind]);
            $owned = !in_array($mode, ['refused', 'invalid'], true);
            $logged = !in_array('audit', $stages, true) && !in_array($mode, ['invalid', 'no-user-log'], true);
            $trace = ['run', 'audit'];
            if ($logged) $trace[] = 'user-log';
            if ($owned) array_push($trace, 'close-restart', 'close-recreate', 'unset');
            $this->assertSame([$trace, true, [2, $mode === 'invalid' ? null : 'fixtur01']], [$events, $sameFault, $arguments], $label);
            $this->assertSame($owned ? ['restart' => true, 'recreate' => true] : [], $auditLive, $label);
            $this->assertSame($owned ? ['restart' => $beforeClose && in_array('close-restart', $stages, true),
                'recreate' => $beforeClose && in_array('close-recreate', $stages, true)] : [], $live, $label);
            $this->assertSame($owned ? 'PMSS_TEST_RESTART_FDS' : null, $unset, $label);
            $outcome = $mode === 'body' ? 'failed' : ($owned ? 'success' : 'refused');
            $this->pmssAssertArraySubsetSame(['outcome' => $outcome, 'duration_ms' => 125,
                'failed' => $mode === 'body' ? ['internal'] : []], $record, $label);
            $message = $mode === 'body' ? 'restart failed unexpectedly' : ($owned ? 'restart completed' : 'account refused');
            $this->assertSame($outcome === 'success' ? false : $message, $record['reason'] ?? false, $label);
            $this->assertSame($logged ? ['fixtur01', 'restartUser: '.$outcome.($mode === 'body' ? ' (internal)' : ''), $auditLive] : null, $userLog, $label);
            $code = $outcome !== 'success' || $falseAt === 'audit' ? 1 : 0;
            if ($outcome === 'success' && $falseAt === 'audit') $message = 'restart audit log unavailable';
            $this->assertSame($stages === [] ? [$code, [$code === 0 ? 'stdout' : 'stderr', $message."\n"]] : [null, null], [$result, $output], $label);
        }
    }
}
