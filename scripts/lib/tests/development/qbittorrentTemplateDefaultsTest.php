<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class QbittorrentTemplateDefaultsTest extends TestCase
{
    public function testTemplatePinsSharedConnectionLimits(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.qbittorrent.conf', ["Session\\MaxConnections=300\n", "Session\\MaxConnectionsPerTorrent=75\n", "Session\\MaxUploads=20\n", "Session\\MaxUploadsPerTorrent=4\n", "Bittorrent\\MaxConnecs=300\n", "Bittorrent\\MaxConnecsPerTorrent=75\n"]);
    }

    public function testTemplatePinsSharedDiskCacheDefaults(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.qbittorrent.conf', ["Session\\DiskCacheSize=128\n", "Session\\DiskCacheTTL=120\n", "Downloads\\DiskWriteCacheSize=128\n", "Downloads\\DiskWriteCacheTTL=120\n", "Session\\Preallocation=true\n", "Downloads\\PreAllocation=true\n"]);
    }

    public function testTemplateUsesPosixDiskIoAndLimitedAsyncThreads(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.qbittorrent.conf', ["Session\\AsyncIOThreadsCount=4\n", "Session\\DiskIOType=Posix\n"]);
    }

    public function testTemplatePinsSeedingAlgorithms(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.qbittorrent.conf', ["Session\\uTPMixedMode=TCP\n", "Session\\ChokingAlgorithm=FixedSlots\n", "Session\\SeedChokingAlgorithm=FastestUpload\n"]);
    }

    public function testTemplatePinsWebUiProtectionDefaults(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.qbittorrent.conf', ["WebUI\\CSRFProtection=true\n", "WebUI\\ClickjackingProtection=true\n", "WebUI\\HostHeaderValidation=false\n"]);
    }

    public function testTemplatePinsSendBufferDefaults(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.qbittorrent.conf', ["Session\\SendBufferWatermark=1024\n", "Session\\SendBufferLowWatermark=256\n", "Session\\SendBufferWatermarkFactor=150\n"]);
    }

}
