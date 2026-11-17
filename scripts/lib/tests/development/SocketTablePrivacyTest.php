<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/socketTablePrivacy.php';

/**
 * Socket-table privacy applier (default-on, ADR 0068): restricts the address-bearing
 * /proc/net tables to root and drops in the tcp_no_metrics_save sysctl.
 */
class SocketTablePrivacyTest extends TestCase
{
    /** Build a fake /proc/net root with each address-bearing table at $mode. */
    private function fakeProcNet(int $mode): string
    {
        $dir = $this->pmssMakeTempDir('pmss-procnet-', 0700);
        foreach (pmssSocketTablePrivacyTables() as $table) {
            $path = $this->pmssWriteFile($dir.'/'.$table, "sl local_address rem_address\n");
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

    /** Default-on (ADR 0068, 2026-11-17): no marker file is read; every host gets the restriction. */
    public function testAppliesWithoutAnyMarkerFile(): void
    {
        $procNet = $this->fakeProcNet(0444);
        $dropin = $this->pmssMakeTempDir('pmss-sysctl-', 0700).'/91-pmss-socket-table-privacy.conf';

        $result = pmssSocketTablePrivacyApply(static function (): void {}, $procNet, $dropin);

        $this->assertSame(10, $result['tables_changed'], 'all ten tables move from 0444 to 0440');
        foreach (pmssSocketTablePrivacyTables() as $table) {
            $this->assertSame(0440, $this->modeOf($procNet.'/'.$table), $table.' must be root-only');
        }
        $this->assertTrue(is_file($dropin), 'sysctl drop-in must be written');
        $this->assertStringContainsString('net.ipv4.tcp_no_metrics_save = 1', (string) file_get_contents($dropin));
    }

    public function testReapplyIsANoOp(): void
    {
        $procNet = $this->fakeProcNet(0440);
        $dropin = $this->pmssMakeTempDir('pmss-sysctl-', 0700).'/91-pmss-socket-table-privacy.conf';
        pmssSocketTablePrivacyApply(static function (): void {}, $procNet, $dropin);

        $result = pmssSocketTablePrivacyApply(static function (): void {}, $procNet, $dropin);

        $this->assertSame(0, $result['tables_changed'], 'tables already at 0440 are left alone');
        $this->assertSame('unchanged', $result['metrics'], 'an identical drop-in is not rewritten');
    }

    /** Boot reapply and the stage-2 loader must not depend on the retired marker file. */
    public function testBootAndLoaderDoNotReadTheRetiredMarker(): void
    {
        $base = dirname(__DIR__, 4);
        foreach ([
            $base.'/etc/seedbox/config/template.pmss-boot-tuning.sh',
            $base.'/scripts/lib/update/systemPrep/bpf/socket-privacy-load.sh',
            $base.'/scripts/lib/update/systemPrep/socketTablePrivacy.php',
        ] as $file) {
            $this->assertStringNotContainsString('socket-table-privacy.enabled', (string) file_get_contents($file), basename($file).' must not gate on the retired marker');
        }
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
        $result = $this->pmssExecShellCommand('sh -n '.escapeshellarg($loader));
        $this->assertSame(0, $result['rc'], 'loader must be valid sh: '.$result['output']);
    }
}
