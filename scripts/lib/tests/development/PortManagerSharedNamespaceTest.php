<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/portManager.php';

final class PortManagerSharedNamespaceTest extends TestCase
{
    private function runPortManagerMain(array $arguments): array
    {
        [$rc, $output] = $this->pmssCaptureStdout(static function () use ($arguments): int {
            return \pmssPortManagerMain(array_merge(['portManager.php'], $arguments));
        });

        return ['rc' => $rc, 'output' => $output];
    }

    private function makePortDir(): string
    {
        return $this->pmssMakeTempDir('pmss-port-shared-', 0755);
    }

    private function trackPortNamespace(): array
    {
        $portDir = $this->makePortDir();
        $legacyDir = $this->makePortDir();
        $this->pmssTrackEnvOverrides(['PMSS_PORT_MANAGER_DIR' => $portDir, 'PMSS_PORT_MANAGER_LEGACY_DIR' => $legacyDir]);

        return [$portDir, $legacyDir];
    }

    public function testUsedPortsIncludeAllManagedServices(): void
    {
        $portDir = $this->makePortDir();
        $legacyDir = $this->makePortDir();
        mkdir($legacyDir.'/scgi', 0755, true);
        file_put_contents($portDir.'/lighttpd-alice', "24000\n");
        file_put_contents($portDir.'/rclone-bob', "25000\n");
        file_put_contents($portDir.'/qbittorrent-carol', "not-a-port\n");
        file_put_contents($legacyDir.'/scgi/26000', '');

        $used = \pmssPortManagerUsedPorts($portDir, $legacyDir);

        $this->assertTrue(isset($used[24000]));
        $this->assertTrue(isset($used[25000]));
        $this->assertTrue(isset($used[26000]));
        $this->assertFalse(isset($used[0]));
    }

    public function testAssignServicePortAdoptsAvailablePreferredPort(): void
    {
        [$portDir] = $this->trackPortNamespace();

        $port = \pmssPortManagerAssignServicePort('alice', 'rclone', 25000);

        $this->assertSame(25000, $port);
        $this->assertSame('25000', trim((string) file_get_contents($portDir.'/rclone-alice')));
    }

    public function testAssignServicePortDoesNotReuseOtherServicePort(): void
    {
        [$portDir] = $this->trackPortNamespace();
        file_put_contents($portDir.'/lighttpd-alice', "25000\n");

        $port = \pmssPortManagerAssignServicePort('bob', 'rclone', 25000);

        $this->assertTrue(is_int($port));
        $this->assertTrue($port !== 25000);
        $this->assertSame((string) $port, trim((string) file_get_contents($portDir.'/rclone-bob')));
    }

    public function testAssignServicePortDoesNotReuseLegacyRtorrentReservation(): void
    {
        [$portDir, $legacyDir] = $this->trackPortNamespace();
        mkdir($legacyDir.'/scgi', 0755, true);
        file_put_contents($legacyDir.'/scgi/25000', '');

        $port = \pmssPortManagerAssignServicePort('bob', 'deluge-web', 25000);

        $this->assertTrue(is_int($port));
        $this->assertTrue($port !== 25000);
        $this->assertSame((string) $port, trim((string) file_get_contents($portDir.'/deluge-web-bob')));
    }

    public function testAssignHelperReportsCliStatusWithoutChangingPortContract(): void
    {
        $this->trackPortNamespace();

        $assigned = '';
        $port = \pmssPortManagerAssignServicePort('alice', 'rclone', 25000, $assigned);
        $cli = $this->runPortManagerMain(['assign', 'alice', 'rclone']);
        $existing = '';
        $again = \pmssPortManagerAssignServicePort('alice', 'rclone', null, $existing);

        $this->assertSame(25000, $port);
        $this->assertSame('assigned', $assigned);
        $this->assertSame(0, $cli['rc']);
        $this->assertSame('25000', $cli['output']);
        $this->assertSame(25000, $again);
        $this->assertSame('already_assigned', $existing);
    }
}
