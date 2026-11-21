<?php
/** Verify durable web-root rollback through intercepted migration faults. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users/webRoot.php';

class WebRootMigrationRollbackSafetyTest extends TestCase
{
    public function testMovedTreesRetainGuardedRollbackUntilLinkingSucceeds(): void
    {
        // Implementation: scripts/lib/update/users/webRootMigration.php.
        // Every filesystem and account mutation is intercepted; no real paths are touched.
        $script = <<<'PHP'
namespace WebRootMigrationRollbackFixture;
function observe($phase, $value = null) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
    return $value;
}
function pmssUserWebRootMigrationRelativeTarget(...$args) { return '../durable/state'; }
function pmssUserWebRootMigrationParentSafe(...$args) { return true; }
function is_link($path) { return false; }
function file_exists($path) { return $path === '/fixture/home/www/state'; }
function is_dir($path) { return true; }
function pmssUserWebRootMigrationDestinationConflict(...$args) { return null; }
function pmssUserWebRootMigrationPrepareParent(...$args) { return true; }
function pmssPathExistsOrLink($path) {
    if ($path === '/fixture/home/durable/state') return false;
    // The first source probe can itself be the rollback guard after a snapshot fault.
    ++$GLOBALS['sourceChecks'];
    $phase = $GLOBALS['sourceChecks'] === 1 ? 'link-check' : 'rollback-check';
    return observe($phase, $GLOBALS['linked'] || $GLOBALS['mode'] === 'source-race');
}
function pmssUserWebRootMigrationSnapshot($path, $identity) {
    return observe($path === '/fixture/home/www/state' ? 'before' : 'after', ['snapshot']);
}
function pmssUserWebRootMigrationRenameAsAccount($user, $home, $source, $target) {
    // Assert rollback direction and authority separately from the simulated result.
    if ([$user, $home] !== ['fixtur01', '/fixture/home']) throw new \LogicException('Unexpected authority');
    if ([$source, $target] === ['/fixture/home/www/state', '/fixture/home/durable/state']) {
        return observe('move', true);
    }
    if ([$source, $target] !== ['/fixture/home/durable/state', '/fixture/home/www/state']) {
        throw new \LogicException('Unexpected rollback paths');
    }
    return $GLOBALS['restored'] = observe('rollback', $GLOBALS['mode'] !== 'rollback-false');
}
function pmssUserWebRootMigrationRun($user, $home, $paths, $command) {
    if ([$user, $home, $paths, $command] !== ['fixtur01', '/fixture/home', ['/fixture/home/www/state'],
        "ln -sT -- '../durable/state' '/fixture/home/www/state'"]) throw new \LogicException('Unexpected link');
    // A command may create the link before an observation throws; occupancy must prevent rollback.
    if ($GLOBALS['mode'] === 'link-created') $GLOBALS['linked'] = true;
    return $GLOBALS['linked'] = observe('link', $GLOBALS['mode'] !== 'link-false');
}
function pmssUserWebRootMigrationLog(...$args) { observe('log'); }
PHP;
        $script .= \pmssTestingFunctionExport('pmssUserMigrateWebRootPath', 'WebRootMigrationRollbackFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_WEBROOT_ROLLBACK'), true) as [$mode, $phases]) {
    foreach ([\RuntimeException::class, \Error::class] as $kind) {
        $GLOBALS['events'] = $GLOBALS['faults'] = [];
        $GLOBALS['mode'] = $mode; $GLOBALS['sourceChecks'] = 0;
        $GLOBALS['linked'] = $GLOBALS['restored'] = false;
        foreach ($phases as $phase) $GLOBALS['faults'][$phase] = new $kind($phase);
        $caught = null;
        try {
            pmssUserMigrateWebRootPath('fixtur01', '/fixture/home', 'www/state', 'durable/state', static function () {});
        } catch (\Throwable $error) { $caught = $error; }
        // Check object identity too: propagation must retain the original throwable.
        $phase = $caught === null ? null : $caught->getMessage();
        $rows[] = [$GLOBALS['events'], $GLOBALS['linked'], $GLOBALS['restored'], $phase,
            $caught === ($phase === null ? null : ($GLOBALS['faults'][$phase] ?? null))];
    }
}
echo json_encode($rows);
PHP;
        $normal = ['before', 'move', 'after', 'link-check', 'link', 'log'];
        // Pre-move faults own no rollback; later faults restore only an absent source.
        $cases = [
            ['success', [], $normal, true, false, null],
            ['success', ['before'], ['before'], false, false, 'before'],
            ['success', ['move'], ['before', 'move'], false, false, 'move'],
            ['success', ['after'], ['before', 'move', 'after', 'link-check', 'rollback'], false, true, 'after'],
            ['source-race', ['after'], ['before', 'move', 'after', 'link-check'], false, false, 'after'],
            ['rollback-false', ['after'], ['before', 'move', 'after', 'link-check', 'rollback'], false, false, 'after'],
            ['success', ['link-check'], ['before', 'move', 'after', 'link-check', 'rollback-check', 'rollback'], false, true, 'link-check'],
            ['success', ['link'], ['before', 'move', 'after', 'link-check', 'link', 'rollback-check', 'rollback'], false, true, 'link'],
            ['link-created', ['link'], ['before', 'move', 'after', 'link-check', 'link', 'rollback-check'], true, false, 'link'],
            ['success', ['log'], $normal, true, false, 'log'],
            // Combined failures preserve finally precedence and attempt cleanup once.
            ['success', ['after', 'rollback'], ['before', 'move', 'after', 'link-check', 'rollback'], false, false, 'rollback'],
            ['success', ['link', 'rollback-check'], ['before', 'move', 'after', 'link-check', 'link', 'rollback-check'], false, false, 'rollback-check'],
            ['link-false', ['rollback'], ['before', 'move', 'after', 'link-check', 'link', 'rollback-check', 'rollback'], false, false, 'rollback'],
            ['link-false', ['log'], ['before', 'move', 'after', 'link-check', 'link', 'rollback-check', 'rollback', 'log'], false, true, 'log'],
        ];
        $input = array_map(static function (array $case): array { return [$case[0], $case[1]]; }, $cases);
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_WEBROOT_ROLLBACK' => json_encode($input)]);
        $this->assertSame(count($cases) * 2, count($rows));
        foreach ($cases as $index => [$mode, $phases, $trace, $linked, $restored, $caught]) {
            foreach ([0, 1] as $offset) {
                $this->assertSame([$trace, $linked, $restored, $caught, true], $rows[$index * 2 + $offset], $mode.':'.implode('+', $phases));
            }
        }
    }
}
