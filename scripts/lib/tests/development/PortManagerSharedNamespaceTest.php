<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/portManager.php';

final class PortManagerSharedNamespaceTest extends TestCase
{
    /** Open a deterministic in-range listener for bind-conflict tests. */
    private function openListener(string $address): array
    {
        for ($port = 22000; $port <= 22100; $port++) {
            $errno = 0;
            $error = '';
            $server = @stream_socket_server(
                sprintf('tcp://%s:%d', $address, $port),
                $errno,
                $error,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
            );
            if ($server !== false) {
                return [$server, $port];
            }
        }

        throw new SkipTest('No free in-range listener port available');
    }

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

    /** Simulate a reservation change between preflight and lock acquisition. */
    private function runLockRace(string $action, string $mutation): array
    {
        $portDir = $this->makePortDir();
        $assignment = $portDir.'/rclone-alice';
        if ($mutation === 'remove') file_put_contents($assignment, "25000\n");
        $script = 'namespace PortManagerLockRaceFixture; require_once '.var_export($this->pmssRepoPath('scripts/lib/portManager.php'), true).';';
        $script .= '$GLOBALS["assignment"] = '.var_export($assignment, true).'; $GLOBALS["mutation"] = '.var_export($mutation, true).';';
        $script .= <<<'PHP'
function pmssLockFileAcquire($path) {
    if ($GLOBALS['mutation'] === 'create') \file_put_contents($GLOBALS['assignment'], "25000\n");
    else \unlink($GLOBALS['assignment']);
    return \tmpfile();
}
function pmssUserBaseContext() { return []; }
function pmssUserWriteLogs() { return true; }
PHP;
        $source = $action === 'assign' ? 'scripts/lib/portManager/selection.php' : 'scripts/lib/portManager.php';
        $script .= $this->pmssInlinePhpLibraryInNamespace($source, 'PortManagerLockRaceFixture');
        if ($action === 'assign') {
            $script .= '$status = null; $port = pmssPortManagerAssignServicePort("alice", "rclone", 26000, $status);';
            $script .= 'echo json_encode(["port" => $port, "status" => $status, "file" => \file_get_contents($GLOBALS["assignment"])]);';
        } else {
            $script .= '\ob_start(); $rc = pmssPortManagerMain(["portManager.php", "release", "alice", "rclone"]); $output = \ob_get_clean();';
            $script .= 'echo json_encode(["rc" => $rc, "output" => $output, "present" => \file_exists($GLOBALS["assignment"])]);';
        }
        return $this->pmssRunInlinePhpJson($script, ['PMSS_PORT_MANAGER_DIR' => $portDir, 'PMSS_PORT_MANAGER_LEGACY_DIR' => $portDir.'/missing']);
    }

    public function testAssignmentCreatedWhileWaitingForLockIsAdopted(): void
    {
        $this->assertSame(['port' => 25000, 'status' => 'already_assigned', 'file' => "25000\n"], $this->runLockRace('assign', 'create'));
    }

    public function testAssignmentRemovedWhileWaitingForReleaseLockIsNotUnlinkedAgain(): void
    {
        $this->assertSame(['rc' => 0, 'output' => 'No port assigned', 'present' => false], $this->runLockRace('release', 'remove'));
    }

    public function testAssignmentCreatedWhileWaitingForReleaseLockIsRemoved(): void
    {
        $this->assertSame(['rc' => 0, 'output' => 'Port released', 'present' => false], $this->runLockRace('release', 'create'));
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

    public function testAvailabilityRejectsLoopbackAndWildcardListeners(): void
    {
        foreach (array('127.0.0.1', '0.0.0.0') as $address) {
            [$server, $port] = $this->openListener($address);

            $this->assertFalse(\pmssPortManagerPortIsAvailable($port), $address.' listener must be rejected');
            fclose($server);
            $this->assertTrue(\pmssPortManagerPortIsAvailable($port), $address.' port should recover after close');
        }
    }
}
