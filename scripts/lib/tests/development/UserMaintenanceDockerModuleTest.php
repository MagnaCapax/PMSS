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

        $this->pmssAssertRepoFileContainsString('scripts/lib/update/userMaintenance.php', "require_once __DIR__.'/users/docker.php';");
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/docker.php', $dockerFunctions);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/update/userMaintenance.php', $dockerFunctions);
    }
}
