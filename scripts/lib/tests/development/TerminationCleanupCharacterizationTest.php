<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock removal decisions and audit bytes without touching account paths or services. */
class TerminationCleanupCharacterizationTest extends TestCase
{
    public function testRacedLingerCleanupPreservesLiveAccountsAndCompletionGuards(): void
    {
        $script = 'namespace TerminationLingerFixture; require_once '.var_export($this->pmssRepoPath('scripts/lib/user/terminationCleanup.php'), true).';';
        $script .= <<<'PHP'
function pmssUserAccountLookup($username) { return $GLOBALS['live'] ? ['uid' => 1000] : null; }
function pmssPathExistsOrLink($path) { return $path === '/var/lib/systemd/linger/user1234' && $GLOBALS['linger']; }
function pmssTerminateUserUnlinkPath($username, $phase, $path, $dryRun) {
    $GLOBALS['calls'][] = [$username, $phase, $path, $dryRun];
    if (!$dryRun && $GLOBALS['removeOk']) $GLOBALS['linger'] = false;
    return $GLOBALS['removeOk'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/termination/completion.php', 'TerminationLingerFixture');
        $script .= <<<'PHP'
$paths = ['home' => '/absent-home', 'user_backup' => '/absent-backup', 'user_backup_superseded' => '/absent-superseded'];
$purge = [];
foreach (array_keys($paths) as $label) {
    $purge['remove_'.$label.'_initial'] = $purge['clear_immutable_'.$label] = $purge['remove_'.$label.'_leftovers'] = 0;
}
$results = [];
foreach ([[false, false, true, true], [false, false, true, false], [false, true, true, true],
    [true, false, true, true], [false, false, false, true]] as [$live, $dry, $linger, $removeOk]) {
    $calls = [];
    $cleared = pmssTerminateUserClearLinger('user1234', $dry);
    $results[] = [$cleared, $linger, $calls, pmssTerminateUserCompletion('user1234', $paths, $purge, $dry)['line']];
}
$live = false;
foreach (['', '../user1234', "user1234\0other", 'user1234/other', 'user1234;other'] as $invalid) {
    $calls = [];
    try { pmssTerminateUserClearLinger($invalid, false); $results[] = 'accepted'; }
    catch (\InvalidArgumentException $error) { $results[] = [$error->getMessage(), $calls]; }
}
echo json_encode($results);
PHP;
        $call = ['user1234', 'remove_stale_linger', '/var/lib/systemd/linger/user1234', false];
        $dryCall = $call;
        $dryCall[3] = true;
        $expected = [
            [true, false, [$call], '## Done. User termination verified.'],
            [false, true, [$call], '## Incomplete: linger_still_present'],
            [true, true, [$dryCall], '## Done. User termination flow completed (dry run).'],
            [false, true, [], '## Incomplete: account_still_present'],
            [true, false, [$call], '## Done. User termination verified.'],
        ];
        foreach (range(1, 5) as $unused) $expected[] = ['Refusing unsafe linger cleanup', []];
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));

        // Keep the cleanup outside the initial account-only phase, including resumes.
        $this->pmssAssertRepoFileContract('scripts/terminateUser.php', ['ordered' => [[
            'pmssTerminateUserFinalRemovalSteps($username, $uid, $killUserCommand)',
            'pmssTerminateUserClearLinger($username, $dryRun);',
            'pmssTerminateUserCompletion($username, $purgePaths, $purgeResults, $dryRun)',
        ]]]);
    }

