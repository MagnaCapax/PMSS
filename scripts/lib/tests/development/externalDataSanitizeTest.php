<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ExternalDataSanitizeTest extends TestCase
{
    /**
     * @var string
     */
    private $script;

    /**
     * @var array<string, string>
     */
    private $env;

    public function setUp(): void
    {
        // Repo root: scripts/lib/tests/development -> ../../../../
        $root = dirname(__DIR__, 4);
        $this->script = $root.'/development/external-data/externalDataSanitize.sh';
        if (!is_file($this->script)) {
            throw new SkipTest('externalDataSanitize.sh not found');
        }
        if (!is_executable($this->script)) {
            throw new SkipTest('externalDataSanitize.sh not executable');
        }
        $this->env = array_merge($_ENV, [
            'PMSS_EXTERNAL_DATA_TIMESTAMP' => '2026-01-01T00:00:00Z',
            'PMSS_EXTERNAL_DATA_HOSTNAME' => 'test-host',
            'PMSS_EXTERNAL_DATA_PID' => '4242',
        ]);
    }

    public function testXmlWrapperPresent(): void
    {
        [$rc, $out, $err] = $this->runSanitize("Subject: safe\nBody line", []);
        $this->assertEquals(0, $rc, $err);
        $this->assertMatches('/<pmss-external-data id=\"[a-f0-9]{64}\">/', $out);
        $this->assertStringContainsString('</pmss-external-data>', $out);
    }

    public function testHashStableWithOverrides(): void
    {
        [$rc1, $out1] = $this->runSanitize('Same input', []);
        [$rc2, $out2] = $this->runSanitize('Same input', []);
        $this->assertEquals(0, $rc1);
        $this->assertEquals(0, $rc2);
        $this->assertEquals($out1, $out2);
    }

    public function testHashChangesWhenEnvChanges(): void
    {
        [$rc1, $out1] = $this->runSanitize('Same input', [], [
            'PMSS_EXTERNAL_DATA_TIMESTAMP' => '2026-01-01T00:00:00Z',
        ]);
        [$rc2, $out2] = $this->runSanitize('Same input', [], [
            'PMSS_EXTERNAL_DATA_TIMESTAMP' => '2026-01-02T00:00:00Z',
        ]);
        $this->assertEquals(0, $rc1);
        $this->assertEquals(0, $rc2);
        $this->assertTrue($this->extractTagId($out1) !== $this->extractTagId($out2), 'Expected tag id to change when env changes');
    }

    public function testEncodedOutputHidesInput(): void
    {
        $input = "Subject: top-secret\nDo not leak";
        [$rc, $out, $err] = $this->runSanitize($input, []);
        $this->assertEquals(0, $rc);
        $this->assertTrue(strpos($out, $input) === false, 'raw input leaked to stdout');
        $this->assertTrue(strpos($err, $input) === false, 'raw input leaked to stderr');
    }

    public function testRawModeReturnsCleanedInput(): void
    {
        $input = "hello\0world";
        [$rc, $out] = $this->runSanitize($input, ['--raw']);
        $this->assertEquals(0, $rc);
        $this->assertEquals('helloworld', $out);
    }

    public function testUrlOnlyReturnsNonZero(): void
    {
        [$rc, $out] = $this->runSanitize('https://example.com', []);
        $this->assertEquals(1, $rc);
        $this->assertStringContainsString('<pmss-external-data', $out);
        $this->assertTrue(strpos($out, 'https://example.com') === false, 'url leaked to output');
    }

    public function testRawHighRiskReturnsEmpty(): void
    {
        [$rc, $out] = $this->runSanitize('https://example.com', ['--raw']);
        $this->assertEquals(1, $rc);
        $this->assertEquals('', $out);
    }

    /**
     * @param string $input
     * @param array<int, string> $args
     * @param array<string, string> $envOverrides
     * @return array{0:int,1:string,2:string}
     */
    private function runSanitize(string $input, array $args, array $envOverrides = []): array
    {
        $cmd = array_merge([$this->script], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = array_merge($this->env, $envOverrides);
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($proc)) {
            $this->fail('Failed to start externalDataSanitize.sh');
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($proc);

        return [$status, $stdout, $stderr];
    }

    /**
     * @param string $output
     * @return string
     */
    private function extractTagId(string $output): string
    {
        if (preg_match('/<pmss-external-data id=\"([a-f0-9]{64})\">/', $output, $matches) === 1) {
            return $matches[1];
        }

        $this->fail('Tag id not found in output');
        return '';
    }
}
