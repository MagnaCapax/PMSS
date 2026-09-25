<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/socketTablePrivacy.php';

/**
 * Opt-in socket-table privacy applier: enabling restricts the address-bearing
 * /proc/net tables to root and drops in the tcp_no_metrics_save sysctl; the
 * default (no marker) restores stock 0444 and removes the drop-in.
 */
class SocketTablePrivacyTest extends TestCase
{
    /** Build a fake /proc/net root with each address-bearing table at $mode. */
    private function fakeProcNet(int $mode): string
    {
        $dir = $this->pmssMakeTempDir('pmss-procnet-', 0700);
        foreach (pmssSocketTablePrivacyTables() as $table) {
            $path = $dir.'/'.$table;
            file_put_contents($path, "sl local_address rem_address\n");
            chmod($path, $mode);
        }
        return $dir;
    }

    private function modeOf(string $path): int
    {
        clearstatcache(true, $path);
        return fileperms($path) & 0777;
    }

    public function testTableListCoversV4AndV6AddressBearingSources(): void
    {
        $this->assertSame(
            ['tcp', 'tcp6', 'udp', 'udp6', 'udplite', 'udplite6', 'raw', 'raw6', 'icmp', 'icmp6'],
            pmssSocketTablePrivacyTables(),
            'the applier must cover every address-bearing /proc/net table, IPv4 and IPv6'
        );
    }

    public function testEnabledRestrictsTablesAndWritesSysctlDropin(): void
    {
        $procNet = $this->fakeProcNet(0444);
        $marker = $this->pmssMakeTempDir('pmss-marker-', 0700).'/socket-table-privacy.enabled';
        file_put_contents($marker, "");
        $dropin = $this->pmssMakeTempDir('pmss-sysctl-', 0700).'/91-pmss-socket-table-privacy.conf';

        $result = pmssSocketTablePrivacyApply(static function (): void {}, $marker, $procNet, $dropin);

        $this->assertTrue($result['enabled'], 'marker present => enabled');
        $this->assertSame(10, $result['tables_changed'], 'all ten tables move from 0444 to 0440');
        foreach (pmssSocketTablePrivacyTables() as $table) {
            $this->assertSame(0440, $this->modeOf($procNet.'/'.$table), $table.' must be root-only when enabled');
        }
        $this->assertTrue(is_file($dropin), 'sysctl drop-in must be written when enabled');
        $this->assertStringContainsString('net.ipv4.tcp_no_metrics_save = 1', (string) file_get_contents($dropin));
    }

    public function testDisabledRestoresStockModeAndRemovesDropin(): void
    {
        $procNet = $this->fakeProcNet(0440); // simulate a previously-enabled host
        $marker = $this->pmssMakeTempDir('pmss-marker-', 0700).'/socket-table-privacy.enabled'; // absent
        $dropinDir = $this->pmssMakeTempDir('pmss-sysctl-', 0700);
        $dropin = $dropinDir.'/91-pmss-socket-table-privacy.conf';
        file_put_contents($dropin, "net.ipv4.tcp_no_metrics_save = 1\n");

        $result = pmssSocketTablePrivacyApply(static function (): void {}, $marker, $procNet, $dropin);

        $this->assertFalse($result['enabled'], 'no marker => disabled');
        $this->assertSame(10, $result['tables_changed'], 'all ten tables restored from 0440 to stock 0444');
        foreach (pmssSocketTablePrivacyTables() as $table) {
            $this->assertSame(0444, $this->modeOf($procNet.'/'.$table), $table.' must return to stock mode when disabled');
        }
        $this->assertFalse(is_file($dropin), 'sysctl drop-in must be removed when disabled');
    }

    public function testDisabledIsANoOpOnAStockHost(): void
    {
        $procNet = $this->fakeProcNet(0444);
        $marker = $this->pmssMakeTempDir('pmss-marker-', 0700).'/socket-table-privacy.enabled'; // absent
        $dropin = $this->pmssMakeTempDir('pmss-sysctl-', 0700).'/91-pmss-socket-table-privacy.conf'; // absent

        $result = pmssSocketTablePrivacyApply(static function (): void {}, $marker, $procNet, $dropin);

        $this->assertFalse($result['enabled']);
        $this->assertSame(0, $result['tables_changed'], 'stock 0444 tables are already correct: nothing changes');
        $this->assertFalse(is_file($dropin));
    }

    public function testDisabledNeverWidensAMoreRestrictiveTable(): void
    {
        // A table the kernel or another layer left more restrictive than stock (0400)
        // must NOT be loosened back to 0444 by the disabled restore path.
        $procNet = $this->fakeProcNet(0444);
        chmod($procNet.'/tcp', 0400);
        $marker = $this->pmssMakeTempDir('pmss-marker-', 0700).'/socket-table-privacy.enabled'; // absent
        $dropin = $this->pmssMakeTempDir('pmss-sysctl-', 0700).'/91-pmss-socket-table-privacy.conf';

        pmssSocketTablePrivacyApply(static function (): void {}, $marker, $procNet, $dropin);

        $this->assertSame(0400, $this->modeOf($procNet.'/tcp'), 'disable must not widen a 0400 table to 0444');
        $this->assertSame(0444, $this->modeOf($procNet.'/udp'), 'a stock 0444 table stays 0444');
    }

    public function testEnabledDetectionFollowsTheMarker(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-marker-detect-', 0700);
        $marker = $dir.'/socket-table-privacy.enabled';
        $this->assertFalse(pmssSocketTablePrivacyEnabled($marker), 'absent marker => disabled');
        file_put_contents($marker, "");
        $this->assertTrue(pmssSocketTablePrivacyEnabled($marker), 'present marker => enabled');
    }

    /** The prebuilt BPF object must match its integrity sidecar, or the loader refuses it. */
    public function testStage2BpfObjectMatchesItsSidecar(): void
    {
        $dir = dirname(__DIR__, 2).'/update/systemPrep/bpf';
        $obj = $dir.'/sockdiag_filter.bpf.o';
        $sidecar = $dir.'/sockdiag_filter.bpf.o.sha256';
        $this->assertTrue(is_file($obj), 'prebuilt BPF object must be committed');
        $this->assertTrue(is_file($sidecar), 'BPF integrity sidecar must be committed');
        $recorded = strtok(trim((string) file_get_contents($sidecar)), " \t");
        $this->assertSame(hash_file('sha256', $obj), $recorded, 'BPF object hash must match the sidecar (rebuild regenerates both)');
    }

    /** The fail-open loader must be present and syntactically valid sh. */
    public function testStage2LoaderIsPresentAndValidSh(): void
    {
        $loader = dirname(__DIR__, 2).'/update/systemPrep/bpf/socket-privacy-load.sh';
        $this->assertTrue(is_file($loader), 'BPF loader script must be committed');
        exec('sh -n '.escapeshellarg($loader).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, 'loader must be valid sh: '.implode("\n", $out));
    }
}
