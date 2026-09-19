<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class LighttpdConfigFreshnessTest extends TestCase
{
    private $home;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('home', 'pmss-lighttpd-freshness-', 0700);
        $this->pmssEnsureDir($this->home.'/.lighttpd/custom.d');
    }

    private function writeConfigFile(string $relativePath, string $content, int $mtime): string
    {
        $path = $this->home.'/'.$relativePath;
        $this->pmssEnsureDir(dirname($path));
        file_put_contents($path, $content);
        touch($path, $mtime);
        // The watched set now includes the custom.d directory itself (Refs #874), and
        // creating a fragment moves that directory's mtime to now. Pin it low so each
        // case exercises the mtimes it declares rather than the fixture's side effects;
        // the removal case below sets it explicitly.
        $customDir = $this->home.'/.lighttpd/custom.d';
        if (is_dir($customDir)) {
            touch($customDir, 1);
        }

        return $path;
    }

    public function testNewestConfigMtimeIncludesCustomFragments(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        $this->writeConfigFile('.lighttpd/custom', 'custom', 110);
        $this->writeConfigFile('.lighttpd/custom.d/pmss-rclone.conf', 'rclone', 120);
        $this->writeConfigFile('.lighttpd/custom.d/pmss-deluge.conf', 'deluge', 130);

        $this->assertSame(130, \pmssLighttpdNewestConfigMtime($this->home, $mainConfig));
    }

    public function testConfigNewerThanProcessTriggersReload(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        $this->writeConfigFile('.lighttpd/custom.d/pmss-deluge.conf', 'deluge', 200);

        $this->assertTrue(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, 150));
    }

    public function testProcessNewerThanConfigDoesNotReload(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        $this->writeConfigFile('.lighttpd/custom.d/pmss-deluge.conf', 'deluge', 200);

        $this->assertFalse(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, 250));
    }

    public function testMissingProcessStartFailsSoft(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        $this->writeConfigFile('.lighttpd/custom.d/pmss-deluge.conf', 'deluge', 200);

        $this->assertFalse(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, null));
    }

    public function testFragmentRemovalIsVisibleToTheFreshnessCheck(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        $fragment = $this->writeConfigFile('.lighttpd/custom.d/pmss-media-stack.conf', 'media', 120);

        // What --uninstall does: delete the fragment. The surviving files' mtimes do not
        // move, so before #874 the reload never fired and the removed proxy routes kept
        // answering 503. Unlinking moves the parent directory's mtime, which is the
        // signal the watched set now carries.
        unlink($fragment);
        touch($this->home.'/.lighttpd/custom.d', 300);

        $this->assertTrue(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, 200));
    }

    public function testNonConfFilesDoNotAffectReloadDecision(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        $this->writeConfigFile('.lighttpd/custom.d/readme.txt', 'ignored', 300);

        $this->assertFalse(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, 150));
    }
}
