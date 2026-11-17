<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class PikkumiesContractTest extends TestCase
{
    /** Check the shared fixture against only the v1 section, not older contracts. */
    public function testFixtureValuesAppearInContractSection(): void
    {
        $fixture = $this->pmssReadJsonArrayFile(__DIR__.'/../fixtures/pikkumies-contract-v1.1.json');
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        $docs = $this->pmssReadRepoFile('docs/contracts.md');
        $start = '## Pikkumies Eternals and web UIs (contract v1.1)';
        $end = '## Notes for Agentic Coding';
        $this->assertSame(1, substr_count($docs, $start));
        $this->assertSame(1, substr_count($docs, $end));
        $sectionStart = strpos($docs, $start);
        $sectionEnd = strpos($docs, $end, $sectionStart);
        $this->assertSame(true, $sectionEnd > $sectionStart);
        $section = substr($docs, $sectionStart, $sectionEnd - $sectionStart);

        $values = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($fixture));
        $this->assertStringContainsAllStrings(iterator_to_array($values, false), $section, 'Missing fixture value: ');
        $this->assertStringContainsString(implode(' ', $fixture['eternals']['argv']), $section);
        foreach ($fixture['ask']['exitCodes'] as $code => $meaning) {
            $this->assertStringContainsString('`'.$code.'` = '.$meaning, $section);
        }
    }

    /** Host-owned activation markers must never enter the tracked snapshot. */
    public function testNoEnabledMarkerIsTracked(): void
    {
        $root = dirname(__DIR__, 4);
        $result = $this->pmssExecShellCommand('git -C '.escapeshellarg($root).' ls-files -- '.escapeshellarg(':(glob)etc/seedbox/config/*.enabled'));
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame([], $result['lines']);
    }

    /** The panel route is the default for each account UI. */
    public function testUiRoutesArePinned(): void
    {
        $fixture = $this->pmssReadJsonArrayFile(__DIR__.'/../fixtures/pikkumies-contract-v1.1.json');
        $this->assertSame(['/user-<u>/pikkumies/', '/user-<u>/pikkumies-hermes/', '/user-<u>/hermes/'], $fixture['routes']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/skel/www/pikkumiesOpen.php', [
            "'/pikkumies-hermes/' : '/pikkumies/'", 'pmssPikkumiesOpenSocketLive($socket)',
        ], ['pikkumiesEternals.enabled', 'CertificateState', 'TlsValid', 'mcx.fi']);
    }
}
