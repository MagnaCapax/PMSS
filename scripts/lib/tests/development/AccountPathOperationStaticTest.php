<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Keep account-path operations on the established user or managed-file paths. */
final class AccountPathOperationStaticTest extends TestCase
{
    /** Fixed commands with an account argument; each entry also pins its reviewed line. */
    private const SAFE = [
        'scripts/cron/checkLighttpdInstances.php:62' => ['66d938ed265b0ac243e1b992e1820850e3e6fe1e', 'fixed config utility with quoted account'],
        'scripts/cron/webPublicCertsProcess.php:129' => ['8073e46d40d522b379cbc0518ad64b2e494f42a4', 'fixed config utility with quoted account'],
        'scripts/lib/rtorrent/processInspection.php:98' => ['13417f72f7995d00594046ad6921f6924f10f56a', 'process listing with quoted account'],
        'scripts/lib/rtorrent/processLifecycle.php:147' => ['12b37a5ae2ed26df531cf9f4d0ea67203ef8646a', 'fixed launcher with quoted account'],
        'scripts/lib/lighttpd/userConfigApply.php:61' => ['af1754514e1c4518bfe8bbc90929d930bb125806', 'fixed port utility with quoted account'],
        'scripts/lib/lighttpd/delugeWebConf.php:66' => ['ca3295ab37e134547d4dcbf5aad5f4ab9578fd7f', 'fixed process name with quoted account'],
        'scripts/lib/network/fireqos.php:39' => ['b4712db6815bc3f3adbcce7ab5678ddb8db7a60b', 'uid lookup with quoted account'],
        'scripts/lib/resources/log.php:32' => ['abcdc22775d788f8fa306195c4bbca4a3da976ab', 'uid lookup with quoted account'],
        'scripts/lib/nginxConfig/userConfigsGenerate.php:159' => ['66d938ed265b0ac243e1b992e1820850e3e6fe1e', 'fixed config utility with quoted account'],
        'scripts/lib/update/users/docker.php:79' => ['b1fd9ef63ccf528d30bc352bef2c0d326d4311b5', 'uid lookup with quoted account'],
    ];

    public function testAccountPathOperationsUseManagedBoundaries(): void
    {
        $root = dirname(__DIR__, 4);
        $offenders = [];
        $reviewed = [];
        foreach (['scripts/cron', 'scripts/lib', 'scripts/util'] as $directory) {
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory));
            foreach ($entries as $entry) {
                if (!$entry->isFile() || !preg_match('/\.(?:php|sh)$/', $entry->getFilename())) continue;
                $path = substr($entry->getPathname(), strlen($root) + 1);
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
            'pmssPathTargetIsSafe($src, true, true)',
            'pmssPathTargetIsSafe($dst, true, true)',
            'pmssAccountPathRun($localUser, $home, [$src, $dst], $command)',
        ]);
        $source = $this->pmssReadRepoFile('scripts/lib/userTransfer/postSetup.php');
        $this->assertTrue(strpos($source, "'Normalising user permissions'") < strpos($source, 'pmssUserTransferRenameRutorrentShare($home, $remoteUser, $localUser)'),
            'Imported source ownership must be normalised before account rename');
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
