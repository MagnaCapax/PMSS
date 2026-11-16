<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users/webRootReconcile.php';

/** Full-restore staging in scripts/lib/update/users/webRootReconcile.php; intercepted I/O only. */
class WebRootReconcileFullCleanupSafetyTest extends TestCase
{
    public function testFullRestoreRetainsOnlyPublishedStagesThroughFaults(): void
    {
        $script = <<<'PHP'
namespace WebRootFullCleanupFixture;
function observe($phase, $value = true) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
    return $GLOBALS['mode'] === $phase.'-false' ? false : $value;
}
// No host filesystem or account operation is executed by this fixture.
function random_bytes($length) { observe('random'); return str_repeat("\xaa", $length); }
function pmssPathAbsoluteStringIsSafe($path, $options) { return observe('path'); }
// Cleanup and publication must use the same account and exact acquired staging name.
function checkStage($user, $home, $stage) {
    if ([$user, $home, $stage] !== ['fixtur01', '/fixture/home',
        '/fixture/home/.pmss-web-root-reconcile-aaaaaaaaaaaa'])
        throw new \LogicException('Unexpected staging authority or path');
}
function pmssUserWebRootMigrationRun($user, $home, $paths, $command) {
    checkStage($user, $home, $paths[0]);
    if ($command !== "mkdir -m 0700 -- '/fixture/home/.pmss-web-root-reconcile-aaaaaaaaaaaa'")
        throw new \LogicException('Unexpected stage creation command');
    return $GLOBALS['owned'] = observe('mkdir');
}
function pmssUserWebRootReconcileTreeIsSafe($path, $home, $placed = true) {
    return observe($placed ? 'stage' : 'skeleton');
}
function pmssUserWebRootReconcileCopyEntry($source, $target, $home, $relative, &$stats, $user) {
    checkStage($user, $home, $target);
    if ([$source, $relative] !== ['/fixture/skel/www', '']) throw new \LogicException('Unexpected copy');
    $stats['files_restored']++;
    return observe('copy');
}
function pmssUserWebRootReconcileApplyOwnership($path, $user) { return observe('owner'); }
function pmssDirectoryEntriesRead($path) {
    return observe('entries', $GLOBALS['mode'] === 'scan-failed' ? false
        : ($GLOBALS['mode'] === 'occupied' ? ['keep.php'] : []));
}
function pmssPathExistsOrLink($path) { return observe('exists', $GLOBALS['mode'] !== 'missing'); }
function is_link($path) { observe('link'); return $GLOBALS['mode'] === 'linked'; }
function pmssUserWebRootMigrationRenameAsAccount($user, $home, $source, $target) {
    checkStage($user, $home, $source);
    if ($target !== '/fixture/home/www') throw new \LogicException('Unexpected publication');
    $GLOBALS['published'] = observe('rename');
    if ($GLOBALS['published']) $GLOBALS['owned'] = false;
    return $GLOBALS['published'];
}
function pmssUserWebRootMigrationRemoveCopy($user, $home, $stage) {
    checkStage($user, $home, $stage);
    if (!$GLOBALS['owned'] || $GLOBALS['published']) throw new \LogicException('Unowned cleanup');
    $result = observe('cleanup');
    if ($result) $GLOBALS['owned'] = false;
}
function pmssUserWebRootMigrationLog($user, $logger, $message) {
    $GLOBALS['message'] = $message;
    observe('log');
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssUserWebRootReconcileFull', 'WebRootFullCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_WEBROOT_FULL_CLEANUP'), true) as [$mode, $phases]) {
    foreach ([\RuntimeException::class, \Error::class] as $kind) {
        $GLOBALS['mode'] = $mode; $GLOBALS['events'] = $GLOBALS['faults'] = [];
        $GLOBALS['owned'] = $GLOBALS['published'] = false; $GLOBALS['message'] = null;
        foreach ($phases as $phase) $GLOBALS['faults'][$phase] = new $kind($phase);
        $stats = ['files_restored' => 0]; $caught = $result = null;
        try {
            $result = pmssUserWebRootReconcileFull('/fixture/home/www', '/fixture/skel/www',
                '/fixture/home', 'fixtur01', null, $stats);
        } catch (\Throwable $error) { $caught = $error; }
        // Name-generation faults retain their existing logged false result.
        // Other faults must preserve the original object, including cleanup precedence.
        $expectedFault = $phases === [] || $phases === ['random'] ? null : end($GLOBALS['faults']);
        $rows[] = [$result, $GLOBALS['events'], $GLOBALS['owned'], $GLOBALS['published'],
            $GLOBALS['message'], $stats['files_restored'], $caught === $expectedFault];
    }
}
echo json_encode($rows);
PHP;
        $normal = ['random', 'path', 'mkdir', 'skeleton', 'copy', 'stage', 'owner', 'entries', 'exists', 'link', 'rename', 'log'];
        $cases = [];
        // Refusals preserve short-circuit order, statistics and diagnostic bytes.
        foreach (['success', 'missing', 'path-false', 'mkdir-false', 'skeleton-false', 'copy-false',
            'stage-false', 'owner-false', 'scan-failed', 'occupied', 'linked', 'rename-false'] as $mode) $cases[] = [$mode, []];
        foreach ($normal as $phase) $cases[] = ['success', [$phase]];
        // Cleanup must still run after every throwing operation that owns a stage.
        foreach (['skeleton', 'copy', 'stage', 'owner', 'entries', 'exists', 'link', 'rename'] as $phase) {
            $cases[] = ['success', [$phase, 'cleanup']];
        }
        // Failed cleanup keeps ownership observable; cleanup and refusal-log faults must not retry.
        $cases[] = ['copy-false', ['cleanup']];
        $cases[] = ['copy-false', ['log']];
        $cases[] = ['cleanup-false', ['copy']];
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_WEBROOT_FULL_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases) * 2, count($rows));
        foreach ($cases as $index => [$mode, $phases]) {
            $trace = $normal;
            if ($mode === 'missing') $trace = array_values(array_diff($trace, ['link']));
            $stop = substr($mode, -6) === '-false' && $mode !== 'cleanup-false' ? substr($mode, 0, -6)
                : (in_array($mode, ['scan-failed', 'occupied', 'linked'], true) ? 'link' : null);
            if ($stop !== null) {
                $trace = array_slice($trace, 0, array_search($stop, $trace, true) + 1);
                if (!in_array($stop, ['path', 'mkdir'], true)) { $trace[] = 'cleanup'; $trace[] = 'log'; }
            }
            if ($phases !== []) $trace = array_slice($trace, 0, array_search($phases[0], $trace, true) + 1);
            // Observe ownership separately from the trace so leaks and unsafe removals fail.
            $acquired = in_array('mkdir', $trace, true) && $mode !== 'mkdir-false' && !in_array('mkdir', $phases, true);
            $published = in_array('rename', $trace, true) && $mode !== 'rename-false' && !in_array('rename', $phases, true);
            if ($phases !== [] && $acquired && !$published && !in_array('cleanup', $trace, true)) $trace[] = 'cleanup';
            if ($phases === ['random']) $trace[] = 'log';
            $owned = $acquired && !$published && ($mode === 'cleanup-false' || in_array('cleanup', $phases, true));
            $result = $phases === [] ? $published : ($phases === ['random'] ? false : null);
            $message = in_array('log', $trace, true) ? ($phases === ['random'] ? 'Unable to create web-root staging name'
                : ($published ? 'Restored complete web root from skeleton' : 'Full web-root restore refused or failed')) : null;
            $expected = [$result, $trace, $owned, $published, $message, in_array('copy', $trace, true) ? 1 : 0, true];
            foreach ([0, 1] as $offset) $this->assertSame($expected, $rows[$index * 2 + $offset], $mode.':'.implode('+', $phases));
        }
    }
}
