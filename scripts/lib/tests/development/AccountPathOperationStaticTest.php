<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Keep account-path operations on the established user or managed-file paths. */
final class AccountPathOperationStaticTest extends TestCase
{
    /** Fixed commands with an account argument; each entry also pins its reviewed line. */
    private const SAFE = [
        'scripts/cron/webPublicCertsProcess.php:114' => ['cd5455be1bf21155c002ba607313f214d4da6b28', 'fixed config utility with quoted account'],
        'scripts/cron/webPublicCertsProcess.php:150' => ['8073e46d40d522b379cbc0518ad64b2e494f42a4', 'fixed config utility with quoted account'],
        'scripts/lib/rtorrent/processInspection.php:113' => ['13417f72f7995d00594046ad6921f6924f10f56a', 'process listing with quoted account'],
        'scripts/lib/lighttpd/userConfigApply.php:63' => ['af1754514e1c4518bfe8bbc90929d930bb125806', 'fixed port utility with quoted account'],
        'scripts/lib/network/fireqos.php:26' => ['b4712db6815bc3f3adbcce7ab5678ddb8db7a60b', 'uid lookup with quoted account'],
        'scripts/lib/resources/log.php:32' => ['abcdc22775d788f8fa306195c4bbca4a3da976ab', 'uid lookup with quoted account'],
        'scripts/lib/nginxConfig/userConfigsGenerate.php:211' => ['66d938ed265b0ac243e1b992e1820850e3e6fe1e', 'fixed config utility with quoted account'],
        'scripts/lib/update/users/docker.php:116' => ['b1fd9ef63ccf528d30bc352bef2c0d326d4311b5', 'uid lookup with quoted account'],
        'scripts/lib/update/users/rutorrent.php:60' => ['10019e1ef850eb7765ac7ee4a3ee662f3b5fa53f', 'account step wrapper for deprecated plugin'],
        'scripts/lib/update/users/rutorrent.php:77' => ['d062608dbb673d40273b90bb95bc14763cd256b9', 'account step wrapper for retracker removal'],
        'scripts/lib/update/users/rutorrent.php:81' => ['3dd0ae350c20d790277f9d0eabc1cc60d563392f', 'account step wrapper for torrents directory'],
        'scripts/lib/update/users/rutorrent.php:86' => ['34745298797ba4c2173feeda1eb0bf7a2ba81e66', 'account step wrapper for RSS directory'],
        'scripts/lib/update/users/rutorrent.php:182' => ['57a8c8d8fd485bc0e56b412d2401ba63a84d6f48', 'account step wrapper for tree move'],
    ];

    public function testAccountPathOperationsUseManagedBoundaries(): void
    {
        $offenders = [];
        $reviewed = [];
        foreach (['scripts/cron', 'scripts/lib', 'scripts/util'] as $directory) {
            foreach ($this->pmssRepoFiles($directory, '/\.(?:php|sh)$/') as $path => $entry) {
                if (preg_match('~/(?:tests|testing|devristo|vendor)/~', $path)) continue;
                foreach ($this->scanFile($entry->getPathname()) as $line => $operation) {
                    $key = $path.':'.$line;
                    if (isset(self::SAFE[$key]) && sha1($operation) === self::SAFE[$key][0]) {
                        $reviewed[$key] = true;
                    } else {
                        $offenders[] = $key.' '.$operation;
                    }
                }
            }
        }
        $this->assertSame([], $offenders, "Account path operations need a managed boundary:\n".implode("\n", $offenders));
        $expectedReviewed = array_keys(self::SAFE);
        $actualReviewed = array_keys($reviewed);
        sort($expectedReviewed);
        sort($actualReviewed);
        $this->assertSame($expectedReviewed, $actualReviewed, 'Reviewed operation list has stale locations');
    }

    public function testDetectorRecognizesDirectOperationsAndManagedPaths(): void
    {
        $cases = [
            "chown('/home/'.\$user.'/file', 'root');" => true,
            "file_put_contents(\$homeDir.'/file', 'value');" => true,
            "exec('lighttpd -t -f '.\$home.'/config');" => true,
            "exec(pmssBuildUserShellCommand(\$user, 'lighttpd -t -f '.\$home.'/config'));" => false,
            "pmssWriteUserFile(\$homeDir.'/file', 'value', \$user, 0640);" => false,
            "pmssAccountPathRun(\$user, \$homeDir, [\$homeDir.'/bin'], 'mkdir -- '.escapeshellarg(\$homeDir.'/bin'));" => false,
            "include \$home.'/extra.php';" => true,
            "chown root:root /home/\$user/file" => true,
            "runStep('mode', 'find /home/\$user -not -type l -exec chmod 750 {} +');" => false,
        ];
        foreach ($cases as $source => $expected) {
            $this->assertSame($expected, $this->operationOnLine($source), $source);
        }
    }

