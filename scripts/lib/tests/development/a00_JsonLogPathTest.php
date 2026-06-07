<?php
namespace PMSS\Tests;

// Ensure the JSON log path reads from env on first call and logging writes a line
require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/update.php';

class A00_JsonLogPathTest extends TestCase
{
    /**
     * @var string|false
     */
    private $previousCorrelationId = false;

    public function setUp(): void
    {
        $this->previousCorrelationId = getenv('PMSS_CORRELATION_ID');
        $this->pmssTrackEnvKeys(['PMSS_JSON_LOG'], true);
        $this->resetCorrelationId();
        $this->resetJsonLogPath();
    }

    public function tearDown(): void
    {
        $this->pmssRestoreEnv('PMSS_CORRELATION_ID', $this->previousCorrelationId, true);
        $this->resetCorrelationIdCache();
        $this->resetJsonLogPath();
    }

    public function testJsonPathReadsEnvOnFirstCall(): void
    {
        $this->resetJsonLogPath();
        $tmp = $this->pmssMakeTempFile('pmss');
        putenv('PMSS_JSON_LOG='.$tmp);
        $this->assertEquals($tmp, \pmssJsonLogPath());
    }

    public function testCorrelationIdIsGeneratedAndExported(): void
    {
        $this->resetCorrelationId();
        $id = \pmssCorrelationId();
        $this->assertMatches('/^\d{8}-\d{6}-[a-z0-9-]+-[a-f0-9]{6}$/', $id);
        $this->assertEquals($id, (string) (getenv('PMSS_CORRELATION_ID') ?: ''));
    }

    public function testLogJsonWritesLine(): void
    {
        $this->resetJsonLogPath();
        $this->resetCorrelationId();
        $path = $this->pmssMakeTempFile('pmss');
        file_put_contents($path, '');
        putenv('PMSS_JSON_LOG='.$path);
        $expectedCorrelationId = \pmssCorrelationId();
        $this->assertEquals($path, \pmssJsonLogPath());
        \pmssLogJson(['event' => 'edge', 'val' => 1]);
        $data = $this->pmssDecodeJsonArray(trim(file_get_contents($path)));
        $this->assertEquals('edge', $data['event'] ?? '');
        $this->assertMatches('/^\d{4}-\d{2}-\d{2}T/', $data['ts'] ?? '');
        $this->assertEquals($expectedCorrelationId, $data['pmss_correlation_id'] ?? '');
    }

    public function testLogJsonRespectsProvidedCorrelationId(): void
    {
        $this->resetJsonLogPath();
        $path = $this->pmssMakeTempFile('pmss');
        file_put_contents($path, '');
        putenv('PMSS_JSON_LOG='.$path);
        putenv('PMSS_CORRELATION_ID=test-correlation-id');
        $this->resetCorrelationIdCache();
        \pmssLogJson(['event' => 'edge', 'val' => 2]);
        $data = $this->pmssDecodeJsonArray(trim(file_get_contents($path)));
        $this->assertEquals('test-correlation-id', $data['pmss_correlation_id'] ?? '');
    }

    private function resetJsonLogPath(): void
    {
        $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
        putenv('PMSS_JSON_LOG');
    }

    private function resetCorrelationId(): void
    {
        putenv('PMSS_CORRELATION_ID');
        $this->resetCorrelationIdCache();
    }

    private function resetCorrelationIdCache(): void
    {
        $GLOBALS['PMSS_CORRELATION_ID_CACHE'] = null;
    }
}
