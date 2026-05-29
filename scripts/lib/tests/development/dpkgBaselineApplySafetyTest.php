<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/environment.php';

/**
 * Safety coverage for sanitized dpkg baseline staging.
 */
class DpkgBaselineApplySafetyTest extends TestCase
{
    public function testDpkgSelectionSanitizerKeepsBaselinePolicySnapshot(): void
    {
        $available = static function (string $package): bool { return in_array($package, ['alpha', 'held'], true); };
        [$plan, $output] = $this->pmssCaptureStdout(function () use ($available): array {
            return \pmssDpkgSelectionsSanitise(['alpha', 'held HOLD', 'wireguard-dkms install', 'repo-mediaarea install', 'nzbdrone install', 'linux-image-6.1.0-1-amd64 install', 'php8.2-cli install', 'python3.11-venv install', 'missing-tool install', 'cgroup-bin install', 'bad$name install', 'purged purge'], 12, $available);
        });

        $this->assertSame([["alpha\tinstall", "held\thold", "wireguard-dkms\tdeinstall", "repo-mediaarea\tdeinstall", "purged\tpurge"], true, true, ['missing-tool'], ['wireguard-dkms', 'repo-mediaarea', 'nzbdrone', 'php8.2-cli', 'python3.11-venv'], ['linux-image-6.1.0-1-amd64']], [$plan['sanitised'], $plan['warnings'], $plan['short_form_seen'], $plan['dropped_unavailable'], $plan['dropped_obsolete'], $plan['dropped_kernel']]);
        $this->assertStringContainsString('Invalid dpkg selection entry at line 11: bad$name install', $output);
    }

    public function testWriteSanitisedDpkgSelectionsTempFileWritesPayloadWhenHelperExists(): void
    {
        $this->pmssSkipUnlessFunctionExists('pmssWriteSanitisedDpkgSelectionsTempFile');

        $tmpDir = $this->pmssMakeTempDir('pmss-dpkg-stage-', 0700);
        $path = null;
        $output = '';

        $this->pmssWithEnv(['TMPDIR' => $tmpDir], function () use (&$path, &$output): void {
            [$path, $output] = $this->pmssCaptureStdout(function (): ?string {
                return \pmssWriteSanitisedDpkgSelectionsTempFile([
                    "alpha\tinstall",
                    "beta\thold",
                ]);
            });
        });

        $this->assertTrue(is_string($path) && $path !== '', 'Expected a staged dpkg baseline temp file');
        $this->assertTrue(file_exists((string) $path), 'Expected staged temp file to exist');
        $this->assertEquals('', $output, 'Successful staging should not emit warnings');
        $this->assertEquals("alpha\tinstall\nbeta\thold\n", (string) file_get_contents($path));
    }

    public function testWriteSanitisedDpkgSelectionsTempFileAppendsTrailingNewlineWhenHelperExists(): void
    {
        $this->pmssSkipUnlessFunctionExists('pmssWriteSanitisedDpkgSelectionsTempFile');

        $tmpDir = $this->pmssMakeTempDir('pmss-dpkg-newline-', 0700);
        $path = null;

        $this->pmssWithEnv(['TMPDIR' => $tmpDir], function () use (&$path): void {
            [$path] = $this->pmssCaptureStdout(function (): ?string {
                return \pmssWriteSanitisedDpkgSelectionsTempFile(["gamma\tdeinstall"]);
            });
        });

        $payload = (string) file_get_contents((string) $path);
        $this->assertSame("\n", substr($payload, -1), 'Expected staged baseline payload to end with one newline');
    }

    public function testCreatePrivateTempDirUsesProcessTempRoot(): void
    {
        $this->pmssSkipUnlessFunctionExists('pmssCreatePrivateTempDir');

        $path = \pmssCreatePrivateTempDir('pmss-libssl-');
        $tmpDir = realpath(sys_get_temp_dir());
        $realPath = is_string($path) ? realpath($path) : false;

        $this->assertTrue(is_string($tmpDir) && $tmpDir !== '', 'Expected process temp root to resolve');
        $this->assertTrue(is_string($realPath) && is_dir($realPath), 'Expected private temp directory to exist');
        $this->assertSame($tmpDir.'/', substr((string) $realPath, 0, strlen($tmpDir) + 1));
        $this->assertEquals(0700, fileperms((string) $path) & 0777);

        @rmdir((string) $path);
    }

