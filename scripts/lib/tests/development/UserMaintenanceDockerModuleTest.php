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

    public function testNeutraliseMasksUnitAndMarkerRecognisesIt(): void
    {
        $home = $this->pmssMakeTempPath('pmss-docker-home-');
        $unitDir = $home.'/.config/systemd/user';
        $this->assertTrue(@mkdir($unitDir, 0755, true), 'temp home unit dir created');
        $unit = $unitDir.'/docker.service';

        // (a) a real unit reads as NOT neutralised, then masks cleanly.
        file_put_contents($unit, "[Service]\nExecStart=/usr/bin/dockerd-rootless.sh\n");
        $this->assertFalse(\pmssUserDockerServiceUnitIsNeutralised($unit), 'real unit is not neutralised');
        $this->assertTrue(\pmssNeutralizeUserDockerServiceUnit($unit, $home), 'mask succeeds');
        $this->assertTrue(is_link($unit) && readlink($unit) === '/dev/null', 'unit becomes a /dev/null symlink');
        $this->assertTrue(\pmssUserDockerServiceUnitIsNeutralised($unit), 'masked unit reads as neutralised (install-marker)');
        $this->assertFalse(is_file($unit), 'masked unit is not a regular file, so is_file() would loop — marker must use is_link');

        // (b) masking is idempotent on an already-masked unit.
        $this->assertTrue(\pmssNeutralizeUserDockerServiceUnit($unit, $home), 'mask is idempotent');
        $this->assertTrue(\pmssUserDockerServiceUnitIsNeutralised($unit), 'still neutralised after idempotent call');

        // (c) refuses to touch a path whose parent is outside the user's home.
        $outside = $this->pmssMakeTempPath('pmss-docker-outside-');
        file_put_contents($outside, 'keep');
        $this->assertFalse(\pmssNeutralizeUserDockerServiceUnit($outside, $home), 'refuses outside home');
        $this->assertSame('keep', file_get_contents($outside), 'outside path left untouched');
    }
}
