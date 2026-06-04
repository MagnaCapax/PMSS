<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class RuntimeLoggingBootstrapTest extends TestCase
{
    public function testRuntimeLibraryUsesStructuredLogMessageImplementation(): void
    {
        $source = trim($this->pmssRunRepoInlinePhpRequire(
            'scripts/lib/runtime.php',
            '$function = new ReflectionFunction("logMessage"); '
            .'echo str_replace("\\\\", "/", $function->getFileName());',
            ['PMSS_TEST_MODE' => '1']
        ));

        $this->assertStringContainsString('/scripts/lib/update/logging.php', $source);
    }

    public function testRuntimeLogMessageKeepsStdoutAndStructuredJson(): void
    {
        $jsonPath = $this->pmssMakeTempPath('pmss-runtime-json-', '.jsonl');
        $logDir = $this->pmssMakeTempDir('pmss-runtime-log-dir-');

        $result = $this->pmssRunRepoInlinePhpRequireJson(
            'scripts/lib/runtime.php',
            '$jsonPath = '.var_export($jsonPath, true).'; '
            .'$logPath = '.var_export($logDir.'/update.log', true).'; '
            .'$GLOBALS["PMSS_JSON_LOG_PATH"] = null; '
            .'ob_start(); logMessage("runtime structured test"); $stdout = ob_get_clean(); '
            .'$payload = pmssJsonLineFileLast($jsonPath); '
            .'echo json_encode(['
            .'"stdout" => $stdout, '
            .'"payload" => $payload, '
            .'"file" => (string) @file_get_contents($logPath)'
            .']);',
            [
                'PMSS_JSON_LOG' => $jsonPath,
                'PMSS_LOG_DIR' => $logDir,
                'PMSS_TEST_MODE' => '1',
            ]
        );

        $this->assertEquals("runtime structured test\n", $result['stdout']);
        $this->assertTrue(is_array($result['payload']));
        $this->assertEquals('log', $result['payload']['event'] ?? null);
        $this->assertEquals('runtime structured test', $result['payload']['message'] ?? null);
        $this->assertEquals([], $result['payload']['context'] ?? null);
        $this->assertStringContainsString('runtime structured test', (string) $result['file']);
    }

    public function testRuntimeBootstrapKeepsLegacyLogmsgFallbackFlow(): void
    {
        $jsonPath = $this->pmssMakeTempPath('pmss-runtime-logmsg-', '.jsonl');
        $scriptName = __DIR__.'/runtime-logmsg-bootstrap.php';
        $fallbackPath = '/tmp/'.basename($scriptName, '.php').'.log';
        @unlink($fallbackPath);

        $result = $this->pmssRunRepoInlinePhpRequireJson(
            'scripts/lib/runtime.php',
            '$_SERVER["SCRIPT_NAME"] = '.var_export($scriptName, true).'; '
            .'$jsonPath = '.var_export($jsonPath, true).'; '
            .'$GLOBALS["PMSS_JSON_LOG_PATH"] = null; '
            .'ob_start(); logmsg("runtime wrapper line"); $stdout = ob_get_clean(); '
            .'echo json_encode(['
            .'"stdout" => $stdout, '
            .'"json_lines" => count(pmssJsonLineFileRead($jsonPath)), '
            .'"fallback_exists" => file_exists('.var_export($fallbackPath, true).'), '
            .'"fallback" => (string) @file_get_contents('.var_export($fallbackPath, true).')'
            .']);',
            [
                'PMSS_JSON_LOG' => $jsonPath,
                'PMSS_TEST_MODE' => '1',
            ]
        );

        @unlink($fallbackPath);

        $this->assertEquals("runtime wrapper line\n", $result['stdout']);
        $this->assertEquals(0, $result['json_lines']);
        $this->assertTrue($result['fallback_exists']);
        $this->assertStringContainsString('runtime wrapper line', (string) $result['fallback']);
    }

    public function testLegacyLogmsgDefaultsCanRedirectToConfiguredFileAndStderr(): void
    {
        $logPath = $this->pmssMakeTempPath('pmss-logmsg-defaults-', '.log');
        $stderrPath = $this->pmssMakeTempPath('pmss-logmsg-defaults-', '.stderr');
        $logDir = dirname($logPath);

        $result = $this->pmssRunRepoInlinePhpRequireJson(
            'scripts/lib/log.php',
            '$GLOBALS["PMSS_LOGMSG_DEFAULTS"] = ['
            .'"script" => "/scripts/util/update-step2.php", '
            .'"dir" => '.var_export($logDir, true).', '
            .'"fallback_dir" => '.var_export($logDir, true).', '
            .'"base_name" => '.var_export(basename($logPath, '.log'), true).', '
            .'"write_to_stderr" => true'
            .']; '
            .'ob_start(); logmsg("configured stderr line"); $stdout = ob_get_clean(); '
            .'echo json_encode(['
            .'"stdout" => $stdout, '
            .'"file" => (string) @file_get_contents('.var_export($logPath, true).')'
            .']);',
            ['PMSS_TEST_MODE' => '1'],
            '2>'.escapeshellarg($stderrPath)
        );

        $stderr = (string) @file_get_contents($stderrPath);

        $this->assertSame('', $result['stdout']);
        $this->assertStringContainsString('configured stderr line', $stderr);
        $this->assertStringContainsString('configured stderr line', (string) $result['file']);
    }
}