    public function testCreatePrivateTempFileUsesProcessTempRoot(): void
    {
        $this->pmssSkipUnlessFunctionExists('pmssCreatePrivateTempFile');

        $path = \pmssCreatePrivateTempFile('pmss-file-');
        $tmpDir = realpath(sys_get_temp_dir());
        $realPath = is_string($path) ? realpath($path) : false;

        $this->assertTrue(is_string($tmpDir) && $tmpDir !== '', 'Expected process temp root to resolve');
        $this->assertTrue(is_string($realPath) && is_file($realPath), 'Expected private temp file to exist');
        $this->assertSame($tmpDir.'/', substr((string) $realPath, 0, strlen($tmpDir) + 1));

        @unlink((string) $path);
    }

    public function testCreatePrivateTempDirRejectsUnsafePrefixesBeforeTempnam(): void
    {
        $this->pmssSkipUnlessFunctionExists('pmssCreatePrivateTempDir');

        foreach (['', '../pmss', '/pmss', '.pmss', "pmss\nstage"] as $prefix) {
            [$path, $output] = $this->pmssCaptureStdout(function () use ($prefix): ?string {
                return \pmssCreatePrivateTempDir($prefix);
            });

            $this->assertSame(null, $path);
            $this->assertStringContainsString('Refusing private temporary directory with unsafe prefix', $output);
        }
    }

    public function testPrivateTempDirRealpathAcceptsOwnedPrefixUnderTempRoot(): void
    {
        $this->pmssSkipUnlessFunctionExists('pmssPrivateTempDirRealpath');

        $tmpDir = $this->pmssMakeTempDir('pmss-private-realpath-root-', 0700);
        $ownedDir = $tmpDir.'/pmss-libssl-owned';
        @mkdir($ownedDir, 0700);
        $resolved = null;
        $output = '';

        $this->pmssWithEnv(['TMPDIR' => $tmpDir], function () use ($ownedDir, &$resolved, &$output): void {
            [$resolved, $output] = $this->pmssCaptureStdout(function () use ($ownedDir): ?string {
                return \pmssPrivateTempDirRealpath($ownedDir, 'pmss-libssl-');
            });
        });

        $this->assertSame(realpath($ownedDir), $resolved);
        $this->assertEquals('', $output, 'Expected accepted private temp directory to stay quiet');
    }

    public function testPrivateTempDirRealpathRejectsWrongPrefixUnderTempRoot(): void
    {
        $this->pmssSkipUnlessFunctionExists('pmssPrivateTempDirRealpath');

        $tmpDir = $this->pmssMakeTempDir('pmss-private-reject-root-', 0700);
        $wrongDir = $tmpDir.'/other-cache';
        @mkdir($wrongDir, 0700);
        $resolved = 'sentinel';
        $output = '';

        $this->pmssWithEnv(['TMPDIR' => $tmpDir], function () use ($wrongDir, &$resolved, &$output): void {
            [$resolved, $output] = $this->pmssCaptureStdout(function () use ($wrongDir): ?string {
                return \pmssPrivateTempDirRealpath($wrongDir, 'pmss-libssl-');
            });
        });

        $this->assertSame(null, $resolved);
        $this->assertStringContainsString('Refusing temporary directory cleanup outside PMSS temp scope', $output);
    }

    public function testPrivateTempDirRealpathRejectsUnsafePrefixBeforeScopeMatch(): void
    {
        $this->pmssSkipUnlessFunctionExists('pmssPrivateTempDirRealpath');

        $tmpDir = $this->pmssMakeTempDir('pmss-private-prefix-root-', 0700);
        $ownedDir = $tmpDir.'/pmss-libssl-owned';
        @mkdir($ownedDir, 0700);
        $resolved = 'sentinel';
        $output = '';

        $this->pmssWithEnv(['TMPDIR' => $tmpDir], function () use ($ownedDir, &$resolved, &$output): void {
            [$resolved, $output] = $this->pmssCaptureStdout(function () use ($ownedDir): ?string {
                return \pmssPrivateTempDirRealpath($ownedDir, '../pmss-libssl-');
            });
        });

        $this->assertSame(null, $resolved);
        $this->assertStringContainsString('Refusing temporary directory cleanup for unsafe prefix', $output);
    }

