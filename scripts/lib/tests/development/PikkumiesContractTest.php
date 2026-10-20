<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class PikkumiesContractTest extends TestCase
{
    /** Check the shared fixture against only the v1 section, not older contracts. */
    public function testFixtureValuesAppearInContractSection(): void
    {
        $root = dirname(__DIR__, 4);
        $json = file_get_contents(__DIR__.'/../fixtures/pikkumies-contract-v1.1.json');
        $this->assertSame(true, is_string($json));
        $fixture = json_decode($json, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame(true, is_array($fixture));

        $docs = file_get_contents($root.'/docs/contracts.md');
        $this->assertSame(true, is_string($docs));
        $start = '## Pikkumies Eternals and web UIs (contract v1.1)';
        $end = '## Notes for Agentic Coding';
        $this->assertSame(1, substr_count($docs, $start));
        $this->assertSame(1, substr_count($docs, $end));
        $sectionStart = strpos($docs, $start);
        $sectionEnd = strpos($docs, $end, $sectionStart);
        $this->assertSame(true, $sectionEnd > $sectionStart);
        $section = substr($docs, $sectionStart, $sectionEnd - $sectionStart);

        $values = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($fixture));
        foreach ($values as $value) {
            $this->assertStringContainsString((string) $value, $section, 'Missing fixture value: '.(string) $value);
        }
        $this->assertStringContainsString(implode(' ', $fixture['eternals']['argv']), $section);
        foreach ($fixture['ask']['exitCodes'] as $code => $meaning) {
            $this->assertStringContainsString('`'.$code.'` = '.$meaning, $section);
        }
    }

    /** Host-owned activation markers must never enter the tracked snapshot. */
    public function testNoEnabledMarkerIsTracked(): void
    {
        $root = dirname(__DIR__, 4);
        $output = [];
        $status = 0;
        exec('git -C '.escapeshellarg($root).' ls-files -- '.escapeshellarg(':(glob)etc/seedbox/config/*.enabled').' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertSame([], $output);
    }

    /** UI ports are fixed, distinct, privileged, and outside browser-denied ports. */
    public function testUiPortsArePinned(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../fixtures/pikkumies-contract-v1.1.json'), true);
        $ports = array_map('intval', $fixture['ports']);
        $badPorts = [0, 1, 7, 9, 21, 22, 23, 25, 53, 69, 80, 110, 143, 443, 445, 554, 600, 6665, 6666, 6667, 6668, 6669, 6697, 10080];
        $this->assertSame([844, 845, 846], $ports);
        $this->assertSame(3, count(array_unique($ports)));
        foreach ($ports as $port) {
            $this->assertSame(true, $port > 0 && $port < 1024);
            $this->assertSame(false, in_array($port, $badPorts, true));
        }
    }
}
