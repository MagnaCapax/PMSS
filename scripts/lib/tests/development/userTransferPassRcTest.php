<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userTransfer.php';

/** The main and final pass exit codes decide whether a transfer completes (ADR 0316). */
class UserTransferPassRcTest extends TestCase
{
    public function testOnlyCleanAndVanishedSourceFilesCountAsTransferred(): void
    {
        foreach ([0 => true, 24 => true, 1 => false, 12 => false, 23 => false, 255 => false] as $rc => $ok) {
            $this->assertSame($ok, \pmssUserTransferRsyncRcTransferred($rc), 'rc='.$rc);
        }
    }

    public function testVanishedSourceFilesStillRecordCompletion(): void
    {
        foreach ([[0, 0], [24, 0], [0, 24], [24, 24]] as [$main, $final]) {
            $this->assertSame('complete', $this->finish($main, $final)['rc'], "main=$main final=$final");
        }
    }

    public function testRealErrorsStillFailWithoutCompletion(): void
    {
        foreach ([[23, 0], [0, 23], [12, 0], [0, 255], [1, 24]] as [$main, $final]) {
            $result = $this->finish($main, $final);
            $this->assertSame(1, $result['rc'], "main=$main final=$final");
            $this->assertStringContainsString(sprintf('main rc=%d, final rc=%d', $main, $final), implode("\n", $result['logs']));
        }
    }

    /** Run the real block from the password notice up to the completion record, side effects captured. */
    private function finish(int $lastMainRc, int $lastFinalRc): array
    {
        static $loaded = false;
        if (!$loaded) {
            $source = $this->pmssReadRepoFile('scripts/lib/userTransfer.php');
            $start = strpos($source, '            if ($cfg[\'printPassword\'])');
            $end = strpos($source, '            pmssUserTransferRecordCompletion(', (int) $start);
            $this->assertTrue($start !== false && $end !== false);
            $fixture = <<<'PHP'
namespace PMSS\Tests\TransferPassRcFixture;
function logMessage($message) { $GLOBALS['transferPassRc'][] = $message; }
function finish($cfg, $password, $lastMainRc, $lastFinalRc, $recovery) {
PHP;
            eval($fixture.substr($source, $start, $end - $start)."\nreturn 'complete'; }");
            $loaded = true;
        }
        $GLOBALS['transferPassRc'] = [];
        $rc = \PMSS\Tests\TransferPassRcFixture\finish(['printPassword' => false], '', $lastMainRc, $lastFinalRc, true);
        $result = ['rc' => $rc, 'logs' => $GLOBALS['transferPassRc']];
        unset($GLOBALS['transferPassRc']);
        return $result;
    }
}
