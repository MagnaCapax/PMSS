<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/remoteBinary.php';

class RemoteBinaryHelperTest extends TestCase
{
    private function withFakeDownloadBody(string $body, callable $callback, array $extraEnv = array()): void
    {
        $root = $this->pmssMakeTempDir('pmss-remote-binary-', 0700);
        $binDir = $root.'/bin';
        $commandLog = $root.'/commands.log';
        $dpkgCapture = $root.'/dpkg.capture';

        $this->pmssWriteExecutableFiles($binDir, [
            'wget' => <<<'SH'
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
if [ "${PMSS_TEST_WGET_FILE:-}" != "" ]; then
    cat "${PMSS_TEST_WGET_FILE}" > "$out"
else
    printf '%s' "${PMSS_TEST_WGET_BODY}" > "$out"
fi
printf 'wget %s\n' "$out" >> "${PMSS_TEST_COMMAND_LOG}"
SH,
            'install' => <<<'SH'
#!/bin/sh
src="$3"
dest="$4"
cat "$src" > "$dest"
chmod 0755 "$dest" 2>/dev/null || true
printf 'install %s %s\n' "$src" "$dest" >> "${PMSS_TEST_COMMAND_LOG}"
SH,
            'dpkg' => <<<'SH'
#!/bin/sh
pkg="$2"
printf 'dpkg %s\n' "$pkg" >> "${PMSS_TEST_COMMAND_LOG}"
cat "$pkg" > "${PMSS_TEST_DPKG_CAPTURE}"
SH
        ]);

        $env = $this->pmssPathPrefixedEnvironment($binDir, array_merge(['PMSS_TEST_WGET_BODY' => $body], $extraEnv));
        $env['PMSS_TEST_COMMAND_LOG'] = $commandLog;
        $env['PMSS_TEST_DPKG_CAPTURE'] = $dpkgCapture;
        $sourceFile = isset($extraEnv['PMSS_TEST_WGET_FILE']) ? (string) $extraEnv['PMSS_TEST_WGET_FILE'] : '';
        $expectedSha256 = $sourceFile !== '' && is_file($sourceFile) ? (string) hash_file('sha256', $sourceFile) : hash('sha256', $body);

        $this->pmssWithEnv($env, function () use ($callback, $root, $commandLog, $dpkgCapture, $body, $expectedSha256): void {
            $callback($root, $commandLog, $dpkgCapture, $body, $expectedSha256);
        });
    }

    private function createSourceArchive(string $root): string
    {
        $archiveRoot = $root.'/archive-root';
        $sourceDir = $archiveRoot.'/source';
        @mkdir($sourceDir, 0755, true);
        @file_put_contents($sourceDir.'/payload.txt', 'archive-ok');

        $archivePath = $root.'/archive.tar.gz';
        $output = [];
        $rc = 0;
        exec(
            sprintf('tar -czf %s -C %s source 2>&1', escapeshellarg($archivePath), escapeshellarg($archiveRoot)),
            $output,
            $rc
        );
        $this->assertEquals(0, $rc, implode("\n", $output));
        return $archivePath;
    }

    public function testFetchPinnedRemoteFileReturnsTempPathForMatchingChecksum(): void
    {
        $this->withFakeDownloadBody('payload', function ($root, $commandLog, $dpkgCapture, $body, $expectedSha256): void {
            $path = \pmssFetchPinnedRemoteFile('demo archive', 'https://example.invalid/archive', $expectedSha256);

            $this->assertTrue(is_string($path) && $path !== '', 'Expected matching download to return a temp path');
            $this->assertEquals($body, (string) file_get_contents($path));
            $this->assertStringContainsString('wget ', $this->pmssReadFileOrEmpty($commandLog));
            @unlink($path);
        });
    }

