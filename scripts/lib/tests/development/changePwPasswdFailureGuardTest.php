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

        $this->assertStringContainsString('$alphabet = pmssUserPasswordGenerationAlphabet();', $source);
        $this->assertStringNotContainsString('!@#$%', $source);
    }

    public function testJsonlModeKeepsDefaultHumanOutputGuarded(): void
    {
        $source = $this->pmssReadRepoFile('scripts/changePw.php');

        $this->assertStringContainsString('$jsonlOutput = false;', $source);
        $this->assertStringContainsString('$parseOptions = true;', $source);
        $this->assertStringContainsString("if (\$parseOptions && \$token === '--jsonl') {", $source);
        $this->assertStringContainsString('if (!$jsonlOutput) {', $source);
        $this->assertStringContainsString('pmssChangePwEmitJsonl($username, $password, $passwdReturnCode, $htpasswdReturnCode, $qbittorrentReturnCode);', $source);
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
        $source = $this->pmssReadRepoFile('scripts/changePw.php');

        $this->assertStringContainsString('$htpasswdOutput = [];', $source);
        $this->assertStringContainsString('$htpasswdReturnCode = 0;', $source);
        $this->assertStringContainsString('if ($htpasswdReturnCode !== 0 || !is_file($htpasswdFile)) {', $source);
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
