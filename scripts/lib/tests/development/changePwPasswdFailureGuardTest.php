<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/passwordFile.php';

class changePwPasswdFailureGuardTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-change-pw-']; }

    public function testChangePwCredentialSyncSourceContracts(): void
    {
        $legacyPattern = "'printf ".'%s | passwd %s'."'";
        $this->pmssAssertRepoFileContractCases([
            'scripts/changePw.php' => [
                'required' => [
                    'exec($cmd.\' 2>&1\', $passwdOutput, $passwdReturnCode);',
                    "printf '%%s' %s | passwd %s",
                    '$alphabet = \'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_\';',
                    '$jsonlOutput = false;',
                    '$parseOptions = true;',
                    "if (\$parseOptions && \$token === '--jsonl') {",
                    'if (!$jsonlOutput) {',
                    'pmssChangePwEmitJsonl($username, $password, $passwdReturnCode, $htpasswdReturnCode, $qbittorrentUpdated);',
                    '?bool $qbittorrentUpdated',
                    "'qbittorrent_updated' => \$qbittorrentUpdated,",
                    '$htpasswdOutput = [];',
                    '$htpasswdReturnCode = pmssUserHtpasswdPasswordUpdate($username, $password, $htpasswdFile, $htpasswdOutput);',
                    'if ($htpasswdReturnCode !== 0) {',
                ],
                'forbidden' => [
                    'shell_exec($cmd);' => 'changePw must not ignore passwd exit codes',
                    $legacyPattern,
                    '!@#$%',
                    '$qbittorrent'.'ReturnCode = 0;',
                    "'qbittorrent".'_rc'."' =>",
                ],
            ],
        ]);
    }

    public function testPasswdFailureExitsBeforeHttpCredentialSync(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/changePw.php',
            [
                'exec($cmd.\' 2>&1\', $passwdOutput, $passwdReturnCode);',
                'if ($passwdReturnCode !== 0) {',
                'exit(1);',
                'pmssUserHtpasswdPasswordUpdate($username, $password, $htpasswdFile, $htpasswdOutput);',
            ],
            'changePw missing guard substring: ',
            'changePw guard order changed near: '
        );
    }

    public function testHtpasswdFailureExitsBeforeQbittorrentSync(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/changePw.php',
            [
                '$htpasswdOutput = [];',
                '$htpasswdReturnCode = pmssUserHtpasswdPasswordUpdate($username, $password, $htpasswdFile, $htpasswdOutput);',
                'if ($htpasswdReturnCode !== 0) {',
                'htpasswd update failed for {$username}; aborting credential sync',
                '$qbittorrentUpdated = pmssUpdateQbittorrentPasswordAsUser($username, $password);',
            ],
            'changePw htpasswd guard missing substring: ',
            'changePw htpasswd guard order changed near: '
        );
    }

    public function testHtpasswdUpdateRunsAsAccountAndProducesValidLine(): void
    {
        $username = $this->pmssCurrentOwner();
        $path = $this->tempDir.'/.lighttpd/.htpasswd';
        mkdir(dirname($path), 0700);
        $binDir = $this->tempDir.'/bin';
        mkdir($binDir, 0700);
        $stub = <<<'SH'
#!/bin/sh
if [ "$1" = -c ]; then shift; fi
if [ "$1" = -b ]; then shift; fi
if [ "$1" = -m ]; then shift; fi
php -r '$hash = password_hash($argv[3], PASSWORD_BCRYPT); file_put_contents($argv[1], $argv[2].":".$hash."\n");' "$@"
SH;
        file_put_contents($binDir.'/htpasswd', $stub."\n");
        chmod($binDir.'/htpasswd', 0700);
        $originalPath = getenv('PATH');
        putenv('PATH='.$binDir.':'.$originalPath);
        $output = [];
        try {
            $this->assertEquals(0, \pmssUserHtpasswdPasswordUpdate($username, 'test-pass-1', $path, $output));
            $this->assertEquals(0600, fileperms($path) & 0777);
            $line = trim((string) file_get_contents($path));
            $this->assertTrue(strpos($line, $username.':') === 0);
            $this->assertTrue(password_verify('test-pass-1', substr($line, strlen($username) + 1)));
            $this->assertEquals(0, \pmssUserHtpasswdPasswordUpdate($username, 'test-pass-2', $path, $output));
        } finally {
            putenv('PATH='.$originalPath);
        }
        $this->assertEquals(1, count(file($path)));
    }

    public function testHtpasswdUpdateRejectsLinkedFile(): void
    {
        $username = $this->pmssCurrentOwner();
        $dir = $this->tempDir.'/.lighttpd';
        mkdir($dir, 0700);
        [$target, $path] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/target', $dir.'/.htpasswd', "unchanged\n");
        $output = [];
        $this->assertTrue(\pmssUserHtpasswdPasswordUpdate($username, 'test-pass', $path, $output) !== 0);
        $this->assertEquals("unchanged\n", file_get_contents($target));
    }

    public function testHtpasswdUpdateRejectsLinkedDirectory(): void
    {
        $username = $this->pmssCurrentOwner();
        [$targetDir, $linkedDir] = $this->pmssCreateSymlinkedDirectoryOrSkip($this->tempDir.'/target-dir', $this->tempDir.'/.lighttpd');
        $target = $targetDir.'/.htpasswd';
        file_put_contents($target, "unchanged\n");
        $output = [];
        $this->assertTrue(\pmssUserHtpasswdPasswordUpdate($username, 'test-pass', $linkedDir.'/.htpasswd', $output) !== 0);
        $this->assertEquals("unchanged\n", file_get_contents($target));
    }

    public function testQbittorrentHashWritePrecedesNonGracefulRestart(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/changePw.php',
            [
                '$qbittorrentUpdated = pmssUpdateQbittorrentPasswordAsUser($username, $password);',
                'if ($qbittorrentUpdated) {',
                'killall -u %s -KILL %s 2>/dev/null',
            ],
            'changePw qBittorrent restart guard missing substring: ',
            'changePw qBittorrent restart order changed near: '
        );
        $this->pmssAssertRepoFileNotContainsString(
            'scripts/changePw.php',
            'killall -u %s -TERM %s 2>/dev/null',
            'qBittorrent must not gracefully overwrite the newly written password hash'
        );
    }
}
