<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';

class RuntimePathOverridesTest extends TestCase
{
    public function testPmssLogDirDefaultsToVarLogPmss(): void
    {
        $this->assertEnvResolvedPath('PMSS_LOG_DIR', null, '/var/log/pmss', '\pmssLogDir');
    }

    public function testPmssLogDirUsesEnvOverride(): void
    {
        $this->assertEnvResolvedPath('PMSS_LOG_DIR', '/tmp/pmss-log-override/', '/tmp/pmss-log-override', '\pmssLogDir');
    }

    public function testPmssRuntimeDirDefaultsToVarRunPmss(): void
    {
        $this->assertEnvResolvedPath('PMSS_RUNTIME_DIR', null, '/var/run/pmss', '\pmssRuntimeDir');
    }

    public function testPmssRuntimeDirUsesEnvOverride(): void
    {
        $this->assertEnvResolvedPath('PMSS_RUNTIME_DIR', '/tmp/pmss-runtime-override/', '/tmp/pmss-runtime-override', '\pmssRuntimeDir');
    }

    public function testPmssStateDirDefaultsToVarLibPmss(): void
    {
        $this->assertEnvResolvedPath('PMSS_STATE_DIR', null, '/var/lib/pmss', '\pmssStateDir');
    }

    public function testPmssStateDirUsesEnvOverride(): void
    {
        $this->assertEnvResolvedPath('PMSS_STATE_DIR', '/tmp/pmss-state-override/', '/tmp/pmss-state-override', '\pmssStateDir');
    }

    public function testUpdateLoggingResolvesPmssLogFileFromLogDirOverride(): void
    {
        $logDir = '/tmp/pmss-log-bootstrap-'.bin2hex(random_bytes(4));
        $libraryPath = dirname(__DIR__, 3).'/lib/update.php';
        $script = 'require '.var_export($libraryPath, true).'; echo PMSS_LOG_FILE;';
        $output = trim($this->pmssRunInlinePhp($script, ['PMSS_LOG_DIR' => $logDir]));

        $this->assertEquals($logDir.'/update.log', $output);
    }

    private function assertEnvResolvedPath(string $envKey, ?string $envValue, string $expected, callable $resolver): void
    {
        $this->pmssWithEnv([$envKey => $envValue], function () use ($expected, $resolver): void {
            $this->assertEquals($expected, $resolver());
        });
    }

}
