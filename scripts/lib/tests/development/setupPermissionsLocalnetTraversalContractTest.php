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

    public function testPermissionTargetsFilterBeforeChmod(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/setupPermissions.php', [
            'find /etc/skel -mindepth 1 -not -type l -perm /002 -exec chmod o-w -- {} +',
            'find /etc/seedbox -mindepth 1 -not -type l -perm /002 -exec chmod o-w -- {} +',
            'find /etc/skel -type f -perm /007 -exec chmod o-rwx -- {} +',
            'find /etc/seedbox -type f -perm /007 -exec chmod o-rwx -- {} +',
            "find /etc/openvpn -maxdepth 1 -type f -name '*.conf'",
            '-not -user root -o -not -group root',
            '-exec chown root:root {} +',
        ]);
        $this->pmssAssertRepoFileNotContainsString('scripts/util/setupPermissions.php', 'chmod -R o-w');
    }

    public function testPermissionHardeningSkipsSymlinkTargetsBeforeActing(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/setupPermissions.php', [
            'function pmssPermissionTargetDirectoryExists(string $path): bool',
            'function pmssPermissionTargetFileExists(string $path): bool',
            'return !pmssSkipSymlink($path) && is_dir($path);',
            'return !pmssSkipSymlink($path) && is_file($path);',
            'if ($node->isLink()) {',
            "if (pmssPermissionTargetDirectoryExists('/etc/wireguard')) {",
            "if (pmssPermissionTargetFileExists('/etc/openvpn/ta.key')) {",
        ]);
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
