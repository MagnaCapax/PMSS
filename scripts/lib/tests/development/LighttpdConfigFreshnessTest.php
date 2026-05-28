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

    public function testNonConfFilesDoNotAffectReloadDecision(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        $this->writeConfigFile('.lighttpd/custom.d/readme.txt', 'ignored', 300);

        $this->assertFalse(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, 150));
    }
}
