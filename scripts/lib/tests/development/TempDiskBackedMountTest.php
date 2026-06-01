<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/distro.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class TempDiskBackedMountTest extends TestCase
{
    private function configureTempDiskBacked(?int $distroVersion = null, ?string $pathPrefix = null): array
    {
        $messages = [];
        $callback = function () use (&$messages, $distroVersion): void {
            \pmssConfigureTempDiskBackedMount($this->pmssMakeArrayLogger($messages), $distroVersion);
        };

        if ($pathPrefix === null) {
            $callback();
        } else {
            $this->pmssWithPathPrefix($pathPrefix, $callback);
        }
        return $messages;
    }

    private function assertTmpMountMasked(int $distroVersion, string $prefix): void
    {
        $logPath = $this->pmssMakeTempPath($prefix, '.log');
        $binDir = $this->pmssMakeInvocationLogStub('systemctl', $logPath, $prefix.'bin-');

        $this->configureTempDiskBacked($distroVersion, $binDir);

        $this->assertEquals("mask tmp.mount\n", (string) file_get_contents($logPath));
    }

    public function testSkipsBeforeDebian13(): void
    {
        $messages = $this->configureTempDiskBacked(12);

        $this->pmssAssertMessagesContain($messages, 'Leaving /tmp mount policy unchanged');
    }

    public function testMasksTmpMountOnDebian13(): void
    {
        $logPath = $this->pmssMakeTempPath('pmss-tmp-mask-', '.log');
        $binDir = $this->pmssMakeInvocationLogStub('systemctl', $logPath, 'pmss-tmp-mask-bin-');

        $messages = $this->configureTempDiskBacked(13, $binDir);

        $this->assertEquals("mask tmp.mount\n", (string) file_get_contents($logPath));
        $this->pmssAssertMessagesContain($messages, 'Masked tmp.mount');
    }

    public function testMasksTmpMountOnLaterDebianVersions(): void
    {
        $this->assertTmpMountMasked(14, 'pmss-tmp-mask-later-');
    }

    public function testWarnsWhenSystemctlMissing(): void
    {
        $root = $this->pmssMakeTempDir('pmss-tmp-missing-', 0700);
        $messages = [];
        $this->pmssWithEnv(['PATH' => $root], function () use (&$messages): void {
            $messages = $this->configureTempDiskBacked(13);
        });

        $this->pmssAssertMessagesContain($messages, 'systemctl unavailable');
    }

    public function testDetectsDebian13FromOsReleaseWhenVersionMissing(): void
    {
        $logPath = $this->pmssMakeTempPath('pmss-tmp-detect-', '.log');
        $binDir = $this->pmssMakeInvocationLogStub('systemctl', $logPath, 'pmss-tmp-detect-bin-');

        $this->pmssWithOsRelease(['ID' => 'debian', 'VERSION_ID' => '12', 'VERSION_CODENAME' => 'trixie'], function () use ($binDir): void {
            $this->configureTempDiskBacked(null, $binDir);
        });

        $this->assertEquals("mask tmp.mount\n", (string) file_get_contents($logPath));
    }

}
