<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/traffic.php';
require_once __DIR__.'/../../user/trafficLimit.php';

class UserTrafficStateHelpersTest extends TestCase
{
    public function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-traffic-state-');
    }

    public function testSharedTrafficPayloadReaderReturnsSerializedArrays(): void
    {
        $path = $this->tempDir.'/traffic-data-array';
        $expected = ['raw' => ['month' => 1536.4], 'extra' => ['week' => 12]];
        $this->pmssWriteSerializedFixture($path, $expected);

        $this->assertEquals($expected, \pmssReadSerializedArrayFile($path));
    }

    public function testSharedTrafficReadersRejectSymlinkedFile(): void
    {
        $target = $this->tempDir.'/traffic-data-target';
        $this->pmssWriteSerializedFixture($target, ['raw' => ['month' => 2048]]);
        $link = $this->tempDir.'/traffic-data-link-for-array-reader';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $this->assertEquals(null, \pmssReadSerializedArrayFile($link));
        $this->assertEquals(0, \pmssReadUserTrafficMonth($link));
    }

    public function testReadUserTrafficMonthHandlesPayloadCases(): void
    {
        foreach ([
            ['missing', null, 0],
            ['invalid', 'not serialized', 0],
            ['missing-month', serialize(['raw' => ['week' => 128]]), 0],
            ['valid', serialize(['raw' => ['month' => 1536.4]]), 1536],
        ] as [$name, $payload, $expected]) {
            $path = $this->tempDir.'/traffic-data-'.$name;
            if ($payload !== null) {
                file_put_contents($path, $payload);
            }

            $this->assertEquals($expected, \pmssReadUserTrafficMonth($path), 'case '.$name);
        }
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
        $this->assertEquals([
            'normal' => $this->tempDir.'/home/alice/.trafficData',
            'local' => $this->tempDir.'/home/alice/.trafficDataLocal',
            'ingress' => $this->tempDir.'/home/alice/.trafficDataIngress',
            'ingressLocal' => $this->tempDir.'/home/alice/.trafficDataIngressLocal',
        ], \pmssTrafficDataPaths('alice', $this->tempDir.'/home'));
    }

    public function testTrafficDataPathKeyCharacterizationCoversModeAndBucketMatrix(): void
    {
        $this->assertEquals([
            'normal',
            'local',
            'ingress',
            'ingressLocal',
            'normal',
        ], [
            \pmssTrafficDataPathKey(false, 'egress'),
            \pmssTrafficDataPathKey(true, 'egress'),
            \pmssTrafficDataPathKey(false, 'ingress'),
            \pmssTrafficDataPathKey(true, 'ingress'),
            \pmssTrafficDataPathKey(false, 'bogus'),
        ]);
    }

    public function testManagedDirsEnsureContinuesAfterUnsafeDirectory(): void
    {
        $unsafeTarget = $this->pmssEnsureDir($this->tempDir.'/runtime-target');
        $unsafePath = $this->tempDir.'/runtime-link';
        $safePath = $this->tempDir.'/runtime-safe';
        $failures = [];
        $this->pmssCreateSymlinkOrSkip($unsafeTarget, $unsafePath);

        \pmssManagedDirsEnsure([
            $unsafePath => 0755,
            $safePath => 0600,
        ], function (string $dir) use (&$failures): void {
            $failures[] = $dir;
        });

        $this->assertEquals([$unsafePath], $failures);
        $this->assertTrue(is_dir($safePath));
    }

    public function testManagedSerializedTargetsWriteKeepsHealthyTargetsAfterFailure(): void
    {
        $goodPath = $this->tempDir.'/traffic-data-good';
        $badTarget = $this->tempDir.'/traffic-data-target';
        $badPath = $this->tempDir.'/traffic-data-link-for-writer';
        $payload = ['raw' => ['month' => 42.0], 'daily' => []];
        $failures = [];
        file_put_contents($badTarget, 'seed');
        $this->pmssCreateSymlinkOrSkip($badTarget, $badPath);

        $result = \pmssManagedSerializedTargetsWrite(serialize($payload), [
            [$goodPath, 'root', 0600, false],
            [$badPath, 'root', 0600, false],
        ], function (string $path) use (&$failures): void {
            $failures[] = $path;
        });

        $this->assertFalse($result);
        $this->assertEquals([$badPath], $failures);
        $this->assertEquals($payload, \pmssReadSerializedArrayFile($goodPath));
        $this->assertEquals('seed', file_get_contents($badTarget));
    }

    public function testTrafficSeedInitialStateSeedsHomePayloadsAndReportsRuntimeWarnings(): void
    {
        $homeDir = $this->tempDir.'/home';
        $runtimeDir = $this->tempDir.'/runtime';
        $messages = [];
        $this->pmssEnsureDir($homeDir.'/alice');

        $this->assertFalse(\pmssTrafficSeedInitialState('alice', $homeDir, $runtimeDir, $this->pmssMakeArrayLogger($messages)));

        $expected = ['raw' => array_fill_keys(array_keys(\pmssStatsCompareTimesBuild(0)), 0.0), 'daily' => []];
        foreach ([
            $homeDir.'/alice/.trafficData',
            $homeDir.'/alice/.trafficDataLocal',
        ] as $path) {
            $this->assertEquals($expected, \pmssReadSerializedArrayFile($path));
        }
        $this->pmssAssertMessagesContain($messages, '[WARN] Failed to write traffic state for alice at '.$runtimeDir.'/trafficStats/alice');
        $this->pmssAssertMessagesContain($messages, '[WARN] Failed to write traffic state for alice-localnet at '.$runtimeDir.'/trafficStats/alice-localnet');
    }

    public function testReadUserTrafficStatesRejectsInvalidUsernameBeforePathResolution(): void
    {
        $this->pmssEnsureDir($this->tempDir.'/home/alice/evil');
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
        $this->assertEquals([
            'limit' => $this->tempDir.'/home/alice/.trafficLimit',
            'stats' => $this->tempDir.'/runtime/trafficStats/alice-localnet',
        ], [
            'limit' => \pmssTrafficLimitPath('alice', $this->tempDir.'/home'),
            'stats' => \pmssTrafficStatsPath('alice-localnet', null, $this->tempDir.'/runtime'),
        ]);
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
        $targetRuntime = $this->pmssEnsureDir($this->tempDir.'/runtime-target');
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
        $this->pmssEnsureDir($homeDir.'/alice');

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

    public function testTrafficStorageSavePreservesIngressLocalnetRouting(): void
    {
        $homeDir = $this->tempDir.'/home';
        $statsDir = $this->tempDir.'/trafficStats';
        $payload = ['raw' => ['month' => 4096], 'daily' => ['today' => 2048]];
        $this->pmssEnsureDir($homeDir.'/alice');
        $this->pmssEnsureDir($statsDir);

        $storage = new \TrafficStorage([
            'home_dir' => $homeDir,
            'stats_dir' => $statsDir,
            'traffic_mode' => 'ingress',
        ]);
        $storage->save('alice-localnet', $payload);

        $this->assertEquals($payload, \pmssReadSerializedArrayFile($homeDir.'/alice/.trafficDataIngressLocal'));
        $this->assertEquals($payload, \pmssReadSerializedArrayFile($statsDir.'/alice-localnet'));
    }

    public function testTrafficStorageSaveRejectsInvalidUserKeys(): void
    {
        $storage = new \TrafficStorage([
            'home_dir' => $this->tempDir.'/home',
            'runtime_dir' => $this->tempDir.'/runtime',
        ]);
        $storage->ensureRuntime();

        foreach ([
            '../evil' => [
                $this->tempDir.'/runtime/trafficStats/../evil',
                $this->tempDir.'/home/../evil/.trafficData',
            ],
            'alice-localnet-extra' => [
                $this->tempDir.'/runtime/trafficStats/alice-localnet-extra',
                $this->tempDir.'/home/alice/.trafficDataIngressLocal',
            ],
        ] as $userKey => $unexpectedPaths) {
            $storage->save($userKey, ['raw' => ['month' => 1024]]);
            foreach ($unexpectedPaths as $path) {
                $this->assertTrue(!file_exists($path), $userKey.' wrote '.$path);
            }
        }
    }

    public function testTrafficLimitReadGiBFileRejectsSymlinkedFile(): void
    {
        $target = $this->tempDir.'/limit-target';
        file_put_contents($target, "500\n");
        $link = $this->tempDir.'/limit-link';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $this->assertEquals(0, \pmssTrafficLimitReadGiBFile($link));
    }

    public function testTrafficLimitReadGiBFileHandlesContentCases(): void
    {
        foreach ([
            ['missing', null, 0],
            ['plain', "500\n", 500],
            ['suffixed', "750GiB\n", 750],
            ['invalid', "five hundred\n", 0],
        ] as [$name, $payload, $expected]) {
            $path = $this->tempDir.'/limit-'.$name;
            if ($payload !== null) {
                file_put_contents($path, $payload);
            }

            $this->assertEquals($expected, \pmssTrafficLimitReadGiBFile($path), 'case '.$name);
        }
    }

    public function testTrafficLimitStateReadHandlesBaseAndBonusCases(): void
    {
        foreach ([
            ['combined', "5\n", "2GiB\n", ['limitGiB' => 5, 'bonusGiB' => 2, 'effectiveLimitGiB' => 7]],
            ['bonus-only', null, "9\n", ['limitGiB' => 0, 'bonusGiB' => 9, 'effectiveLimitGiB' => 0]],
        ] as [$name, $limitPayload, $bonusPayload, $expected]) {
            $limitPath = $this->tempDir.'/traffic-limit-'.$name;
            $bonusPath = $this->tempDir.'/bonus-traffic-'.$name;
            if ($limitPayload !== null) {
                file_put_contents($limitPath, $limitPayload);
            }
            file_put_contents($bonusPath, $bonusPayload);

            $this->assertEquals($expected, \pmssTrafficLimitStateRead($limitPath, $bonusPath), 'case '.$name);
        }
    }
}
