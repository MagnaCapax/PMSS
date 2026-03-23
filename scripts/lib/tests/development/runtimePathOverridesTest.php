<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';

class RuntimePathOverridesTest extends TestCase
{
    public function testPmssLogDirDefaultsToVarLogPmss(): void
    {
        $previous = $this->pmssCaptureEnv(['PMSS_LOG_DIR']);
        putenv('PMSS_LOG_DIR');

        try {
            $this->assertEquals('/var/log/pmss', \pmssLogDir());
        } finally {
            $this->pmssRestoreEnvMap($previous);
        }
    }

    public function testPmssLogDirUsesEnvOverride(): void
    {
        $previous = $this->pmssCaptureEnv(['PMSS_LOG_DIR']);
        putenv('PMSS_LOG_DIR=/tmp/pmss-log-override/');

        try {
            $this->assertEquals('/tmp/pmss-log-override', \pmssLogDir());
        } finally {
            $this->pmssRestoreEnvMap($previous);
        }
    }

    public function testPmssRuntimeDirDefaultsToVarRunPmss(): void
    {
        $previous = $this->pmssCaptureEnv(['PMSS_RUNTIME_DIR']);
        putenv('PMSS_RUNTIME_DIR');

        try {
            $this->assertEquals('/var/run/pmss', \pmssRuntimeDir());
        } finally {
            $this->pmssRestoreEnvMap($previous);
        }
    }

    public function testPmssRuntimeDirUsesEnvOverride(): void
    {
        $previous = $this->pmssCaptureEnv(['PMSS_RUNTIME_DIR']);
        putenv('PMSS_RUNTIME_DIR=/tmp/pmss-runtime-override/');

        try {
            $this->assertEquals('/tmp/pmss-runtime-override', \pmssRuntimeDir());
        } finally {
            $this->pmssRestoreEnvMap($previous);
        }
    }

    public function testPmssStateDirDefaultsToVarLibPmss(): void
    {
        $previous = $this->pmssCaptureEnv(['PMSS_STATE_DIR']);
        putenv('PMSS_STATE_DIR');

        try {
            $this->assertEquals('/var/lib/pmss', \pmssStateDir());
        } finally {
            $this->pmssRestoreEnvMap($previous);
        }
    }

    public function testPmssStateDirUsesEnvOverride(): void
    {
        $previous = $this->pmssCaptureEnv(['PMSS_STATE_DIR']);
        putenv('PMSS_STATE_DIR=/tmp/pmss-state-override/');

        try {
            $this->assertEquals('/tmp/pmss-state-override', \pmssStateDir());
        } finally {
            $this->pmssRestoreEnvMap($previous);
        }
    }

    public function testUpdateLoggingResolvesPmssLogFileFromLogDirOverride(): void
    {
        $logDir = '/tmp/pmss-log-bootstrap-'.bin2hex(random_bytes(4));
        $libraryPath = dirname(__DIR__, 3).'/lib/update.php';
        $script = 'require '.var_export($libraryPath, true).'; echo PMSS_LOG_FILE;';
        $command = 'PMSS_LOG_DIR='.escapeshellarg($logDir).' php -r '.escapeshellarg($script).' 2>/dev/null';
        $output = trim((string) @shell_exec($command));

        $this->assertEquals($logDir.'/update.log', $output);
    }

}
