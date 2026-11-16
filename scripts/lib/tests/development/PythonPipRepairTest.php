<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/python/pip.php';

/** Regression coverage for the repair invoked by scripts/lib/update/apps/python.php. */
final class PythonPipRepairTest extends TestCase
{
    /** Keep every launcher and replacement inside a fresh disposable tree. */
    private function fixture(): array
    {
        $root = $this->pmssMakeTempDir('pmss-pip-repair-');
        $this->pmssTrackEnvOverrides(['PMSS_DRY_RUN' => '0']);
        $this->pmssWriteExecutableFile($root.'/local/pip', '#!'.$root."/missing/python3.11\n");
        $this->pmssWriteExecutableFile($root.'/system/pip', "#!/bin/sh\nexit 0\n");
        return [$root.'/local', $root.'/system'];
    }

    /** Cover Python generations left behind by distro upgrades and both command names. */
    public function testMissingPythonLaunchersAreBackedUpAndRerunsConverge(): void
    {
        foreach (['python', 'python2', 'python2.7', 'python3', 'python3.11'] as $interpreter) {
            [$local, $system] = $this->fixture();
            $bytes = '#!'.dirname($local).'/missing/'.$interpreter."\nprint('fixture')\n";
            foreach (['pip', 'pip3'] as $name) {
                $this->pmssWriteExecutableFile($local.'/'.$name, $bytes);
                $this->pmssWriteExecutableFile($system.'/'.$name, "#!/bin/sh\nexit 0\n");
            }
            \pmssPythonPipRepair($local, $system);
            \pmssPythonPipRepair($local, $system);
            foreach (['pip', 'pip3'] as $name) {
                $this->assertFalse(file_exists($local.'/'.$name));
                $this->assertSame($bytes, file_get_contents($local.'/'.$name.'.pmss-disabled'));
                $this->assertTrue(is_executable($system.'/'.$name));
            }
        }
    }

    /** An unfamiliar header is not evidence that PMSS may retire a local command. */
    public function testExistingInterpretersAndUnrecognizedLaunchersStayUntouched(): void
    {
        foreach (["#!/bin/sh\n", "#!/usr/bin/env python3\n", "no shebang\n", "\x7fELF\0", 'existing'] as $header) {
            [$local, $system] = $this->fixture();
            if ($header === 'existing') {
                $this->pmssWriteExecutableFile(dirname($local).'/python3', "#!/bin/sh\nexit 0\n");
                $header = '#!'.dirname($local)."/python3\n";
            }
            $this->pmssWriteExecutableFile($local.'/pip', $header);
            \pmssPythonPipRepair($local, $system);
            $this->assertSame($header, file_get_contents($local.'/pip'));
            $this->assertFalse(file_exists($local.'/pip.pmss-disabled'));
        }
    }

    /** Never retire a launcher without a usable replacement for the same name. */
    public function testMissingNonExecutableOrFailingDistroPipPreservesLocalLauncher(): void
    {
        foreach (['missing', 'nonExecutable', 'failure'] as $state) {
            [$local, $system] = $this->fixture();
            if ($state === 'missing') {
                unlink($system.'/pip');
            } elseif ($state === 'nonExecutable') {
                chmod($system.'/pip', 0644);
            } else {
                $this->pmssWriteExecutableFile($system.'/pip', "#!/bin/sh\nexit 23\n");
            }
            \pmssPythonPipRepair($local, $system);
            $this->assertTrue(file_exists($local.'/pip'));
            $this->assertFalse(file_exists($local.'/pip.pmss-disabled'));
        }
    }

    /** Every existing backup entry blocks replacement, including dangling symlinks. */
    public function testBackupFilesDirectoriesAndDanglingLinksAreNeverClobbered(): void
    {
        foreach (['file', 'directory', 'link'] as $type) {
            [$local, $system] = $this->fixture();
            $backup = $local.'/pip.pmss-disabled';
            if ($type === 'file') {
                $this->pmssWriteFile($backup, 'previous backup');
            } elseif ($type === 'directory') {
                mkdir($backup);
            } else {
                symlink($local.'/absent', $backup);
            }
            \pmssPythonPipRepair($local, $system);
            $this->assertTrue(file_exists($local.'/pip'));
            if ($type === 'file') $this->assertSame('previous backup', file_get_contents($backup));
            if ($type === 'directory') $this->assertTrue(is_dir($backup));
            if ($type === 'link') $this->assertSame($local.'/absent', readlink($backup));
        }
    }

    /** Operator-managed links are outside this narrow regular-file migration. */
    public function testSymlinkedLocalLauncherIsPreserved(): void
    {
        [$local, $system] = $this->fixture();
        rename($local.'/pip', $local.'/target');
        symlink($local.'/target', $local.'/pip');
        \pmssPythonPipRepair($local, $system);
        $this->assertSame($local.'/target', readlink($local.'/pip'));
        $this->assertFalse(file_exists($local.'/pip.pmss-disabled'));
    }

    /** A failed backup command must leave the original command available. */
    public function testFailedMovePreservesLocalLauncher(): void
    {
        [$local, $system] = $this->fixture();
        $stubPath = $this->pmssMakeExecutableStub('mv', "#!/bin/sh\nexit 23\n", 'pmss-pip-move-fail-');
        $this->pmssWithPathPrefix($stubPath, function () use ($local, $system): void {
            \pmssPythonPipRepair($local, $system);
        });
        $this->assertTrue(file_exists($local.'/pip'));
        $this->assertFalse(file_exists($local.'/pip.pmss-disabled'));
    }

    /** The updater's existing dry-run switch must suppress both shell commands. */
    public function testDryRunRecordsRepairWithoutMovingLauncher(): void
    {
        [$local, $system] = $this->fixture();
        $this->pmssResetRuntimeProfile();
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function () use ($local, $system): void {
            \pmssPythonPipRepair($local, $system);
        });
        $this->assertTrue(file_exists($local.'/pip'));
        $this->assertFalse(file_exists($local.'/pip.pmss-disabled'));
        $this->assertSame(\pmssBuildCommand('mv', ['-nT', '--', $local.'/pip', $local.'/pip.pmss-disabled']),
            $this->pmssFindProfileCommand('Backing up stale local pip launcher'));
    }
}
