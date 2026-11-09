<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/agentDiagnostics.php';

/** Exercise static probes against fixture files, never the live cgroup tree. */
class IoSharingDiagnosticsTest extends TestCase
{
    public function testCommandsAreStaticAndAllHaveFallbacks(): void
    {
        $specs = \pmssAgentDiagnosticsSectionSpecs()['io_sharing'];
        $this->assertSame(['shadow_mode', 'local_policy_present', 'shadow_log_age_seconds', 'published_read_iops',
            'system_conf_bytes', 'default_io_accounting', 'slice_direct_tasks', 'account_tasks'], array_keys($specs));
        $this->assertSame($specs, \pmssAgentDiagnosticsSectionSpecs("fixture'; echo injected")['io_sharing']);
        foreach ($specs as $spec) {
            $this->pmssAssertArraySubsetSame(['type' => 'command', 'format' => 'text', 'fallback' => 'unknown'], $spec);
        }
    }

    /** Replace only fixed host paths; no fixture name becomes a command argument. */
    private function collect(string $name, array $paths): string
    {
        $spec = \pmssAgentDiagnosticsSectionSpecs()['io_sharing'][$name];
        $spec['command'] = str_replace(array_keys($paths), array_values($paths), $spec['command']);
        return \pmssAgentDiagnosticsSpecCollect($spec);
    }

    public function testTaskCountsIncludeSlicesAndEveryDescendantWithoutNames(): void
    {
        $root = $this->pmssMakeTempDir('pmss-io-membership-');
        $paths = ['/sys/fs/cgroup/blkio/user.slice' => $root.'/slices'];
        foreach (['slice_direct_tasks', 'account_tasks'] as $name) $this->assertSame('unknown', $this->collect($name, $paths));
        $this->pmssWriteFiles($root.'/slices', [
            'user-1000.slice/cgroup.procs' => "11\n22\n",
            'user-1000.slice/session-1.scope/cgroup.procs' => "33\n44\n",
            'user-1000.slice/user@1000.service/app.slice/run.scope/cgroup.procs' => "55\n66\n77\n",
            'user-1001.slice/cgroup.procs' => "88\n",
            'user-1001.slice/empty.scope/cgroup.procs' => '',
        ]);
        $this->assertSame('3', $this->collect('slice_direct_tasks', $paths));
        $this->assertSame('8', $this->collect('account_tasks', $paths));
    }

    public function testPolicyAgeCeilingAndManagerEvidenceHaveMissingFallbacks(): void
    {
        $root = $this->pmssMakeTempDir('pmss-io-diagnostics-');
        $paths = ['/scripts/lib/cgroup/policy.php' => $root.'/policy.php',
            '/etc/seedbox/config/cgroup.policy.local.php' => $root.'/local.php',
            '/var/log/pmss/dynamic-iops.jsonl' => $root.'/shadow.jsonl',
            '/var/run/pmss/io-ceiling.json' => $root.'/ceiling.json',
            '/etc/systemd/system.conf.d' => $root.'/conf.d', '/etc/systemd/system.conf' => $root.'/system.conf'];
        foreach (['shadow_mode', 'shadow_log_age_seconds', 'published_read_iops', 'system_conf_bytes', 'default_io_accounting'] as $name) {
            $this->assertSame('unknown', $this->collect($name, $paths));
        }
        $this->assertSame('no', $this->collect('local_policy_present', $paths));
        $conf = "# fixture\nDefaultIOAccounting=yes\n";
        $this->pmssWriteFiles($root, ['policy.php' => '<?php function pmssCgroupPolicyLoad() { return ["cgroup" => ["dynamicIops" => ["mode" => "shadow"]]]; }',
            'local.php' => '<?php return [];', 'shadow.jsonl' => "{}\n", 'ceiling.json' => '{"published":{"read_iops":1234}}',
            'system.conf' => $conf, 'conf.d/90-pmss-io-placement.conf' => "DefaultIOAccounting=no\n"]);
        touch($root.'/shadow.jsonl', time() - 100);
        $this->assertSame('shadow', $this->collect('shadow_mode', $paths));
        $this->assertSame('yes', $this->collect('local_policy_present', $paths));
        $this->assertSame('1234', $this->collect('published_read_iops', $paths));
        $this->assertSame((string) strlen($conf), $this->collect('system_conf_bytes', $paths));
        $age = (int) $this->collect('shadow_log_age_seconds', $paths);
        $this->assertTrue($age >= 100 && $age < 120);
        $this->assertSame("DefaultIOAccounting=yes\nDefaultIOAccounting=no", $this->collect('default_io_accounting', $paths));
        file_put_contents($root.'/ceiling.json', '{malformed');
        $this->assertSame('unknown', $this->collect('published_read_iops', $paths));
    }
}
