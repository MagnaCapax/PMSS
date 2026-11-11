<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../runtime/filesystem.php';

/** Exercise scripts/lib/runtime/filesystemTemp.php acquisition with memory-only filesystem faults. */
class PrivateTempAcquisitionSafetyTest extends TestCase
{
    private function observe(array $cases): array
    {
        $script = <<<'PHP'
namespace PrivateTempAcquisitionFixture;
function operation($name) {
    $GLOBALS['calls'][$name] = ($GLOBALS['calls'][$name] ?? 0) + 1;
    if ($name === 'unlink' && $GLOBALS['calls'][$name] === ($GLOBALS['case']['cleanupFaultAt'] ?? 2)
        && $GLOBALS['cleanupFailure'] !== null) {
        throw $GLOBALS['cleanupFailure'];
    }
    if ($name !== ($GLOBALS['case']['stage'] ?? null)) return true;
    if (($GLOBALS['case']['once'] ?? false) && $GLOBALS['calls'][$name] > 1) return true;
    if ($GLOBALS['failure'] !== null) throw $GLOBALS['failure'];
    return false;
}
function pmssPrivateTempBaseDirRealpath() { return '/fixture'; }
function pmssFilesystemPathHasNulByte($path) { return strpos($path, "\0") !== false; }
function tempnam($base, $prefix) {
    if (!operation('allocate')) return false;
    $GLOBALS['node'] = 'file';
    return '/fixture/pmss-temp-token';
}
function is_link($path) {
    operation('link');
    if ($GLOBALS['case']['symlink'] ?? false) {
        $GLOBALS['node'] = 'link';
        return true;
    }
    return false;
}
function realpath($path) { return operation('file-realpath') ? $path : false; }
function is_file($path) { return operation('file-type'); }
function pmssRegularFilePathIsReadable($path) {
    return $GLOBALS['node'] === 'file' && ($GLOBALS['case']['cleanupAllowed'] ?? true);
}
function unlink($path) {
    $GLOBALS['cleanup'][] = 'unlink';
    if (!operation('unlink')) return false;
    $GLOBALS['node'] = null;
    return true;
}
function mkdir($path, $mode) {
    $GLOBALS['modes']['mkdir'] = $mode;
    if ($GLOBALS['case']['foreignDirectory'] ?? false) $GLOBALS['node'] = 'foreign-directory';
    if (!operation('mkdir')) return false;
    $GLOBALS['node'] = 'directory';
    return true;
}
function chmod($path, $mode) { $GLOBALS['modes']['chmod'] = $mode; return operation('chmod'); }
function pmssPrivateTempDirRealpath($path, $prefix) { return operation('directory-verify') ? $path : null; }
function rmdir($path) { $GLOBALS['cleanup'][] = 'rmdir'; $GLOBALS['node'] = null; return true; }
PHP;
        foreach (['pmssPrivateTempPrefixIsSafe', 'pmssCreatePrivateTempFile', 'pmssCreatePrivateTempDir'] as $function) {
            $script .= \pmssTestingFunctionExport($function, 'PrivateTempAcquisitionFixture');
        }
        $script .= '$cases = '.var_export($cases, true).';';
        $script .= <<<'PHP'
$rows = [];
foreach ($cases as $case) {
    $GLOBALS['case'] = $case;
    $GLOBALS['failure'] = ($case['fault'] ?? '') === 'exception' ? new \RuntimeException('acquisition fault')
        : (($case['fault'] ?? '') === 'error' ? new \Error('acquisition fault') : null);
    $cleanupClass = $case['cleanupFault'] ?? null;
    $GLOBALS['cleanupFailure'] = $cleanupClass !== null ? new $cleanupClass('cleanup fault') : null;
    $GLOBALS['node'] = null; $GLOBALS['cleanup'] = $GLOBALS['modes'] = $GLOBALS['calls'] = [];
    $result = $caught = null;
    try {
        $result = ($case['kind'] ?? 'file') === 'file'
            ? pmssCreatePrivateTempFile('pmss-temp-') : pmssCreatePrivateTempDir('pmss-temp-');
    } catch (\Throwable $error) { $caught = $error; }
    $rows[] = [$result, $GLOBALS['node'], $GLOBALS['cleanup'], $GLOBALS['modes'],
        $caught === null ? null : get_class($caught), $caught === ($GLOBALS['cleanupFailure'] ?? $GLOBALS['failure'])];
}
echo json_encode($rows);
PHP;
        return $this->pmssRunInlinePhpJson($script);
    }

    public function testFileResultsRetainOwnershipAndGuardedCleanup(): void
    {
        $this->assertSame([
            ['/fixture/pmss-temp-token', 'file', [], [], null, true],
            [null, null, [], [], null, true],
            [null, null, ['unlink'], [], null, true],
            [null, null, ['unlink'], [], null, true],
            [null, 'file', [], [], null, true],
        ], $this->observe([[], ['stage' => 'allocate'], ['stage' => 'file-realpath'], ['stage' => 'file-type'],
            ['stage' => 'file-realpath', 'cleanupAllowed' => false]]));
    }

