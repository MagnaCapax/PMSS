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

    public function testPasswdFailureExitsBeforeHttpCredentialSync(): void
    {
        $source = $this->pmssReadRepoFile('scripts/changePw.php');

        $this->assertOrderedStrings(
            [
                'exec($cmd.\' 2>&1\', $passwdOutput, $passwdReturnCode);',
                'if ($passwdReturnCode !== 0) {',
                'exit(1);',
                '$htpasswdCommand = file_exists($htpasswdFile) ? \'htpasswd -b -m\' : \'htpasswd -c -b -m\';',
            ],
            $source,
            'changePw missing guard substring: ',
            'changePw guard order changed near: '
        );
    }
}
