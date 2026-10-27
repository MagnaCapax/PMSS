<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/passwordFile.php';
require_once __DIR__.'/../../user/changePwCredential.php';
require_once __DIR__.'/../../user/credentialGenerate.php';
require_once __DIR__.'/../../lighttpd/htpasswd.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/panelSessionAuth.php';

class changePwPasswdFailureGuardTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-change-pw-']; }

    public function testChangePwCredentialSyncSourceContracts(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/changePw.php' => [
                'required' => [
                    'pmssChangePwShadowUpdate($username, $password, $useBcrypt, $passwdOutput);',
                    'pmssUserHtpasswdHashWrite($htpasswdFile, $username, $passwordHash, $username)',
                    'return pmssAccountPasswordGenerate();',
                    "require_once __DIR__.'/lib/user/credentialGenerate.php';",
                    '$jsonlOutput = false;',
                    '$parseOptions = true;',
                    "if (\$parseOptions && \$token === '--jsonl') {",
                    'if (!$jsonlOutput) {',
                    'pmssChangePwEmitJsonl($username, $password, $passwdReturnCode, $htpasswdReturnCode, $qbittorrentUpdated);',
                    '?bool $qbittorrentUpdated',
                    "'qbittorrent_updated' => \$qbittorrentUpdated,",
                    '$htpasswdOutput = [];',
                    'pmssUserHtpasswdPasswordUpdate($username, $password, $htpasswdFile, $htpasswdOutput);',
                    'if ($htpasswdReturnCode !== 0) {',
                ],
                'forbidden' => [
                    'shell_exec($cmd);' => 'changePw must not ignore passwd exit codes',
                    "printf '%%s' %s | passwd %s" => 'password must not be placed in the command line',
                    '!@#$%',
                    '$qbittorrent'.'ReturnCode = 0;',
                    "'qbittorrent".'_rc'."' =>",
                ],
            ],
        ]);
    }

    public function testGeneratedAccountCredentialsUseSharedCharacterSet(): void
    {
        $source = $this->pmssReadRepoFile('scripts/changePw.php');
        foreach (['legacyPasswordSeed', 'rand(', 'mt_rand('] as $legacyCall) {
            $this->assertTrue(strpos($source, $legacyCall) === false);
        }
        for ($i = 0; $i < 1000; $i++) {
            $password = \pmssAccountPasswordGenerate();
            $this->assertSame(16, strlen($password));
            $this->assertMatches('/^[A-Za-z0-9][A-Za-z0-9_-]{14}[A-Za-z0-9]$/', $password);
            foreach (['!', '@', '#', '$', '%', '&', ':'] as $forbidden) {
                $this->assertTrue(strpos($password, $forbidden) === false);
            }
        }
    }

    public function testPasswdFailureExitsBeforeHttpCredentialSync(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/changePw.php',
            [
                'pmssChangePwShadowUpdate($username, $password, $useBcrypt, $passwdOutput);',
                'if ($passwdReturnCode !== 0) {',
                'pmssUserHtpasswdHashWrite($htpasswdFile, $username, $passwordHash, $username)',
            ],
            'changePw missing guard substring: ',
            'changePw guard order changed near: '
        );
        $this->pmssAssertRepoFileMatches('scripts/changePw.php', '/if \(\$passwdReturnCode !== 0\) \{.*?exit\(1\);/s');
    }

    public function testHtpasswdFailureExitsBeforeQbittorrentSync(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/changePw.php',
            [
                '$htpasswdOutput = [];',
                '$htpasswdReturnCode = $useBcrypt',
                'if ($htpasswdReturnCode !== 0) {',
                'htpasswd update failed for {$username}; aborting credential sync',
                '$qbittorrentUpdated = pmssUpdateQbittorrentPasswordAsUser($username, $password);',
            ],
            'changePw htpasswd guard missing substring: ',
            'changePw htpasswd guard order changed near: '
        );
    }

    public function testBcryptShadowAndHtpasswdUseOneHashAcrossUnsuspendSync(): void
    {
        $versionPath = $this->tempDir.'/debian_version';
        file_put_contents($versionPath, "12.15\n");
        $this->assertTrue(\pmssChangePwBcryptSupported($versionPath));
        file_put_contents($versionPath, "11.11\n");
        $this->assertTrue(\pmssChangePwBcryptSupported($versionPath));

        $username = $this->pmssCurrentOwner();
        $password = 'test-secret-with-special-$-chars';
        $inputPath = $this->tempDir.'/chpasswd-input';
        $argsPath = $this->tempDir.'/chpasswd-args';
        $binDir = $this->tempDir.'/bin';
        mkdir($binDir, 0700);
        $this->pmssWriteExecutableFile($binDir.'/chpasswd', "#!/bin/sh\nprintf '%s' \"\$*\" > \"\$PMSS_TEST_ARGS\"\ncat > \"\$PMSS_TEST_INPUT\"\n", 0755, 0700);
        $originalPath = getenv('PATH');
        putenv('PATH='.$binDir.':'.$originalPath);
        putenv('PMSS_TEST_INPUT='.$inputPath);
        putenv('PMSS_TEST_ARGS='.$argsPath);
        $output = [];
        try {
            [$status, $hash] = \pmssChangePwShadowUpdate($username, $password, true, $output);
        } finally {
            putenv('PATH='.$originalPath);
            putenv('PMSS_TEST_INPUT');
            putenv('PMSS_TEST_ARGS');
        }
        $this->assertEquals(0, $status);
        $this->assertTrue(strpos($hash, '$2y$') === 0);
        $this->assertTrue(password_verify($password, $hash));
        $this->assertSame('-e', (string) file_get_contents($argsPath));
        $this->assertFalse(strpos((string) file_get_contents($argsPath), $password) !== false);
        $this->assertFalse(strpos((string) file_get_contents($argsPath), $hash) !== false);
        $this->assertSame($username.':'.$hash."\n", (string) file_get_contents($inputPath));

        $homeRoot = $this->pmssTrackHomeRoot($this->tempDir.'/home');
        $htpasswdPath = $homeRoot.'/'.$username.'/.lighttpd/.htpasswd';
        mkdir(dirname($htpasswdPath), 0755, true);
        $this->assertTrue(\pmssUserHtpasswdHashWrite($htpasswdPath, $username, $hash, $username));
        $beforeSync = (string) file_get_contents($htpasswdPath);
        $this->assertSame($username.':'.$hash."\n", $beforeSync);
        $shadowPath = $this->tempDir.'/shadow';
        file_put_contents($shadowPath, $username.':'.$hash.':20000:0:99999:7:::'."\n");
        $this->assertTrue(\pmssUserHtpasswdSyncFromShadow($username, $shadowPath));
        $this->assertSame($beforeSync, (string) file_get_contents($htpasswdPath));
    }

    public function testDebianTenAndUnknownVersionsKeepPasswdPath(): void
    {
        $versionPath = $this->tempDir.'/debian_version';
        foreach (["10.13\n", "bookworm/sid\n", "", "12.invalid\n"] as $version) {
            file_put_contents($versionPath, $version);
            $this->assertFalse(\pmssChangePwBcryptSupported($versionPath));
        }
        $this->assertFalse(\pmssChangePwBcryptSupported($this->tempDir.'/missing-version'));

        $inputPath = $this->tempDir.'/passwd-input';
        $argsPath = $this->tempDir.'/passwd-args';
        $binDir = $this->tempDir.'/bin';
        mkdir($binDir, 0700);
        $this->pmssWriteExecutableFile($binDir.'/passwd', "#!/bin/sh\nprintf '%s' \"\$*\" > \"\$PMSS_TEST_ARGS\"\ncat > \"\$PMSS_TEST_INPUT\"\n", 0755, 0700);
        $originalPath = getenv('PATH');
        putenv('PATH='.$binDir.':'.$originalPath);
        putenv('PMSS_TEST_INPUT='.$inputPath);
        putenv('PMSS_TEST_ARGS='.$argsPath);
        $output = [];
        try {
            [$status, $hash] = \pmssChangePwShadowUpdate('alice', 'legacy-secret', false, $output);
        } finally {
            putenv('PATH='.$originalPath);
            putenv('PMSS_TEST_INPUT');
            putenv('PMSS_TEST_ARGS');
        }
        $this->assertSame(0, $status);
        $this->assertSame('', $hash);
        $this->assertSame('alice', (string) file_get_contents($argsPath));
        $this->assertSame("legacy-secret\nlegacy-secret\n", (string) file_get_contents($inputPath));
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
if [ "$1" = -i ]; then shift; else exit 2; fi
if [ "$1" = -B ]; then shift; else exit 2; fi
printf '%s' "$*" > "$PMSS_TEST_ARGS"
IFS= read -r password
printf '%s' "$password" > "$PMSS_TEST_INPUT"
printf '%s' "$password" | php -r '$hash = password_hash(stream_get_contents(STDIN), PASSWORD_BCRYPT); $rows = is_file($argv[1]) ? file($argv[1]) : []; $rows = array_filter($rows, function ($row) use ($argv) { return strpos($row, $argv[2].":") !== 0; }); $rows[] = $argv[2].":".$hash."\n"; file_put_contents($argv[1], implode("", $rows));' "$@"
SH;
        $this->pmssWriteExecutableFile($binDir.'/htpasswd', $stub."\n", 0755, 0700);
        $originalPath = getenv('PATH');
        putenv('PATH='.$binDir.':'.$originalPath);
        putenv('PMSS_TEST_ARGS='.$this->tempDir.'/htpasswd-args');
        putenv('PMSS_TEST_INPUT='.$this->tempDir.'/htpasswd-input');
        $output = [];
        try {
            $this->assertEquals(0, \pmssUserHtpasswdPasswordUpdate($username, 'test-pass-1', $path, $output));
            $this->assertSame('test-pass-1', (string) file_get_contents($this->tempDir.'/htpasswd-input'));
            $this->assertFalse(strpos((string) file_get_contents($this->tempDir.'/htpasswd-args'), 'test-pass-1') !== false);
            $this->assertEquals(0600, fileperms($path) & 0777);
            $line = trim((string) file_get_contents($path));
            $this->assertTrue(strpos($line, $username.':') === 0);
            $hash = substr($line, strlen($username) + 1);
            $this->assertTrue(strpos($hash, '$2y$') === 0);
            $this->assertTrue(\pmssPanelSessionPasswordMatchesHash('test-pass-1', $hash));
            $this->assertFalse(\pmssPanelSessionPasswordMatchesHash('wrong', $hash));
            $legacyEntry = 'legacy:$apr1$old$unchanged';
            file_put_contents($path, $line."\n".$legacyEntry."\n");
            $this->assertEquals(0, \pmssUserHtpasswdPasswordUpdate($username, 'test-pass-2', $path, $output));
        } finally {
            putenv('PATH='.$originalPath);
            putenv('PMSS_TEST_ARGS');
            putenv('PMSS_TEST_INPUT');
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertEquals(2, count($lines));
        $this->assertSame($legacyEntry, $lines[0]);
        $this->assertTrue(strpos($lines[1], $username.':$2y$') === 0);
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
                'pmssTorrentClientNativeStop($username, \'qbittorrent\', 9);',
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
