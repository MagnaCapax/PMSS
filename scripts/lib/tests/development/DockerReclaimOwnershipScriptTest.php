<?php
/**
 * Hermetic coverage for the docker-reclaim-ownership tenant helper.
 */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../runtime/environment.php';

class DockerReclaimOwnershipScriptTest extends TestCase
{
    private $homeDir;
    private $fakeBinDir;
    private $dockerLog;
    private $bashBin;
    private $scriptPath;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-docker-reclaim', 0700);
        $this->homeDir = $this->tempDir.'/home';
        $this->fakeBinDir = $this->tempDir.'/bin';
        $this->dockerLog = $this->tempDir.'/docker.log';
        $this->bashBin = trim($this->pmssRunShellCommand('command -v bash', [], '2>/dev/null'));
        $this->scriptPath = __DIR__.'/../../../../etc/skel/bin/docker-reclaim-ownership';

        @mkdir($this->homeDir, 0700, true);
        @mkdir($this->fakeBinDir, 0700, true);

        // Records every invocation; chown itself is the real daemon's job
        // inside the container, so the stub only needs to prove the wiring.
        $dockerStub = <<<'BASH'
#!/usr/bin/env bash
set -eu
if [[ -n "${PMSS_TEST_DOCKER_LOG:-}" ]]; then
  printf '%s\n' "$*" >>"$PMSS_TEST_DOCKER_LOG"
fi
exit 0
BASH;
        $this->pmssWriteExecutableFile($this->fakeBinDir.'/docker', $dockerStub, 0700);
    }

    /**
     * @param array<string,string> $env
     * @return array{rc:int,output:string,dockerLog:string}
     */
    private function runHelper(array $args, array $env = []): array
    {
        $environment = array_replace([
            'HOME' => $this->homeDir,
            'PATH' => $this->fakeBinDir.':'.(getenv('PATH') !== false ? getenv('PATH') : '/usr/bin:/bin'),
            'PMSS_TEST_DOCKER_LOG' => $this->dockerLog,
        ], $env);
        $result = $this->pmssExecArgvCommand(array_merge([$this->bashBin, $this->scriptPath], $args), $environment);
        return [
            'rc' => $result['rc'],
            'output' => $result['output'],
            'dockerLog' => $this->pmssReadFileOrEmpty($this->dockerLog),
        ];
    }

    public function testNoArgumentsShowsUsage(): void
    {
        $result = $this->runHelper([]);

        $this->assertTrue($result['rc'] !== 0, 'missing path should fail');
        $this->assertStringContainsString('Usage: docker-reclaim-ownership PATH', $result['output']);
        $this->assertSame('', $result['dockerLog']);
    }

    public function testHelpFlagShowsUsageWithoutRunningDocker(): void
    {
        $result = $this->runHelper(['--help']);

        $this->assertTrue($result['rc'] !== 0);
        $this->assertStringContainsString('Usage: docker-reclaim-ownership PATH', $result['output']);
        $this->assertSame('', $result['dockerLog']);
    }

    public function testMissingDockerBinaryFails(): void
    {
        $emptyPath = $this->tempDir.'/empty-bin';
        @mkdir($emptyPath, 0700, true);
        $target = $this->pmssEnsureDir($this->homeDir.'/docker/jellyfin');

        $result = $this->runHelper([$target], ['PATH' => $emptyPath]);

        $this->assertTrue($result['rc'] !== 0, 'docker-less run should fail');
        $this->assertStringContainsString('docker command not found in PATH', $result['output']);
    }

    public function testNonexistentPathFails(): void
    {
        $result = $this->runHelper([$this->homeDir.'/does-not-exist']);

        $this->assertTrue($result['rc'] !== 0);
        $this->assertStringContainsString('Path not found', $result['output']);
        $this->assertSame('', $result['dockerLog']);
    }

    public function testPathOutsideHomeIsRefusedWithoutRunningDocker(): void
    {
        $outside = $this->pmssEnsureDir($this->tempDir.'/outside');

        $result = $this->runHelper([$outside]);

        $this->assertTrue($result['rc'] !== 0, 'path outside $HOME must be refused');
        $this->assertStringContainsString('is outside your home directory', $result['output']);
        $this->assertSame('', $result['dockerLog'], 'docker must never run against a path outside $HOME');
    }

    public function testValidPathInsideHomeReclaimsOwnership(): void
    {
        $target = $this->pmssEnsureDir($this->homeDir.'/docker/jellyfin/config');

        $result = $this->runHelper([$target]);

        $this->assertEquals(0, $result['rc']);
        $this->assertStringContainsString('owned by you again', $result['output']);
        $this->assertStringContainsAllStrings(['-v', $target.':/reclaim', 'chown -R 0:0 /reclaim'], $result['dockerLog']);
    }

    public function testSkeletonCopiesInstallerScript(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/users/filesystem.php', "'bin/docker-reclaim-ownership'");
    }
}
