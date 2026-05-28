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
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/util/setupPermissions.php',
            [
                "'directory' => ['Ensuring /etc/seedbox is traversable', 'chmod o+x /etc/seedbox']",
                '@chmod($configDir, 0775);',
            ],
            'setupPermissions.php missing traversal substring: ',
            'Parent traversal fix must run before /etc/seedbox/config normalization: '
        );
    }

    public function testConfigDirectoryRootKeepsTraversePermission(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/util/setupPermissions.php', '@chmod($configDir, 0775);');
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