    public function testFileMetadataThrowablesUseTheExistingCleanupGuard(): void
    {
        foreach (['file-realpath', 'file-type'] as $stage) {
            foreach (['exception' => 'RuntimeException', 'error' => 'Error'] as $fault => $class) {
                $this->assertSame([
                    [null, null, ['unlink'], [], $class, true],
                    [null, 'file', [], [], $class, true],
                ], $this->observe([['stage' => $stage, 'fault' => $fault],
                    ['stage' => $stage, 'fault' => $fault, 'cleanupAllowed' => false]]));
            }
        }
    }

    public function testInitialLinkProbeThrowablesCleanCandidatesBeforeEitherOwnershipTransfer(): void
    {
        foreach (['file', 'directory'] as $kind) {
            foreach (['exception' => 'RuntimeException', 'error' => 'Error'] as $fault => $class) {
                $case = ['kind' => $kind, 'stage' => 'link', 'fault' => $fault];
                $this->assertSame([
                    [null, null, ['unlink'], [], $class, true],
                    [null, 'file', [], [], $class, true],
                ], $this->observe([$case, $case + ['cleanupAllowed' => false]]));
            }
        }
    }

    public function testInitialLinkRefusalsLeaveReplacementLinksUntouched(): void
    {
        $this->assertSame([
            [null, 'link', [], [], null, true],
            [null, 'link', [], [], null, true],
        ], $this->observe([['symlink' => true], ['kind' => 'directory', 'symlink' => true]]));
    }

    public function testInitialLinkProbeCleanupFaultUsesNormalThrowablePrecedence(): void
    {
        foreach (['exception', 'error'] as $fault) {
            foreach (['RuntimeException', 'Error'] as $cleanupFault) {
                $this->assertSame([[null, 'file', ['unlink'], [], $cleanupFault, true]],
                    $this->observe([['stage' => 'link', 'fault' => $fault,
                        'cleanupFault' => $cleanupFault, 'cleanupFaultAt' => 1]]));
            }
        }
    }

    public function testDirectoryResultsKeepModesAndCleanupOrder(): void
    {
        $modes = ['mkdir' => 0700, 'chmod' => 0700];
        $this->assertSame([
            ['/fixture/pmss-temp-token', 'directory', ['unlink'], $modes, null, true],
            [null, null, [], [], null, true],
            [null, 'file', ['unlink', 'unlink'], [], null, true],
            [null, null, ['unlink'], ['mkdir' => 0700], null, true],
            [null, null, ['unlink', 'rmdir'], $modes, null, true],
            [null, null, ['unlink', 'rmdir'], $modes, null, true],
        ], $this->observe(array_map(static function ($stage): array {
            return ['kind' => 'directory', 'stage' => $stage];
        }, [null, 'allocate', 'unlink', 'mkdir', 'chmod', 'directory-verify'])));
    }

    public function testDirectoryValidationThrowablesRemoveTheEmptyCandidate(): void
    {
        foreach (['chmod', 'directory-verify'] as $stage) {
            foreach (['exception' => 'RuntimeException', 'error' => 'Error'] as $fault => $class) {
                $this->assertSame([[null, null, ['unlink', 'rmdir'], ['mkdir' => 0700, 'chmod' => 0700], $class, true]],
                    $this->observe([['kind' => 'directory', 'stage' => $stage, 'fault' => $fault]]));
            }
        }
    }

    public function testDirectoryConversionThrowablesKeepGuardedCleanup(): void
    {
        foreach (['exception' => 'RuntimeException', 'error' => 'Error'] as $fault => $class) {
            $cases = [
                ['stage' => 'unlink', 'once' => true],
                ['stage' => 'unlink', 'cleanupAllowed' => false],
                ['stage' => 'mkdir'],
                ['stage' => 'mkdir', 'foreignDirectory' => true],
            ];
            $this->assertSame([
                [null, null, ['unlink', 'unlink'], [], $class, true],
                [null, 'file', ['unlink'], [], $class, true],
                [null, null, ['unlink'], ['mkdir' => 0700], $class, true],
                [null, 'foreign-directory', ['unlink'], ['mkdir' => 0700], $class, true],
            ], $this->observe(array_map(static function (array $case) use ($fault): array {
                return $case + ['kind' => 'directory', 'fault' => $fault];
            }, $cases)));
        }
        // Failed mkdir must never authorize removal of another actor's directory.
        $this->assertSame([[null, 'foreign-directory', ['unlink'], ['mkdir' => 0700], null, true]],
            $this->observe([['kind' => 'directory', 'stage' => 'mkdir', 'foreignDirectory' => true]]));
    }

    public function testDirectoryConversionCleanupFaultUsesNormalThrowablePrecedence(): void
    {
        foreach (['exception', 'error'] as $fault) {
            foreach (['RuntimeException', 'Error'] as $cleanupFault) {
                $this->assertSame([[null, 'file', ['unlink', 'unlink'], [], $cleanupFault, true]],
                    $this->observe([['kind' => 'directory', 'stage' => 'unlink', 'fault' => $fault,
                        'cleanupFault' => $cleanupFault]]));
            }
        }
    }
}
