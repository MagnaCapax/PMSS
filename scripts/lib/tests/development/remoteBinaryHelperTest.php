<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/remoteBinary.php';

class RemoteBinaryHelperTest extends TestCase
{
    private function withFakeCommands(array $env, callable $callback): void
    {
        $root = $this->pmssMakeTempDir('pmss-remote-binary-', 0700);
        $binDir = $root.'/bin';
        $commandLog = $root.'/commands.log';
        $dpkgCapture = $root.'/dpkg.capture';
        @mkdir($binDir, 0755, true);

        $this->pmssWriteExecutableFile($binDir.'/wget', <<<'SH'
#!/bin/sh
out=''
while [ "$#" -gt 0 ]; do
    if [ "$1" = "-O" ]; then
        out="$2"
        shift 2
        continue
    fi
    shift
done
if [ "$out" = "" ]; then
    exit 2
fi
printf '%s' "${PMSS_TEST_WGET_BODY}" > "$out"
printf 'wget %s\n' "$out" >> "${PMSS_TEST_COMMAND_LOG}"
SH
        );
        $this->pmssWriteExecutableFile($binDir.'/install', <<<'SH'
#!/bin/sh
src="$3"
dest="$4"
cat "$src" > "$dest"
chmod 0755 "$dest" 2>/dev/null || true
printf 'install %s %s\n' "$src" "$dest" >> "${PMSS_TEST_COMMAND_LOG}"
SH
        );
        $this->pmssWriteExecutableFile($binDir.'/dpkg', <<<'SH'
#!/bin/sh
pkg="$2"
printf 'dpkg %s\n' "$pkg" >> "${PMSS_TEST_COMMAND_LOG}"
cat "$pkg" > "${PMSS_TEST_DPKG_CAPTURE}"
SH
        );

        $env = $this->pmssPathPrefixedEnvironment($binDir, $env);
        $env['PMSS_TEST_COMMAND_LOG'] = $commandLog;
        $env['PMSS_TEST_DPKG_CAPTURE'] = $dpkgCapture;

        $this->pmssWithEnv($env, function () use ($callback, $root, $commandLog, $dpkgCapture): void {
            $callback($root, $commandLog, $dpkgCapture);
        });
    }
    public function testFetchPinnedRemoteFileReturnsTempPathForMatchingChecksum(): void
    {
        $body = 'payload';
        $expectedSha256 = hash('sha256', $body);

        $this->withFakeCommands(['PMSS_TEST_WGET_BODY' => $body], function ($root, $commandLog) use ($body, $expectedSha256): void {
            $path = \pmssFetchPinnedRemoteFile('demo archive', 'https://example.invalid/archive', $expectedSha256);

            $this->assertTrue(is_string($path) && $path !== '', 'Expected matching download to return a temp path');
            $this->assertEquals($body, (string) file_get_contents($path));
            $this->assertStringContainsString('wget ', $this->pmssReadFileOrEmpty($commandLog));
            @unlink($path);
        });
    }

