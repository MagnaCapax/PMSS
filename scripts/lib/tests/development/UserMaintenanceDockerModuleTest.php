<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/users/docker.php';

class UserMaintenanceDockerModuleTest extends TestCase
{
    public function testStaleLingerPruneKeepsLiveNamesAndUnsafeEntries(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-linger-');
        $outside = $this->pmssMakeTempFile('pmss-linger-outside-');
        $this->pmssWriteFiles($dir, array_fill_keys(['fixtur01', 'fixtur02', 'fixtur03', 'backup-fixtur01'], ''));
        mkdir($dir.'/fixtur04');
        symlink($outside, $dir.'/fixtur05');
        $seen = [];
        $lookup = static function (string $name) use (&$seen) {
            $seen[] = $name;
            return $name === 'fixtur02' ? ['uid' => 1234] : false;
        };
        $this->pmssWithEnv(['PMSS_TEST_MODE' => '0'], function () use ($dir, $lookup): void {
            $this->assertSame(0, \pmssUserMaintenancePruneStaleLinger($dir, $lookup, true, posix_getuid()));
            $this->assertTrue(is_file($dir.'/fixtur01'));
            $this->assertSame(3, \pmssUserMaintenancePruneStaleLinger($dir, $lookup, false, posix_getuid()));
        });
        $this->assertFalse(file_exists($dir.'/fixtur01'));
        $this->assertTrue(is_file($dir.'/fixtur02'));
        $this->assertFalse(file_exists($dir.'/fixtur03'));
        $this->assertFalse(is_link($dir.'/fixtur05'));
        $this->assertTrue(is_file($outside), 'symlink target must remain');
        $this->assertTrue(is_dir($dir.'/fixtur04'));
        $this->assertTrue(is_file($dir.'/backup-fixtur01'));
        $this->assertFalse(in_array('backup-fixtur01', $seen, true));
    }

    public function testStaleLingerPruneSkipsTestModeAndLinkedDirectory(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-linger-');
        $this->pmssWriteFile($dir.'/fixtur01', '');
        $lookup = static function (string $name) { return false; };
        $this->pmssWithEnv(['PMSS_TEST_MODE' => '1'], function () use ($dir, $lookup): void {
            $this->assertSame(0, \pmssUserMaintenancePruneStaleLinger($dir, $lookup, false, posix_getuid()));
        });
        $link = $this->pmssMakeTempPath('pmss-linger-link-');
        symlink($dir, $link);
        $this->pmssWithEnv(['PMSS_TEST_MODE' => '0'], function () use ($link, $lookup): void {
            $this->assertSame(0, \pmssUserMaintenancePruneStaleLinger($link, $lookup, false, posix_getuid()));
        });
        $this->assertTrue(is_file($dir.'/fixtur01'));
    }

    public function testDockerUnitExecBinaryParsingStaysStable(): void
    {
        foreach ([
            ["[Service]\nExecStart=/usr/bin/dockerd-rootless.sh --experimental\n", '/usr/bin/dockerd-rootless.sh', 'plain ExecStart binary'],
            [";ignored\n# ExecStart=/bad\nExecStart=-/usr/local/bin/dockerd-rootless.sh --flag\n", '/usr/local/bin/dockerd-rootless.sh', 'systemd dash prefix'],
            ["[Service]\nEnvironment=FOO=bar\n", null, 'missing ExecStart'],
            ["[Service]\nExecStart=\n", null, 'empty ExecStart'],
            ["[Service]\nExecStart=-\n", null, 'dash-only ExecStart'],
        ] as $case) {
            $unit = $this->pmssWriteFile($this->pmssMakeTempPath('pmss-docker-unit-'), $case[0]);
            $this->assertSame($case[1], \pmssUserDockerUnitExecBinary($unit), $case[2]);
        }
    }

    public function testUserMaintenanceOnlyOrchestratesDockerModule(): void
    {
        $dockerFunctions = array_map(static function (string $function): string {
            return 'function '.$function.'(';
        }, ['pmssEnsureLingerAndDocker', 'pmssEnsureRootlessDockerInstalled', 'pmssEnsureDockerDependencies']);

        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/userMaintenance.php' => [
                'required' => ["'users/docker.php'"],
                'forbidden' => $dockerFunctions,
            ],
            'scripts/lib/update/users/docker.php' => ['required' => [$dockerFunctions[0], "'dockerInstall.php'", "'dockerDependencies.php'"]],
            'scripts/lib/update/users/dockerInstall.php' => ['required' => [$dockerFunctions[1]]],
            'scripts/lib/update/users/dockerDependencies.php' => ['required' => [$dockerFunctions[2]]],
        ]);
    }

    public function testDockerUnitKeptFunctionalAndPriorMaskReverted(): void
    {
        // GH#873 correction: the per-user docker.service unit is the customer-usable start
        // path (`systemctl --user start docker.service`, the Rootless Docker KB article; GH#794
        // treats broken `systemctl --user` as a fault to FIX). It MUST stay functional — it is
        // NOT masked. A unit a prior release masked (symlink -> /dev/null) is un-masked so the
        // real customer-usable unit is restored.
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/users/dockerInstall.php' => [
                'required' => ["=== '/dev/null'", 'unit present and valid'],
                'forbidden' => ['pmssNeutralizeUserDockerServiceUnit', 'neutralised (masked)'],
            ],
            'scripts/lib/user/rootlessDockerConfig.php' => [
                'required' => ['pmssUserRootlessDockerConfigConverge'],
                'forbidden' => ['pmssNeutralizeUserDockerServiceUnit'],
            ],
        ]);
    }
}
