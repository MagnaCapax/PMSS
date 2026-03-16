<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class setupSkelPermissionsLocalnetTraversalContractTest extends TestCase
{
    private function loadSetupSkelPermissionsSource(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../util/setupSkelPermissions.php');
    }

    private function loadSystemTestSource(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../util/systemTest.php');
    }

    private function loadStartRtorrentSource(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../startRtorrent');
    }

    public function testSeedboxParentTraversalStepRemainsInPermissionTargets(): void
    {
        $src = $this->loadSetupSkelPermissionsSource();

        $this->assertStringContainsString("'/etc/seedbox' => [", $src);
        $this->assertStringContainsString("'directory' => ['Ensuring /etc/seedbox is traversable', 'chmod o+x /etc/seedbox']", $src);
    }

    public function testSeedboxParentTraversalRunsBeforeConfigTreeNormalization(): void
    {
        $src = $this->loadSetupSkelPermissionsSource();

        $parentPos = strpos($src, "'directory' => ['Ensuring /etc/seedbox is traversable', 'chmod o+x /etc/seedbox']");
        $configPos = strpos($src, '@chmod($configDir, 0775);');

        $this->assertTrue($parentPos !== false, 'setupSkelPermissions.php should preserve /etc/seedbox traversal before config normalization');
        $this->assertTrue($configPos !== false, 'setupSkelPermissions.php should keep the config directory traversable');
        $this->assertTrue($parentPos < $configPos, 'Parent traversal fix must run before /etc/seedbox/config normalization');
    }

    public function testConfigDirectoryRootKeepsTraversePermission(): void
    {
        $src = $this->loadSetupSkelPermissionsSource();

        $this->assertStringContainsString('@chmod($configDir, 0775);', $src);
    }

    public function testSystemTestChecksBothSeedboxTraversalDirectories(): void
    {
        $src = $this->loadSystemTestSource();

        $this->assertStringContainsString("foreach (['/etc/seedbox', '/etc/seedbox/config'] as \$dir)", $src);
        $this->assertStringContainsString('missing world-exec (users cannot traverse to localnet)', $src);
    }

    public function testStartRtorrentKeepsLocalnetReadabilityPreflightAndHint(): void
    {
        $src = $this->loadStartRtorrentSource();

        $this->assertStringContainsString("escapeshellarg('test -r '.\$localnet)", $src);
        $this->assertStringContainsString('ls -ld /etc/seedbox /etc/seedbox/config {$localnet}', $src);
    }
}
