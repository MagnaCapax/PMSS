<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users/webRoot.php';

/** Characterize webRoot.php dispatch/logging and webRootAccount.php command bytes without host I/O. */
final class WebRootMigrationDispatchCharacterizationTest extends TestCase
{
    public function testMigrationOrderContextRefusalsAndLoggerFaults(): void
    {
        $script = <<<'PHP'
namespace { function logMessage($message) { throw new \LogicException('Unexpected default logger call'); } }
namespace WebRootDispatchFixture {
function pmssUserContextIdentity($ctx) { return [$ctx['user'], $ctx['home']]; }
function record($phase, $user, $home, $source, $target, $misfile, $logger) {
    $GLOBALS['calls'][] = [$phase, $user, $home, $source, $target, $misfile, is_callable($logger)];
    if ($GLOBALS['faultPhase'] === $phase) throw $GLOBALS['fault'];
    if ($GLOBALS['custom']) pmssUserWebRootMigrationLog($user, $logger, $phase.':'.$source);
}
function pmssUserMigrateWebRootBasenameCollapsePath($user, $home, $source, $target, $misfile, $logger) {
    record('copy', $user, $home, $source, $target, $misfile, $logger);
}
function pmssUserMigrateWebRootPath($user, $home, $source, $target, $logger) {
    record('move', $user, $home, $source, $target, null, $logger);
}
PHP;
        foreach (['pmssUserWebRootMigrationLog', 'pmssUserMigrateWebRootState'] as $function) {
            $script .= \pmssTestingFunctionExport($function, 'WebRootDispatchFixture');
        }
        $script .= <<<'PHP'
$rows = [];
foreach (['custom', 'default', 'empty-user', 'empty-home', 'copy-fault', 'move-fault', 'logger-fault'] as $case) {
    $GLOBALS['calls'] = $GLOBALS['logs'] = [];
    $GLOBALS['faultPhase'] = $case === 'copy-fault' ? 'copy' : ($case === 'move-fault' ? 'move' : '');
    $GLOBALS['custom'] = $case !== 'default';
    $GLOBALS['fault'] = new \RuntimeException('fixture fault');
    $logger = static function ($message) use ($case) {
        $GLOBALS['logs'][] = $message;
        if ($case === 'logger-fault') throw $GLOBALS['fault'];
    };
    $caught = null;
    try {
        pmssUserMigrateWebRootState(['user' => $case === 'empty-user' ? '' : 'fixtur01',
            'home' => $case === 'empty-home' ? '' : '/fixture/home'], $GLOBALS['custom'] ? $logger : null);
    } catch (\Throwable $error) { $caught = $error; }
    $rows[] = [$GLOBALS['calls'], $GLOBALS['logs'], $caught === $GLOBALS['fault']];
}
echo json_encode($rows);
}
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $calls = [
            ['copy', 'fixtur01', '/fixture/home', 'www/public', '.local/share/pmss/public', 'public', true],
            ['move', 'fixtur01', '/fixture/home', 'www/public', '.local/share/pmss/public', null, true],
            ['copy', 'fixtur01', '/fixture/home', 'www/rutorrent/share', '.local/share/pmss/rutorrent/share', 'share', true],
            ['move', 'fixtur01', '/fixture/home', 'www/rutorrent/share', '.local/share/pmss/rutorrent/share', null, true],
        ];
        $logs = ['[user:fixtur01] copy:www/public', '[user:fixtur01] move:www/public',
            '[user:fixtur01] copy:www/rutorrent/share', '[user:fixtur01] move:www/rutorrent/share'];
        $this->assertSame([
            [$calls, $logs, false], [$calls, [], false], [[], [], false], [[], [], false],
            [array_slice($calls, 0, 1), [], true], [array_slice($calls, 0, 2), array_slice($logs, 0, 1), true],
            [array_slice($calls, 0, 1), array_slice($logs, 0, 1), true],
        ], $rows);
    }

    public function testCopyAndRemovalRetainGuardsAuthorityAndQuoting(): void
    {
        $script = <<<'PHP'
namespace WebRootCommandFixture;
function is_link($source) { $GLOBALS['events'][] = 'link'; return $GLOBALS['case'] === 'link'; }
function pmssPathExistsOrLink($target) { $GLOBALS['events'][] = 'target'; return $GLOBALS['case'] === 'occupied'; }
function pmssUserWebRootMigrationRun($user, $home, $paths, $command) {
    $GLOBALS['events'][] = [$user, $home, $paths, $command];
    return $GLOBALS['case'] !== 'refused';
}
PHP;
        foreach (['pmssUserWebRootMigrationCopyTree', 'pmssUserWebRootMigrationRemoveCopy'] as $function) {
            $script .= \pmssTestingFunctionExport($function, 'WebRootCommandFixture');
        }
        $script .= <<<'PHP'
$rows = [];
foreach (['link', 'occupied', 'copied', 'refused', 'remove'] as $case) {
    $GLOBALS['case'] = $case; $GLOBALS['events'] = [];
    $result = $case === 'remove'
        ? pmssUserWebRootMigrationRemoveCopy('fixtur01', '/fixture/home', "/fixture/home/part's")
        : pmssUserWebRootMigrationCopyTree('fixtur01', '/fixture/home', '/fixture/home/source', "/fixture/home/part's");
    $rows[] = [$result, $GLOBALS['events']];
}
echo json_encode($rows);
PHP;
        $copy = ['fixtur01', '/fixture/home', ['/fixture/home/source', "/fixture/home/part's"],
            "cp -aT -- '/fixture/home/source' '/fixture/home/part'\\''s'"];
        $remove = ['fixtur01', '/fixture/home', ["/fixture/home/part's"], "rm -rf -- '/fixture/home/part'\\''s'"];
        $this->assertSame([
            [false, ['link']], [false, ['link', 'target']], [true, ['link', 'target', $copy]],
            [false, ['link', 'target', $copy]], [null, [$remove]],
        ], $this->pmssRunInlinePhpJson($script));
    }
}
