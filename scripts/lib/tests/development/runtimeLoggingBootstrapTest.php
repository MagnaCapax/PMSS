<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class RuntimeLoggingBootstrapTest extends TestCase
{
    public function testRuntimeLibraryUsesStructuredLogMessageImplementation(): void
    {
        $runtimePath = dirname(__DIR__, 2).'/runtime.php';
        $source = trim($this->pmssRunInlinePhp(
            'require '.var_export($runtimePath, true).'; '
            .'$function = new ReflectionFunction("logMessage"); '
            .'echo str_replace("\\\\", "/", $function->getFileName());',
            ['PMSS_TEST_MODE' => '1']
        ));

        $this->assertStringContainsString('/scripts/lib/update/logging.php', $source);
    }

    public function testRuntimeLogMessageKeepsStdoutAndStructuredJson(): void
    {
        $runtimePath = dirname(__DIR__, 2).'/runtime.php';
        $jsonPath = $this->pmssMakeTempPath('pmss-runtime-json-', '.jsonl');
        $logDir = $this->pmssMakeTempDir('pmss-runtime-log-dir-');

        $result = $this->pmssRunInlinePhpJson(
            '$jsonPath = '.var_export($jsonPath, true).'; '
            .'$logPath = '.var_export($logDir.'/update.log', true).'; '
            .'require '.var_export($runtimePath, true).'; '
            .'$GLOBALS["PMSS_JSON_LOG_PATH"] = null; '
            .'ob_start(); logMessage("runtime structured test"); $stdout = ob_get_clean(); '
            .'$lines = @file($jsonPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); '
            .'$payload = is_array($lines) && !empty($lines) ? json_decode((string) end($lines), true) : null; '
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
        $runtimePath = dirname(__DIR__, 2).'/runtime.php';
        $jsonPath = $this->pmssMakeTempPath('pmss-runtime-logmsg-', '.jsonl');
        $scriptName = __DIR__.'/runtime-logmsg-bootstrap.php';
        $fallbackPath = '/tmp/'.basename($scriptName, '.php').'.log';
        @unlink($fallbackPath);

        $result = $this->pmssRunInlinePhpJson(
            '$_SERVER["SCRIPT_NAME"] = '.var_export($scriptName, true).'; '
            .'$jsonPath = '.var_export($jsonPath, true).'; '
            .'require '.var_export($runtimePath, true).'; '
            .'$GLOBALS["PMSS_JSON_LOG_PATH"] = null; '
            .'ob_start(); logmsg("runtime wrapper line"); $stdout = ob_get_clean(); '
            .'$lines = @file($jsonPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); '
            .'echo json_encode(['
            .'"stdout" => $stdout, '
            .'"json_lines" => is_array($lines) ? count($lines) : 0, '
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
}
