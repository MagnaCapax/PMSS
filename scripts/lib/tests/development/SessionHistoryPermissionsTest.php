<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/sessionHistoryPermissions.php';

class SessionHistoryPermissionsTest extends TestCase
{
    /** Create a target under the fixture root with exact starting permissions. */
    private function makeTarget(string $root, string $path, int $mode): string
    {
        $target = $root.$path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        file_put_contents($target, 'fixture');
        chmod($target, $mode);
        return $target;
    }

    public function testExistingPathLosesOnlyOtherRead(): void
    {
        $root = $this->pmssMakeTempDir('pmss-session-modes-');
        $target = $this->makeTarget($root, '/var/log/wtmp.db', 0767);

        $this->assertTrue(\pmssSessionHistoryPermissionsHarden($root));
        clearstatcache(true, $target);
        $this->assertSame(0763, fileperms($target) & 07777);
        $this->assertSame('fixture', file_get_contents($target));
    }

    public function testMissingPathsAreSuccessful(): void
    {
        $root = $this->pmssMakeTempDir('pmss-session-missing-');
        $this->assertTrue(\pmssSessionHistoryPermissionsHarden($root));
    }

    public function testLegacyDebianPathsRemainCovered(): void
    {
        $root = $this->pmssMakeTempDir('pmss-session-legacy-');
        $paths = [
            '/var/log/wtmp', '/var/run/utmp', '/var/log/lastlog', '/var/log/faillog',
            '/usr/bin/netstat', '/usr/bin/who', '/usr/bin/w',
        ];
        foreach ($paths as $path) {
            $this->makeTarget($root, $path, 0644);
        }

        $this->assertTrue(\pmssSessionHistoryPermissionsHarden($root));
        foreach ($paths as $path) {
            clearstatcache(true, $root.$path);
            $this->assertSame(0640, fileperms($root.$path) & 07777, $path);
        }
    }

    public function testRotatedWtmpGlobAndLastlog2AreCovered(): void
    {
        $root = $this->pmssMakeTempDir('pmss-session-debian13-');
        $paths = [
            '/var/log/wtmp_202610.db', '/var/log/wtmp_202611.db',
            '/var/lib/lastlog/lastlog2.db',
        ];
        foreach ($paths as $path) {
            $this->makeTarget($root, $path, 0644);
        }

        $this->assertTrue(\pmssSessionHistoryPermissionsHarden($root));
        foreach ($paths as $path) {
            clearstatcache(true, $root.$path);
            $this->assertSame(0640, fileperms($root.$path) & 07777, $path);
        }
    }

    public function testAlreadyHardenedModeIsUnchanged(): void
    {
        $root = $this->pmssMakeTempDir('pmss-session-idempotent-');
        $target = $this->makeTarget($root, '/var/log/wtmp', 0640);

        $this->assertTrue(\pmssSessionHistoryPermissionsHarden($root));
        clearstatcache(true, $target);
        $this->assertSame(0640, fileperms($target) & 07777);
    }
}
