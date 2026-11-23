<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/remoteBinary.php';

/** FileBot is judged by the package database; its binary is never run to ask for a version. */
class FilebotInstallerPackageStateTest extends TestCase
{
    public function testPackageStateJudgesCurrentStaleAndForeignInstalls(): void
    {
        $this->assertSame('current', \pmssPinnedRemoteDebState('install ok installed', '4.9.4', '4.9.4'));
        $this->assertSame('reinstall', \pmssPinnedRemoteDebState('install ok installed', '4.9.3', '4.9.4'));
        $this->assertSame('reinstall', \pmssPinnedRemoteDebState('install ok installed', '', '4.9.4'));
        // A package dpkg does not hold is not judged here, whatever version string came back.
        foreach (['', 'deinstall ok config-files', 'install ok half-configured', 'hold ok installed'] as $status) {
            $this->assertSame('legacy', \pmssPinnedRemoteDebState($status, '4.9.4', '4.9.4'), $status);
        }
    }

    public function testPinnedVersionInstalledProbesNothingAndReinstallsNothing(): void
    {
        // The reported layout: pinned deb installed, /usr/bin/filebot a symlink the probe refuses.
        $result = $this->fixture('install ok installed', '4.9.4');
        $this->assertSame([], $result['probes']);
        $this->assertSame([], $result['installs']);
        $this->assertSame([], $result['logs']);
    }

    public function testOtherInstalledVersionUpgradesThroughTheDebWithoutTouchingTheBinaryPath(): void
    {
        $result = $this->fixture('install ok installed', '4.9.3');
        $this->assertSame([], $result['probes'], 'no binary probe and no stale-binary removal');
        $this->assertSame(['FileBot 4.9.4'], array_column($result['installs'], 0));
        $this->assertSame('https://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb', $result['installs'][0][1]);
    }

    public function testNotDpkgInstalledKeepsTheGuardedBinaryGate(): void
    {
        $blocked = $this->fixture('', '', false);
        $this->assertSame(['/usr/bin/filebot'], array_column($blocked['probes'], 0));
        $this->assertSame([], $blocked['installs']);

        $open = $this->fixture('', '', true);
        $this->assertSame(['/usr/bin/filebot'], array_column($open['probes'], 0));
        $this->assertSame(['FileBot 4.9.4'], array_column($open['installs'], 0));
    }

    /** Run the real installer entry with recording dependencies; nothing downloads or runs. */
    private function fixture(string $status, string $version, bool $gateOpens = true): array
    {
        $script = <<<'PHP'
namespace FilebotFixture;
function pmssPackageStatus($package) { return $GLOBALS['state']['status']; }
function pmssPackageVersion($package) { return $GLOBALS['state']['version']; }
function logmsg($message) { $GLOBALS['result']['logs'][] = $message; }
function pmssPinnedRemoteBinaryPrepareInstall(...$arguments) {
    $GLOBALS['result']['probes'][] = $arguments;
    return $GLOBALS['state']['gate'];
}
function pmssInstallPinnedRemoteDebPackage(...$arguments) {
    $GLOBALS['result']['installs'][] = $arguments;
    return true;
}
PHP;
        $script .= '$GLOBALS["state"] = '.var_export(['status' => $status, 'version' => $version, 'gate' => $gateOpens], true).';';
        $script .= '$GLOBALS["result"] = ["probes" => [], "installs" => [], "logs" => []];';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/apps/filebot.php', 'FilebotFixture');
        $script .= 'echo json_encode($GLOBALS["result"]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
