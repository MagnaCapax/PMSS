<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/configBackups.php';

class ConfigBackupsCharacterizationTest extends TestCase
{
    public function testNulInputsCannotOverwriteOrPruneExistingBackups(): void
    {
        [$sourceRoot, $backupRoot] = $this->pmssConfigBackupsFixtureRoots();
        $source = $this->pmssWriteRelativeFile($sourceRoot, 'etc/nginx/nginx.conf', "new config\n");
        $serviceDir = $backupRoot.'/nginx';
        mkdir($serviceDir, 0700);
        $backupPath = $serviceDir.'/20000101000000__'.\pmssConfigBackupsPathKey($source).'.bak';
        file_put_contents($backupPath, 'keep');

        // Include edge bytes that trim() used to erase and embedded bytes it retained.
        foreach (["\0%s", "%s\0", " \0%s\t", "\t%s\0\n", "%s\0suffix"] as $format) {
            foreach (['service', 'source'] as $field) {
                $serviceArg = $field === 'service' ? sprintf($format, 'nginx') : 'nginx';
                $sourceArg = $field === 'source' ? sprintf($format, $source) : $source;
                [$backup, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($serviceArg, $sourceArg, $backupRoot) {
                    $options = [
                        'backupRoot' => $backupRoot, 'logger' => $logger, 'logSuccess' => false,
                        'timestamp' => '20000101000000', 'pmssVersion' => '', 'correlationId' => '',
                        'ttlSeconds' => 1, 'nowTs' => 1800000000,
                    ];
                    $backup = \pmssBackupCriticalConfig($serviceArg, $sourceArg, $options);
                    \pmssPruneCriticalConfigBackups($serviceArg, $sourceArg, $options);
                    return $backup;
                });

                $this->assertSame(null, $backup);
                $this->assertSame(2, count($messages));
                $this->assertSame(false, strpos(implode('', $messages), "\0"));
                $this->assertSame([$backupPath], glob($serviceDir.'/*.bak'));
                $this->assertSame('keep', file_get_contents($backupPath));
                $this->assertSame("new config\n", file_get_contents($source));
            }
        }
    }

    public function testWhitespaceNormalizedBackupAndPruneRemainCompatible(): void
    {
        [$sourceRoot, $backupRoot] = $this->pmssConfigBackupsFixtureRoots();
        $source = $this->pmssWriteRelativeFile($sourceRoot, 'etc/nginx/nginx.conf', "config\n");
        $options = [
            'backupRoot' => $backupRoot, 'logSuccess' => false, 'ttlSeconds' => 0,
            'timestamp' => '20000101000000', 'pmssVersion' => '', 'correlationId' => '',
        ];
        $backup = \pmssBackupCriticalConfig(" \tnginx\n", " \t".$source."\n", $options);
        $this->assertSame($backupRoot.'/nginx/20000101000000__'.\pmssConfigBackupsPathKey($source).'.bak', $backup);
        $this->assertSame("config\n", file_get_contents($backup));
        $options['ttlSeconds'] = 1;
        $options['nowTs'] = 1800000000;
        \pmssPruneCriticalConfigBackups(" \tnginx\n", " \t".$source."\n", $options);
        $this->assertSame(false, file_exists($backup));
    }

    public function testBackupFilenameFormatRemainsStable(): void
    {
        [$sourceRoot, $backupRoot] = $this->pmssConfigBackupsFixtureRoots();
        $source = $this->pmssWriteRelativeFile($sourceRoot, 'etc/ssh/sshd_config', "Port 22\n");

        $backup = \pmssBackupCriticalConfig('sshd', $source, array(
            'backupRoot' => $backupRoot,
            'correlationId' => 'abc-123',
            'logSuccess' => false,
            'pmssVersion' => 'git/main@2026-01-31',
            'timestamp' => '20260131123456',
            'ttlSeconds' => 0,
        ));

        $this->assertEquals(
            '20260131123456__etc_ssh_sshd_config__v=git_main_2026-01-31__cid=abc-123.bak',
            basename((string) $backup)
        );
    }

