<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/configBackups.php';

class ConfigBackupsServiceGuardTest extends TestCase
{
    public function testNormalizeServiceAcceptsSafeServiceNames(): void
    {
        foreach (['sshd', 'php-cgi_1.0', '.nginx', 'nginx.', '...', '..nginx'] as $service) {
            $this->assertEquals($service, \pmssConfigBackupsNormalizeService($service));
            $this->assertEquals($service, \pmssConfigBackupsNormalizeService(" \t".$service."\n"));
        }
    }

    public function testNormalizeServiceRejectsDotDirectories(): void
    {
        foreach (['.', '..', ' . ', "\t..\n"] as $service) {
            $this->assertEquals('', \pmssConfigBackupsNormalizeService($service));
        }
    }

    public function testDotDirectoryBackupAndPruneLeaveAncestorsUntouched(): void
    {
        [$sourceRoot, $parentRoot] = $this->pmssConfigBackupsFixtureRoots();
        $source = $this->pmssWriteRelativeFile($sourceRoot, 'etc/nginx/nginx.conf', "worker_processes auto;\n");
        $backupRoot = $parentRoot.'/config';
        mkdir($backupRoot, 0755);
        $name = '20000101000000__'.\pmssConfigBackupsPathKey($source).'.bak';
        $modes = [];
        // Both dot components resolve inside this fixture, including on a regression.
        foreach ([$parentRoot, $backupRoot] as $directory) {
            file_put_contents($directory.'/'.$name, 'keep');
            $modes[$directory] = fileperms($directory);
        }

        foreach (['.', '..', ' . ', "\t..\n"] as $service) {
            [$backup, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($service, $source, $backupRoot) {
                $options = [
                    'backupRoot' => $backupRoot, 'logger' => $logger, 'logSuccess' => false,
                    'timestamp' => '20260101000000', 'pmssVersion' => 'test', 'correlationId' => '',
                    'maxCount' => 1, 'ttlSeconds' => 1, 'nowTs' => 1800000000,
                ];
                $backup = \pmssBackupCriticalConfig($service, $source, $options);
                \pmssPruneCriticalConfigBackups($service, $source, $options);
                return $backup;
            });

            $this->assertEquals(null, $backup);
            $this->assertEquals([
                '[WARN] Refusing config backup with invalid service name',
                '[WARN] Refusing config backup prune with invalid service name',
            ], $messages);
            foreach ($modes as $directory => $mode) {
                clearstatcache(true, $directory);
                $this->assertEquals($mode, fileperms($directory));
                $this->assertEquals([$directory.'/'.$name], glob($directory.'/*.bak'));
                $this->assertEquals('keep', file_get_contents($directory.'/'.$name));
            }
        }
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
        [$sourceRoot, $backupRoot] = $this->pmssConfigBackupsFixtureRoots();
        $source = $this->pmssWriteRelativeFile($sourceRoot, 'etc/nginx/nginx.conf', "worker_processes auto;\n");

        [$backup, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($backupRoot, $source) {
            return \pmssBackupCriticalConfig('../nginx', $source, array(
                'backupRoot' => $backupRoot,
                'logger' => $logger,
                'logSuccess' => false,
            ));
        });

        $this->assertEquals(null, $backup);
        $this->assertEquals(['[WARN] Refusing config backup with invalid service name'], $messages);
        $this->assertTrue(!is_dir($backupRoot.'/../nginx'));
    }

    public function testPruneRejectsInvalidServiceWithoutTouchingFilesystem(): void
    {
        [$sourceRoot, $backupRoot] = $this->pmssConfigBackupsFixtureRoots();
        $source = $this->pmssWriteRelativeFile($sourceRoot, 'etc/nginx/nginx.conf', "worker_processes auto;\n");

        [, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($backupRoot, $source): void {
            \pmssPruneCriticalConfigBackups('nginx/child', $source, array(
                'backupRoot' => $backupRoot,
                'logger' => $logger,
            ));
        });

        $this->assertEquals(['[WARN] Refusing config backup prune with invalid service name'], $messages);
        $this->assertTrue(glob($backupRoot.'/*') === []);
    }

    public function testBackupRejectsRelativeSourcePath(): void
    {
        $backupRoot = $this->pmssConfigBackupsFixtureRoots()[1];

        [$backup, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($backupRoot) {
            return \pmssBackupCriticalConfig('nginx', 'etc/nginx/nginx.conf', array(
                'backupRoot' => $backupRoot,
                'logger' => $logger,
                'logSuccess' => false,
            ));
        });

        $this->assertEquals(null, $backup);
        $this->assertEquals(['[WARN] Refusing config backup for non-absolute source path: etc/nginx/nginx.conf'], $messages);
        $this->assertEquals(array(), glob($backupRoot.'/nginx/*.bak') ?: array());
    }

    public function testPruneRejectsRelativeBackupRootWithoutTouchingFilesystem(): void
    {
        [$sourceRoot] = $this->pmssConfigBackupsFixtureRoots();
        $source = $this->pmssWriteRelativeFile($sourceRoot, 'etc/nginx/nginx.conf', "worker_processes auto;\n");

        [, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($source): void {
            \pmssPruneCriticalConfigBackups('nginx', $source, array(
                'backupRoot' => 'relative-backups',
                'logger' => $logger,
            ));
        });

        $this->assertEquals(['[WARN] Refusing config backup with non-absolute backup root: relative-backups'], $messages);
        $this->assertTrue(!file_exists('relative-backups'));
    }
}
