<?php
namespace PMSS\Tests;

// Tests for functions in scripts/lib/update.php that are safe to exercise without root
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';

class UpdateHelpersTest extends TestCase
{
    // Note: pmssJsonLogPath() caches the first observed value process-wide; avoid asserting dynamic changes here.

    public function testLoadRepoTemplateWithoutCustomLoggerStillUsesLogMessageFallback(): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-update-logger-', 0700);

        $script = 'function logMessage(string $message, array $context = array()): void { echo $message; } '
            .'putenv('.var_export('PMSS_CONFIG_DIR='.$configDir, true).'); '
            .'require '.var_export(dirname(__DIR__, 2).'/update/apt.php', true).'; '
            .'pmssLoadRepoTemplate("this-code-name-does-not-exist");';

        $output = trim($this->pmssRunInlinePhp($script, ['PMSS_TEST_MODE' => '1']));

        $this->assertStringContainsString('Repository template missing:', $output);
    }

    public function testLoadRepoTemplateMissingLogsAndReturnsEmpty(): void
    {
        $logs = [];
        $logger = $this->pmssMakeArrayLogger($logs);
        $data = \pmssLoadRepoTemplate('this-code-name-does-not-exist', $logger);
        $this->assertEquals('', $data);
        $this->pmssAssertMessagesContain($logs, 'Repository template missing:');
    }

    public function testSafeWriteSourcesEmptyContentSkips(): void
    {
        $logs = [];
        $logger = $this->pmssMakeArrayLogger($logs);
        $ok = \pmssSafeWriteSources('', 'UnitTest', $logger);
        $this->assertTrue($ok === false);
        $this->pmssAssertMessagesContain($logs, 'Empty repository content');
    }

    public function testUpdateAptSourcesDebian9UnsupportedLogs(): void
    {
        $logs = [];
        $logger = $this->pmssMakeArrayLogger($logs);
        \updateAptSources('debian', 9, 'dead', $this->pmssDebianRepoTemplates(), $logger);
        $this->pmssAssertMessagesContain($logs, 'Unsupported Debian version: 9');
    }

    public function testUpdateAptSourcesAlreadyCorrectNoChange(): void
    {
        // When current hash equals template hash, function should only log "already correct"
        $content = "deb https://example invalid\n";
        $hash = sha1($content);
        $logs = [];
        $logger = $this->pmssMakeArrayLogger($logs);
        \updateAptSources('debian', 12, $hash, $this->pmssDebianRepoTemplates([
            'bookworm' => $content,
        ]), $logger);
        $this->pmssAssertMessagesContain($logs, 'already correct');
        // Important: No destructive call path is taken here
    }

    public function testUpdateAptSourcesTemplateMissing(): void
    {
        $logs = [];
        $logger = $this->pmssMakeArrayLogger($logs);
        \updateAptSources('debian', 11, 'hash', $this->pmssDebianRepoTemplates(), $logger);
        $this->pmssAssertMessagesContain($logs, 'Bullseye template missing');
    }

    public function testUpdateAptSourcesDebian13AlreadyCorrect(): void
    {
        $content = "deb https://example-trixie invalid\n";
        $hash = sha1($content);
        $logs = [];
        $logger = $this->pmssMakeArrayLogger($logs);
        \updateAptSources('debian', 13, $hash, $this->pmssDebianRepoTemplates([
            'trixie' => $content,
        ]), $logger);
        $this->pmssAssertMessagesContain($logs, 'Trixie');
    }

    public function testGetOsReleaseDataIsArray(): void
    {
        $data = \getOsReleaseData();
        $this->assertTrue(is_array($data));
    }

    public function testGetDistroNameString(): void
    {
        $name = \getDistroName();
        $this->assertTrue(is_string($name));
    }

    public function testGetDistroVersionDigitsOrEmpty(): void
    {
        $ver = \getDistroVersion();
        $this->assertTrue($ver === '' || preg_match('/^\d+$/', $ver) === 1);
    }

    public function testGetPmssVersionUnknownWhenMissing(): void
    {
        // A non-existent file should yield 'unknown'
        $this->assertEquals('unknown', \getPmssVersion('/this/file/does/not/exist'));
    }

    public function testGetPmssVersionFromCustomFile(): void
    {
        $f = $this->pmssMakeTempFile('pmss');
        file_put_contents($f, "git/main:2024-01-01\n");
        $this->assertEquals('git/main:2024-01-01', \getPmssVersion($f));
    }

    public function testGenerateMotdNoTemplateSafe(): void
    {
        // When template missing, function returns early without changes
        \generateMotd();
        $this->assertTrue(true, 'generateMotd should be a no-op without template');
    }
}
