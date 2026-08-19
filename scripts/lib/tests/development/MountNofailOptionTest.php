<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/services/quota.php';

/**
 * pmssEnsureMountNofailOption ensures `/home` (or any mount) carries `nofail` so a boot-time
 * mount failure leaves a remote-managed host REACHABLE instead of hanging at an emergency prompt.
 * Adds `nofail` only — no other option is touched — and is idempotent.
 */
class MountNofailOptionTest extends TestCase
{
    private function makeFstab(string $content): string
    {
        ['fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-nofail-', $content);
        return $fstab;
    }

    private function ensureNofail(string $fstab): array
    {
        return $this->pmssArrayLoggerMessages(function (callable $logger) use ($fstab): void {
            \pmssEnsureMountNofailOption('/home', $logger, $fstab);
        });
    }

    public function testAddsNofailWhenMissing(): void
    {
        // Real-world /home line observed in the fleet without nofail.
        $original = "/dev/md1 /home ext4 defaults,noatime,usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1,commit=60 0 0\n";
        $fstab = $this->makeFstab($original);

        $messages = $this->ensureNofail($fstab);

        $updated = (string) file_get_contents($fstab);
        $this->assertStringContainsString('nofail', $updated, 'nofail should be added to the /home options');
        // Every pre-existing option is preserved (minimal delta — add only).
        foreach (['noatime', 'usrjquota=aquota.user', 'grpjquota=aquota.group', 'jqfmt=vfsv1', 'commit=60'] as $opt) {
            $this->assertStringContainsString($opt, $updated, "pre-existing option {$opt} must be preserved");
        }
        // Only the /home options column changed — device + mount + fstype values preserved
        // (assert token presence, not exact spacing — the fstab writer normalises column whitespace).
        foreach (['/dev/md1', '/home', 'ext4'] as $col) {
            $this->assertStringContainsString($col, $updated, "column value {$col} must be preserved");
        }
        $this->assertTrue($this->pmssMessagesContain($messages, 'Added nofail option'), 'expected an added-nofail log');
    }

    public function testNoChangeWhenNofailPresent(): void
    {
        $original = "/dev/md1 /home ext4 defaults,noatime,nofail 0 0\n";
        $fstab = $this->makeFstab($original);

        $messages = $this->ensureNofail($fstab);

        $this->assertEquals($original, (string) file_get_contents($fstab), 'fstab must be untouched when nofail already present');
        $this->assertTrue($this->pmssMessagesContain($messages, 'already present'), 'expected an already-present skip log');
    }

    public function testMountNotFoundIsSafe(): void
    {
        $original = "/dev/md0 / ext4 defaults 0 1\n";
        $fstab = $this->makeFstab($original);

        $messages = $this->ensureNofail($fstab);

        $this->assertEquals($original, (string) file_get_contents($fstab), 'fstab untouched when /home absent');
        $this->assertTrue($this->pmssMessagesContain($messages, 'not found'), 'expected a not-found warning');
    }
}
