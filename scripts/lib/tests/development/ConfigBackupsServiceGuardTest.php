<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/configBackups.php';

class ConfigBackupsServiceGuardTest extends TestCase
{
    /** @return array{0:string,1:string} */
    private function makeBackupRoots(): array
    {
        return [$this->pmssMakeTempDir('pmss-backups-src-'), $this->pmssMakeTempDir('pmss-backups-root-')];
    }

    private function writeSourceFile(string $root, string $relativePath, string $content): string
    {
        $path = $root.'/'.ltrim($relativePath, '/');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);

        return $path;
    }

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
        [$sourceRoot, $backupRoot] = $this->makeBackupRoots();
        $messages = [];
        $source = $this->writeSourceFile($sourceRoot, 'etc/nginx/nginx.conf', "worker_processes auto;\n");

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
        [$sourceRoot, $backupRoot] = $this->makeBackupRoots();
        $messages = [];
        $source = $this->writeSourceFile($sourceRoot, 'etc/nginx/nginx.conf', "worker_processes auto;\n");

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

    public function testBackupRejectsRelativeSourcePath(): void
    {
        $backupRoot = $this->pmssMakeTempDir('pmss-backups-root-');
        $messages = [];

        $backup = \pmssBackupCriticalConfig('nginx', 'etc/nginx/nginx.conf', array(
            'backupRoot' => $backupRoot,
            'logger' => function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
            'logSuccess' => false,
        ));

        $this->assertEquals(null, $backup);
        $this->assertEquals(['[WARN] Refusing config backup for non-absolute source path: etc/nginx/nginx.conf'], $messages);
        $this->assertEquals(array(), glob($backupRoot.'/nginx/*.bak') ?: array());

        $this->pmssRemoveTree($backupRoot);
    }

    public function testPruneRejectsRelativeBackupRootWithoutTouchingFilesystem(): void
    {
        [$sourceRoot] = $this->makeBackupRoots();
        $messages = [];
        $source = $this->writeSourceFile($sourceRoot, 'etc/nginx/nginx.conf', "worker_processes auto;\n");

        \pmssPruneCriticalConfigBackups('nginx', $source, array(
            'backupRoot' => 'relative-backups',
            'logger' => function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        ));

        $this->assertEquals(['[WARN] Refusing config backup with non-absolute backup root: relative-backups'], $messages);
        $this->assertTrue(!file_exists('relative-backups'));

        $this->pmssRemoveTree($sourceRoot);
    }
}
