<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/environment.php';

/** ADR 0278: every dpkg baseline purges fail2ban and ufw, and the sanitiser keeps the purge lines. */
class DpkgBaselineRemovesFail2banUfwTest extends TestCase
{
    private const REMOVED = ['fail2ban', 'ufw'];

    public function testEveryBaselinePurgesFail2banAndUfw(): void
    {
        $files = glob(dirname(__DIR__, 2).'/update/dpkg/selections*.txt') ?: [];
        $this->assertTrue(count($files) >= 5, 'Expected the dpkg baseline files');
        foreach ($files as $file) {
            $states = [];
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (in_array($cols[0] ?? '', self::REMOVED, true)) {
                    $states[$cols[0]][] = strtolower($cols[1] ?? 'install');
                }
            }
            foreach (self::REMOVED as $package) {
                $this->assertEquals(['purge'], $states[$package] ?? [], basename($file).": $package must appear once, as purge");
            }
        }
    }

    public function testSanitiserKeepsPurgeLinesWhenPackageUnavailable(): void
    {
        $plan = \pmssDpkgSelectionsSanitise(["fail2ban\tpurge", "ufw\t\t\tpurge"], 12, static function (): bool { return false; }, static function (): bool { return false; });
        $this->assertEquals(["fail2ban\tpurge", "ufw\tpurge"], $plan['sanitised']);
    }
}
