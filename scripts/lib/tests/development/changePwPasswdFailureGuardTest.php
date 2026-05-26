<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class changePwPasswdFailureGuardTest extends TestCase
{
    public function testPasswdCommandUsesExitCodeAwareExecCall(): void
    {
        $source = $this->pmssReadRepoFile('scripts/changePw.php');

        $this->assertStringNotContainsString('shell_exec($cmd);', $source, 'changePw must not ignore passwd exit codes');
        $this->assertStringContainsString('exec($cmd.\' 2>&1\', $passwdOutput, $passwdReturnCode);', $source, 'changePw must capture passwd stderr and return code');
    }

    public function testPasswdCommandTreatsPasswordPayloadAsPrintfData(): void
    {
        $source = $this->pmssReadRepoFile('scripts/changePw.php');

        $this->assertStringContainsString("printf '%%s' %s | passwd %s", $source);
        $legacyPattern = "'printf ".'%s | passwd %s'."'";
        $this->assertStringNotContainsString($legacyPattern, $source);
    }

    public function testGeneratedPasswordUsesSharedSafeAlphabet(): void
    {
        $source = $this->pmssReadRepoFile('scripts/changePw.php');

        $this->assertStringContainsString('$alphabet = \'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_\';', $source);
        $this->assertStringNotContainsString('!@#$%', $source);
    }

    public function testJsonlModeKeepsDefaultHumanOutputGuarded(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/changePw.php', [
            '$jsonlOutput = false;',
            '$parseOptions = true;',
            "if (\$parseOptions && \$token === '--jsonl') {",
            'if (!$jsonlOutput) {',
            'pmssChangePwEmitJsonl($username, $password, $passwdReturnCode, $htpasswdReturnCode, $qbittorrentUpdated);',
        ]);
    }

    public function testJsonlModeReportsQbittorrentUpdateAsBoolean(): void
    {
        $source = $this->pmssReadRepoFile('scripts/changePw.php');

        $this->assertStringContainsString('?bool $qbittorrentUpdated', $source);
        $this->assertStringContainsString("'qbittorrent_updated' => \$qbittorrentUpdated,", $source);
        $this->assertStringNotContainsString('$qbittorrent'.'ReturnCode = 0;', $source);
        $this->assertStringNotContainsString("'qbittorrent".'_rc'."' =>", $source);
    }

    public function testPasswdFailureExitsBeforeHttpCredentialSync(): void
    {
        $source = $this->pmssReadRepoFile('scripts/changePw.php');

        $this->assertOrderedStrings(
            [
                'exec($cmd.\' 2>&1\', $passwdOutput, $passwdReturnCode);',
                'if ($passwdReturnCode !== 0) {',
                'exit(1);',
                '$htpasswdCommand = is_file($htpasswdFile) ? \'htpasswd -b -m\' : \'htpasswd -c -b -m\';',
            ],
            $source,
            'changePw missing guard substring: ',
            'changePw guard order changed near: '
        );
    }

    public function testHtpasswdCommandUsesExitCodeAwareExecCall(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/changePw.php', [
            '$htpasswdOutput = [];',
            '$htpasswdReturnCode = 0;',
            'if ($htpasswdReturnCode !== 0 || !is_file($htpasswdFile)) {',
        ]);
    }

    public function testHtpasswdFailureExitsBeforeOwnershipUpdate(): void
    {
        $source = $this->pmssReadRepoFile('scripts/changePw.php');

        $this->assertOrderedStrings(
            [
                '$htpasswdOutput = [];',
                '$htpasswdReturnCode = 0;',
                'if ($htpasswdReturnCode !== 0 || !is_file($htpasswdFile)) {',
                'htpasswd update failed for {$username}; aborting credential sync',
                '$chownOutput = [];',
            ],
            $source,
            'changePw htpasswd guard missing substring: ',
            'changePw htpasswd guard order changed near: '
        );
    }
}
