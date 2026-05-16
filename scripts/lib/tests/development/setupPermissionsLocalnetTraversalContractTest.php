<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SetupPermissionsLocalnetTraversalContractTest extends TestCase
{
    public function testSeedboxParentTraversalStepRemainsInPermissionTargets(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/setupPermissions.php', [
            "'/etc/seedbox' => [",
            "'directory' => ['Ensuring /etc/seedbox is traversable', 'chmod o+x /etc/seedbox']",
        ]);
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
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/systemStatus.php', [
            'function pmssSystemStatusChecks(',
            "foreach (['/etc/seedbox', '/etc/seedbox/config'] as \$dir)",
            'missing world-exec (users cannot traverse to localnet)',
        ]);
    }

    public function testStartRtorrentKeepsLocalnetReadabilityPreflightAndHint(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/startRtorrent', [
            "escapeshellarg('test -r '.\$localnet)",
            "@filesize(\$localnet) === 0",
            'is empty; rTorrent will likely fail with a localnet ip filter error.',
            'ls -ld /etc/seedbox /etc/seedbox/config {$localnet}',
        ]);
    }
}
