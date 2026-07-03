<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Guards the cgroup-v2 + hidepid=2 tenant-isolation invariant.
 *
 * Origin: on 2026-06-30 a `gid=proc` /proc exemption (proc-group bypass) was
 * applied live to make the per-user systemd manager start under cgroup v2 +
 * hidepid=2 (systemd issue 12955). That exemption grants any member of the
 * exempted group FULL cross-tenant /proc visibility — a privacy hole (MISSION
 * cardinal value: liberty/privacy). ADR-0027 rejected it and chose the
 * manager-independent path instead. These tests structurally forbid the hole
 * from re-entering the codebase and forbid any weakening of hidepid=2.
 */
class ProcHidepidIsolationGuardTest extends TestCase
{
    public function testProcMountEnforcesHidepid2WithoutGidBypass(): void
    {
        // bootDefaults.php is the sole enforcer of the /proc hidepid=2 mount.
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/systemPrep/bootDefaults.php' => [
                'required' => [
                    "['hidepid=' => 'hidepid=2']",
                    'defaults,hidepid=2',
                    'remount,hidepid=2',
                ],
                'forbidden' => [
                    'gid=' => 'bootDefaults.php must NOT add a gid= option to the /proc mount: gid=<group> exempts that group from hidepid and exposes every tenant\'s /proc (the 2026-06-30 hole). See ADR-0027.',
                ],
            ],
        ]);
    }

    public function testHostEnvironmentAdvisoryDoesNotSuggestRemovingHidepid(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/systemPrep/hostEnvironment.php' => [
                'required' => [
                    'Do NOT remount /proc without hidepid',
                    'expected and non-fatal',
                    'See ADR-0027',
                ],
                'forbidden' => [
                    'Consider remounting' => 'hostEnvironment.php must not advise remounting /proc without hidepid on v2 (the pre-ADR-0027 hole-suggesting wording).',
                ],
            ],
        ]);
    }

    public function testNoProcGroupHidepidBypassAnywhereInScripts(): void
    {
        // Generalized invariant: the proc-group / gid=proc hidepid bypass must
        // not appear in ANY script, not only the two /proc files above — a
        // future file could reintroduce it. Scan the whole scripts/ tree.
        $root = $this->pmssRepoPath('scripts');
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $ext = $file->getExtension();
            if ($ext !== 'php' && $ext !== 'sh') {
                continue;
            }
            $path = $file->getPathname();
            if (strpos($path, '/tests/') !== false) {
                continue; // this guard legitimately names the forbidden patterns
            }
            $src = @file_get_contents($path);
            if ($src === false) {
                continue;
            }
            // Target the bypass MECHANISM, not any prose mention of it (a comment that
            // documents the hole is legitimate). The hole needs a 'proc' group (groupadd)
            // and/or the /proc mount carrying a gid= exemption alongside hidepid=.
            if (preg_match('/groupadd\s+(?:-\S+\s+)*proc\b/', $src) === 1
                || preg_match('/\b(?:hidepid=\S*,\S*gid=|gid=\S*,\S*hidepid=)/', $src) === 1
                || preg_match('#\b(?:mount|remount)\b[^\n\r]*\bgid=proc\b#', $src) === 1
            ) {
                $offenders[] = substr($path, strlen($root) + 1);
            }
        }
        sort($offenders);
        $this->assertSame(
            [],
            $offenders,
            'Proc-group / gid=proc hidepid bypass (cross-tenant /proc exposure hole, ADR-0027) found in: '.implode(', ', $offenders)
        );
    }
}