    public function testRemovePrivateTempDirRejectsWrongPrefixBeforeRunStep(): void
    {
        $this->pmssSkipUnlessFunctionExists('pmssRemovePrivateTempDir');

        $tmpDir = $this->pmssMakeTempDir('pmss-private-cleanup-root-', 0700);
        $wrongDir = $tmpDir.'/other-cache';
        @mkdir($wrongDir, 0700);
        $result = 0;
        $output = '';

        $this->pmssWithEnv(['TMPDIR' => $tmpDir, 'PMSS_DRY_RUN' => '1'], function () use ($wrongDir, &$result, &$output): void {
            $this->pmssResetRuntimeProfile();
            [$result, $output] = $this->pmssCaptureStdout(function () use ($wrongDir): int {
                return \pmssRemovePrivateTempDir($wrongDir, 'pmss-libssl-', 'Cleaning unit-test temp dir');
            });
        });

        $this->assertSame(1, $result);
        $this->assertStringContainsString('Refusing temporary directory cleanup outside PMSS temp scope', $output);
        $this->assertSame(null, $this->pmssFindProfileCommand('Cleaning unit-test temp dir'));
    }

    public function testWriteSanitisedDpkgSelectionsTempFileFailurePathOnlyWhenHelperExists(): void
    {
        $this->pmssSkipUnlessFunctionExists('pmssWriteSanitisedDpkgSelectionsTempFile');

        $blockedPath = $this->pmssMakeReadableTempPath('pmss-dpkg-blocked-', 'tmpdir');
        $path = 'sentinel';
        $output = '';

        $this->pmssWithEnv(['TMPDIR' => $blockedPath], function () use (&$path, &$output): void {
            [$path, $output] = $this->pmssCaptureStdout(function (): ?string {
                return \pmssWriteSanitisedDpkgSelectionsTempFile(["delta\tinstall"]);
            });
        });

        if ($path === null) {
            $this->assertStringContainsString(
                'Unable to create temporary file for sanitized dpkg selections baseline',
                $output
            );
            return;
        }

        $this->assertTrue(is_string($path) && $path !== '', 'Expected helper to return a temporary path or null');
        @unlink($path);
    }

    public function testApplyDpkgSelectionsBlockedTmpdirBehaviorMatchesCurrentBaseline(): void
    {
        $blockedPath = $this->pmssMakeReadableTempPath('pmss-dpkg-apply-fail-', 'tmpdir');
        $result = false;
        $output = '';
        $applyCommand = 'unexpected';
        $installCommand = 'unexpected';

        $this->pmssWithEnv(['TMPDIR' => $blockedPath, 'PMSS_DRY_RUN' => '1'], function () use (&$result, &$output, &$applyCommand, &$installCommand): void {
            $this->pmssResetRuntimeProfile();
            [$result, $output] = $this->pmssCaptureStdout(function (): bool {
                return \pmssApplyDpkgSelections(12, true);
            });
            $applyCommand = $this->pmssFindProfileCommand('Applying dpkg selection baseline');
            $installCommand = $this->pmssFindProfileCommand('Installing packages from selection baseline');
        });

        if ($result === false) {
            $this->assertStringContainsString(
                'Refusing to apply raw dpkg selections baseline after sanitized baseline staging failed',
                $output
            );
            $this->assertSame(null, $applyCommand);
            $this->assertSame(null, $installCommand);
            return;
        }

        $this->assertTrue($result, 'Expected either fail-closed or staged baseline fallback behavior');
        $this->assertTrue(is_string($applyCommand) && $applyCommand !== '');
        $this->assertTrue(is_string($installCommand) && $installCommand !== '');
    }

    public function testApplyDpkgSelectionsDryRunProfilesCommandsAndCleansTempFile(): void
    {
        $tmpDir = $this->pmssMakeTempDir('pmss-dpkg-apply-ok-', 0700);
        $result = false;
        $applyCommand = null;
        $installCommand = null;

        $this->pmssWithEnv(['TMPDIR' => $tmpDir, 'PMSS_DRY_RUN' => '1'], function () use (&$result, &$applyCommand, &$installCommand): void {
            $this->pmssResetRuntimeProfile();
            [$result] = $this->pmssCaptureStdout(function (): bool {
                return \pmssApplyDpkgSelections(12, true);
            });
            $applyCommand = $this->pmssFindProfileCommand('Applying dpkg selection baseline');
            $installCommand = $this->pmssFindProfileCommand('Installing packages from selection baseline');
        });

        $matches = [];
        $this->assertTrue($result, 'Expected dry-run dpkg baseline application to succeed when staging works');
        $this->assertTrue(
            is_string($applyCommand) && preg_match("/^dpkg --set-selections < '([^']+)'$/", $applyCommand, $matches) === 1,
            'Expected staged dpkg baseline command to reference a temporary file'
        );
        $this->assertEquals(\aptCmd('dselect-upgrade -y'), $installCommand);
        $this->assertFalse(file_exists($matches[1]), 'Expected staged dpkg baseline temp file to be cleaned up');
    }
}
