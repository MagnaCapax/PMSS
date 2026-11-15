<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/systemd/recovery.php';

/** Fault injection for scripts/lib/update/services/systemd/recovery.php; no host actions. */
class RequiredServicesLockCleanupSafetyTest extends TestCase
{
    public function testEnvironmentFaultsCannotSkipOwnedLockRelease(): void
    {
        $script = <<<'PHP'
namespace RequiredServicesCleanupFixture;
function observe($stage) {
    $GLOBALS['events'][] = $stage;
    if (isset($GLOBALS['faults'][$stage])) throw $GLOBALS['faults'][$stage];
}
function pmssSystemdRuntimeAvailable() { observe('runtime'); return $GLOBALS['case'] !== 'no-runtime'; }
function pmssLockFileAcquire($path, $nonBlocking, $mode, $parents, $closeOnBusy, &$busy) {
    $GLOBALS['arguments'] = [$path, $nonBlocking, $mode, $parents, $closeOnBusy];
    observe('acquire');
    $busy = $GLOBALS['case'] === 'busy';
    if ($busy || $GLOBALS['case'] === 'unavailable') return false;
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function logMessage($message) { $GLOBALS['logs'][] = $message; }
function getenv($key) { observe('read'); return $GLOBALS['prior']; }
function putenv($assignment) {
    $GLOBALS['restores'][] = [$assignment, is_resource($GLOBALS['handle'])];
    observe('restore');
    return $GLOBALS['case'] !== 'restore-false';
}
function pmssLockHandleExportChildCloseFds($handle) { observe('export'); }
function shell_exec($command) {
    observe('manager');
    return $GLOBALS['case'] === 'shutdown' ? 'stopping' : 'running';
}
function pmssSystemdUnitState($action, $unit) { observe('state'); return $action === 'is-enabled' ? 'enabled' : 'inactive'; }
function pmssNginxServiceCommand($action, $verify) { return 'fixture-nginx-start'; }
function runStep($description, $command) { observe('step'); return 0; }
function pmssLockHandleRelease($handle) {
    $GLOBALS['releasedOwned'] = $handle === $GLOBALS['handle'] && is_resource($handle);
    \fclose($handle);
    observe('release');
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/services/systemd/recovery.php', 'RequiredServicesCleanupFixture');
        $script .= <<<'PHP'
$cases = ['success' => [], 'no-runtime' => [], 'busy' => [], 'unavailable' => [],
    'acquire' => ['acquire'], 'read' => ['read'], 'export' => ['export'],
    'manager' => ['manager'], 'state' => ['state'], 'step' => ['step'],
    'restore' => ['restore'], 'release' => ['release'], 'read-release' => ['read', 'release'],
    'export-restore' => ['export', 'restore'], 'step-restore' => ['step', 'restore'],
    'restore-release' => ['restore', 'release'], 'step-restore-release' => ['step', 'restore', 'release'],
    'shutdown' => [], 'restore-false' => []];
$rows = [];
foreach ($cases as $case => $stages) {
    foreach (['RuntimeException', 'Error'] as $kind) {
        foreach ([false, '', '7,9'] as $prior) {
            $GLOBALS['case'] = $case; $GLOBALS['prior'] = $prior;
            $GLOBALS['events'] = $GLOBALS['restores'] = $GLOBALS['logs'] = $GLOBALS['arguments'] = [];
            $GLOBALS['handle'] = null; $GLOBALS['releasedOwned'] = false; $GLOBALS['faults'] = [];
            foreach ($stages as $stage) $GLOBALS['faults'][$stage] = new $kind($stage.' fault');
            $caught = null; $result = 'not-returned';
            try { $result = pmssRecoverRequiredSystemdServices('/fixture/update.lock'); }
            catch (\Throwable $error) { $caught = $error; }
            $failure = null;
            foreach ($GLOBALS['faults'] as $stage => $error) if ($caught === $error) $failure = $stage;
            // Record ownership before fixture cleanup can conceal a leaked handle.
            $live = is_resource($GLOBALS['handle']);
            $rows[] = [$case, $kind, $prior, $stages, $result, $failure, $caught !== null,
                $live, $GLOBALS['releasedOwned'], $GLOBALS['events'], $GLOBALS['restores'], $GLOBALS['logs'], $GLOBALS['arguments']];
            if ($live) \fclose($GLOBALS['handle']);
        }
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(114, count($rows));
        foreach ($rows as [$case, $kind, $prior, $stages, $result, $failure, $threw, $live, $released, $events, $restores, $logs, $arguments]) {
            $label = $case.'/'.$kind.'/'.var_export($prior, true);
            $acquired = !in_array($case, ['no-runtime', 'busy', 'unavailable', 'acquire'], true);
            $restored = $acquired && !in_array('read', $stages, true);
            $expectedFailure = $stages === [] ? null : end($stages);
            $this->assertSame([$stages === [] ? null : 'not-returned', $expectedFailure, $stages !== [], false, $acquired],
                [$result, $failure, $threw, $live, $released], $label);
            $this->assertSame($case === 'no-runtime' ? [] : ['/fixture/update.lock', true, 'c', true, true], $arguments, $label);
            // Restoration must run with the lock held, preserving absent versus empty values.
            $this->assertSame($restored ? [[PMSS_UPDATE_LOCK_FDS_ENV.($prior === false ? '' : '='.$prior), true]] : [], $restores, $label);
            $this->assertSame($acquired ? ['release'] : [], array_values(array_filter($events, static function ($event): bool {
                return $event === 'release';
            })), $label);
            if ($acquired) $this->assertSame($restored ? ['restore', 'release'] : ['read', 'release'], array_slice($events, -2), $label);
            if ($case === 'success' || $case === 'restore-false') {
                $expected = ['runtime', 'acquire', 'read', 'export'];
                for ($index = 0; $index < 4; $index++) $expected = array_merge($expected, ['manager', 'state', 'state', 'step']);
                $this->assertSame(array_merge($expected, ['restore', 'release']), $events, $label);
            }
            if ($case === 'shutdown') $this->assertSame(['runtime', 'acquire', 'read', 'export', 'manager', 'restore', 'release'], $events, $label);
            $expectedLogs = $case === 'busy' ? ['[SKIP] Required service recovery: update lock busy']
                : ($case === 'unavailable' ? ['[WARN] Required service recovery: update lock unavailable'] : []);
            $this->assertSame($expectedLogs, $logs, $label);
        }
    }
}
