<?php
/** Cover Scryer credential preservation while a sibling proxy blocks bootstrap. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/proxyFragments.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/toggleAppRuntime.php';

class ToggleAppScryerRecoveryTest extends TestCase
{
    public function testRepeatedProxyConflictKeepsSeededLoginUntilRecovery(): void
    {
        $home = $this->pmssMakeTempDir('toggle-scryer-conflict-');
        $this->pmssWithEnv(array('PMSS_TEST_MODE' => '1'), function () use ($home): void {
            $login = $home.'/.pmss/apps/scryer.login';
            $secured = $home.'/.pmss/apps/scryer.secured';
            $store = $home.'/.local/share/scryer/scryer.db';
            $conflict = $home.'/.lighttpd/custom.d/custom.conf';
            $managed = $home.'/.lighttpd/custom.d/pmss-scryer.conf';
            $saved = "fixtur01\n".str_repeat('a', 48)."\n";
            // Model a started instance awaiting repair, without invoking Scryer or reading its database.
            $this->pmssWriteFile($store, 'seeded', 0700);
            $this->pmssWriteFile($login, $saved, 0700);
            $this->assertTrue(chmod($login, 0600));
            $this->pmssWriteFile($conflict, '$HTTP["url"] =~ "^/user-fixtur01/scryer/" {}', 0700);
            $proxy = \pmssLighttpdManagedProxyFragment('scryer', 'fixtur01', 23001);

            // Each refused pass retries regeneration: neither may rotate the seed or authorize a start.
            for ($pass = 0; $pass < 2; ++$pass) {
                $this->assertFalse(\pmssToggleAppBootstrap('scryer', 'fixtur01', $home, 23001, $proxy));
                clearstatcache();
                $this->assertFalse(file_exists($secured));
                $this->assertFalse(file_exists($managed));
                $this->assertSame($saved, file_get_contents($login));
                $this->assertSame(str_repeat('a', 48)."\n", file_get_contents($home.'/.pmss/apps/scryer.pw'));
                $this->assertSame(0600, fileperms($login) & 0777);
            }

            // Removing only the conflicting fixture lets the same credential finish publication.
            $this->assertTrue(unlink($conflict));
            $this->assertTrue(\pmssToggleAppBootstrap('scryer', 'fixtur01', $home, 23001, $proxy));
            $this->assertSame($saved, file_get_contents($login));
            $this->assertSame("secured\n", file_get_contents($secured));
            $this->assertSame($proxy, file_get_contents($managed));
        });
    }
}
