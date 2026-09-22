<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../update/users/docker.php';

class UserMaintenanceDockerModuleTest extends TestCase
{
    public function testDockerUnitExecBinaryParsingStaysStable(): void
    {
        foreach ([
            ["[Service]\nExecStart=/usr/bin/dockerd-rootless.sh --experimental\n", '/usr/bin/dockerd-rootless.sh', 'plain ExecStart binary'],
            [";ignored\n# ExecStart=/bad\nExecStart=-/usr/local/bin/dockerd-rootless.sh --flag\n", '/usr/local/bin/dockerd-rootless.sh', 'systemd dash prefix'],
            ["[Service]\nEnvironment=FOO=bar\n", null, 'missing ExecStart'],
            ["[Service]\nExecStart=\n", null, 'empty ExecStart'],
            ["[Service]\nExecStart=-\n", null, 'dash-only ExecStart'],
        ] as $case) {
            $unit = $this->pmssMakeTempPath('pmss-docker-unit-');
            file_put_contents($unit, $case[0]);
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
            'scripts/lib/update/users/docker.php' => ['required' => $dockerFunctions],
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
            'scripts/lib/update/users/docker.php' => [
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