    public function testPruneOnlyRemovesBackupsForMatchingSourceKey(): void
    {
        [$sourceRoot, $backupRoot] = $this->pmssConfigBackupsFixtureRoots();
        $serviceDir = $backupRoot.'/nginx';
        @mkdir($serviceDir, 0700, true);

        $targetSource = $this->pmssWriteRelativeFile($sourceRoot, 'etc/nginx/nginx.conf', "worker_processes auto;\n");
        $otherSource = $this->pmssWriteRelativeFile($sourceRoot, 'etc/nginx/proxy_params', "proxy_set_header Host \$host;\n");

        $targetKey = \pmssConfigBackupsPathKey($targetSource);
        $otherKey = \pmssConfigBackupsPathKey($otherSource);
        file_put_contents($serviceDir.'/20260131100000__'.$targetKey.'.bak', 'old');
        file_put_contents($serviceDir.'/20260131110000__'.$targetKey.'.bak', 'new');
        file_put_contents($serviceDir.'/20260131120000__'.$otherKey.'.bak', 'other');

        \pmssPruneCriticalConfigBackups('nginx', $targetSource, array(
            'backupRoot' => $backupRoot,
            'maxCount' => 1,
            'ttlSeconds' => 0,
        ));

        $remaining = glob($serviceDir.'/*.bak') ?: array();
        sort($remaining, SORT_STRING);
        $this->assertEquals(array(
            $serviceDir.'/20260131110000__'.$targetKey.'.bak',
            $serviceDir.'/20260131120000__'.$otherKey.'.bak',
        ), $remaining);
    }

    public function testBackupRejectsSymlinkSourcePath(): void
    {
        [$sourceRoot, $backupRoot] = $this->pmssConfigBackupsFixtureRoots();
        $source = $this->pmssWriteRelativeFile($sourceRoot, 'etc/ssh/sshd_config', "Port 22\n");
        $sourceLink = $sourceRoot.'/etc/ssh/sshd_config.link';
        symlink($source, $sourceLink);

        $backup = \pmssBackupCriticalConfig('sshd', $sourceLink, array(
            'backupRoot' => $backupRoot,
            'logSuccess' => false,
        ));

        $this->assertTrue($backup === null, 'Expected symlinked source path to be rejected');
        $this->assertEquals(array(), glob($backupRoot.'/sshd/*.bak') ?: array());
    }

    public function testBackupRejectsSymlinkedServiceDirectory(): void
    {
        [$sourceRoot, $backupRoot, $outsideRoot] = $this->pmssConfigBackupsFixtureRoots(true);
        $source = $this->pmssWriteRelativeFile($sourceRoot, 'etc/nginx/nginx.conf', "worker_processes auto;\n");
        symlink($outsideRoot, $backupRoot.'/nginx');

        $backup = \pmssBackupCriticalConfig('nginx', $source, array(
            'backupRoot' => $backupRoot,
            'logSuccess' => false,
        ));

        $this->assertTrue($backup === null, 'Expected symlinked service directory to be rejected');
        $this->assertEquals(array(), glob($outsideRoot.'/*.bak') ?: array());
    }

    public function testPruneSkipsSymlinkedServiceDirectory(): void
    {
        [$sourceRoot, $backupRoot, $outsideRoot] = $this->pmssConfigBackupsFixtureRoots(true);
        $source = $this->pmssWriteRelativeFile($sourceRoot, 'etc/proftpd/proftpd.conf', "ServerName pmss\n");

        $sourceKey = \pmssConfigBackupsPathKey($source);
        $backupPath = $outsideRoot.'/20260131100000__'.$sourceKey.'.bak';
        file_put_contents($backupPath, 'old');
        symlink($outsideRoot, $backupRoot.'/proftpd');

        \pmssPruneCriticalConfigBackups('proftpd', $source, array(
            'backupRoot' => $backupRoot,
            'maxCount' => 0,
            'ttlSeconds' => 0,
        ));

        $this->assertTrue(is_file($backupPath), 'Expected prune to skip symlinked service directory');
    }
}
