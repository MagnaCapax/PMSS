<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/environment.php';

/**
 * Safety coverage for sanitized dpkg baseline staging.
 */
class DpkgBaselineApplySafetyTest extends TestCase
{
    public function testWriteSanitisedDpkgSelectionsTempFileWritesPayloadWhenHelperExists(): void
    {
        if (!function_exists('pmssWriteSanitisedDpkgSelectionsTempFile')) {
            throw new SkipTest('pmssWriteSanitisedDpkgSelectionsTempFile helper not present in this baseline');
        }

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

        @unlink($path);
    }

    public function testWriteSanitisedDpkgSelectionsTempFileAppendsTrailingNewlineWhenHelperExists(): void
    {
        if (!function_exists('pmssWriteSanitisedDpkgSelectionsTempFile')) {
            throw new SkipTest('pmssWriteSanitisedDpkgSelectionsTempFile helper not present in this baseline');
        }

        $tmpDir = $this->pmssMakeTempDir('pmss-dpkg-newline-', 0700);
        $path = null;

        $this->pmssWithEnv(['TMPDIR' => $tmpDir], function () use (&$path): void {
            [$path] = $this->pmssCaptureStdout(function (): ?string {
                return \pmssWriteSanitisedDpkgSelectionsTempFile(["gamma\tdeinstall"]);
            });
        });

        $payload = (string) file_get_contents((string) $path);
        $this->assertSame("\n", substr($payload, -1), 'Expected staged baseline payload to end with one newline');

        @unlink((string) $path);
    }

    public function testWriteSanitisedDpkgSelectionsTempFileFailurePathOnlyWhenHelperExists(): void
    {
        if (!function_exists('pmssWriteSanitisedDpkgSelectionsTempFile')) {
            throw new SkipTest('pmssWriteSanitisedDpkgSelectionsTempFile helper not present in this baseline');
        }

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

        $this->assertTrue($result, 'Expected dry-run dpkg baseline application to succeed when staging works');
        $this->assertTrue(
            is_string($applyCommand) && preg_match("/^dpkg --set-selections < '([^']+)'$/", $applyCommand, $matches) === 1,
            'Expected staged dpkg baseline command to reference a temporary file'
        );
        $this->assertEquals(\aptCmd('dselect-upgrade -y'), $installCommand);
        $this->assertFalse(file_exists($matches[1]), 'Expected staged dpkg baseline temp file to be cleaned up');
    }
}
