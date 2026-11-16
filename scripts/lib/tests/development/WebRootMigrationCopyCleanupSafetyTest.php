<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users/webRoot.php';

/** Staging ownership in scripts/lib/update/users/webRoot.php; no real copies or removals. */
class WebRootMigrationCopyCleanupSafetyTest extends TestCase
{
    public function testCopyFaultsCleanOnlyUnpublishedStagingAndPreserveOrdinaryOrdering(): void
    {
        $script = <<<'PHP'
namespace WebRootMigrationCopyCleanupFixture;
function observe($phase, $value = null) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
    return $value;
}
// Every filesystem/account operation is intercepted; paths are synthetic observations.
function pmssPathExistsOrLink($path) { return $path === '/fixture/home/misfile'; }
function pmssUserWebRootMigrationRelativeTarget(...$args) { return '../durable/state'; }
function pmssUserWebRootMigrationParentSafe(...$args) { return true; }
function is_link($path) { return false; }
function file_exists($path) {
    return $path === '/fixture/home/durable/state'
        ? observe('target', $GLOBALS['mode'] === 'target-present' || $GLOBALS['mode'] === 'not-empty'
            || $GLOBALS['mode'] === 'rmdir-false') : false;
}
function pmssUserWebRootMigrationDestinationConflict($target, $relative, $source, $initial) {
    if ($initial) return $GLOBALS['mode'] === 'preflight-conflict' ? 'fixture conflict' : null;
    return observe('conflict', $GLOBALS['mode'] === 'conflict' ? 'fixture conflict' : null);
}
function pmssUserWebRootMigrationSnapshot($path) {
    if ($path === '/fixture/home/misfile') return ['fixture' => 'snapshot'];
    return observe('snapshot', $GLOBALS['mode'] === 'snapshot-null' ? null
        : ['fixture' => $GLOBALS['mode'] === 'snapshot-mismatch' ? 'changed' : 'snapshot']);
}
function pmssUserWebRootMigrationPrepareParent(...$args) { return true; }
function getmypid() { return 41; }
function random_bytes($length) { return str_repeat("\xaa", $length); }
function checkStage($user, $home, $path) {
    if ([$user, $home, $path] !== ['fixtur01', '/fixture/home',
        '/fixture/home/durable/.state.pmss-misfile-copy-41-aaaaaaaaaaaa'])
        throw new \LogicException('Unexpected staging authority or path');
}
function pmssUserWebRootMigrationCopyTree($user, $home, $source, $target) {
    checkStage($user, $home, $target);
    if ($source !== '/fixture/home/misfile') throw new \LogicException('Unexpected source');
    return observe('copy', $GLOBALS['mode'] !== 'copy-false');
}
function pmssUserWebRootMigrationRemoveCopy($user, $home, $path) {
    checkStage($user, $home, $path);
    if ($GLOBALS['published']) throw new \LogicException('Published copy must be retained');
    return observe('cleanup', $GLOBALS['mode'] !== 'cleanup-false');
}
function pmssUserWebRootMigrationDirectoryIsEmpty($target) {
    return observe('empty', $GLOBALS['mode'] !== 'not-empty');
}
function pmssUserWebRootMigrationRun($user, $home, $paths, $command) {
    if ([$paths, $command] !== [['/fixture/home/durable/state'], "rmdir -- '/fixture/home/durable/state'"])
        throw new \LogicException('Unexpected destination removal');
    return observe('rmdir', $GLOBALS['mode'] !== 'rmdir-false');
}
function pmssUserWebRootMigrationRenameAsAccount($user, $home, $source, $target) {
    checkStage($user, $home, $source);
    if ($target !== '/fixture/home/durable/state') throw new \LogicException('Unexpected publication');
    return $GLOBALS['published'] = observe('rename', $GLOBALS['mode'] !== 'rename-false');
}
function pmssUserWebRootMigrationLog($user, $logger, $message) {
    $GLOBALS['message'] = $message;
    observe('log');
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssUserMigrateWebRootBasenameCollapsePath', 'WebRootMigrationCopyCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_WEBROOT_COPY_CLEANUP'), true) as [$mode, $phases]) {
    foreach ([\RuntimeException::class, \Error::class] as $kind) {
        $GLOBALS['mode'] = $mode; $GLOBALS['events'] = $GLOBALS['faults'] = [];
        $GLOBALS['published'] = false; $GLOBALS['message'] = null;
        foreach ($phases as $phase) $GLOBALS['faults'][$phase] = new $kind($phase);
        $caught = null;
        try {
            pmssUserMigrateWebRootBasenameCollapsePath('fixtur01', '/fixture/home',
                'www/state', 'durable/state', 'misfile', static function () {});
        } catch (\Throwable $error) { $caught = $error; }
        $rows[] = [$GLOBALS['events'], $GLOBALS['published'], $GLOBALS['message'],
            $caught === ($phases === [] ? null : end($GLOBALS['faults']))];
    }
}
echo json_encode($rows);
PHP;
        $normal = ['copy', 'snapshot', 'conflict', 'target', 'rename', 'log'];
        $traces = ['success' => $normal, 'preflight-conflict' => ['log'],
            'copy-false' => ['copy', 'cleanup', 'log'], 'snapshot-null' => ['copy', 'snapshot', 'cleanup', 'log'],
            'snapshot-mismatch' => ['copy', 'snapshot', 'cleanup', 'log'],
            'conflict' => ['copy', 'snapshot', 'conflict', 'cleanup', 'log'],
            'not-empty' => ['copy', 'snapshot', 'conflict', 'target', 'empty', 'cleanup', 'log'],
            'rmdir-false' => ['copy', 'snapshot', 'conflict', 'target', 'empty', 'rmdir', 'cleanup', 'log'],
            'target-present' => ['copy', 'snapshot', 'conflict', 'target', 'empty', 'rmdir', 'rename', 'log'],
            'rename-false' => ['copy', 'snapshot', 'conflict', 'target', 'rename', 'cleanup', 'log']];
        $copied = 'Copied misfiled misfile to durable storage for www/state';
        $verification = 'Verification failed; removed incomplete copy for misfile';
        $conflict = 'Preserving destination conflict for www/state';
        $messages = ['success' => $copied, 'target-present' => $copied,
            'snapshot-null' => $verification, 'snapshot-mismatch' => $verification,
            'conflict' => 'fixture conflict', 'preflight-conflict' => 'fixture conflict',
            'not-empty' => $conflict, 'rmdir-false' => $conflict];
        // Ordinary refusals lock cleanup-before-log order; both throwable families visit every stage.
        $cases = [];
        foreach (array_keys($traces) as $mode) $cases[] = [$mode, []];
        foreach (['copy', 'snapshot', 'conflict', 'target', 'empty', 'rmdir', 'rename', 'log'] as $phase) {
            $cases[] = ['target-present', [$phase]];
        }
        // Combined faults prove cleanup precedence, while cleanup/log faults must not trigger retries.
        foreach (['copy', 'snapshot', 'rename'] as $phase) $cases[] = ['success', [$phase, 'cleanup']];
        foreach (['cleanup', 'log'] as $phase) $cases[] = ['copy-false', [$phase]];
        $cases[] = ['cleanup-false', ['copy']];
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_WEBROOT_COPY_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases) * 2, count($rows));
        foreach ($cases as $index => [$mode, $phases]) {
            // A throwing operation stops the ordinary trace; finally adds only an unattempted cleanup.
            $trace = $traces[$mode] ?? $normal;
            if ($phases !== []) $trace = array_slice($trace, 0, array_search($phases[0], $trace, true) + 1);
            $published = in_array('rename', $trace, true) && $mode !== 'rename-false' && !in_array('rename', $phases, true);
            if ($phases !== [] && !in_array('cleanup', $trace, true) && !$published
                && $mode !== 'preflight-conflict') $trace[] = 'cleanup';
            $message = in_array('log', $trace, true)
                ? ($messages[$mode] ?? 'Unable to copy misfiled misfile to durable storage') : null;
            foreach ([0, 1] as $offset) $this->assertSame([$trace, $published, $message, true], $rows[$index * 2 + $offset], $mode.':'.implode('+', $phases));
        }
    }
}
