<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * A terminated account's per-user resource and traffic logs are kept (ADR 0044) but moved out of the name-keyed
 * path, so a later account with the same name does not read them as its own usage (#1307 acceptance UC12).
 */
class TerminateUserUsageHistoryTest extends TestCase
{
    public function testHistoryMovesAsideOnlyOnceTheNameIsUnassigned(): void
    {
        $root = $this->pmssMakeTempDir('pmss-usage-history-', 0700);
        $files = ['resources/user1234', 'traffic/user1234', 'traffic/user1234-localnet', 'traffic-ingress/user1234',
            'traffic/user12345', 'resources/other', 'run/resourceStats/user1234', 'run/trafficStats/user1234', 'run/resourceStats/other'];
        foreach ($files as $rel) {
            if (!is_dir(dirname($root.'/'.$rel))) mkdir(dirname($root.'/'.$rel), 0755, true);
            file_put_contents($root.'/'.$rel, $rel."\n");
        }
        $script = 'namespace UsageHistoryFixture; require_once '.var_export($this->pmssRepoPath('scripts/lib/user/terminationCleanup.php'), true).';';
        $script .= 'function pmssUserAccountLookup($u) { return $GLOBALS[\'live\'] ? [\'uid\' => 1000] : null; }';
        $script .= 'function pmssUserLifecycleContextLogStatusMessage($a, $phase, $u, $status, $m, $x = []) { $GLOBALS[\'log\'][] = [$phase, $status]; }';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/termination/completion.php', 'UsageHistoryFixture');
        $script .= '$root = '.var_export($root, true).'; $out = [];'
            .' foreach ([[true, false], [false, true], [false, false]] as [$live, $dry]) { $log = [];'
            .' $out[] = [pmssTerminateUserArchiveUsageHistory(\'user1234\', $dry, $root, 1800000000, $root.\'/run\'), count($log)]; }'
            .' try { pmssTerminateUserArchiveUsageHistory(\'../user1234\', false, $root); } catch (\InvalidArgumentException $e) { $out[] = $e->getMessage(); }'
            .' echo json_encode($out);';
        $this->assertSame([[false, 0], [true, 6], [true, 6], 'Refusing unsafe usage history archive'], $this->pmssRunInlinePhpJson($script));

        foreach (['resources/user1234', 'traffic/user1234', 'traffic/user1234-localnet', 'traffic-ingress/user1234'] as $rel) {
            $this->assertFalse(file_exists($root.'/'.$rel), $rel.' left under the reusable name');
            $moved = dirname($root.'/'.$rel).'/terminated/'.basename($rel).'.20270115080000';
            $this->assertSame($rel."\n", (string) @file_get_contents($moved), $rel.' history kept');
            $this->assertSame(0700, fileperms(dirname($moved)) & 0777);
        }
        // The derived caches go; the source history above is what is kept.
        $this->assertFalse(file_exists($root.'/run/resourceStats/user1234') || file_exists($root.'/run/trafficStats/user1234'));
        $this->assertSame("run/resourceStats/other\n", file_get_contents($root.'/run/resourceStats/other'));
        // Another account's files, including one whose name starts with this one, stay where they are.
        $this->assertSame("traffic/user12345\n", file_get_contents($root.'/traffic/user12345'));
        $this->assertSame("resources/other\n", file_get_contents($root.'/resources/other'));
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/terminateUser.php', [
            'pmssTerminateUserClearIopsBudgetState($username, $dryRun);',
            'pmssTerminateUserArchiveUsageHistory($username, $dryRun);',
            'pmssTerminateUserCompletion($username, $purgePaths, $purgeResults, $dryRun)',
        ]);
    }
}
