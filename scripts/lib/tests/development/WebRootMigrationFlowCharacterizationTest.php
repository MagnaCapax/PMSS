<?php
namespace PMSS\Tests;

/** Characterize durable web-root migration ordering, rollback and diagnostics. */
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users/webRoot.php';

class WebRootMigrationFlowCharacterizationTest extends TestCase
{
    public function testMigrationRetainsVerificationLinkAndRollbackBoundaries(): void
    {
        // Implementation: scripts/lib/update/users/webRootMigration.php.
        // Account guards stay in scripts/lib/update/users/webRootAccount.php and
        // scripts/lib/update/users/webRootMutation.php; all operations below are intercepted.
        $script = <<<'PHP'
namespace WebRootMigrationFlowFixture;
function pmssUserWebRootMigrationRelativeTarget($home, $source, $target) {
    return $GLOBALS['mode'] === 'unsafe' ? null : '../durable/state';
}
function pmssUserWebRootMigrationParentSafe($home, $path) { return true; }
function is_link($path) { return false; }
function file_exists($path) { return $path === '/fixture/home/www/state'; }
function is_dir($path) { return true; }
function pmssUserWebRootMigrationDestinationConflict($target, $relative, $source, $empty) {
    return $GLOBALS['mode'] === 'conflict' ? 'fixture conflict' : null;
}
function pmssUserWebRootMigrationPrepareParent($user, $home, $parent, $logger) {
    $GLOBALS['events'][] = ['prepare', $parent];
    return $GLOBALS['mode'] !== 'prepare-false';
}
function pmssPathExistsOrLink($path) {
    $GLOBALS['events'][] = ['exists', $path];
    return $path === '/fixture/home/www/state' && $GLOBALS['mode'] === 'source-race';
}
function pmssUserWebRootMigrationSnapshot($path, $identity) {
    $GLOBALS['events'][] = ['snapshot', $path, $identity];
    if ($path === '/fixture/home/www/state') return $GLOBALS['mode'] === 'before-null' ? null : ['snapshot'];
    if (in_array($GLOBALS['mode'], ['snapshot-null', 'verify-rollback-false'], true)) return null;
    return $GLOBALS['mode'] === 'snapshot-mismatch' ? ['changed'] : ['snapshot'];
}
function pmssUserWebRootMigrationRenameAsAccount($user, $home, $source, $target) {
    $GLOBALS['events'][] = ['rename', $user, $home, $source, $target];
    return $target === '/fixture/home/durable/state' ? $GLOBALS['mode'] !== 'move-false'
        : !in_array($GLOBALS['mode'], ['rollback-false', 'verify-rollback-false'], true);
}
function pmssUserWebRootMigrationRun($user, $home, $paths, $command) {
    $GLOBALS['events'][] = ['run', $user, $home, $paths, $command];
    return !in_array($GLOBALS['mode'], ['link-false', 'rollback-false'], true);
}
function pmssUserWebRootMigrationLog($user, $logger, $message) {
    $GLOBALS['events'][] = ['log', $user, $message];
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssUserMigrateWebRootPath', 'WebRootMigrationFlowFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (['success', 'snapshot-null', 'snapshot-mismatch', 'link-false', 'rollback-false',
    'verify-rollback-false', 'source-race', 'move-false', 'conflict', 'prepare-false', 'unsafe', 'before-null'] as $mode) {
    $GLOBALS['mode'] = $mode; $GLOBALS['events'] = [];
    pmssUserMigrateWebRootPath('fixtur01', '/fixture/home/', 'www/state', 'durable/state', static function () {});
    $rows[$mode] = $GLOBALS['events'];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(12, count($rows));
        $source = '/fixture/home/www/state';
        $target = '/fixture/home/durable/state';
        $snapshot = ['snapshot', $source, true];
        $prepare = ['prepare', '/fixture/home/durable'];
        $checkTarget = ['exists', $target];
        $move = ['rename', 'fixtur01', '/fixture/home', $source, $target];
        $after = ['snapshot', $target, true];
        $checkSource = ['exists', $source];
        $link = ['run', 'fixtur01', '/fixture/home', [$source], "ln -sT -- '../durable/state' '/fixture/home/www/state'"];
        $rollback = ['rename', 'fixtur01', '/fixture/home', $target, $source];
        $traces = [
            'success' => [$snapshot, $prepare, $checkTarget, $move, $after, $checkSource, $link],
            'snapshot-null' => [$snapshot, $prepare, $checkTarget, $move, $after, $checkSource, $rollback],
            'snapshot-mismatch' => [$snapshot, $prepare, $checkTarget, $move, $after, $checkSource, $rollback],
            'link-false' => [$snapshot, $prepare, $checkTarget, $move, $after, $checkSource, $link, $checkSource, $rollback],
            'rollback-false' => [$snapshot, $prepare, $checkTarget, $move, $after, $checkSource, $link, $checkSource, $rollback],
            'verify-rollback-false' => [$snapshot, $prepare, $checkTarget, $move, $after, $checkSource, $rollback],
            'source-race' => [$snapshot, $prepare, $checkTarget, $move, $after, $checkSource, $checkSource],
            'move-false' => [$snapshot, $prepare, $checkTarget, $move], 'conflict' => [],
            'prepare-false' => [$snapshot, $prepare], 'unsafe' => [], 'before-null' => [$snapshot],
        ];
        $messages = ['success' => 'Migrated www/state to durable storage',
            'snapshot-null' => 'Verification failed; restored www/state',
            'snapshot-mismatch' => 'Verification failed; restored www/state',
            'link-false' => 'Unable to link www/state; restored original path',
            'rollback-false' => 'Unable to link www/state; durable copy preserved at durable/state',
            'verify-rollback-false' => 'Verification failed; durable copy preserved at durable/state',
            'source-race' => 'Unable to link www/state; durable copy preserved at durable/state',
            'move-false' => 'Unable to move www/state to durable storage',
            'prepare-false' => 'Unable to move www/state to durable storage', 'conflict' => 'fixture conflict',
            'unsafe' => 'Refusing unsafe web-root migration path: www/state',
            'before-null' => 'Refusing unsafe symlink or unreadable tree at www/state'];
        foreach ($traces as $mode => $trace) {
            if (isset($messages[$mode])) $trace[] = ['log', 'fixtur01', $messages[$mode]];
            $this->assertSame($trace, $rows[$mode], $mode);
        }
    }
}
