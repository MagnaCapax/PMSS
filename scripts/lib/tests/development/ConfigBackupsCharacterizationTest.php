<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/configBackups.php';

class ConfigBackupsCharacterizationTest extends TestCase
{
    /** @return array{0:string,1:string}|array{0:string,1:string,2:string} */
    private function makeBackupRoots(bool $withOutside = false): array
    {
        $roots = [$this->pmssMakeTempDir('pmss-backups-src-'), $this->pmssMakeTempDir('pmss-backups-root-')];
        if ($withOutside) {
            $roots[] = $this->pmssMakeTempDir('pmss-backups-outside-');
        }

        return $roots;
    }

    private function writeSourceFile(string $root, string $relativePath, string $content): string
    {
        $path = $root.'/'.ltrim($relativePath, '/');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);

        return $path;
    }

    public function testBackupFilenameFormatRemainsStable(): void
    {
        [$sourceRoot, $backupRoot] = $this->makeBackupRoots();
        $source = $this->writeSourceFile($sourceRoot, 'etc/ssh/sshd_config', "Port 22\n");

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
        [$sourceRoot, $backupRoot] = $this->makeBackupRoots();
        $serviceDir = $backupRoot.'/nginx';
        @mkdir($serviceDir, 0700, true);

        $targetSource = $this->writeSourceFile($sourceRoot, 'etc/nginx/nginx.conf', "worker_processes auto;\n");
        $otherSource = $this->writeSourceFile($sourceRoot, 'etc/nginx/proxy_params', "proxy_set_header Host \$host;\n");

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
        [$sourceRoot, $backupRoot] = $this->makeBackupRoots();
        $source = $this->writeSourceFile($sourceRoot, 'etc/ssh/sshd_config', "Port 22\n");
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
        [$sourceRoot, $backupRoot, $outsideRoot] = $this->makeBackupRoots(true);
        $source = $this->writeSourceFile($sourceRoot, 'etc/nginx/nginx.conf', "worker_processes auto;\n");
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
        [$sourceRoot, $backupRoot, $outsideRoot] = $this->makeBackupRoots(true);
        $source = $this->writeSourceFile($sourceRoot, 'etc/proftpd/proftpd.conf', "ServerName pmss\n");

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
