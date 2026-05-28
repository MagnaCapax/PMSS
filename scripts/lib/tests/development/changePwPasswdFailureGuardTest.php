<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class changePwPasswdFailureGuardTest extends TestCase
{
    public function testPasswdCommandUsesExitCodeAwareExecCall(): void
    {
        $this->pmssAssertRepoFileNotContainsString('scripts/changePw.php', 'shell_exec($cmd);', 'changePw must not ignore passwd exit codes');
        $this->pmssAssertRepoFileContainsString('scripts/changePw.php', 'exec($cmd.\' 2>&1\', $passwdOutput, $passwdReturnCode);', 'changePw must capture passwd stderr and return code');
    }

    public function testPasswdCommandTreatsPasswordPayloadAsPrintfData(): void
    {
        $legacyPattern = "'printf ".'%s | passwd %s'."'";
        $this->pmssAssertRepoFileContainsString('scripts/changePw.php', "printf '%%s' %s | passwd %s");
        $this->pmssAssertRepoFileNotContainsString('scripts/changePw.php', $legacyPattern);
    }

    public function testGeneratedPasswordUsesSharedSafeAlphabet(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/changePw.php', '$alphabet = \'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_\';');
        $this->pmssAssertRepoFileNotContainsString('scripts/changePw.php', '!@#$%');
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
        $this->pmssAssertRepoFileContainsAllStrings('scripts/changePw.php', ['?bool $qbittorrentUpdated', "'qbittorrent_updated' => \$qbittorrentUpdated,"]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/changePw.php', ['$qbittorrent'.'ReturnCode = 0;', "'qbittorrent".'_rc'."' =>"]);
    }

    public function testPasswdFailureExitsBeforeHttpCredentialSync(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/changePw.php',
            [
                'exec($cmd.\' 2>&1\', $passwdOutput, $passwdReturnCode);',
                'if ($passwdReturnCode !== 0) {',
                'exit(1);',
                '$htpasswdCommand = is_file($htpasswdFile) ? \'htpasswd -b -m\' : \'htpasswd -c -b -m\';',
            ],
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
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/changePw.php',
            [
                '$htpasswdOutput = [];',
                '$htpasswdReturnCode = 0;',
                'if ($htpasswdReturnCode !== 0 || !is_file($htpasswdFile)) {',
                'htpasswd update failed for {$username}; aborting credential sync',
                '$chownOutput = [];',
            ],
            'changePw htpasswd guard missing substring: ',
            'changePw htpasswd guard order changed near: '
        );
    }
}
