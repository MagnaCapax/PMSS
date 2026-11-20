<?php
/** Verify scripts/lib/user/iopsLimit.php rejects malformed monthly cache containers. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/iopsLimit.php';

final class IopsLimitInputSafetyTest extends TestCase
{
    public function testMalformedContainersPreserveTheIndependentCounter(): void
    {
        // Serialized objects decode to incomplete objects, which must never receive array offsets.
        $invalid = [null, false, true, 123, 1.5, '', 'month', new \stdClass(), (object) ['month' => 900]];
        foreach (['io_read_ops', 'io_write_ops'] as $direction) {
            foreach ($invalid as $container) {
                foreach ([$container, ['raw' => $container]] as $counter) {
                    $data = [
                        'io_read_ops' => ['raw' => ['month' => 12.6]],
                        'io_write_ops' => ['raw' => ['month' => 12.6]],
                    ];
                    $data[$direction] = $counter;
                    $path = $this->pmssWriteTempFile('iops-container-', serialize($data));
                    $this->assertSame(13, \pmssReadUserMonthlyIopsUsage($path));
                }
            }
        }
    }

    public function testMalformedMonthlyValuesKeepTheExistingZeroFallback(): void
    {
        foreach ([null, false, true, '', 'invalid', [], [100], new \stdClass(), -1, INF, NAN,
            '1e999', '9223372036854775808', 1.0E14 + 1] as $value) {
            $path = $this->pmssWriteTempFile('iops-month-', serialize([
                'io_read_ops' => ['raw' => ['month' => $value]],
                'io_write_ops' => ['raw' => ['month' => 7]],
            ]));
            $this->assertSame(7, \pmssReadUserMonthlyIopsUsage($path));
        }
    }

    public function testAbsentOrInvalidCountersYieldZero(): void
    {
        foreach ([[], ['io_read_ops' => []], ['io_write_ops' => ['raw' => []]],
            ['io_read_ops' => new \stdClass(), 'io_write_ops' => ['raw' => new \stdClass()]]] as $data) {
            $path = $this->pmssWriteTempFile('iops-empty-', serialize($data));
            $this->assertSame(0, \pmssReadUserMonthlyIopsUsage($path));
        }
    }

    public function testValidCountersKeepConversionCeilingsAndCombinedRounding(): void
    {
        foreach ([[0, 0, 0], [12, 8, 20], ['12', '8', 20], [0.4, 0.4, 1],
            [0.6, 0.6, 1], [1200.4, 799.6, 2000], [1.0E14, 1.0E14, 200000000000000]] as $case) {
            $path = $this->pmssWriteTempFile('iops-valid-', serialize([
                'io_read_ops' => ['raw' => ['month' => $case[0]]],
                'io_write_ops' => ['raw' => ['month' => $case[1]]],
            ]));
            $this->assertSame($case[2], \pmssReadUserMonthlyIopsUsage($path));
        }
    }

    public function testInvalidSerializedRootsKeepTheMissingCacheFallback(): void
    {
        foreach (['', 'invalid', serialize(false), serialize(123), serialize(new \stdClass())] as $bytes) {
            $path = $this->pmssWriteTempFile('iops-root-', $bytes);
            $this->assertSame(0, \pmssReadUserMonthlyIopsUsage($path));
        }
    }
}
