<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class QbittorrentTemplateDefaultsTest extends TestCase
{
    public function testTemplatePinsSharedConnectionLimits(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.qbittorrent.conf');

        $this->assertStringContainsAllStrings(["Session\\MaxConnections=300\n", "Session\\MaxConnectionsPerTorrent=75\n", "Session\\MaxUploads=20\n", "Session\\MaxUploadsPerTorrent=4\n", "Bittorrent\\MaxConnecs=300\n", "Bittorrent\\MaxConnecsPerTorrent=75\n"], $template);
    }

    public function testTemplatePinsSharedDiskCacheDefaults(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.qbittorrent.conf');

        $this->assertStringContainsAllStrings(["Session\\DiskCacheSize=128\n", "Session\\DiskCacheTTL=120\n", "Downloads\\DiskWriteCacheSize=128\n", "Downloads\\DiskWriteCacheTTL=120\n", "Session\\Preallocation=true\n", "Downloads\\PreAllocation=true\n"], $template);
    }

    public function testTemplateUsesPosixDiskIoAndLimitedAsyncThreads(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.qbittorrent.conf');

        $this->assertStringContainsString("Session\\AsyncIOThreadsCount=4\n", $template);
        $this->assertStringContainsString("Session\\DiskIOType=Posix\n", $template);
    }

    public function testTemplatePinsSeedingAlgorithms(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.qbittorrent.conf');

        $this->assertStringContainsAllStrings(["Session\\uTPMixedMode=TCP\n", "Session\\ChokingAlgorithm=FixedSlots\n", "Session\\SeedChokingAlgorithm=FastestUpload\n"], $template);
    }

    public function testTemplateKeepsWebUiProtectionsEnabled(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.qbittorrent.conf');

        $this->assertStringContainsAllStrings(["WebUI\\CSRFProtection=true\n", "WebUI\\ClickjackingProtection=true\n", "WebUI\\HostHeaderValidation=true\n"], $template);
    }

    public function testTemplatePinsSendBufferDefaults(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.qbittorrent.conf');

        $this->assertStringContainsAllStrings(["Session\\SendBufferWatermark=1024\n", "Session\\SendBufferLowWatermark=256\n", "Session\\SendBufferWatermarkFactor=150\n"], $template);
    }

}
