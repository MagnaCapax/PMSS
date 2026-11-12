<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/network/iptables.php';
require_once dirname(__DIR__, 2).'/traffic.php';

class MonitoringRulesLoopbackExemptionTest extends TestCase
{
    public function testMonitoringRulesExemptLoopbackBeforePerUserRules(): void
    {
        $loopbackRule = '$rules = "/sbin/iptables -A OUTPUT -d 127.0.0.0/8 -j ACCEPT\\n";';
        $userLoop = 'foreach ($userUids as $thisUid) {';

        $this->pmssAssertRepoFileSubstringCount('scripts/lib/network/rules.php', $loopbackRule, 1, 'Loopback exemption should be declared exactly once');
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/lib/network/rules.php',
            [$loopbackRule, $userLoop],
            'Expected monitoring rule fragment: ',
            'Loopback exemption must run before per-user accounting rules: '
        );
    }

    public function testMonitoringRulesUseAccountUidsAsMarks(): void
    {
        $rules = \networkMonitoringRulesRender([1001, 2002, 4004], ['10.0.0.0/8']);
        foreach ([1001, 2002, 4004] as $uid) {
            $this->assertStringContainsString("--uid-owner {$uid} -j MARK --set-mark {$uid}", $rules);
        }
        $this->assertStringNotContainsString('--set-mark 1\n', $rules);
    }

    public function testNoRegisteredPortsPreservesAccountingBytes(): void
    {
        $expected = "/sbin/iptables -A OUTPUT -d 127.0.0.0/8 -j ACCEPT\n"
            ."/sbin/iptables -A OUTPUT -d 10.0.0.0/8 -m owner --uid-owner 1001 -j ACCEPT\n"
            ."/sbin/iptables -A OUTPUT ! -d 10.0.0.0/8 -m owner --uid-owner 1001 -j MARK --set-mark 1001\n"
            ."/sbin/iptables -A OUTPUT -m owner --uid-owner 1001 -j ACCEPT\n"
            ."/sbin/iptables -A OUTPUT -d 10.0.0.0/8 -j ACCEPT\n";
        $this->assertSame($expected, \networkMonitoringRulesRender(['alice' => 1001], ['10.0.0.0/8']));
        $this->assertSame("/sbin/iptables -A OUTPUT -d 127.0.0.0/8 -j ACCEPT\n", \networkMonitoringRulesRender([], []));
    }

    public function testOwnerGuardPrecedesLoopbackAndKeepsAccountingBytes(): void
    {
        $users = ['alice' => 1001, 'bob' => '2002'];
        $prefix = '';
        foreach ([1001 => [22001, 32001], 2002 => [22002]] as $uid => $ports) {
            foreach ($ports as $port) {
                $prefix .= "/sbin/iptables -A OUTPUT -o lo -p tcp --syn --dport {$port} -m owner ! --uid-owner {$uid} -m owner ! --uid-owner 0 -j REJECT --reject-with tcp-reset\n";
            }
        }
        $rules = \networkMonitoringRulesRender($users, ['10.0.0.0/8'], ['alice' => [22001, 32001], 'bob' => [22002]]);
        $this->assertSame($prefix.\networkMonitoringRulesRender($users, ['10.0.0.0/8']), $rules);
        // Parsing for either apply path must retain both negated owners verbatim.
        $this->assertSame(explode("\n", rtrim(str_replace('/sbin/iptables ', '', $rules))), \networkParseMonitoringCommands($rules));
    }

    public function testOwnerGuardRejectsMalformedInputsAndDeduplicatesPorts(): void
    {
        $users = ['alice' => 1001, 'bob' => '2002;id', 'carol' => 1.5, 'dave' => -1, 'eve' => '4294967295'];
        $ports = ['alice' => [true, 1023, 1024, '1024', 65535, 65536, '22000;id', "22000\0", [], 3.5],
            'bob' => [23000], 'carol' => [24000], 'dave' => [25000], 'eve' => [26000]];
        $rules = \networkMonitoringRulesRender($users, [], $ports);
        $guard = strstr($rules, '/sbin/iptables -A OUTPUT -d 127.0.0.0/8', true);
        $this->assertSame(2, substr_count($guard, '-j REJECT'));
        $this->assertStringContainsString('--dport 1024 ', $guard);
        $this->assertStringContainsString('--dport 65535 ', $guard);
        $this->assertSame(2, substr_count($guard, '--uid-owner 1001 -m owner ! --uid-owner 0'));
    }

    public function testOwnerUidRequiresUnsignedIntegerWithinLinuxRange(): void
    {
        foreach ([null, [], true, 1.5, -1, '', '1e3', "1001\0", '4294967295', str_repeat('9', 40)] as $uid) {
            $this->assertSame(null, \networkMonitoringOwnerUid($uid));
        }
        foreach ([0 => '0', 1001 => '01001', 4294967294 => '4294967294'] as $expected => $uid) {
            $this->assertSame($expected, \networkMonitoringOwnerUid($uid));
        }
    }

    public function testOwnerGuardEmptyMapKeepsLegacyRules(): void
    {
        $users = ['alice' => 1001, 'bob' => 1002];
        foreach ([[], ['10.0.0.0/8', '192.168.0.0/16']] as $localnets) {
            $this->assertSame(\networkMonitoringRulesRender($users, $localnets), \networkMonitoringRulesRender($users, $localnets, []));
        }
    }

    public function testMonitoringCallerAlwaysReadsRegistryWithoutHostMarker(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/makeMonitoringRules.php' => [
                'required' => ['$webUiPorts = networkQbittorrentWebUiPortsRead($userUids);',
                    'networkMonitoringRulesRender($userUids, $localnets, $webUiPorts)'],
                'forbidden' => ['PMSS_NETWORK_LOOPBACK_OWNER_GUARD_MARKER', 'loopbackOwnerGuardEnabled'],
            ],
        ]);
    }

    public function testOwnerGuardCountersAreExcludedFromTrafficAccounting(): void
    {
        $usage = "0 0 ACCEPT all -- * * 0.0.0.0/0 127.0.0.0/8\n"
            ."2 120 ACCEPT all -- * * 0.0.0.0/0 0.0.0.0/0 owner UID match 1001\n";
        $guard = "3 180 REJECT tcp -- * lo 0.0.0.0/0 0.0.0.0/0 tcp dpt:22001 flags:0x17/0x02 owner UID match ! 1001 owner UID match ! 0 reject-with tcp-reset\n";
        $this->assertSame(\pmssTrafficParseOutputUsage($usage, []), \pmssTrafficParseOutputUsage($guard.$usage, []));
    }

    public function testUpdateRemovesObsoleteMarkerWithoutFollowingLinks(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/update-step2.php');
        $this->assertSame(1, preg_match("/runStep\('Removing obsolete qBittorrent loopback owner marker',\s*'([^']+)'\);/", $source, $matches));
        $root = $this->pmssMakeTempDir('pmss-loopback-cleanup-');
        $path = $root.'/marker';
        $this->pmssWriteFile($root.'/target', 'keep');
        $this->pmssEnsureDir($root.'/directory');
        $command = str_replace('/etc/seedbox/config/qbittorrentLoopbackOwnerGuard.enabled', escapeshellarg($path), $matches[1]);
        foreach (['missing', 'file', 'link', 'dangling', 'directory-link', 'directory'] as $kind) {
            if ($kind === 'file') $this->pmssWriteFile($path, '');
            if ($kind === 'link') symlink($root.'/target', $path);
            if ($kind === 'dangling') symlink($root.'/absent', $path);
            if ($kind === 'directory-link') symlink($root.'/directory', $path);
            if ($kind === 'directory') $this->pmssEnsureDir($path);
            // Repeating the same migration is harmless, including for missing paths.
            for ($run = 0; $run < 2; $run++) {
                $result = $this->pmssExecShellCommand($command);
                $this->assertSame(0, $result['rc']);
                clearstatcache();
                $this->assertFalse(is_link($path));
                $this->assertSame($kind === 'directory', file_exists($path));
                $this->assertSame('keep', file_get_contents($root.'/target'));
                $this->assertTrue(is_dir($root.'/directory'));
                $this->assertFalse(file_exists($root.'/absent'));
            }
        }
    }
}
