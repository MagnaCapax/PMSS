<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/configBackups.php';

class ConfigBackupsTest extends TestCase
{
    public function testBackupCreatesFileWithMetadataInName(): void
    {
        $root = $this->pmssMakeTempDir('pmss-backups-src-');
        $backupRoot = $this->pmssMakeTempDir('pmss-backups-root-');

        $source = $root.'/etc/ssh/sshd_config';
        $this->pmssWriteFile($source, "Port 22\n");

        $backup = \pmssBackupCriticalConfig('sshd', $source, array(
            'backupRoot' => $backupRoot,
            'timestamp' => '20260131123456',
            'pmssVersion' => 'git/main@2026-01-31',
            'correlationId' => 'abc-123',
            'ttlSeconds' => 0,
            'logSuccess' => false,
        ));

        $this->assertTrue(is_string($backup) && $backup !== '');
        $this->assertTrue(is_file($backup));
        $this->assertEquals("Port 22\n", file_get_contents($backup));

        $expectedKey = \pmssConfigBackupsPathKey($source);
        $this->assertStringContainsString('/sshd/', (string) $backup);
        $this->assertStringContainsString('20260131123456__'.$expectedKey, (string) $backup);
        $this->assertStringContainsString('__v=git_main_2026-01-31', (string) $backup);
        $this->assertStringContainsString('__cid=abc-123', (string) $backup);
        $this->assertTrue((fileperms($backup) & 0777) === 0600);
    }

    public function testBackupReturnsNullWhenSourceMissing(): void
    {
        $backupRoot = $this->pmssMakeTempDir('pmss-backups-root-');
        $missing = $backupRoot.'/nope.conf';

        $backup = \pmssBackupCriticalConfig('sshd', $missing, array(
            'backupRoot' => $backupRoot,
        ));

        $this->assertTrue($backup === null);
    }

    public function testBackupHelpersStillWorkWithoutRuntimeLoggerBootstrap(): void
    {
        $root = $this->pmssMakeTempDir('pmss-backups-src-');
        $backupRoot = $this->pmssMakeTempDir('pmss-backups-root-');

        $source = $root.'/etc/nginx/nginx.conf';
        $this->pmssWriteFile($source, "worker_processes auto;\n");

        $script = 'require '.var_export(dirname(__DIR__, 2).'/configBackups.php', true).'; '
            .'$backup = pmssBackupCriticalConfig('.var_export('nginx', true).', '.var_export($source, true).', '
            .var_export(array(
                'backupRoot' => $backupRoot,
                'timestamp' => '20260131123456',
                'ttlSeconds' => 0,
                'logSuccess' => false,
            ), true).'); '
            .'pmssPruneCriticalConfigBackups('.var_export('nginx', true).', '.var_export($source, true).', '
            .var_export(array(
                'backupRoot' => $backupRoot,
                'maxCount' => 10,
                'ttlSeconds' => 0,
            ), true).'); '
            .'echo is_string($backup) ? basename($backup) : "null";';

        $output = $this->pmssRunInlinePhp($script, [], '2>&1');

        $this->assertTrue(is_string($output));
        $this->assertStringContainsString('.bak', $output);
    }

    public function testPruneKeepsNewestNBackups(): void
    {
        $root = $this->pmssMakeTempDir('pmss-backups-src-');
        $backupRoot = $this->pmssMakeTempDir('pmss-backups-root-');

        $source = $root.'/etc/ssh/sshd_config';
        $this->pmssWriteFile($source, "Port 22\n");

        $serviceDir = $backupRoot.'/sshd';
        @mkdir($serviceDir, 0700, true);
        $key = \pmssConfigBackupsPathKey($source);

        $paths = array(
            $serviceDir.'/20260131100000__'.$key.'.bak',
            $serviceDir.'/20260131110000__'.$key.'.bak',
            $serviceDir.'/20260131120000__'.$key.'.bak',
            $serviceDir.'/20260131130000__'.$key.'.bak',
        );
        foreach ($paths as $p) {
            file_put_contents($p, 'x');
        }

        \pmssPruneCriticalConfigBackups('sshd', $source, array(
            'backupRoot' => $backupRoot,
            'maxCount' => 2,
            'ttlSeconds' => 0,
        ));

        $remaining = glob($serviceDir.'/*__'.$key.'*.bak') ?: array();
        sort($remaining, SORT_STRING);
        $this->assertEquals(array(
            $serviceDir.'/20260131120000__'.$key.'.bak',
            $serviceDir.'/20260131130000__'.$key.'.bak',
        ), $remaining);
    }

    public function testPruneDropsBackupsOlderThanTtl(): void
    {
        $root = $this->pmssMakeTempDir('pmss-backups-src-');
        $backupRoot = $this->pmssMakeTempDir('pmss-backups-root-');

        $source = $root.'/etc/nginx/nginx.conf';
        $this->pmssWriteFile($source, "worker_processes auto;\n");

        $serviceDir = $backupRoot.'/nginx';
        @mkdir($serviceDir, 0700, true);
        $key = \pmssConfigBackupsPathKey($source);

        $old = $serviceDir.'/20251201000000__'.$key.'.bak';
        $new = $serviceDir.'/20260104000000__'.$key.'.bak';
        file_put_contents($old, 'old');
        file_put_contents($new, 'new');

        $dt = \DateTime::createFromFormat('YmdHis', '20260105000000');
        $this->assertTrue($dt !== false);
        $nowTs = $dt->getTimestamp();

        \pmssPruneCriticalConfigBackups('nginx', $source, array(
            'backupRoot' => $backupRoot,
            'maxCount' => 10,
            'ttlSeconds' => 3 * 86400,
            'nowTs' => $nowTs,
        ));

        $this->assertTrue(!file_exists($old));
        $this->assertTrue(file_exists($new));
    }

    public function testPruneReturnsQuietlyWhenServiceDirectoryMissing(): void
    {
        $root = $this->pmssMakeTempDir('pmss-backups-src-');
        $backupRoot = $this->pmssMakeTempDir('pmss-backups-root-');

        $source = $root.'/etc/ssh/sshd_config';
        $this->pmssWriteFile($source, "Port 22\n");

        [, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($backupRoot, $source): void {
            \pmssPruneCriticalConfigBackups('sshd', $source, array(
                'backupRoot' => $backupRoot,
                'logger' => $logger,
            ));
        });

        $this->assertEquals(array(), $messages);
    }

    public function testPathKeyFallsBackForBlankInput(): void
    {
        $this->assertEquals('unknown_path', \pmssConfigBackupsPathKey(" \n\t "));
    }

    public function testSanitizeLabelCondensesUnsafeCharacters(): void
    {
        $this->assertEquals('git_main_2026-01-31', \pmssConfigBackupsSanitizeLabel('  git/main @2026-01-31  '));
        $this->assertEquals('abcd', \pmssConfigBackupsSanitizeLabel('abcd-efgh', 4));
    }

}
