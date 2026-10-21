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
                'pmssConfigPermissionsSweep($configDir);',
            ],
            'setupPermissions.php missing traversal substring: ',
            'Parent traversal fix must run before /etc/seedbox/config normalization: '
        );
    }

    public function testToggleAppCatalogPathLosesGroupWriteOnly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/util/setupPermissions.php');
        $this->assertTrue(preg_match('#"(find /etc/skel/www [^"]+)"#', $source, $match) === 1, 'catalog permission step missing');
        $dir = $this->pmssMakeTempDir('pmss-skel-www-');
        $catalog = $this->pmssWriteFile($dir.'/toggleAppCatalog.php', "<?php return [];\n");
        $other = $this->pmssWriteFile($dir.'/other.php', "x\n");
        chmod($dir, 0770);
        chmod($catalog, 0660);
        chmod($other, 0660);
        $command = str_replace(['\\\\', '/etc/skel/www'], ['\\', escapeshellarg($dir)], $match[1]);
        exec($command.' 2>&1', $output, $rc);
        clearstatcache();
        $this->assertSame(0, $rc, implode("\n", $output));
        $this->assertSame(0750, fileperms($dir) & 0777);
        $this->assertSame(0640, fileperms($catalog) & 0777);
        $this->assertSame(0660, fileperms($other) & 0777);
    }

    public function testConfigDirectoryRootKeepsTraversePermission(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/configPermissions.php', '$chmod($configDir, 0775);');
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
            "if (pmssPermissionTargetDirectoryExists('/etc/wireguard')) {",
            "if (pmssPermissionTargetFileExists('/etc/openvpn/ta.key')) {",
        ]);
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/configPermissions.php', 'if ($node->isLink()) {');
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
            "pmssBuildUserShellCommand(\$user, 'test -r '.escapeshellarg(\$localnet), '/bin/sh')",
            "@filesize(\$localnet) === 0",
            'is empty; rTorrent will likely fail with a localnet ip filter error.',
            'ls -ld /etc/seedbox /etc/seedbox/config {$localnet}',
        ]);
    }

    public function testStartRtorrentRefreshesExecutorThroughManagedWriter(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/startRtorrent', [
            "require_once __DIR__.'/lib/lighttpd/userFileWrite.php';",
            'pmssReplaceAccountFile($user, $home, $executor, $skelData, 0755, true)',
        ]);
        $this->pmssAssertRepoFileNotContainsString('scripts/startRtorrent', '@copy($skelExecutor, $executor)');
    }

    public function testStartRtorrentCreatesSessionAsAccountUser(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/startRtorrent',
            "pmssBuildUserShellCommand(\$user, 'mkdir -m 755 -p -- '.escapeshellarg(\$home.'/session'))");
        $this->pmssAssertRepoFileNotContainsString('scripts/startRtorrent', "@chown(\$home.'/session', \$user)");
    }

    public function testUpdateStep2RegistersPermissionShutdownRescueBeforeWork(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/update-step2.php', [
            'function pmssUpdateStep2RegisterShutdownGuard(): void',
            'update-step2 exited before final permission refresh',
            'function pmssUpdateStep2ShutdownReason(): string',
            "pmssUpdateStep2RunRescueAction('permission_refresh_rescue', ['reason' => \$reason], static function () use (\$helper): int {",
            "pmssUpdateStep2LogRescueEvent(\$event, \$rc === 0 ? 'ok' : 'error', ['rc' => \$rc]);",
            "return runStep('Restoring system permissions (shutdown)', \$helper);",
            'pmssUpdateStep2RegisterShutdownGuard();',
        ]);
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/util/update-step2.php',
            [
                'pmssUpdateStep2RegisterShutdownGuard();',
                "pmssRunProfiledCallable('Acquiring update-step2 lock'",
                "['Applying runtime service templates', 'pmssApplyRuntimeTemplates'",
                "pmssLogJson(['event' => 'phase', 'name' => 'setupPermissions', 'status' => 'start']);",
                "\$GLOBALS['PMSS_UPDATE_STEP2_COMPLETED'] = true;",
            ],
            'update-step2.php missing shutdown permission guard substring: ',
            'Permission rescue guard must be registered before phase-2 work can exit early: '
        );
    }
}
