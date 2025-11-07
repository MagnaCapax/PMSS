<?php
namespace PMSS\Tests;

// Ensure the JSON log path reads from env on first call and logging writes a line
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';

class A00_JsonLogPathTest extends TestCase
{
    public function testJsonPathReadsEnvOnFirstCall(): void
    {
        $this->resetJsonLogPath();
        $tmp = $this->tmpFile();
        putenv('PMSS_JSON_LOG='.$tmp);
        $this->assertEquals($tmp, \pmssJsonLogPath());
    }

    public function testLogJsonWritesLine(): void
    {
        $this->resetJsonLogPath();
        $path = $this->tmpFile();
        file_put_contents($path, '');
        putenv('PMSS_JSON_LOG='.$path);
        \pmssResetJsonLogPath();
        $this->assertEquals($path, \pmssJsonLogPath());
        \pmssLogJson(['event' => 'edge', 'val' => 1]);
        $raw = trim(file_get_contents($path));
        $data = json_decode($raw, true);
        $this->assertEquals('edge', $data['event'] ?? '');
        $this->assertMatches('/^\d{4}-\d{2}-\d{2}T/', $data['ts'] ?? '');
    }

    private function tmpFile(): string
    {
        $f = tempnam(sys_get_temp_dir(), 'pmss');
        if ($f === false) {
            $f = sys_get_temp_dir().'/pmss-'.bin2hex(random_bytes(4));
            touch($f);
        }
        return $f;
    }

    private function resetJsonLogPath(): void
    {
        \pmssResetJsonLogPath();
        putenv('PMSS_JSON_LOG');
    }
}
