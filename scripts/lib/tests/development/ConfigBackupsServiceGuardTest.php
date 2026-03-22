<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/configBackups.php';

class ConfigBackupsServiceGuardTest extends TestCase
{
    public function testNormalizeServiceAcceptsSafeServiceNames(): void
    {
        $this->assertEquals('sshd', \pmssConfigBackupsNormalizeService('sshd'));
        $this->assertEquals('php-cgi_1.0', \pmssConfigBackupsNormalizeService('php-cgi_1.0'));
    }

    public function testNormalizeServiceRejectsBlankNames(): void
    {
        $this->assertEquals('', \pmssConfigBackupsNormalizeService(" \n\t "));
    }

    public function testNormalizeServiceRejectsTraversalSeparators(): void
    {
        $this->assertEquals('', \pmssConfigBackupsNormalizeService('../nginx'));
        $this->assertEquals('', \pmssConfigBackupsNormalizeService('sshd/child'));
    }

    public function testNormalizeServiceRejectsShellMetacharacters(): void
    {
        $this->assertEquals('', \pmssConfigBackupsNormalizeService('nginx;rm'));
    }

    public function testBackupRejectsInvalidServiceWithoutCreatingDirectories(): void
    {
        $sourceRoot = $this->pmssMakeTempDir('pmss-backups-src-');
        $backupRoot = $this->pmssMakeTempDir('pmss-backups-root-');
        $messages = [];
        $source = $sourceRoot.'/etc/nginx/nginx.conf';
        @mkdir(dirname($source), 0755, true);
        file_put_contents($source, "worker_processes auto;\n");

        $backup = \pmssBackupCriticalConfig('../nginx', $source, array(
            'backupRoot' => $backupRoot,
            'logger' => function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
            'logSuccess' => false,
        ));

        $this->assertEquals(null, $backup);
        $this->assertEquals(['[WARN] Refusing config backup with invalid service name'], $messages);
        $this->assertTrue(!is_dir($backupRoot.'/../nginx'));

        $this->pmssRemoveTree($sourceRoot);
        $this->pmssRemoveTree($backupRoot);
    }

    public function testPruneRejectsInvalidServiceWithoutTouchingFilesystem(): void
    {
        $sourceRoot = $this->pmssMakeTempDir('pmss-backups-src-');
        $backupRoot = $this->pmssMakeTempDir('pmss-backups-root-');
        $messages = [];
        $source = $sourceRoot.'/etc/nginx/nginx.conf';
        @mkdir(dirname($source), 0755, true);
        file_put_contents($source, "worker_processes auto;\n");

        \pmssPruneCriticalConfigBackups('nginx/child', $source, array(
            'backupRoot' => $backupRoot,
            'logger' => function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        ));

        $this->assertEquals(['[WARN] Refusing config backup prune with invalid service name'], $messages);
        $this->assertTrue(glob($backupRoot.'/*') === []);

        $this->pmssRemoveTree($sourceRoot);
        $this->pmssRemoveTree($backupRoot);
    }
}
