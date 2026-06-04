<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';

class RuntimePathOverridesTest extends TestCase
{
    public function testRuntimeHelpersDefaultToPmssDirectories(): void
    {
        foreach ([
            ['PMSS_LOG_DIR', '/var/log/pmss', '\pmssLogDir'],
            ['PMSS_RUNTIME_DIR', '/var/run/pmss', '\pmssRuntimeDir'],
            ['PMSS_STATE_DIR', '/var/lib/pmss', '\pmssStateDir'],
        ] as $case) {
            $this->pmssAssertEnvResolvedPath($case[0], null, $case[1], $case[2]);
        }
    }

    public function testRuntimeHelpersHonorEnvOverrides(): void
    {
        foreach ([
            ['PMSS_LOG_DIR', '/tmp/pmss-log-override/', '/tmp/pmss-log-override', '\pmssLogDir'],
            ['PMSS_RUNTIME_DIR', '/tmp/pmss-runtime-override/', '/tmp/pmss-runtime-override', '\pmssRuntimeDir'],
            ['PMSS_STATE_DIR', '/tmp/pmss-state-override/', '/tmp/pmss-state-override', '\pmssStateDir'],
        ] as $case) {
            $this->pmssAssertEnvResolvedPath($case[0], $case[1], $case[2], $case[3]);
        }
    }

    public function testUpdateLoggingResolvesPmssLogFileFromLogDirOverride(): void
    {
        $logDir = '/tmp/pmss-log-bootstrap-'.bin2hex(random_bytes(4));
        $libraryPath = dirname(__DIR__, 3).'/lib/update.php';
        $output = trim($this->pmssRunInlinePhpRequire($libraryPath, 'echo PMSS_LOG_FILE;', ['PMSS_LOG_DIR' => $logDir]));

        $this->assertEquals($logDir.'/update.log', $output);
    }
}
