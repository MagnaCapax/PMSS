<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsSwitch.php';

/** Memory-only lock faults for etc/skel/www/configureTorrentClientsSwitch.php. */
class TorrentClientsOtherSwitchLockCleanupSafetyTest extends TestCase
{
    public function testSwitchOwnsLockAcrossAcquisitionCallbacksAndRecoveryFaults(): void
    {
        $script = <<<'PHP'
namespace OtherSwitchCleanupFixture;
function step($stage, $value = true) {
    $count = $GLOBALS['counts'][$stage] = ($GLOBALS['counts'][$stage] ?? 0) + 1;
    if ($count > 1) $stage .= '-rollback';
    $GLOBALS['events'][] = $stage;
    if (isset($GLOBALS['faults'][$stage])) throw $GLOBALS['faults'][$stage];
    return $value;
}
function is_dir($path) { return true; }
function is_link($path) { return basename($path) === 'default'; }
function is_file($path) { return false; }
function file_exists($path) { return false; }
function readlink($path) { return step('readlink', '/home/.pmss/clients/'.$GLOBALS['client'].'/4.1.0'); }
function function_exists($name) { return !$GLOBALS['legacy']; }
function fopen($path, $mode) {
    step('open');
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function pmssConfigureTorrentClientLockOpen($dir, $client, $label) {
    step('acquire');
    if ($GLOBALS['mode'] === 'busy') return [null, ['ok' => false, 'status' => 409]];
    return [$GLOBALS['handle'] = \fopen('php://memory', 'w+'), null];
}
function pmssConfigureFileRead($path, $limit) { return step('read-'.pathinfo($path, PATHINFO_EXTENSION), '4.1.0'); }
function pmssConfigureTorrentClientMajorDowngrade($old, $new) { return false; }
function pmssConfigureTorrentClientRtorrentRcCheck($home, $version) { return step('rc-check', null); }
function pmssConfigureFileWrite($path, $content) { return step('write-'.pathinfo($path, PATHINFO_EXTENSION)); }
function touch($path) { return step('enable'); }
function flock($handle, $operation) {
    if ($handle !== $GLOBALS['handle']) throw new \LogicException('Wrong lock');
    return step($operation === LOCK_UN ? 'unlock' : 'lock', $GLOBALS['mode'] !== 'busy' && $GLOBALS['mode'] !== 'cleanup-false');
}
function fclose($handle) {
    \fclose($handle);
    return step('close', $GLOBALS['mode'] !== 'cleanup-false');
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssConfigureTorrentClientOtherSwitch', 'OtherSwitchCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (['deluge', 'transmission', 'rtorrent'] as $client) foreach ([false, true] as $legacy) {
    $prefix = $legacy ? ['open', 'lock'] : ['acquire'];
    $before = array_merge($prefix, ['readlink', 'resolve', 'read-lastRun'], $client === 'rtorrent' ? ['rc-check'] : [], ['backup', 'stop', 'repoint', 'write-lastRun', 'start']);
    $success = array_merge($before, ['write-lastgood'], $client === 'deluge' ? ['enable'] : [], ['write-notice', 'unlock', 'close']);
    $recovery = array_merge($before, ['read-lastgood', 'stop-rollback', 'repoint-rollback', 'start-rollback', 'write-lastRun-rollback', 'write-notice', 'unlock', 'close']);
    $cases = [['success', [], $success, 200], ['recovery', [], $recovery, 503],
        ['busy', [], $legacy ? ['open', 'lock', 'close'] : ['acquire'], 409]];
    if (!$legacy) $cases[] = ['cleanup-false', [], $success, 200];
    foreach (['success' => $success, 'recovery' => $recovery] as $mode => $trace) {
        foreach ($trace as $index => $stage) {
            $expected = array_slice($trace, 0, $index + 1);
            if (!in_array($stage, ['acquire', 'open', 'close'], true)) {
                if ($stage !== 'unlock') $expected[] = 'unlock';
                $expected[] = 'close';
            }
            $cases[] = [$mode, [$stage], $expected, null];
        }
        $cases[] = [$mode, ['backup', 'unlock', 'close'], array_merge(array_slice($trace, 0, array_search('backup', $trace, true) + 1), ['unlock', 'close']), null];
    }
    foreach ($cases as [$mode, $stages, $trace, $status]) foreach (['RuntimeException', 'Error'] as $kind) {
        $GLOBALS['client'] = $client; $GLOBALS['legacy'] = $legacy; $GLOBALS['mode'] = $mode;
        $GLOBALS['events'] = $GLOBALS['faults'] = $GLOBALS['counts'] = []; $GLOBALS['handle'] = null;
        foreach ($stages as $stage) $GLOBALS['faults'][$stage] = new $kind($stage.' fault');
        $ops = ['versions' => ['4.2.0' => ['kind' => $client === 'deluge' ? 'venv' : 'static', 'rpcUnixSocket' => true]]];
        foreach (['resolve', 'backup', 'stop', 'repoint', 'start'] as $name) {
            $ops[$name] = static function () use ($name, $mode) {
                if (!is_resource($GLOBALS['handle'])) throw new \LogicException('Premature lock close');
                $value = $name === 'backup' ? ['ok' => true] : ($name === 'resolve' ? '/fixture/bin'
                    : !($mode === 'recovery' && $name === 'start' && !isset($GLOBALS['counts']['start'])));
                return step($name, $value);
            };
        }
        $result = $caught = null;
        try { $result = pmssConfigureTorrentClientOtherSwitch('/fixture/home', ['client' => $client, 'version' => '4.2.0'], $ops); }
        catch (\Throwable $error) { $caught = $error; }
        $expectedError = $stages === [] ? null : $GLOBALS['faults'][end($stages)];
        // Observe ownership before teardown could hide a leaked descriptor.
        $live = is_resource($GLOBALS['handle']);
        if ($live) \fclose($GLOBALS['handle']);
        $rows[] = [[$status, true, false, $trace], [$result['status'] ?? null, $caught === $expectedError, $live, $GLOBALS['events']]];
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(450, count($rows));
        foreach ($rows as $index => [$expected, $actual]) $this->assertSame($expected, $actual, 'case '.$index);
    }
}