    public function testPortValidationRetainsExistingValuesAndWarningPayloads(): void
    {
        $script = 'namespace TerminationPortsFixture; require_once '.var_export($this->pmssRepoPath('scripts/lib/user/terminationCleanup.php'), true).';';
        $script .= 'function pmssUserLifecycleContextLogStatusMessage(...$args) { $GLOBALS["events"][] = $args; }';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/termination/ports.php', 'TerminationPortsFixture');
        $script .= <<<'PHP'
$ports = ['scgi' => 4000]; $GLOBALS['events'] = [];
foreach ([['unknown', '50'], ['scgi', '0'], ['scgi', '65536'], ['scgi', '1e3'], ['scgi', str_repeat('9', 100)], ['dht', '65535'], ['listen', '1']] as [$type, $raw]) {
    pmssTerminateUserRtorrentPortRecord('user1234', $ports, $type, $raw);
}
echo json_encode([$ports, $GLOBALS['events']]);
PHP;
        $events = [];
        foreach ([['unknown', '50'], ['scgi', '0'], ['scgi', '65536'], ['scgi', '1e3'], ['scgi', str_repeat('9', 100)]] as [$type, $raw]) {
            $events[] = ['terminate', 'cleanup_ports_config_invalid', 'user1234', 'WARN',
                'Invalid rTorrent '.($type === 'unknown' ? 'port type' : 'port').' in config; skipping port reservation cleanup',
                ['type' => $type, 'raw_port' => $raw]];
        }
        $this->assertSame([['scgi' => 4000, 'dht' => 65535, 'listen' => 1], $events], $this->pmssRunInlinePhpJson($script));
    }

    public function testRemovalDecisionAndAuditMatrix(): void
    {
        $script = 'namespace TerminationCleanupFixture; require_once '.var_export($this->pmssRepoPath('scripts/lib/user/terminationCleanup.php'), true).';';
        $script .= <<<'PHP'
$events = [];
function pmssUserLifecycleContextLogStatusMessage(...$args) { $GLOBALS['events'][] = $args; }
function pmssPathExistsOrLink($path) { return $path !== 'missing'; }
function is_dir($path) { return $path !== 'missing'; }
function pmssDirectoryEntriesRead($path) { return $path === 'scan-failed' ? false : ($path === 'nonempty' ? ['keep'] : []); }
function unlink($path) { return $path !== 'failed'; }
function rmdir($path) { return $path !== 'failed'; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/terminationCleanup.php', 'TerminationCleanupFixture',
            ['termination/filesystem.php']);
        $script .= <<<'PHP'
$results = [];
foreach (['pmssTerminateUserUnlinkPath', 'pmssTerminateUserRemoveEmptyDir'] as $operation) {
    $call = __NAMESPACE__.'\\'.$operation;
    foreach ([true, false] as $dry) {
        foreach (["bad\0path", 'missing', 'scan-failed', 'nonempty', 'failed', 'present'] as $path) {
            $GLOBALS['events'] = [];
            $results[] = [$call('user1234', 'phase', $path, $dry), $GLOBALS['events']];
        }
    }
}
echo json_encode($results);
PHP;
        $event = static function (bool $ok, string $status, string $message, string $path): array {
            return [$ok, [['terminate', 'phase', 'user1234', $status, $message, ['path' => $path]]]];
        };
        $expected = [];
        foreach (['file', 'directory'] as $kind) {
            foreach ([true, false] as $dry) {
                $expected[] = $event(false, 'ERR', 'Refusing unsafe '.$kind.' path', "bad\0path");
                $expected[] = [true, []];
                $message = $kind === 'file' ? 'Removed file' : 'Removed empty directory';
                foreach (['scan-failed', 'nonempty', 'failed', 'present'] as $path) {
                    $expected[] = $kind === 'directory' && $path === 'scan-failed'
                        ? $event(false, 'ERR', 'Failed to read directory', $path)
                        : ($kind === 'directory' && $path === 'nonempty' ? [true, []]
                            : $event($dry || $path !== 'failed', $dry ? 'SKIP' : ($path === 'failed' ? 'ERR' : 'OK'),
                                $dry ? 'Dry run; '.$kind.' not removed' : ($path === 'failed' ? 'Failed to remove '.($kind === 'file' ? 'file' : 'empty directory') : $message), $path));
                }
            }
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }
}
