<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class changePwPasswdFailureGuardTest extends TestCase
{
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
                    '$htpasswdReturnCode = 0;',
                    'if ($htpasswdReturnCode !== 0 || !is_file($htpasswdFile)) {',
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
                '$htpasswdCommand = is_file($htpasswdFile) ? \'htpasswd -b -m\' : \'htpasswd -c -b -m\';',
            ],
            'changePw missing guard substring: ',
            'changePw guard order changed near: '
        );
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
