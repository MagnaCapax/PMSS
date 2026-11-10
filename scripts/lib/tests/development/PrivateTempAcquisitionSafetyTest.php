<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../runtime/filesystem.php';

/** Exercise runtime/filesystemTemp.php acquisition with memory-only filesystem faults. */
class PrivateTempAcquisitionSafetyTest extends TestCase
{
    private function observe(array $cases): array
    {
        $script = <<<'PHP'
namespace PrivateTempAcquisitionFixture;
function operation($name) {
    if ($name !== ($GLOBALS['case']['stage'] ?? null)) return true;
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
function is_link($path) { return false; }
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
    $GLOBALS['node'] = null; $GLOBALS['cleanup'] = $GLOBALS['modes'] = [];
    $result = $caught = null;
    try {
        $result = ($case['kind'] ?? 'file') === 'file'
            ? pmssCreatePrivateTempFile('pmss-temp-') : pmssCreatePrivateTempDir('pmss-temp-');
    } catch (\Throwable $error) { $caught = $error; }
    $rows[] = [$result, $GLOBALS['node'], $GLOBALS['cleanup'], $GLOBALS['modes'],
        $caught === null ? null : get_class($caught), $caught === $GLOBALS['failure']];
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
}
