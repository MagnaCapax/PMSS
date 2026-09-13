<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Preserve capture results, buffer nesting, and environment restoration. */
class TestCaseEnvironmentTest extends TestCase
{
    public function testCaptureWithoutOverridesPreservesLegacyBehavior(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_TEST_CAPTURE_VALUE' => 'before']);
        foreach ([null, false, 0, '', ['value' => 3], new \stdClass()] as $value) {
            $captured = $this->pmssCaptureStdout(function () use ($value) {
                putenv('PMSS_TEST_CAPTURE_VALUE=changed');
                echo "binary\0output\n";
                return $value;
            });
            $this->assertSame([$value, "binary\0output\n"], $captured);
            $this->assertSame('changed', getenv('PMSS_TEST_CAPTURE_VALUE'));
        }
    }

    public function testCaptureRestoresUnsetEmptyAndExistingEnvironment(): void
    {
        foreach ([null, '', 'before'] as $previous) {
            $this->pmssTrackEnvOverrides(['PMSS_TEST_CAPTURE_VALUE' => $previous]);
            $captured = $this->pmssCaptureStdout(function (): string {
                echo getenv('PMSS_TEST_CAPTURE_VALUE');
                putenv('PMSS_TEST_CAPTURE_VALUE=callback-change');
                return 'result';
            }, ['PMSS_TEST_CAPTURE_VALUE' => 'inside']);
            $this->assertSame(['result', 'inside'], $captured);
            $this->assertSame($previous ?? false, getenv('PMSS_TEST_CAPTURE_VALUE'));
        }
    }

    public function testNestedCaptureRestoresOuterEnvironmentAndBuffer(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_TEST_CAPTURE_VALUE' => 'before']);
        $captured = $this->pmssCaptureStdout(function (): array {
            echo getenv('PMSS_TEST_CAPTURE_VALUE');
            $inner = $this->pmssCaptureStdout(function (): void { echo getenv('PMSS_TEST_CAPTURE_VALUE'); }, ['PMSS_TEST_CAPTURE_VALUE' => null]);
            echo getenv('PMSS_TEST_CAPTURE_VALUE');
            return $inner;
        }, ['PMSS_TEST_CAPTURE_VALUE' => 'outer']);
        $this->assertSame([[null, ''], 'outerouter'], $captured);
        $this->assertSame('before', getenv('PMSS_TEST_CAPTURE_VALUE'));
    }

    public function testThrowablesRestoreEnvironmentAndDiscardCapturedOutput(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_TEST_CAPTURE_VALUE' => 'before']);
        foreach ([new \RuntimeException('failure'), new \Error('failure')] as $failure) {
            $level = ob_get_level();
            $this->assertThrows(get_class($failure), function () use ($failure): void {
                $this->pmssCaptureStdout(function () use ($failure): void { echo 'discarded'; throw $failure; }, ['PMSS_TEST_CAPTURE_VALUE' => 'inside']);
            }, 'failure');
            $this->assertSame($level, ob_get_level());
            $this->assertSame('before', getenv('PMSS_TEST_CAPTURE_VALUE'));
        }
    }
}