    public function testRecreateCopyRetainsFinalNodeChecks(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/recreateRestore.php', [
            'clearstatcache(true, $source)',
            'pmssPathTargetIsSafe($source, false, true)',
            'clearstatcache(true, $destination)',
            'pmssPathTargetIsSafe($destination, false, true)',
            'is_link($destination)',
        ]);
    }

    public function testConfigAndSkeletonWritesKeepSharedWriterRouting(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/deluge.php', [
            "pmssDelugeConfigFileWrite(\$configFile, \$updated, 'upload throttle')",
            'pmssWriteUserFile($path, $content, $owner, $mode)',
            'pmssReplaceUserFilePreservingMetadata($path, $content, 0644)',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/lighttpd/userFileWrite.php', [
            'function pmssReplaceUserFilePreservingMetadata(',
            "pmssRootStageFileWrite(\$path, \$content, \$group['name'], \$mode)",
            'pmssReplaceAccountFile($user, $home, $path, $content, $mode, true)',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update.php', [
            'pmssReadRegularFileContents((string) $sourceFile)',
            'pmssReplaceAccountFile((string) $user, $homeDir, $targetFile, $content, 0755, true)',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/rootlessDockerConfig.php', [
            'pmssAccountHomeIdentity($configFile)',
            '$identity[2] === $uid',
            'pmssReplaceAccountFile($identity[1], $identity[0], $configFile, $json, 0600, true)',
            'pmssReplaceUserFile($configFile, $json, static function (string $openedPath): bool',
            '@chmod($openedPath, 0600)',
        ]);
        foreach (['scripts/lib/user/deluge.php', 'scripts/lib/update.php', 'scripts/lib/user/rootlessDockerConfig.php'] as $file) {
            $source = $this->pmssReadRepoFile($file);
            foreach (['tempnam(', 'rename(', 'copy('] as $operation) {
                $this->assertStringNotContainsString($operation, $source, $file);
            }
        }
    }

    public function testTransferSessionRewriteUsesVerifiedOpenInode(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/userTransfer/sessionRewrite.php', [
            'clearstatcache(true, $sessionFile)',
            'pmssPathTargetIsSafe($sessionFile, false, true)',
            'pmssInodeSafeRewriteRegularFile($home, $sessionFile',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/pathSafety.php', [
            "@fopen(\$path, 'r+b')",
            '@fstat($handle)',
            '@lstat($path)',
            "\$opened['ino'] !== \$named['ino']",
        ]);
    }

    public function testTransferShareRenameRunsUnderAccountAndChecksBothFinalNodes(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/userTransfer/postSetup.php', [
            'clearstatcache(true, $src)',
            'clearstatcache(true, $dst)',
            "@readlink(\$share) !== '../../.local/share/pmss/rutorrent/share'",
            "\$share = \$home.'/.local/share/pmss/rutorrent/share'",
            'pmssPathTargetIsSafe($src, true, true)',
            'pmssPathTargetIsSafe($dst, true, true)',
            'pmssAccountPathRun($localUser, $home, [$src, $dst], $command)',
        ]);
        $source = $this->pmssReadRepoFile('scripts/lib/userTransfer/postSetup.php');
        $this->assertTrue(strpos($source, "'Normalising user permissions'") < strpos($source, 'pmssUserTransferRenameRutorrentShare($home, $remoteUser, $localUser)'),
            'Imported source ownership must be normalised before account rename');
    }

    public function testWebRootDirectoryMovesRunUnderAccount(): void
    {
        $migration = $this->pmssReadRepoFile('scripts/lib/update/users/webRoot.php');
        $reconcile = $this->pmssReadRepoFile('scripts/lib/update/users/webRootReconcile.php');
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/webRoot.php', [
            'pmssPathTargetIsSafe($source, true, true)',
            'pmssPathTargetIsSafe($target, true, true)',
            'pmssAccountPathRun($user, $home, $paths, $command)',
            'pmssUserWebRootMigrationRenameAsAccount($user, $home, $temporary, $target)',
            'pmssUserWebRootMigrationRenameAsAccount($user, $home, $source, $target)',
            'pmssUserWebRootMigrationRenameAsAccount($user, $home, $target, $source)',
            "pmssUserWebRootMigrationRun(\$user, \$home, [\$source],",
        ]);
        $this->assertTrue(strpos($migration, '&& @rename($source, $target)') !== false);
        $this->assertFalse(strpos($reconcile, '@rename($stage, $www)') !== false);
        $this->assertTrue(strpos($reconcile, 'pmssUserWebRootMigrationRenameAsAccount($user, $home, $stage, $www)') !== false);
        $this->assertTrue(strpos($reconcile, 'pmssUserWebRootMigrationRun($user, $home, [$target]') !== false);
    }

    public function testTerminationPurgeUsesPinnedSymlinkRefusingTree(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/terminationCleanup.php', [
            'pmssPinnedTreePurgeCommand($path, false)',
            'pmssPinnedTreePurgeCommand($path, true)',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/pathSafety.php', [
            "[ ! -L '.\$arg",
            'cd -P --',
            'find -P . -depth',
            'rm -rf -- ./* ./.[!.]* ./..?*',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/pathSafety/immutableClear.pl', [
            'O_NOFOLLOW',
            'sysopen(my $handle',
            '$inode[1] != $named[1]',
            'ioctl($handle, 0x80086601, $flags)',
            'ioctl($handle, 0x40086602, $updated)',
        ]);
    }

    public function testRecreateTopLevelMoveAndCleanupUseCheckedInodes(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/recreateUser.php', [
            'pmssRecreateMoveTopDirectory($backupDir, $supersededBackup)',
            'pmssRecreateMoveTopDirectory($homeDir, $backupDir)',
            'pmssRecreatePurgeSupersededDirectory($supersededBackup)',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/recreateRestore.php', [
            'clearstatcache(true, $source)',
            'clearstatcache(true, $destination)',
            'pmssPathTargetIsSafe($source, true, true)',
            'pmssPathTargetIsSafe($destination, true, true)',
            'pmssPinnedTreePurgeCommand($path, $clearImmutable)',
        ]);
    }

    /** @return array<int,string> */
    private function scanFile(string $path): array
    {
        $source = (string) file_get_contents($path);
        if (substr($path, -4) === '.php') {
            $source = $this->withoutPhpComments($source);
        }
        $findings = [];
        foreach (explode("\n", $source) as $index => $line) {
            if (preg_match('/^\s*(?:#|\/\/)/', $line)) continue;
            if ($this->operationOnLine($line)) {
                $findings[$index + 1] = trim($line);
            }
        }
        return $findings;
    }

    private function operationOnLine(string $line): bool
    {
        if (preg_match('/pmss(?:AccountPathRun|BuildUser(?:Service)?ShellCommand|WriteUserFile|ReplaceUserFile)/', $line)) return false;
        if (preg_match('/\bfind\b.*-not -type l.*(?:-exec chown -h|-exec chmod)/', $line)
            || preg_match('/\bfind\b.*-type f -links 1.*-exec (?:chmod|chown -h)/', $line)) return false;
        if (preg_match('/\b(?:chown|chmod|chgrp|cp|mv|rm|touch|mkdir|lighttpd)\s+/', $line)
            && $this->hasAccountPath($line)
            && !preg_match('/\b(?:runStep|runProvisionStep|runUserStep|pmssRun)\s*\(/', $line)) return true;
        if (preg_match('/\b(?:runStep|runProvisionStep|runUserStep|pmssRun)\s*\(/', $line)
            && preg_match('/\b(?:chown|chmod|chgrp|cp|mv|rm|find|lighttpd)\b/', $line)
            && $this->hasAccountPath($line)) return true;
        if (preg_match('/(?<!\$)\b(?:include|require)(?:_once)?\s+([^;]+);/', $line, $included)) {
            return $this->hasAccountPath($included[1]);
        }
        if (!preg_match('/\b(chown|chmod|chgrp|file_put_contents|fopen|copy|rename|unlink|touch|mkdir|exec|shell_exec|system|popen|proc_open|passthru)\s*\(([^;]*)/', $line, $call)) return false;
        $firstArg = explode(',', $call[2], 2)[0];
        return $this->hasAccountPath($firstArg)
            || (in_array($call[1], ['exec', 'shell_exec', 'system', 'popen', 'proc_open', 'passthru'], true)
                && preg_match('/\$(?:user|username|thisUser)\b/', $firstArg) === 1);
    }

    private function hasAccountPath(string $text): bool
    {
        return preg_match('~(?:/home/|\$home(?:Dir)?\b|\$userHome\b)~', $text) === 1;
    }

    private function withoutPhpComments(string $source): string
    {
        $result = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $result .= $token;
            } elseif ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $result .= str_repeat("\n", substr_count($token[1], "\n"));
            } else {
                $result .= $token[1];
            }
        }
        return $result;
    }
}
