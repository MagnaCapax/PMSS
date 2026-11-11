<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

/** Behavior lock for the sysctl pipeline; execution belongs on the testing VM.
 * Covers scripts/lib/update/systemPrep/sysctlProfile.php,
 * scripts/lib/update/systemPrep/sysctlSettings.php,
 * scripts/lib/update/systemPrep/sysctlText.php,
 * scripts/lib/update/systemPrep/sysctlSummary.php and
 * scripts/lib/update/systemPrep/sysctlTuning.php through the original include.
 */
final class SysctlPipelineCharacterizationTest extends TestCase
{
    public function testBooleanOverridesKeepThreeStatesAndExactVocabulary(): void
    {
        foreach ([[null, null], ['', null], ['1', true], [' YES ', true], ['True', true],
            ['0', false], [' NO ', false], ['False', false], ['on', null], ['off', null], ['2', null]] as $case) {
            $this->pmssWithEnv(['PMSS_SYSCTL_HAS_SWAP' => $case[0]], function () use ($case): void {
                $this->assertSame($case[1], \pmssSystemPrepReadBoolEnv('PMSS_SYSCTL_HAS_SWAP'));
            });
        }
    }

    public function testOverrideFilteringPreservesGroupsKeysTypesAndInput(): void
    {
        $groups = [
            'vm' => ['drop' => '10', 'keep' => 0, 4 => false, 7 => null],
            'invalid' => 'ignored', 'empty' => [], 'removed' => ['drop' => 1],
            3 => ['later' => true, 'text' => '  unchanged  '],
        ];
        $original = $groups;
        $this->assertSame([
            'vm' => ['keep' => '0', 4 => '', 7 => ''],
            3 => ['later' => '1', 'text' => '  unchanged  '],
        ], \pmssSysctlSettingsFilterOverrides($groups, ['drop', 'drop', 'absent']));
        $this->assertSame($original, $groups);
        $this->assertSame([], \pmssSysctlSettingsFilterOverrides(['empty' => [], 'invalid' => false], []));
    }

    public function testChangeReportingRetainsDuplicateKeysAndNullVersusMissing(): void
    {
        $this->assertSame([
            'dup: old -> 1', 'new: <unset> -> ', 'dup: old -> 2', '0: <unset> -> 0',
        ], \pmssSysctlChangesDescribe(['dup' => 'old', 'null' => null, 'same' => 1], [
            'first' => ['dup' => 1, 'null' => false, 'same' => '1', 'new' => null],
            'ignored' => 'invalid', 'empty' => [], 'last' => ['dup' => '2', 0 => 0],
        ]));
    }

    public function testAssignmentParsingKeepsBothCommentPolicies(): void
    {
        foreach ([
            [' # comment', null, null], ['bad-key = 1', null, null],
            ['key =', ['key', ''], ['key', '']], ['key = 1#x', ['key', '1#x'], ['key', '1#x']],
            ["\tkey = 1 # x\r", ['key', '1 # x'], ['key', '1']],
            ['key = a=b', ['key', 'a=b'], ['key', 'a=b']],
        ] as $case) {
            $this->assertSame($case[1], \pmssSysctlAssignmentLineParse($case[0]));
            $this->assertSame($case[2], \pmssSysctlAssignmentLineParse($case[0], true));
        }
        $path = $this->pmssMakeTempDir('pmss-sysctl-text-').'/overrides.conf';
        $this->pmssWriteFile($path, "key = first\nempty =\nkey = second # note\n# ignored\n");
        $this->assertSame(['key', 'empty'], \pmssSysctlOverridesParse($path));
        $this->assertSame(['key' => 'second # note'], \pmssSysctlFileParse($path));
    }

    public function testRenderingKeepsSectionOrderBlankLinesAndVerbatimValues(): void
    {
        $this->assertSame("# Pulsed Media Config\n# Hardware-aware PMSS baseline\n"
            ."\n# Network\nkey = 0\n\n# Custom\nother =  a=b # c \n\n# Memory\nkey = 1\n",
            \pmssSysctlConfigRender(['net' => ['key' => '0'], 'empty' => [],
                'custom' => ['other' => ' a=b # c '], 'vm' => ['key' => 1]]));
    }

    public function testSummaryRetainsSortedLastValuesPeerFieldsAndDetectionTypes(): void
    {
        $root = $this->pmssMakeTempDir('pmss-sysctl-pipeline-');
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $root]);
        $this->pmssWriteFile($root.'/hardware.json', '{"peer":{"keep":true},"timestamp":"old"}');
        $messages = [];
        \pmssSysctlSummaryWrite($this->pmssMakeArrayLogger($messages), [
            'ram_gb' => '64', 'has_swap' => '0', 'swap_is_fast' => 'false',
            'nic_speed_mbps' => '25000', 'nic_speed_gbps' => '10', 'is_vm' => 1,
        ], ['one' => ['z' => 1, 'a' => false], 'ignored' => null, 'two' => ['z' => 2]],
            [4 => 'z', 7 => 'a'], [2 => 'z: 1 -> 2']);
        $payload = $this->pmssReadJsonArrayFile($root.'/hardware.json');
        $this->assertSame(['peer', 'timestamp', 'sysctl'], array_keys($payload));
        $this->assertSame(['keep' => true], $payload['peer']);
        $this->assertSame([
            'detection' => ['ram_gb' => 64, 'has_swap' => false, 'swap_is_fast' => true,
                'nic_speed_mbps' => 25000, 'nic_speed_gbps' => 10, 'is_vm' => true, 'has_conntrack' => false],
            'applied' => ['a' => '', 'z' => '2'], 'overrides_respected' => ['z', 'a'],
            'changes_made' => ['z: 1 -> 2'],
        ], $payload['sysctl']);
    }

    public function testNicProbeStopsAtFirstValidDefaultRouteEvenWhenItsSpeedIsMissing(): void
    {
        $root = $this->pmssMakeTempDir('pmss-sysctl-route-');
        $route = $root.'/route';
        $this->pmssTrackEnvOverrides(['PMSS_SYSCTL_NIC_SPEED_MBPS' => null,
            'PMSS_SYSCTL_PROC_NET_ROUTE_PATH' => $route, 'PMSS_SYSCTL_SYS_CLASS_NET_PATH' => $root]);
        $this->pmssWriteFile($root.'/fixtur1/speed', "25000\n");
        $this->pmssWriteFile($root.'/fixtur2/speed', "10000\n");
        foreach ([
            ["Iface Destination\ninvalid/name 00000000\nfixtur1 00000000\nfixtur2 00000000\n", 25000],
            ["Iface Destination\nmissing0 00000000\nfixtur1 00000000\n", 1000],
            ["Iface Destination\nfixtur1 00000001\nfixtur2 00000000\n", 10000],
            ["Iface Destination\nfixtur1 00000001\n", 1000],
            ['', 1000],
        ] as $case) {
            $this->pmssWriteFile($route, $case[0]);
            $this->assertSame($case[1], \pmssSysctlNicSpeedMbps());
        }
    }
}
