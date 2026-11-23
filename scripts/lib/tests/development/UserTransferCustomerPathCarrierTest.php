<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/userTransfer.php';

/**
 * The transfer main pass leaves some home trees to the final pass. A customer-owned path that the main pass
 * skips and the final pass does not carry never arrives on the target, and nothing reports it.
 *
 * Expected set: the customer-owned state of ADR 0041 that lives below a tree the main pass does not copy.
 */
class UserTransferCustomerPathCarrierTest extends TestCase
{
    private const CUSTOMER_PATHS = ['session', 'www/public', 'www/rutorrent/share', '.lighttpd/custom', '.lighttpd/custom.d'];

    private function config(): array
    {
        // Namespace mode keeps the rsync command unquoted, so its excludes and sources read back directly.
        return ['localUser' => 'fixtur01', 'remoteUser' => 'fixtur01', 'hostname' => 'transfer.example',
            'namespaceRsync' => true, 'receiver' => ['uid' => 1200, 'gid' => 1200, 'home' => '/home/fixtur01',
                'uidMap' => "0 1200 1\n100000 200000 65536\n", 'gidMap' => "0 1200 1\n100000 200000 65536\n", 'sourceUid' => 1300, 'sourceGid' => 1300]];
    }

    /** Undo the one shell-quoting level the account launcher adds around the rsync command. */
    private function unquoted(string $script): string
    {
        return str_replace("'\\''", "'", $script);
    }

    /** Home-relative exclude patterns of the main pass, without their anchoring slash. */
    private function mainPassExcludes(): array
    {
        preg_match_all("/--exclude='([^']*)'/", $this->unquoted(\pmssUserTransferBuildRsyncMain($this->config())), $matches);
        return array_map(static function (string $pattern): string { return ltrim($pattern, '/'); }, $matches[1]);
    }

    /** Home-relative sources of the final pass (the part after the rsync /./ relative marker). */
    private function finalPassSources(): array
    {
        preg_match_all("~'fixtur01@transfer\\.example:/home/fixtur01/\\./([^']+)'~", $this->unquoted(\pmssUserTransferBuildRsyncFinal($this->config())), $matches);
        return $matches[1];
    }

    private function mainPassSkips(string $path, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            if ($path === $exclude || strpos($path, $exclude.'/') === 0) {
                return true;
            }
        }
        return false;
    }

    public function testEveryCustomerPathTheMainPassSkipsIsCarriedByTheFinalPass(): void
    {
        $excludes = $this->mainPassExcludes();
        $sources = $this->finalPassSources();
        $this->assertTrue(count($excludes) > 5 && count($sources) > 3, 'Both passes must expose their path lists');

        $skipped = 0;
        foreach (self::CUSTOMER_PATHS as $path) {
            if ($this->mainPassSkips($path, $excludes)) {
                ++$skipped;
                $this->assertTrue(in_array($path, $sources, true), 'Skipped by the main pass but not carried by the final pass: '.$path);
            }
        }
        $this->assertTrue($skipped >= 4, 'The main pass must skip the customer trees this test guards, got '.$skipped);
    }

    public function testTheFinalPassCarriesTheWholeCustomerSet(): void
    {
        // Independent of the exclude list: the customer set must not shrink on the final pass.
        $sources = $this->finalPassSources();
        foreach (self::CUSTOMER_PATHS as $path) {
            $this->assertTrue(in_array($path, $sources, true), 'Final pass must carry '.$path);
        }
    }

    public function testMainPassStillSkipsTheTreesTheFinalPassOwns(): void
    {
        // The skip is the reason the final pass exists; if it vanishes the pair is no longer coupled.
        $excludes = $this->mainPassExcludes();
        foreach (['www', 'session', '.lighttpd'] as $tree) {
            $this->assertTrue(in_array($tree, $excludes, true), 'Main pass must still skip '.$tree);
        }
    }
}
