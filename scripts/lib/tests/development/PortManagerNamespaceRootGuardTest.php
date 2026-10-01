<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/portManager.php';

final class PortManagerNamespaceRootGuardTest extends TestCase
{
    public function testBothReservationScansRejectTheSameUnsafeRoots(): void
    {
        $regularFile = $this->pmssMakeTempFile('pmss-port-root-file-');
        $realRoot = $this->pmssMakeTempDir('pmss-port-root-');
        $linkRoot = $this->pmssMakeTempDir('pmss-port-link-').'/ports';
        symlink($realRoot, $linkRoot);

        foreach (['', $regularFile, $linkRoot, $realRoot.'/missing', "/tmp/pmss\0ports"] as $root) {
            $this->assertSame([], \pmssPortManagerLegacyUsedPorts($root));
            $this->assertSame([], \pmssPortManagerUsedPorts($root, ''));
        }
    }

    public function testLegacyUsedPortsRejectsInvalidRoots(): void
    {
        $this->assertSame([], \pmssPortManagerLegacyUsedPorts(''));
        $this->assertSame([], \pmssPortManagerLegacyUsedPorts("/tmp/pmss\0ports"));
        $this->assertSame([], \pmssPortManagerLegacyUsedPorts($this->pmssMakeTempDir('pmss-missing-port-root-').'/missing'));
    }

    public function testLegacyUsedPortsRejectsSymlinkRoot(): void
    {
        $realRoot = $this->pmssMakeTempDir('pmss-legacy-ports-');
        $linkRoot = $this->pmssMakeTempDir('pmss-legacy-ports-link-').'/ports';
        symlink($realRoot, $linkRoot);

        $this->assertSame([], \pmssPortManagerLegacyUsedPorts($linkRoot));
    }

    public function testUsedPortsKeepsLegacyReservationsWhenManagedRootInvalid(): void
    {
        $legacyRoot = $this->pmssMakeTempDir('pmss-legacy-ports-');
        $this->pmssEnsureDir($legacyRoot.'/scgi');
        file_put_contents($legacyRoot.'/scgi/26000', '');

        $used = \pmssPortManagerUsedPorts('', $legacyRoot);

        $this->assertTrue(isset($used[26000]));
        $this->assertFalse(isset($used[0]));
    }

    public function testUsedPortsRejectsSymlinkManagedRoot(): void
    {
        $managedRoot = $this->pmssMakeTempDir('pmss-managed-ports-');
        $linkRoot = $this->pmssMakeTempDir('pmss-managed-ports-link-').'/ports';
        file_put_contents($managedRoot.'/lighttpd-alice', "24000\n");
        symlink($managedRoot, $linkRoot);

        $this->assertSame([], \pmssPortManagerUsedPorts($linkRoot, ''));
    }
}
