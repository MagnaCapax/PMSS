<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SetupPermissionsLocalnetTraversalContractTest extends TestCase
{
    public function testSeedboxParentTraversalStepRemainsInPermissionTargets(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/setupPermissions.php');

        $this->assertStringContainsString("'/etc/seedbox' => [", $src);
        $this->assertStringContainsString("'directory' => ['Ensuring /etc/seedbox is traversable', 'chmod o+x /etc/seedbox']", $src);
    }

    public function testSeedboxParentTraversalRunsBeforeConfigTreeNormalization(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/setupPermissions.php');

        $parentPos = strpos($src, "'directory' => ['Ensuring /etc/seedbox is traversable', 'chmod o+x /etc/seedbox']");
        $configPos = strpos($src, '@chmod($configDir, 0775);');

        $this->assertTrue($parentPos !== false, 'setupPermissions.php should preserve /etc/seedbox traversal before config normalization');
        $this->assertTrue($configPos !== false, 'setupPermissions.php should keep the config directory traversable');
        $this->assertTrue($parentPos < $configPos, 'Parent traversal fix must run before /etc/seedbox/config normalization');
    }

    public function testConfigDirectoryRootKeepsTraversePermission(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/setupPermissions.php');

        $this->assertStringContainsString('@chmod($configDir, 0775);', $src);
    }

    public function testSystemTestChecksBothSeedboxTraversalDirectories(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/systemStatus.php');

        $this->assertStringContainsString('function pmssSystemStatusChecks(', $src);
        $this->assertStringContainsString("foreach (['/etc/seedbox', '/etc/seedbox/config'] as \$dir)", $src);
        $this->assertStringContainsString('missing world-exec (users cannot traverse to localnet)', $src);
    }

    public function testStartRtorrentKeepsLocalnetReadabilityPreflightAndHint(): void
    {
        $src = $this->pmssReadRepoFile('scripts/startRtorrent');

        $this->assertStringContainsString("escapeshellarg('test -r '.\$localnet)", $src);
        $this->assertStringContainsString('ls -ld /etc/seedbox /etc/seedbox/config {$localnet}', $src);
    }
}