    public function testFetchPinnedRemoteFileRejectsNonHttpsUrls(): void
    {
        $body = 'payload';
        $expectedSha256 = hash('sha256', $body);

        $this->withFakeCommands(['PMSS_TEST_WGET_BODY' => $body], function ($root, $commandLog) use ($expectedSha256): void {
            $path = \pmssFetchPinnedRemoteFile('demo archive', 'http://example.invalid/archive', $expectedSha256);

            $this->assertTrue($path === null, 'Expected HTTP download to be rejected');
            $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog));
        });
    }

    public function testFetchPinnedRemoteFileRejectsMalformedUrlsBeforeDownload(): void
    {
        $body = 'payload';
        $expectedSha256 = hash('sha256', $body);
        $cases = [
            'https://example.invalid/archive'."\n".'next',
            'https://',
            '',
        ];

        foreach ($cases as $url) {
            $this->withFakeCommands(['PMSS_TEST_WGET_BODY' => $body], function ($root, $commandLog) use ($expectedSha256, $url): void {
                $path = \pmssFetchPinnedRemoteFile('demo archive', $url, $expectedSha256);

                $this->assertTrue($path === null, 'Expected malformed URL to be rejected');
                $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog));
            });
        }
    }

    public function testFetchPinnedRemoteFileRejectsMalformedChecksumBeforeDownload(): void
    {
        $this->withFakeCommands(['PMSS_TEST_WGET_BODY' => 'payload'], function ($root, $commandLog): void {
            $path = \pmssFetchPinnedRemoteFile('demo archive', 'https://example.invalid/archive', 'not-a-sha256');

            $this->assertTrue($path === null, 'Expected malformed checksum to be rejected');
            $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog));
        });
    }

    public function testFetchPinnedRemoteFileCleansChecksumMismatchTemps(): void
    {
        $before = glob(sys_get_temp_dir().'/pmss-remote-bin-*') ?: [];

        $this->withFakeCommands(['PMSS_TEST_WGET_BODY' => 'payload'], function () use ($before): void {
            $path = \pmssFetchPinnedRemoteFile('demo archive', 'https://example.invalid/archive', str_repeat('a', 64));
            $after = glob(sys_get_temp_dir().'/pmss-remote-bin-*') ?: [];

            $this->assertTrue($path === null, 'Expected checksum mismatch to reject the download');
            $this->assertEquals([], array_values(array_diff($after, $before)), 'Checksum mismatch should not leave temp files behind');
        });
    }

    public function testRunPinnedRemoteArchiveStepRejectsUnsafeExtractionInputsBeforeDownload(): void
    {
        $body = 'payload';
        $expectedSha256 = hash('sha256', $body);
        $cases = [
            ['../archive.tar.gz', 'source', 'compile'],
            ['archive.tar.gz', 'source/child', 'compile'],
            ['', 'source', 'compile'],
            ['archive.tar.gz', '..', 'compile'],
            ['archive.tar.gz', 'source', ''],
            ['archive.tar.gz', 'source', 'compile/../outside'],
        ];

        foreach ($cases as $case) {
            $this->withFakeCommands(['PMSS_TEST_WGET_BODY' => $body], function ($root, $commandLog) use ($expectedSha256, $case): void {
                $workDir = $case[2] === '' ? '' : $root.'/'.$case[2];

                \pmssRunPinnedRemoteArchiveStep(
                    'demo archive',
                    'https://example.invalid/archive',
                    $expectedSha256,
                    $case[0],
                    $case[1],
                    'Extracting demo archive',
                    [],
                    $workDir
                );

                $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog));
            });
        }
    }

    public function testRunPinnedRemoteArchiveStepRejectsSymlinkedWorkspaceBeforeDownload(): void
    {
        $body = 'payload';
        $expectedSha256 = hash('sha256', $body);

        $this->withFakeCommands(['PMSS_TEST_WGET_BODY' => $body], function ($root, $commandLog) use ($expectedSha256): void {
            $target = $root.'/real-workspace';
            $link = $root.'/linked-workspace';
            @mkdir($target, 0755, true);
            $this->pmssCreateSymlinkOrSkip($target, $link);

            \pmssRunPinnedRemoteArchiveStep(
                'demo archive',
                'https://example.invalid/archive',
                $expectedSha256,
                'archive.tar.gz',
                'source',
                'Extracting demo archive',
                [],
                $link
            );

            $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog));
        });
    }

    public function testInstallPinnedRemoteBinarySkipsDownloadWhenChecksumAlreadyMatches(): void
    {
        $body = 'payload';
        $expectedSha256 = hash('sha256', $body);

        $this->withFakeCommands(['PMSS_TEST_WGET_BODY' => 'new-payload'], function ($root, $commandLog) use ($body, $expectedSha256): void {
            $destination = $root.'/binary';
            @file_put_contents($destination, $body);

            \pmssInstallPinnedRemoteBinary('demo binary', 'https://example.invalid/binary', $expectedSha256, $destination, true);

            $this->assertEquals($body, (string) file_get_contents($destination));
            $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog));
        });
    }

    public function testInstallPinnedRemoteBinaryWritesSafeMissingDestination(): void
    {
        $body = 'payload';
        $expectedSha256 = hash('sha256', $body);

        $this->withFakeCommands(['PMSS_TEST_WGET_BODY' => $body], function ($root, $commandLog) use ($body, $expectedSha256): void {
            $destination = $root.'/safe-binary';

            \pmssInstallPinnedRemoteBinary('demo binary', 'https://example.invalid/binary', $expectedSha256, $destination, true);

            $this->assertEquals($body, (string) file_get_contents($destination));
            $this->assertStringContainsString('install ', $this->pmssReadFileOrEmpty($commandLog));
        });
    }

    public function testInstallPinnedRemoteBinaryRejectsUnsafeDestinationsBeforeDownload(): void
    {
        $body = 'payload';
        $expectedSha256 = hash('sha256', $body);

        $this->withFakeCommands(['PMSS_TEST_WGET_BODY' => $body], function ($root, $commandLog) use ($expectedSha256): void {
            $realDestination = $root.'/real-binary';
            $linkDestination = $root.'/link-binary';
            file_put_contents($realDestination, 'original');
            $this->pmssCreateSymlinkOrSkip($realDestination, $linkDestination);

            foreach ([$root.'/missing-parent/binary', $root.'/safe/../binary', $linkDestination] as $destination) {
                \pmssInstallPinnedRemoteBinary('demo binary', 'https://example.invalid/binary', $expectedSha256, $destination, true);
            }

            $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog));
            $this->assertEquals('original', (string) file_get_contents($realDestination));
        });
    }

    public function testInstallPinnedRemoteDebPackageInvokesDpkgForMatchingPackage(): void
    {
        $body = 'package-payload';
        $expectedSha256 = hash('sha256', $body);
        $before = glob(sys_get_temp_dir().'/pmss-remote-deb-*') ?: [];

        $this->withFakeCommands(['PMSS_TEST_WGET_BODY' => $body], function ($root, $commandLog, $dpkgCapture) use ($body, $expectedSha256, $before): void {
            $result = \pmssInstallPinnedRemoteDebPackage('demo package', 'https://example.invalid/demo.deb', $expectedSha256);
            $after = glob(sys_get_temp_dir().'/pmss-remote-deb-*') ?: [];

            $this->assertTrue($result, 'Expected matching package install to succeed');
            $this->assertEquals($body, (string) file_get_contents($dpkgCapture));
            $this->assertStringContainsString('wget ', $this->pmssReadFileOrEmpty($commandLog));
            $this->assertStringContainsString('dpkg ', $this->pmssReadFileOrEmpty($commandLog));
            $this->assertEquals([], array_values(array_diff($after, $before)), 'Successful package install should clean temp files');
        });
    }

    public function testInstallPinnedRemoteDebPackageReturnsDryRunSuccessWithoutCommands(): void
    {
        $body = 'package-payload';
        $expectedSha256 = hash('sha256', $body);
        $before = glob(sys_get_temp_dir().'/pmss-remote-deb-*') ?: [];

        $this->withFakeCommands([
            'PMSS_DRY_RUN' => '1',
            'PMSS_TEST_WGET_BODY' => $body,
        ], function ($root, $commandLog, $dpkgCapture) use ($expectedSha256, $before): void {
            $result = \pmssInstallPinnedRemoteDebPackage('demo package', 'https://example.invalid/demo.deb', $expectedSha256);
            $after = glob(sys_get_temp_dir().'/pmss-remote-deb-*') ?: [];

            $this->assertTrue($result, 'Expected dry-run package install to report success');
            $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog));
            $this->assertTrue(!is_file($dpkgCapture), 'Dry-run should not invoke dpkg');
            $this->assertEquals([], array_values(array_diff($after, $before)), 'Dry-run package install should clean temp files');
        });
    }
}
