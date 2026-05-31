<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/network/config.php';

class NetworkConfigTest extends TestCase
{
    public function testLoadConfigUsesOverride(): void
    {
        $tmp = $this->pmssWritePhpArrayFixture(['interface' => 'eth9'], 'pmss-network-config-');

        $this->pmssWithEnv(['PMSS_NETWORK_CONFIG' => $tmp], function (): void {
            $config = \networkLoadConfig();
            $this->assertEquals('eth9', $config['interface']);
        });
    }

    public function testLoadLocalnetsCreatesDefault(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-localnets-');

        $this->pmssWithEnv([
            'PMSS_LOCALNET_FILE' => $tmp,
            'PMSS_HOSTNAME' => 'seedbox1.pulsedmedia.com',
        ], function () use ($tmp): void {
            $nets = \networkLoadLocalnets();
            $this->assertEquals(['185.148.0.0/22'], $nets);
            $this->assertTrue(file_exists($tmp));
        });
    }

    public function testLoadLocalnetsKeepsNonPmHostsEmptyWhenConfigMissing(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-localnets-');

        $this->pmssWithEnv([
            'PMSS_LOCALNET_FILE' => $tmp,
            'PMSS_HOSTNAME' => 'seedbox1.example.com',
        ], function () use ($tmp): void {
            $this->assertEquals([], \networkLoadLocalnets());
            $this->assertFalse(file_exists($tmp));
        });
    }

    public function testLoadLocalnetsReturnsConfiguredEntries(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-localnets-');
        file_put_contents($tmp, "10.0.0.0/8\n192.168.0.0/16\n");

        $this->pmssWithEnv(['PMSS_LOCALNET_FILE' => $tmp], function (): void {
            $this->assertEquals(['10.0.0.0/8', '192.168.0.0/16'], \networkLoadLocalnets());
        });
    }

    public function testLoadLocalnetsSkipsMalformedEntries(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-localnets-');
        file_put_contents(
            $tmp,
            "10.0.0.0/8\n192.168.0.0/16; touch /tmp/bad\n999.1.1.1/33\n127.0.0.1\n"
        );

        $this->pmssWithEnv(['PMSS_LOCALNET_FILE' => $tmp], function (): void {
            $this->assertEquals(['10.0.0.0/8', '127.0.0.1'], \networkLoadLocalnets());
        });
    }

    public function testLocalnetEntryValidationRejectsUnsafePolicyTokens(): void
    {
        $this->assertTrue(\networkLocalnetEntryIsValid('185.148.0.0/22'));
        $this->assertTrue(\networkLocalnetEntryIsValid('127.0.0.1'));
        $this->assertFalse(\networkLocalnetEntryIsValid('185.148.0.0/33'));
        $this->assertFalse(\networkLocalnetEntryIsValid('not-a-network'));
        $this->assertFalse(\networkLocalnetEntryIsValid('10.0.0.0/8; touch /tmp/bad'));
    }

    public function testLoadLocalnetsRepopulatesBlankPmConfigFileWithDefault(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-localnets-');
        file_put_contents($tmp, "\n");

        $this->pmssWithEnv([
            'PMSS_LOCALNET_FILE' => $tmp,
            'PMSS_HOSTNAME' => 'seedbox1.pulsedmedia.com',
        ], function () use ($tmp): void {
            $this->assertEquals(['185.148.0.0/22'], \networkLoadLocalnets());
            $this->assertEquals("185.148.0.0/22\n", file_get_contents($tmp));
        });
    }

    public function testLoadLocalnetsKeepsBlankNonPmConfigFileEmpty(): void
    {
        $tmp = $this->pmssMakeTempPath('pmss-localnets-');
        file_put_contents($tmp, "\n");

        $this->pmssWithEnv([
            'PMSS_LOCALNET_FILE' => $tmp,
            'PMSS_HOSTNAME' => 'seedbox1.example.com',
        ], function () use ($tmp): void {
            $this->assertEquals([], \networkLoadLocalnets());
            $this->assertEquals("\n", file_get_contents($tmp));
        });
    }

    public function testLoadLocalnetsRejectsSymlinkedConfigFile(): void
    {
        $root = $this->pmssMakeTempDir('pmss-localnets-symlink-');
        [, $link] = $this->pmssCreateSymlinkedFileOrSkip($root.'/target-localnet', $root.'/localnet', "10.0.0.0/8\n");

        $this->pmssWithEnv(['PMSS_LOCALNET_FILE' => $link], function (): void {
            $this->assertEquals([], \networkLoadLocalnets());
        });
    }

    public function testLoadLocalnetsDoesNotPersistDefaultThroughSymlinkedParent(): void
    {
        $root = $this->pmssMakeTempDir('pmss-localnets-parent-');
        [$targetDir, $linkDir] = $this->pmssCreateSymlinkedDirectoryOrSkip($root.'/target', $root.'/linked');
        $path = $linkDir.'/localnet';

        $this->pmssWithEnv([
            'PMSS_LOCALNET_FILE' => $path,
            'PMSS_HOSTNAME' => 'seedbox1.pulsedmedia.com',
        ], function () use ($path, $targetDir): void {
            $this->assertEquals(['185.148.0.0/22'], \networkLoadLocalnets());
            $this->assertFalse(file_exists($path));
            $this->assertFalse(file_exists($targetDir.'/localnet'));
        });
    }
}
