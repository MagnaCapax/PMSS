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
        ['fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-nofail-', $original);

        $messages = $this->ensureNofail($fstab);

        $this->assertStringContainsAllStrings([
            'nofail' => 'nofail should be added to the /home options',
            // Every pre-existing option is preserved (minimal delta — add only).
            'noatime' => 'pre-existing option noatime must be preserved',
            'usrjquota=aquota.user' => 'pre-existing option usrjquota=aquota.user must be preserved',
            'grpjquota=aquota.group' => 'pre-existing option grpjquota=aquota.group must be preserved',
            'jqfmt=vfsv1' => 'pre-existing option jqfmt=vfsv1 must be preserved',
            'commit=60' => 'pre-existing option commit=60 must be preserved',
            // Only the /home options column changed — device + mount + fstype values preserved
            // (assert token presence, not exact spacing — the fstab writer normalises column whitespace).
            '/dev/md1' => 'column value /dev/md1 must be preserved',
            '/home' => 'column value /home must be preserved',
            'ext4' => 'column value ext4 must be preserved',
        ], (string) file_get_contents($fstab));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Added nofail option'), 'expected an added-nofail log');
    }

    public function testNoChangeWhenNofailPresent(): void
    {
        $original = "/dev/md1 /home ext4 defaults,noatime,nofail 0 0\n";
        ['fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-nofail-', $original);

        $messages = $this->ensureNofail($fstab);

        $this->assertEquals($original, (string) file_get_contents($fstab), 'fstab must be untouched when nofail already present');
        $this->assertTrue($this->pmssMessagesContain($messages, 'already present'), 'expected an already-present skip log');
    }

    public function testMountNotFoundIsSafe(): void
    {
        $original = "/dev/md0 / ext4 defaults 0 1\n";
        ['fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-nofail-', $original);

        $messages = $this->ensureNofail($fstab);

        $this->assertEquals($original, (string) file_get_contents($fstab), 'fstab untouched when /home absent');
        $this->assertTrue($this->pmssMessagesContain($messages, 'not found'), 'expected a not-found warning');
    }
}
