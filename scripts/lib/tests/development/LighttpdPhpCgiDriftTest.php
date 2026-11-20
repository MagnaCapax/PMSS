<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';
require_once dirname(__DIR__, 2).'/pathSafety.php';
require_once dirname(__DIR__, 2).'/lighttpd/watchdogPhpCgiDrift.php';

/** A php-cgi pool on a replaced binary is restarted once per installed binary (ADR 0314). */
class LighttpdPhpCgiDriftTest extends TestCase
{
    private $runtime;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('runtime', 'pmss-phpcgi-drift-', 0700);
    }

    /** Fake /proc: links per path, owner per /proc/<pid> path. */
    private function exeDeleted(array $links, array $owners, int $pid = 4242, int $uid = 1008): bool
    {
        $readlink = static function (string $path) use ($links) { return $links[$path] ?? false; };
        $ownerUid = static function (string $path) use ($owners): ?int { return $owners[$path] ?? null; };
        return \pmssProcessExeDeleted($pid, $uid, '/usr/bin/php-cgi', '/proc', $readlink, $ownerUid);
    }

    private function liveLinks(string $exe, string $ns = 'mnt:[4026531841]'): array
    {
        return array('/proc/4242/exe' => $exe, '/proc/4242/ns/mnt' => $ns, '/proc/self/ns/mnt' => 'mnt:[4026531841]');
    }

    public function testDeletedPhpCgiInHostNamespaceOwnedByAccountIsDrift(): void
    {
        $this->assertTrue($this->exeDeleted($this->liveLinks('/usr/bin/php-cgi8.2 (deleted)'), array('/proc/4242' => 1008)));
    }

    public function testLiveBinaryIsNotDrift(): void
    {
        $this->assertFalse($this->exeDeleted($this->liveLinks('/usr/bin/php-cgi8.2'), array('/proc/4242' => 1008)));
    }

    public function testContainerNamespaceIsIgnored(): void
    {
        $links = $this->liveLinks('/usr/bin/php-cgi8.2 (deleted)', 'mnt:[4026532999]');
        $this->assertFalse($this->exeDeleted($links, array('/proc/4242' => 1008)));
    }

    public function testOtherExecutablePathIsIgnored(): void
    {
        $links = $this->liveLinks('/home/acct/bin/php-cgi (deleted)');
        $this->assertFalse($this->exeDeleted($links, array('/proc/4242' => 1008)));
    }

    public function testOtherOwnerIsIgnored(): void
    {
        $this->assertFalse($this->exeDeleted($this->liveLinks('/usr/bin/php-cgi8.2 (deleted)'), array('/proc/4242' => 1009)));
    }

    public function testVanishedProcessIsIgnored(): void
    {
        $this->assertFalse($this->exeDeleted(array('/proc/self/ns/mnt' => 'mnt:[4026531841]'), array()));
    }

    public function testDriftRestartsOncePerInstalledBinary(): void
    {
        $binary = $this->pmssWriteRelativeFile($this->runtime, 'bin/php-cgi8.2', 'x');
        touch($binary, 1763600000);
        $options = array(
            'binary' => $binary,
            'runtimeDir' => $this->runtime.'/state',
            'uid' => 1008,
            'pids' => static function (string $user): array { return array(4242, 4243); },
            'isDeleted' => static function (int $pid, int $uid, string $prefix): bool { return $pid === 4243; },
        );

        $this->assertTrue(\pmssLighttpdWatchdogPhpCgiBinaryReplaced('fixtacct', $options));
        $this->assertFalse(\pmssLighttpdWatchdogPhpCgiBinaryReplaced('fixtacct', $options), 'same binary must not restart again');
        touch($binary, 1763600600);
        $this->assertTrue(\pmssLighttpdWatchdogPhpCgiBinaryReplaced('fixtacct', $options), 'a newer binary allows one more restart');
    }

    public function testNoDriftWritesNoMarker(): void
    {
        $binary = $this->pmssWriteRelativeFile($this->runtime, 'bin/php-cgi8.2', 'x');
        $options = array(
            'binary' => $binary, 'runtimeDir' => $this->runtime.'/state', 'uid' => 1008,
            'pids' => static function (string $user): array { return array(4242); },
            'isDeleted' => static function (int $pid, int $uid, string $prefix): bool { return false; },
        );

        $this->assertFalse(\pmssLighttpdWatchdogPhpCgiBinaryReplaced('fixtacct', $options));
        $this->assertFalse(is_file($this->runtime.'/state/checkLighttpdInstances-phpcgi-fixtacct.mtime'), 'no drift, no marker');
    }
}
