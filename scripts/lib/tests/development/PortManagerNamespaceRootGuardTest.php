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
        $this->pmssWriteFile($legacyRoot.'/scgi/26000', '');

        $used = \pmssPortManagerUsedPorts('', $legacyRoot);

        $this->assertTrue(isset($used[26000]));
        $this->assertFalse(isset($used[0]));
    }

    public function testUsedPortsRejectsSymlinkManagedRoot(): void
    {
        $managedRoot = $this->pmssMakeTempDir('pmss-managed-ports-');
        $linkRoot = $this->pmssMakeTempDir('pmss-managed-ports-link-').'/ports';
        $this->pmssWriteFile($managedRoot.'/lighttpd-fixtur01', "24000\n");
        symlink($managedRoot, $linkRoot);

        $this->assertSame([], \pmssPortManagerUsedPorts($linkRoot, ''));
    }

    public function testMalformedRootsCannotCertifyAnAbsentNamespace(): void
    {
        $readPort = static function (string $path): ?int {
            throw new \RuntimeException('Malformed roots must not reach port decoding');
        };
        foreach (['', "\0", "\0/fixture/ports", "/fixture/po\0rts", "/fixture/ports\0", "/fixture/ports\0suffix"] as $root) {
            foreach ([false, true] as $allowMissing) {
                $complete = true;
                $this->assertSame([], \pmssPortManagerReservationPorts($root, '/*', $allowMissing, $readPort, $complete));
                $this->assertFalse($complete);
            }
        }
    }

    public function testMissingRootsRetainTheirNamespacePolicy(): void
    {
        $root = $this->pmssMakeTempDir('pmss-port-root-').'/missing';
        $readPort = static function (string $path): ?int {
            throw new \RuntimeException('Absent roots must not reach port decoding');
        };
        $complete = false;
        $this->assertSame([], \pmssPortManagerLegacyUsedPorts($root, $complete));
        $this->assertTrue($complete);
        $this->assertSame([], \pmssPortManagerReservationPorts($root, '/*', false, $readPort, $complete));
        $this->assertFalse($complete);
    }

    public function testExistingUnsafeRootsNeverCertifyAnAbsentNamespace(): void
    {
        $realRoot = $this->pmssMakeTempDir('pmss-port-root-');
        $link = $realRoot.'/link';
        $this->pmssCreateSymlinkOrSkip($realRoot, $link);
        $file = $this->pmssMakeTempFile('pmss-port-root-file-');
        foreach ([$link, $file] as $root) {
            $complete = true;
            $this->assertSame([], \pmssPortManagerLegacyUsedPorts($root, $complete));
            $this->assertFalse($complete);
        }
    }

    public function testSuccessfulDecodingCertifiesOnlyTheFinishedPortSet(): void
    {
        $root = $this->pmssMakeTempDir('pmss-port-root-');
        $this->pmssWriteFiles($root, ['a' => '', 'b' => '', 'c' => '', 'd' => '', 'e' => '']);
        $ports = ['a' => 26000, 'b' => null, 'c' => 24000, 'd' => 26000, 'e' => 25000];
        $seen = [];
        $complete = true;
        $readPort = function (string $path) use (&$complete, &$seen, $ports): ?int {
            $this->assertFalse($complete);
            $seen[] = basename($path);
            return $ports[basename($path)];
        };
        $this->assertSame([26000 => true, 24000 => true, 25000 => true],
            \pmssPortManagerReservationPorts($root, '/*', false, $readPort, $complete));
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $seen);
        $this->assertTrue($complete);
        // A successful empty scan remains complete without invoking the decoder.
        $this->assertSame([], \pmssPortManagerReservationPorts($root, '/missing-*', false, $readPort, $complete));
        $this->assertTrue($complete);
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $seen);
    }

    public function testDecoderFaultsLeaveCompletionFalseAndPropagateUnchanged(): void
    {
        $root = $this->pmssMakeTempDir('pmss-port-root-');
        $this->pmssWriteFiles($root, ['a' => '', 'b' => '', 'c' => '']);
        foreach ([new \RuntimeException('fixture decode failure'), new \Error('fixture decode failure')] as $fault) {
            foreach ([1, 3] as $failAt) {
                $complete = true;
                $calls = 0;
                $caught = null;
                $readPort = static function (string $path) use (&$calls, $failAt, $fault): ?int {
                    if (++$calls === $failAt) throw $fault;
                    return 24000;
                };
                try {
                    \pmssPortManagerReservationPorts($root, '/*', false, $readPort, $complete);
                } catch (\Throwable $error) {
                    $caught = $error;
                }
                $this->assertSame($fault, $caught);
                $this->assertSame($failAt, $calls);
                $this->assertFalse($complete);
            }
        }
    }
}
