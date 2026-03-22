<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/configBackups.php';

class ConfigBackupsCharacterizationTest extends TestCase
{
    public function testBackupFilenameFormatRemainsStable(): void
    {
        $sourceRoot = $this->pmssMakeTempDir('pmss-backups-src-');
        $backupRoot = $this->pmssMakeTempDir('pmss-backups-root-');
        $source = $sourceRoot.'/etc/ssh/sshd_config';
        @mkdir(dirname($source), 0755, true);
        file_put_contents($source, "Port 22\n");

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
        $sourceRoot = $this->pmssMakeTempDir('pmss-backups-src-');
        $backupRoot = $this->pmssMakeTempDir('pmss-backups-root-');
        $serviceDir = $backupRoot.'/nginx';
        @mkdir($serviceDir, 0700, true);

        $targetSource = $sourceRoot.'/etc/nginx/nginx.conf';
        $otherSource = $sourceRoot.'/etc/nginx/proxy_params';
        @mkdir(dirname($targetSource), 0755, true);
        file_put_contents($targetSource, "worker_processes auto;\n");
        file_put_contents($otherSource, "proxy_set_header Host \$host;\n");

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
}
