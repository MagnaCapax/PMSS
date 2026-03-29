<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class QbittorrentTemplateDefaultsTest extends TestCase
{
    public function testTemplatePinsSharedConnectionLimits(): void
    {
        $template = $this->loadTemplate();

        $this->assertStringContainsString("Session\\MaxConnections=300\n", $template);
        $this->assertStringContainsString("Session\\MaxConnectionsPerTorrent=75\n", $template);
        $this->assertStringContainsString("Session\\MaxUploads=20\n", $template);
        $this->assertStringContainsString("Session\\MaxUploadsPerTorrent=4\n", $template);
        $this->assertStringContainsString("Bittorrent\\MaxConnecs=300\n", $template);
        $this->assertStringContainsString("Bittorrent\\MaxConnecsPerTorrent=75\n", $template);
    }

    public function testTemplatePinsSharedDiskCacheDefaults(): void
    {
        $template = $this->loadTemplate();

        $this->assertStringContainsString("Session\\DiskCacheSize=128\n", $template);
        $this->assertStringContainsString("Session\\DiskCacheTTL=120\n", $template);
        $this->assertStringContainsString("Downloads\\DiskWriteCacheSize=128\n", $template);
        $this->assertStringContainsString("Downloads\\DiskWriteCacheTTL=120\n", $template);
        $this->assertStringContainsString("Session\\Preallocation=false\n", $template);
        $this->assertStringContainsString("Downloads\\PreAllocation=false\n", $template);
    }

    public function testTemplateUsesPosixDiskIoAndLimitedAsyncThreads(): void
    {
        $template = $this->loadTemplate();

        $this->assertStringContainsString("Session\\AsyncIOThreadsCount=4\n", $template);
        $this->assertStringContainsString("Session\\DiskIOType=Posix\n", $template);
    }

    public function testTemplatePinsSeedingAlgorithms(): void
    {
        $template = $this->loadTemplate();

        $this->assertStringContainsString("Session\\uTPMixedMode=TCP\n", $template);
        $this->assertStringContainsString("Session\\ChokingAlgorithm=FixedSlots\n", $template);
        $this->assertStringContainsString("Session\\SeedChokingAlgorithm=FastestUpload\n", $template);
    }

    public function testTemplatePinsSendBufferDefaults(): void
    {
        $template = $this->loadTemplate();

        $this->assertStringContainsString("Session\\SendBufferWatermark=1024\n", $template);
        $this->assertStringContainsString("Session\\SendBufferLowWatermark=256\n", $template);
        $this->assertStringContainsString("Session\\SendBufferWatermarkFactor=150\n", $template);
    }

    private function loadTemplate(): string
    {
        return $this->pmssReadRepoFile('etc/seedbox/config/template.qbittorrent.conf');
    }
}
