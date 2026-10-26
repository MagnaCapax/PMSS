<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class UserResourcesListCharacterizationTest extends TestCase
{
    /** Run the real listing with read-only observations replaced in a child namespace. */
    private function listing(array $args, array $users = ['alice', 'missing'], int $uid = 0): array
    {
        $script = <<<'PHP'
namespace PMSS\ResourceListingFixture;
function posix_getuid() { return $GLOBALS['fixtureUid']; }
function is_dir($path) { return false; }
function pmssListManagedUsers($command) { return $GLOBALS['fixtureUsers']; }
function pmssUserAccountLookup($user) { return $user === 'missing' ? null : ['uid' => 1001]; }
function pmssReadSystemdProperties($unit, $keys) {
    return array_fill_keys($keys, '');
}
function pmssTrafficLimitReadGiBFile($path) { return 50; }
function pmssReadUserTrafficMonth($path) { return 12800; }
class UserConfigStore {
    public function get($user) { return ['quota' => 20, 'quotaBurst' => 0, 'suspended' => true]; }
}
PHP;
        $script .= '$GLOBALS["fixtureUsers"] = '.var_export($users, true).';';
        $script .= '$GLOBALS["fixtureUid"] = '.$uid.';';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/resourcesList.php', 'PMSS\\ResourceListingFixture');
        // The facade's includes load normally; evaluate its implementation modules in
        // the same namespace when present, so moved calls still use these observations.
        foreach (['resourcesListModel.php', 'resourcesListDisplay.php'] as $module) {
            if (is_file($this->pmssRepoPath('scripts/lib/user/'.$module))) {
                $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/'.$module, 'PMSS\\ResourceListingFixture');
            }
        }
        $script .= 'ob_start(); $rc = pmssUserResourcesListMain('.var_export(array_merge(['listing'], $args), true).');';
        $script .= 'echo json_encode([$rc, ob_get_clean()]);';
        return $this->pmssRunInlinePhpJson($script, [], '');
    }

    public function testTextLayoutsPreserveHeadersWidthsAndFieldOrder(): void
    {
        $brief = "%-10s %-5s %-8s %-8s %-6s %-6s %-6s %-6s %-6s %-7s %-7s";
        $headers = ['User', 'UID', 'MemHigh', 'MemMax', 'CPUWt', 'CPUQt', 'BlkWt', 'RdBW', 'WrBW', 'RdIOPS', 'WrIOPS'];
        $values = ['alice', '1001', '-', '-', '-', '-', '-', '-', '-', '-', '-'];
        foreach ([false, true] as $full) {
            $format = $brief.($full ? " %-6s %-6s %-7s %-7s %-7s %-7s %-8s %-9s" : '')."\n";
            $expected = sprintf($format, ...array_merge($headers, $full ? ['DskQ', 'DskB', 'InoQ', 'InoB', 'NetLim', 'NetUsed', 'ProcMax', 'Suspended'] : []));
            $expected .= str_repeat('-', $full ? 180 : 120)."\n";
            $expected .= sprintf($format, ...array_merge($values, $full ? ['20G', '0G', '15000', '18750', '50G', '12.5G', 'inf', 'yes'] : []));
            $this->assertSame([0, $expected], $this->listing($full ? ['--full'] : []));
        }
    }

    public function testJsonModesPreserveTypesOrderAndJsonlPrecedence(): void
    {
        $entry = ['user' => 'alice', 'uid' => 1001, 'memory_high' => null, 'memory_max' => null, 'cpu_weight' => null,
            'cpu_quota_percent' => null, 'io_weight' => null, 'io_read_bandwidth' => null, 'io_write_bandwidth' => null,
            'io_read_iops' => null, 'io_write_iops' => null, 'disk_quota_gib' => 20, 'disk_burst_gib' => 0,
            'inode_quota' => 15000, 'inode_burst' => 18750, 'network_limit_gib' => 50, 'network_used_gib' => 12.5,
            'process_max' => null, 'suspended' => true];
        $this->assertSame([0, json_encode([$entry], JSON_PRETTY_PRINT)."\n"], $this->listing(['--json']));
        foreach ([['--jsonl'], ['--json', '--jsonl'], ['--full', '--jsonl']] as $args) {
            $this->assertSame([0, json_encode($entry)."\n"], $this->listing($args));
        }
    }

    public function testEmptyAndUnresolvableAccountsKeepTheirDistinctOutput(): void
    {
        foreach ([[[], "No users found.\n"], [['--json'], "[]\n"], [['--jsonl'], '']] as [$args, $expected]) {
            $this->assertSame([0, $expected], $this->listing($args, []));
        }
        $this->assertSame([0, "[]\n"], $this->listing(['--json'], ['missing']));
        $this->assertSame([0, ''], $this->listing(['--jsonl'], ['missing']));
    }

    public function testHelpPrecedesRootGateAndTruthyModeConflictPrecedesCollection(): void
    {
        [$rc, $help] = $this->listing(['--help'], [], 1001);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('--help works without root', $help);
        $this->assertSame([1, ''], $this->listing(['--brief', '--full'], []));
        $this->assertSame($this->listing([]), $this->listing(['--brief=0', '--full=0']));
        $this->assertSame([1, ''], $this->listing([], [], 1001));
    }
}
