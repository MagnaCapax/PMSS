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
        // Boot convergence must remove group options without adding any value.
        $source = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/systemPrep/bootDefaults.php', [
            "['hidepid=' => 'hidepid=2']",
            "true, ['gid=']",
            'defaults,hidepid=2',
            'remount,hidepid=2',
        ]);
        $this->assertStringNotContainsString('gid=', str_replace("['gid=']", '', $source),
            'Boot convergence may only mention the group-option removal prefix. See ADR 0027.');
    }

    public function testHostEnvironmentAdvisoryDoesNotSuggestRemovingHidepid(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/systemPrep/hostEnvironment.php', [
            'Do NOT remount /proc without hidepid',
            'expected and non-fatal',
            'See ADR-0027',
        ], [
            'Consider remounting' => 'hostEnvironment.php must not advise remounting /proc without hidepid on v2 (the pre-ADR-0027 hole-suggesting wording).',
        ]);
    }

    public function testNoProcGroupHidepidBypassAnywhereInScripts(): void
    {
        // Generalized invariant: the proc-group / gid=proc hidepid bypass must
        // not appear in ANY script, not only the two /proc files above — a
        // future file could reintroduce it. Scan the whole scripts/ tree.
        $root = $this->pmssRepoPath('scripts');
        $offenders = [];
        foreach ($this->pmssRepoFiles('scripts', '/\.(?:php|sh)$/D') as $file) {
            $path = $file->getPathname();
            if (strpos($path, '/tests/') !== false) {
                continue; // this guard legitimately names the forbidden patterns
            }
            $src = @file_get_contents($path);
            if ($src === false) {
                continue;
            }
            if ($this->hasProcGroupExemption($src, $path === $root.'/lib/update/systemPrep/isolationRecheck.php')) {
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

    public function testGroupOptionDetectionOnlyAllowsTheExactRuntimeReset(): void
    {
        $reset = "'mount -o remount,hidepid=2,gid=0 /proc'";
        $this->assertFalse($this->hasProcGroupExemption($reset, true));
        $this->assertTrue($this->hasProcGroupExemption($reset, false));
        foreach (['hidepid=2,gid=1065', 'gid=1065,hidepid=2', 'hidepid=2,gid=01',
            'hidepid=2,gid=00', 'hidepid=2,gid=proc', 'hidepid=2,gid=0,nosuid',
            'groupadd proc', 'mount -o gid=proc /proc'] as $options) {
            $source = '<?php $command = '.var_export('mount -o '.$options.' /proc', true).';';
            $this->assertTrue($this->hasProcGroupExemption($source, true), $options);
        }
        $this->assertTrue($this->hasProcGroupExemption($reset."; mount -o hidepid=2,gid=1065 /proc", true));
    }

    /** Preserve the existing detection, allowing only the guard's exact reset command. */
    private function hasProcGroupExemption(string $source, bool $allowReset): bool
    {
        if ($allowReset) $source = str_replace("'mount -o remount,hidepid=2,gid=0 /proc'", '', $source);
        return preg_match('/groupadd\s+(?:-\S+\s+)*proc\b/', $source) === 1
            || preg_match('/\b(?:hidepid=\S*,\S*gid=|gid=\S*,\S*hidepid=)/', $source) === 1
            || preg_match('#\b(?:mount|remount)\b[^\n\r]*\bgid=proc\b#', $source) === 1;
    }
}
