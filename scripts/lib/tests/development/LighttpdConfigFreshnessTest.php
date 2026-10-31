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
        $path = $this->pmssWriteRelativeFile($this->home, $relativePath, $content);
        touch($path, $mtime);

        return $path;
    }

    public function testNewestConfigMtimeIncludesCustomFragments(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        $this->writeConfigFile('.lighttpd/custom', 'custom', 110);
        $this->writeConfigFile('.lighttpd/custom.d/pmss-rclone.conf', 'rclone', 120);
        $this->writeConfigFile('.lighttpd/custom.d/pmss-deluge.conf', 'deluge', 130);
        // Keep directory-entry changes separate from the declared file mtimes.
        touch($this->home.'/.lighttpd/custom.d', 1);

        $this->assertSame(130, \pmssLighttpdNewestConfigMtime($this->home, $mainConfig));
    }

    public function testConfigNewerThanProcessTriggersReload(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        $this->writeConfigFile('.lighttpd/custom.d/pmss-deluge.conf', 'deluge', 200);
        touch($this->home.'/.lighttpd/custom.d', 1);

        $this->assertTrue(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, 150));
    }

    public function testProcessNewerThanConfigDoesNotReload(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        $this->writeConfigFile('.lighttpd/custom.d/pmss-deluge.conf', 'deluge', 200);
        touch($this->home.'/.lighttpd/custom.d', 1);

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
        // Creating the fragment moved the directory mtime to now; pin it so only the
        // unlink below can make the check true.
        touch($this->home.'/.lighttpd/custom.d', 1);
        $processStartTime = time() - 60;
        clearstatcache();
        $this->assertFalse(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, $processStartTime));

        // What --uninstall does: delete the fragment. The surviving files' mtimes do not
        // move, so before #874 the reload never fired and the removed proxy routes kept
        // answering 503. Unlinking moves the parent directory's mtime, which is the
        // signal the watched set now carries.
        unlink($fragment);
        clearstatcache();

        $this->assertTrue(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, $processStartTime));
    }

    public function testAddingAnyCustomDirectoryEntryTriggersReload(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        touch($this->home.'/.lighttpd/custom.d', 1);
        $processStartTime = time() - 60;
        clearstatcache();
        $this->assertFalse(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, $processStartTime));

        // Even a non-.conf entry with an old file mtime changes the directory.
        $this->writeConfigFile('.lighttpd/custom.d/readme.txt', 'added', 100);
        clearstatcache();

        $this->assertTrue(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, $processStartTime));
    }

    public function testContentEditToExistingNonConfFileDoesNotTriggerReload(): void
    {
        $mainConfig = $this->writeConfigFile('.lighttpd.conf', 'main', 100);
        $this->writeConfigFile('.lighttpd/custom.d/readme.txt', 'original', 110);
        touch($this->home.'/.lighttpd/custom.d', 1);

        $this->writeConfigFile('.lighttpd/custom.d/readme.txt', 'edited', 300);
        clearstatcache();

        $this->assertFalse(\pmssLighttpdConfigNewerThanProcess($this->home, $mainConfig, 150));
    }
}