    public function testFetchPinnedRemoteFileRejectsInvalidInputsBeforeDownload(): void
    {
        foreach ([
            'http url' => ['http://example.invalid/archive', null],
            'newline url' => ['https://example.invalid/archive'."\n".'next', null],
            'scheme only' => ['https://', null],
            'empty url' => ['', null],
            'bad checksum' => ['https://example.invalid/archive', 'not-a-sha256'],
        ] as $label => [$url, $checksum]) {
            $this->withFakeDownloadBody('payload', function ($root, $commandLog, $dpkgCapture, $body, $expectedSha256) use ($label, $url, $checksum): void {
                $path = \pmssFetchPinnedRemoteFile('demo archive', $url, $checksum ?? $expectedSha256);

                $this->assertTrue($path === null, 'Expected '.$label.' to be rejected');
                $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog), $label);
            });
        }
    }

    public function testFetchPinnedRemoteFileCleansChecksumMismatchTemps(): void
    {
        $this->pmssAssertNoNewGlobMatches(sys_get_temp_dir().'/pmss-remote-bin-*', function (): void {
            $this->withFakeDownloadBody('payload', function (): void {
                $path = \pmssFetchPinnedRemoteFile('demo archive', 'https://example.invalid/archive', str_repeat('a', 64));

                $this->assertTrue($path === null, 'Expected checksum mismatch to reject the download');
            });
        }, 'Checksum mismatch should not leave temp files behind');
    }

    public function testRunPinnedRemoteArchiveStepRejectsUnsafeExtractionInputsBeforeDownload(): void
    {
        $cases = [
            ['../archive.tar.gz', 'source', 'compile'],
            ['-archive.tar.gz', 'source', 'compile'],
            ['archive.tar.gz', 'source/child', 'compile'],
            ['', 'source', 'compile'],
            ['archive.tar.gz', '..', 'compile'],
            ['archive.tar.gz', '-source', 'compile'],
            ['archive.tar.gz', 'source', ''],
            ['archive.tar.gz', 'source', 'compile/../outside'],
            ['archive.zip', 'source', 'compile'],
        ];

        foreach ($cases as $case) {
            $this->withFakeDownloadBody('payload', function ($root, $commandLog, $dpkgCapture, $body, $expectedSha256) use ($case): void {
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

    public function testRunPinnedRemoteArchiveStepRejectsUnsafePostExtractCommandsBeforeDownload(): void
    {
        $cases = [
            [''],
            ["make\ninstall"],
            ['make; install'],
            ['make && install'],
            ['make || true'],
            ["make\0install"],
            [123],
        ];

        foreach ($cases as $commands) {
            $this->withFakeDownloadBody('payload', function ($root, $commandLog, $dpkgCapture, $body, $expectedSha256) use ($commands): void {
                \pmssRunPinnedRemoteArchiveStep(
                    'demo archive',
                    'https://example.invalid/archive',
                    $expectedSha256,
                    'archive.tar.gz',
                    'source',
                    'Extracting demo archive',
                    $commands,
                    $root.'/compile'
                );

                $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog));
            });
        }
    }

    public function testRunPinnedRemoteArchiveStepRejectsSymlinkedWorkspaceBeforeDownload(): void
    {
        $this->withFakeDownloadBody('payload', function ($root, $commandLog, $dpkgCapture, $body, $expectedSha256): void {
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

    public function testRunPinnedRemoteArchiveStepReturnsTrueForSharedExtraction(): void
    {
        $archivePath = $this->createSourceArchive($this->pmssMakeTempDir('pmss-remote-archive-', 0700));

        $this->withFakeDownloadBody('', function ($root, $commandLog, $dpkgCapture, $body, $expectedSha256): void {
            $result = \pmssRunPinnedRemoteArchiveStep(
                'demo archive',
                'https://example.invalid/archive',
                $expectedSha256,
                'archive.tar.gz',
                'source',
                'Extracting demo archive',
                [],
                $root.'/compile'
            );

            $this->assertTrue($result, 'Expected successful archive extraction to report success');
            $this->assertEquals('archive-ok', (string) @file_get_contents($root.'/compile/source/payload.txt'));
            $this->assertStringContainsString('wget ', $this->pmssReadFileOrEmpty($commandLog));
        }, ['PMSS_TEST_WGET_FILE' => $archivePath]);
    }

    public function testInstallPinnedRemoteBinarySkipsDownloadWhenChecksumAlreadyMatches(): void
    {
        $this->withFakeDownloadBody('new-payload', function ($root, $commandLog, $dpkgCapture, $body, $expectedSha256): void {
            $destination = $root.'/binary';
            @file_put_contents($destination, $body);

            \pmssInstallPinnedRemoteBinary('demo binary', 'https://example.invalid/binary', $expectedSha256, $destination, true);

            $this->assertEquals($body, (string) file_get_contents($destination));
            $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog));
        });
    }

    public function testInstallPinnedRemoteBinaryWritesSafeMissingDestination(): void
    {
        $this->withFakeDownloadBody('payload', function ($root, $commandLog, $dpkgCapture, $body, $expectedSha256): void {
            $destination = $root.'/safe-binary';

            \pmssInstallPinnedRemoteBinary('demo binary', 'https://example.invalid/binary', $expectedSha256, $destination, true);

            $this->assertEquals($body, (string) file_get_contents($destination));
            $this->assertStringContainsString('install ', $this->pmssReadFileOrEmpty($commandLog));
        });
    }

    public function testInstallPinnedRemoteBinaryRejectsUnsafeDestinationsBeforeDownload(): void
    {
        $this->withFakeDownloadBody('payload', function ($root, $commandLog, $dpkgCapture, $body, $expectedSha256): void {
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
        $this->pmssAssertNoNewGlobMatches(sys_get_temp_dir().'/pmss-remote-deb-*', function (): void {
            $this->withFakeDownloadBody('package-payload', function ($root, $commandLog, $dpkgCapture, $body, $expectedSha256): void {
                $result = \pmssInstallPinnedRemoteDebPackage('demo package', 'https://example.invalid/demo.deb', $expectedSha256);

                $this->assertTrue($result, 'Expected matching package install to succeed');
                $this->assertEquals($body, (string) file_get_contents($dpkgCapture));
                $this->assertStringContainsAllStrings(['wget ', 'dpkg '], $this->pmssReadFileOrEmpty($commandLog));
            });
        }, 'Successful package install should clean temp files');
    }

    public function testInstallPinnedRemoteDebPackageReturnsDryRunSuccessWithoutCommands(): void
    {
        $this->pmssAssertNoNewGlobMatches(sys_get_temp_dir().'/pmss-remote-deb-*', function (): void {
            $this->withFakeDownloadBody('package-payload', function ($root, $commandLog, $dpkgCapture, $body, $expectedSha256): void {
                $result = \pmssInstallPinnedRemoteDebPackage('demo package', 'https://example.invalid/demo.deb', $expectedSha256);

                $this->assertTrue($result, 'Expected dry-run package install to report success');
                $this->assertEquals('', $this->pmssReadFileOrEmpty($commandLog));
                $this->assertTrue(!is_file($dpkgCapture), 'Dry-run should not invoke dpkg');
            }, ['PMSS_DRY_RUN' => '1']);
        }, 'Dry-run package install should clean temp files');
    }
}
