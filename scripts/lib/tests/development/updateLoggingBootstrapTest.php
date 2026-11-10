<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';

class UpdateLoggingBootstrapTest extends TestCase
{
    public function testUpdateLibraryUsesStructuredLoggingImplementation(): void
    {
        $source = trim($this->pmssRunRepoInlinePhpRequire('scripts/lib/update.php',
            '$function = new ReflectionFunction("logMessage"); echo str_replace("\\\\", "/", $function->getFileName());',
            $this->pmssTestModeEnv()));

        $this->assertStringContainsString('/scripts/lib/update/logging.php', $source);
    }

    public function testUpdateLibraryKeepsContextArraySignature(): void
    {
        $signature = trim($this->pmssRunRepoInlinePhpRequire('scripts/lib/update.php',
            '$function = new ReflectionFunction("logMessage"); echo (string) $function->getParameters()[1]->getType();',
            $this->pmssTestModeEnv()));

        $this->assertEquals('array', $signature);
    }

    public function testUpdateLibraryProvidesLegacyLogmsgAlias(): void
    {
        $source = trim($this->pmssRunRepoInlinePhpRequire('scripts/lib/update.php',
            '$function = new ReflectionFunction("logmsg"); echo str_replace("\\\\", "/", $function->getFileName());',
            $this->pmssTestModeEnv()));

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
        $value = trim($this->pmssRunRepoInlinePhpRequire('scripts/lib/update.php',
            'putenv("PMSS_CORRELATION_ID=fixed-correlation-id"); '
            .'$GLOBALS["PMSS_CORRELATION_ID_CACHE"] = null; '
            .'echo pmssCorrelationId(false);', $this->pmssTestModeEnv()));

        $this->assertEquals('fixed-correlation-id', $value);
    }

    public function testJsonLogPathRemainsCachedAfterFirstRead(): void
    {
        $path = trim($this->pmssRunRepoInlinePhpRequire('scripts/lib/update.php',
            'putenv("PMSS_JSON_LOG=/tmp/pmss-first.jsonl"); '
            .'$GLOBALS["PMSS_JSON_LOG_PATH"] = null; '
            .'$first = pmssJsonLogPath(); '
            .'putenv("PMSS_JSON_LOG=/tmp/pmss-second.jsonl"); '
            .'echo $first."|".pmssJsonLogPath();', $this->pmssTestModeEnv()));

        $this->assertEquals('/tmp/pmss-first.jsonl|/tmp/pmss-first.jsonl', $path);
    }

    private function emitJsonLog(string $statement, string $bootstrap = ''): array
    {
        $path = $this->pmssMakeTempPath('pmss-update-log-', '.jsonl');
        $script = $bootstrap
            .'putenv('.var_export('PMSS_JSON_LOG='.$path, true).'); '
            .'$GLOBALS["PMSS_JSON_LOG_PATH"] = null; '
            .'ob_start(); '.$statement.' ob_end_clean();';

        trim($this->pmssRunRepoInlinePhpRequire('scripts/lib/update.php', $script, $this->pmssTestModeEnv()));

        $decoded = pmssJsonLineFileLast($path);
        $this->assertTrue(is_array($decoded), 'Expected the JSON log line to decode into an array');

        return $decoded;
    }
}
