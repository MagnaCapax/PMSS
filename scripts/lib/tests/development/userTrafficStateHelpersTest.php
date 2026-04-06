<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/traffic.php';
require_once __DIR__.'/../../user/trafficLimit.php';

class UserTrafficStateHelpersTest extends TestCase
{
    /** @var string */
    private $tempDir;

    public function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-traffic-state-');
    }

    public function testReadUserTrafficMonthReturnsZeroForMissingFile(): void
    {
        $this->assertEquals(0, \pmssReadUserTrafficMonth($this->tempDir.'/missing'));
    }

    public function testSharedTrafficPayloadReaderReturnsSerializedArrays(): void
    {
        $path = $this->tempDir.'/traffic-data-array';
        $expected = ['raw' => ['month' => 1536.4], 'extra' => ['week' => 12]];
        file_put_contents($path, serialize($expected));

        $this->assertEquals($expected, \pmssTrafficReadSerializedArrayFile($path));
    }

    public function testSharedTrafficPayloadReaderRejectsSymlinkedFile(): void
    {
        $target = $this->tempDir.'/traffic-data-target';
        file_put_contents($target, serialize(['raw' => ['month' => 2048]]));
        $link = $this->tempDir.'/traffic-data-link-for-array-reader';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $this->assertEquals(null, \pmssTrafficReadSerializedArrayFile($link));
    }

    public function testReadUserTrafficMonthRejectsSymlinkedFile(): void
    {
        $target = $this->tempDir.'/traffic-data-target';
        file_put_contents($target, serialize(['raw' => ['month' => 2048]]));
        $link = $this->tempDir.'/traffic-data-link';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $this->assertEquals(0, \pmssReadUserTrafficMonth($link));
    }

    public function testReadUserTrafficMonthRejectsInvalidPayload(): void
    {
        $path = $this->tempDir.'/traffic-data-invalid';
        file_put_contents($path, 'not serialized');

        $this->assertEquals(0, \pmssReadUserTrafficMonth($path));
    }

    public function testReadUserTrafficMonthRejectsMissingMonthField(): void
    {
        $path = $this->tempDir.'/traffic-data-missing-month';
        file_put_contents($path, serialize(['raw' => ['week' => 128]]));

        $this->assertEquals(0, \pmssReadUserTrafficMonth($path));
    }

    public function testReadUserTrafficMonthRoundsNumericMonthTotals(): void
    {
        $path = $this->tempDir.'/traffic-data-valid';
        file_put_contents($path, serialize(['raw' => ['month' => 1536.4]]));

        $this->assertEquals(1536, \pmssReadUserTrafficMonth($path));
    }

    public function testTrafficDataPathsUseEnvOverrideAndModeSuffix(): void
    {
        $this->pmssWithEnv(['PMSS_HOME_DIR' => $this->tempDir.'/home'], function (): void {
            $paths = \pmssTrafficDataPaths('alice');

            $this->assertEquals($this->tempDir.'/home/alice/.trafficDataIngressLocal', $paths['ingressLocal']);
        });
    }

    public function testTrafficDataPathsExposeCanonicalKeys(): void
    {
        $paths = \pmssTrafficDataPaths('alice', $this->tempDir.'/home');

        $this->assertEquals($this->tempDir.'/home/alice/.trafficData', $paths['normal']);
        $this->assertEquals($this->tempDir.'/home/alice/.trafficDataLocal', $paths['local']);
        $this->assertEquals($this->tempDir.'/home/alice/.trafficDataIngress', $paths['ingress']);
        $this->assertEquals($this->tempDir.'/home/alice/.trafficDataIngressLocal', $paths['ingressLocal']);
    }

    public function testReadUserTrafficStatesRejectsInvalidUsernameBeforePathResolution(): void
    {
        @mkdir($this->tempDir.'/home/alice/evil', 0755, true);
        file_put_contents(
            $this->tempDir.'/home/alice/evil/.trafficData',
            serialize(['raw' => ['month' => 4096]])
        );

        $this->pmssWithEnv(['PMSS_HOME_DIR' => $this->tempDir.'/home'], function (): void {
            $this->assertEquals([], \pmssReadUserTrafficStates('alice/evil'));
        });
    }

    public function testTrafficLimitAndStatsPathsHonorExplicitBases(): void
    {
        $this->assertEquals(
            $this->tempDir.'/home/alice/.trafficLimit',
            \pmssTrafficLimitPath('alice', $this->tempDir.'/home')
        );
        $this->assertEquals(
            $this->tempDir.'/runtime/trafficStats/alice-localnet',
            \pmssTrafficStatsPath('alice-localnet', null, $this->tempDir.'/runtime')
        );
    }

    public function testTrafficStorageSaveRejectsSymlinkedRuntimeStatsFile(): void
    {
        $messages = [];
        $storage = new \TrafficStorage([
            'home_dir' => $this->tempDir.'/home',
            'runtime_dir' => $this->tempDir.'/runtime',
            'logger' => $this->pmssMakeArrayLogger($messages),
        ]);
        $storage->ensureRuntime();

        $target = $this->tempDir.'/runtime-target';
        file_put_contents($target, 'keep-me');
        $statsPath = \pmssTrafficStatsPath('alice', null, $this->tempDir.'/runtime');
        $this->pmssCreateSymlinkOrSkip($target, $statsPath);

        $storage->save('alice', ['raw' => ['month' => 2048]]);

        $this->assertEquals('keep-me', file_get_contents($target));
        $this->assertTrue(is_link($statsPath));
        $this->pmssAssertMessagesContain($messages, '[WARN] Failed to write traffic state for alice at '.$statsPath);
    }

    public function testTrafficStorageEnsureRuntimeRejectsSymlinkedRuntimeDir(): void
    {
        $messages = [];
        $targetRuntime = $this->tempDir.'/runtime-target';
        @mkdir($targetRuntime, 0755, true);
        $runtimeLink = $this->tempDir.'/runtime-link';
        $this->pmssCreateSymlinkOrSkip($targetRuntime, $runtimeLink);

        $storage = new \TrafficStorage([
            'home_dir' => $this->tempDir.'/home',
            'runtime_dir' => $runtimeLink,
            'logger' => $this->pmssMakeArrayLogger($messages),
        ]);

        $storage->ensureRuntime();

        $this->assertTrue(is_link($runtimeLink));
        $this->assertTrue(!is_dir($targetRuntime.'/trafficStats'));
        $this->pmssAssertMessagesContain($messages, '[WARN] Unable to prepare traffic runtime directory '.$runtimeLink);
    }

    public function testTrafficStorageSaveRejectsSymlinkedHomeTrafficFile(): void
    {
        $homeDir = $this->tempDir.'/home';
        $runtimeDir = $this->tempDir.'/runtime';
        $messages = [];
        @mkdir($homeDir.'/alice', 0755, true);

        $storage = new \TrafficStorage([
            'home_dir' => $homeDir,
            'runtime_dir' => $runtimeDir,
            'logger' => $this->pmssMakeArrayLogger($messages),
        ]);
        $storage->ensureRuntime();

        $target = $this->tempDir.'/home-target';
        file_put_contents($target, 'keep-home');
        $homeTrafficPath = \pmssTrafficDataPaths('alice', $homeDir)['normal'];
        $this->pmssCreateSymlinkOrSkip($target, $homeTrafficPath);

        $storage->save('alice', ['raw' => ['month' => 4096]]);

        $this->assertEquals('keep-home', file_get_contents($target));
        $this->assertTrue(is_link($homeTrafficPath));
        $this->pmssAssertMessagesContain($messages, '[WARN] Failed to write traffic state for alice at '.$homeTrafficPath);
    }

    public function testTrafficStorageSaveRejectsInvalidUsername(): void
    {
        $storage = new \TrafficStorage([
            'home_dir' => $this->tempDir.'/home',
            'runtime_dir' => $this->tempDir.'/runtime',
        ]);
        $storage->ensureRuntime();

        $storage->save('../evil', ['raw' => ['month' => 1024]]);

        $this->assertTrue(!file_exists($this->tempDir.'/runtime/trafficStats/../evil'));
        $this->assertTrue(!file_exists($this->tempDir.'/home/../evil/.trafficData'));
    }

    public function testTrafficStorageSaveRejectsInvalidLocalnetKey(): void
    {
        $storage = new \TrafficStorage([
            'home_dir' => $this->tempDir.'/home',
            'runtime_dir' => $this->tempDir.'/runtime',
        ]);
        $storage->ensureRuntime();

        $storage->save('alice-localnet-extra', ['raw' => ['month' => 1024]]);

        $this->assertTrue(!file_exists($this->tempDir.'/runtime/trafficStats/alice-localnet-extra'));
        $this->assertTrue(!file_exists($this->tempDir.'/home/alice/.trafficDataIngressLocal'));
    }

    public function testTrafficLimitReadGiBFileReturnsZeroForMissingFile(): void
    {
        $this->assertEquals(0, \pmssTrafficLimitReadGiBFile($this->tempDir.'/missing-limit'));
    }

    public function testTrafficLimitReadGiBFileRejectsSymlinkedFile(): void
    {
        $target = $this->tempDir.'/limit-target';
        file_put_contents($target, "500\n");
        $link = $this->tempDir.'/limit-link';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $this->assertEquals(0, \pmssTrafficLimitReadGiBFile($link));
    }

    public function testTrafficLimitReadGiBFileAcceptsPlainIntegerGiB(): void
    {
        $path = $this->tempDir.'/limit-plain';
        file_put_contents($path, "500\n");

        $this->assertEquals(500, \pmssTrafficLimitReadGiBFile($path));
    }

    public function testTrafficLimitReadGiBFileAcceptsGibSuffix(): void
    {
        $path = $this->tempDir.'/limit-suffixed';
        file_put_contents($path, "750GiB\n");

        $this->assertEquals(750, \pmssTrafficLimitReadGiBFile($path));
    }

    public function testTrafficLimitReadGiBFileRejectsInvalidContent(): void
    {
        $path = $this->tempDir.'/limit-invalid';
        file_put_contents($path, "five hundred\n");

        $this->assertEquals(0, \pmssTrafficLimitReadGiBFile($path));
    }

    public function testTrafficLimitStateReadCombinesBaseLimitAndBonus(): void
    {
        $limitPath = $this->tempDir.'/traffic-limit';
        $bonusPath = $this->tempDir.'/bonus-traffic';
        file_put_contents($limitPath, "5\n");
        file_put_contents($bonusPath, "2GiB\n");

        $state = \pmssTrafficLimitStateRead($limitPath, $bonusPath);

        $this->assertEquals(['limitGiB' => 5, 'bonusGiB' => 2, 'effectiveLimitGiB' => 7], $state);
    }

    public function testTrafficLimitStateReadKeepsBonusButDisablesEffectiveLimitWithoutBaseLimit(): void
    {
        $bonusPath = $this->tempDir.'/bonus-only';
        file_put_contents($bonusPath, "9\n");

        $state = \pmssTrafficLimitStateRead($this->tempDir.'/missing-limit', $bonusPath);

        $this->assertEquals(['limitGiB' => 0, 'bonusGiB' => 9, 'effectiveLimitGiB' => 0], $state);
    }
}
