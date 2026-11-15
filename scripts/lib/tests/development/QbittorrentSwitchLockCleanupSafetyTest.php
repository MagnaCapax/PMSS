<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClients.php';

/** Memory-only lock lifetime coverage for etc/skel/www/configureTorrentClients.php. */
class QbittorrentSwitchLockCleanupSafetyTest extends TestCase
{
    public function testSwitchAndRollbackFaultsAlwaysAttemptUnlockThenClose(): void
    {
        $script = <<<'PHP'
namespace QbittorrentSwitchCleanupFixture;
function step($stage, $value = true) {
    $GLOBALS['events'][] = $stage;
    if (isset($GLOBALS['faults'][$stage])) throw $GLOBALS['faults'][$stage];
    return $value;
}
function is_dir($path) { return true; }
function is_link($path) { return false; }
function readlink($path) { return false; }
function function_exists($name) { return true; }
function pmssConfigureTorrentClientLockOpen($dir, $client, $label) {
    step('acquire');
    if ($GLOBALS['mode'] === 'busy') return [null, ['ok' => false, 'status' => 409]];
    return [$GLOBALS['handle'] = \fopen('php://memory', 'w+'), null];
}
function pmssQbittorrentFrontendRefusalClear($home) { return step('clear', $GLOBALS['mode'] !== 'refused'); }
function pmssConfigureFileRead($path, $limit) { return ''; }
function pmssConfigureTorrentClientMajorDowngrade($old, $new) { return $GLOBALS['mode'] === 'downgrade'; }
function pmssConfigureTorrentClientVersionLabel($version) { return $version; }
function pmssConfigureTorrentClientBackupRefusal($snapshot) { return ['ok' => false, 'status' => 503, 'message' => 'Fixture backup failed.']; }
function pmssConfigureFileWrite($path, $content) { return step('write-'.pathinfo($path, PATHINFO_EXTENSION)); }
function touch($path) { return step('enable'); }
function flock($handle, $operation) {
    if ($handle !== $GLOBALS['handle'] || $operation !== LOCK_UN) throw new \LogicException('Wrong lock');
    return step('unlock', $GLOBALS['mode'] !== 'cleanup-false');
}
function fclose($handle) {
    \fclose($handle);
    return step('close', $GLOBALS['mode'] !== 'cleanup-false');
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssConfigureTorrentClientSwitchApply', 'QbittorrentSwitchCleanupFixture');
        $script .= <<<'PHP'
$success = ['acquire', 'clear', 'backup', 'stop', 'repoint', 'restore', 'start', 'write-lastRun',
    'write-lastgood', 'enable', 'write-notice', 'unlock', 'close'];
$recovery = ['acquire', 'clear', 'backup', 'stop', 'repoint', 'restore', 'start', 'stop-rollback',
    'repoint-rollback', 'restore-rollback', 'start-rollback', 'write-lastRun', 'write-notice', 'unlock', 'close'];
$cases = [['success', [], $success, 200], ['cleanup-false', [], $success, 200],
    ['recovery', [], $recovery, 503], ['busy', [], ['acquire'], 409],
    ['refused', [], ['acquire', 'clear', 'unlock', 'close'], 409],
    ['downgrade', [], ['acquire', 'clear', 'write-notice', 'unlock', 'close'], 409],
    ['backup-false', [], ['acquire', 'clear', 'backup', 'write-notice', 'unlock', 'close'], 503]];
foreach (['success' => $success, 'recovery' => $recovery] as $mode => $trace) {
    foreach ($trace as $index => $stage) {
        $expected = array_slice($trace, 0, $index + 1);
        if ($stage !== 'acquire' && $stage !== 'close') {
            if ($stage !== 'unlock') $expected[] = 'unlock';
            $expected[] = 'close';
        }
        $cases[] = [$mode, [$stage], $expected, null];
    }
    $cases[] = [$mode, ['backup', 'unlock', 'close'], ['acquire', 'clear', 'backup', 'unlock', 'close'], null];
}
$rows = [];
foreach ($cases as [$mode, $stages, $trace, $status]) foreach (['RuntimeException', 'Error'] as $kind) {
    $GLOBALS['mode'] = $mode; $GLOBALS['events'] = $GLOBALS['faults'] = []; $GLOBALS['handle'] = null;
    foreach ($stages as $stage) $GLOBALS['faults'][$stage] = new $kind($stage.' fault');
    $counts = []; $ops = ['versions' => ['5.1.0' => []]];
    foreach (['backup', 'stop', 'repoint', 'restore', 'start'] as $name) {
        $ops[$name] = static function () use ($name, &$counts, $mode) {
            $count = $counts[$name] = ($counts[$name] ?? 0) + 1;
            if (!is_resource($GLOBALS['handle'])) throw new \LogicException('Premature lock close');
            $value = $name === 'backup' ? ['ok' => $mode !== 'backup-false']
                : !($mode === 'recovery' && $name === 'start' && $count === 1);
            return step($name.($count > 1 ? '-rollback' : ''), $value);
        };
    }
    $result = $caught = null;
    try { $result = pmssConfigureTorrentClientSwitchApply('/fixture/home', ['client' => 'qbittorrent', 'version' => '5.1.0'], $ops); }
    catch (\Throwable $error) { $caught = $error; }
    $expectedError = $stages === [] ? null : $GLOBALS['faults'][end($stages)];
    // Observe ownership before fixture teardown can hide a leaked stream.
    $live = is_resource($GLOBALS['handle']);
    if ($live) \fclose($GLOBALS['handle']);
    $rows[] = [[$status, true, false, $trace], [$result['status'] ?? null, $caught === $expectedError, $live, $GLOBALS['events']]];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(74, count($rows));
        foreach ($rows as $index => [$expected, $actual]) $this->assertSame($expected, $actual, 'case '.$index);
    }
}
