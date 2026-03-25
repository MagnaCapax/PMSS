<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateLoggingBootstrapTest extends TestCase
{
    public function testUpdateLibraryUsesStructuredLoggingImplementation(): void
    {
        $source = $this->runLibraryScript(
            '$function = new ReflectionFunction("logMessage"); echo str_replace("\\\\", "/", $function->getFileName());'
        );

        $this->assertStringContainsString('/scripts/lib/update/logging.php', $source);
    }

    public function testUpdateLibraryKeepsContextArraySignature(): void
    {
        $signature = $this->runLibraryScript(
            '$function = new ReflectionFunction("logMessage"); echo (string) $function->getParameters()[1]->getType();'
        );

        $this->assertEquals('array', $signature);
    }

    public function testUpdateLibraryProvidesLegacyLogmsgAlias(): void
    {
        $source = $this->runLibraryScript(
            '$function = new ReflectionFunction("logmsg"); echo str_replace("\\\\", "/", $function->getFileName());'
        );

        $this->assertStringContainsString('/scripts/lib/log.php', $source);
    }

    public function testStructuredLogMessageWritesJsonContext(): void
    {
        $payload = $this->emitJsonLog('logMessage("structured log test", ["source" => "unit"]);');

        $this->assertEquals('log', $payload['event'] ?? null);
        $this->assertEquals('structured log test', $payload['message'] ?? null);
        $this->assertEquals(['source' => 'unit'], $payload['context'] ?? null);
    }

    public function testLegacyLogmsgAliasWritesJsonEvent(): void
    {
        $payload = $this->emitJsonLog('logmsg("legacy alias test");');

        $this->assertEquals('log', $payload['event'] ?? null);
        $this->assertEquals('legacy alias test', $payload['message'] ?? null);
        $this->assertEquals([], $payload['context'] ?? null);
    }

    public function testLoggerBootstrapAlsoForwardsLegacyLogmsgToJsonLogger(): void
    {
        $payload = $this->emitJsonLog(
            'logmsg("bootstrap forward test");',
            'require '.var_export(dirname(__DIR__, 2).'/logger.php', true).'; '
        );

        $this->assertEquals('log', $payload['event'] ?? null);
        $this->assertEquals('bootstrap forward test', $payload['message'] ?? null);
        $this->assertEquals([], $payload['context'] ?? null);
    }

    public function testCorrelationIdPrefersEnvironmentValueWhenCacheEmpty(): void
    {
        $value = $this->runLibraryScript(
            'putenv("PMSS_CORRELATION_ID=fixed-correlation-id"); '
            .'$GLOBALS["PMSS_CORRELATION_ID_CACHE"] = null; '
            .'echo pmssCorrelationId(false);'
        );

        $this->assertEquals('fixed-correlation-id', $value);
    }

    public function testJsonLogPathRemainsCachedAfterFirstRead(): void
    {
        $path = $this->runLibraryScript(
            'putenv("PMSS_JSON_LOG=/tmp/pmss-first.jsonl"); '
            .'$GLOBALS["PMSS_JSON_LOG_PATH"] = null; '
            .'$first = pmssJsonLogPath(); '
            .'putenv("PMSS_JSON_LOG=/tmp/pmss-second.jsonl"); '
            .'echo $first."|".pmssJsonLogPath();'
        );

        $this->assertEquals('/tmp/pmss-first.jsonl|/tmp/pmss-first.jsonl', $path);
    }

    private function emitJsonLog(string $statement, string $bootstrap = ''): array
    {
        $path = $this->pmssMakeTempPath('pmss-update-log-', '.jsonl');
        $script = $bootstrap
            .'putenv('.var_export('PMSS_JSON_LOG='.$path, true).'); '
            .'$GLOBALS["PMSS_JSON_LOG_PATH"] = null; '
            .'ob_start(); '.$statement.' ob_end_clean();';

        $this->runLibraryScript($script);

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertTrue(is_array($lines) && !empty($lines), 'Expected JSON log output at '.$path);
        $decoded = json_decode((string) end($lines), true);
        $this->assertTrue(is_array($decoded), 'Expected the JSON log line to decode into an array');

        return $decoded;
    }

    private function runLibraryScript(string $script): string
    {
        $libraryPath = dirname(__DIR__, 2).'/update.php';
        $command = 'PMSS_TEST_MODE=1 php -r '.escapeshellarg('require '.var_export($libraryPath, true).'; '.$script).' 2>/dev/null';
        return trim((string) @shell_exec($command));
    }
}
