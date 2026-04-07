<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';

class RuntimePathOverridesTest extends TestCase
{
    public function testPmssLogDirDefaultsToVarLogPmss(): void
    {
        $this->pmssWithEnv(['PMSS_LOG_DIR' => null], function (): void {
            $this->assertEquals('/var/log/pmss', \pmssLogDir());
        });
    }

    public function testPmssLogDirUsesEnvOverride(): void
    {
        $this->pmssWithEnv(['PMSS_LOG_DIR' => '/tmp/pmss-log-override/'], function (): void {
            $this->assertEquals('/tmp/pmss-log-override', \pmssLogDir());
        });
    }

    public function testPmssRuntimeDirDefaultsToVarRunPmss(): void
    {
        $this->pmssWithEnv(['PMSS_RUNTIME_DIR' => null], function (): void {
            $this->assertEquals('/var/run/pmss', \pmssRuntimeDir());
        });
    }

    public function testPmssRuntimeDirUsesEnvOverride(): void
    {
        $this->pmssWithEnv(['PMSS_RUNTIME_DIR' => '/tmp/pmss-runtime-override/'], function (): void {
            $this->assertEquals('/tmp/pmss-runtime-override', \pmssRuntimeDir());
        });
    }

    public function testPmssStateDirDefaultsToVarLibPmss(): void
    {
        $this->pmssWithEnv(['PMSS_STATE_DIR' => null], function (): void {
            $this->assertEquals('/var/lib/pmss', \pmssStateDir());
        });
    }

    public function testPmssStateDirUsesEnvOverride(): void
    {
        $this->pmssWithEnv(['PMSS_STATE_DIR' => '/tmp/pmss-state-override/'], function (): void {
            $this->assertEquals('/tmp/pmss-state-override', \pmssStateDir());
        });
    }

    public function testUpdateLoggingResolvesPmssLogFileFromLogDirOverride(): void
    {
        $logDir = '/tmp/pmss-log-bootstrap-'.bin2hex(random_bytes(4));
        $libraryPath = dirname(__DIR__, 3).'/lib/update.php';
        $script = 'require '.var_export($libraryPath, true).'; echo PMSS_LOG_FILE;';
        $output = trim($this->pmssRunInlinePhp($script, ['PMSS_LOG_DIR' => $logDir]));

        $this->assertEquals($logDir.'/update.log', $output);
    }

}
