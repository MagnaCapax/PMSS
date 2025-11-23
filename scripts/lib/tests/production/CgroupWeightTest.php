<?php
/**
 * Cgroup Weight Verification Test (Production Probe)
 *
 * Manually run validation tool to confirm that user slices have the correct
 * CPU/IO weights derived from their memory allocation.
 *
 * Usage:
 *   php scripts/lib/tests/production/CgroupWeightTest.php
 *
 * @author PMSS Team
 */

namespace PMSS\Tests\Production;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userCgroup.php';

class CgroupWeightTest extends \PMSS\Tests\TestCase
{
    /**
     * Verify that every active user slice has weights matching the RAM formula.
     */
    public function testActiveUserSliceWeights(): void
    {
        if ($this->isSandbox()) {
            throw new \PMSS\Tests\SkipTest('Cannot inspect systemd slices in sandbox');
        }

        // 1. Discover active user slices
        // #TODO Refactor to use a robust slice discovery helper if available
        exec('systemctl list-units --type=slice --no-legend --no-pager "user-*.slice"', $lines, $rc);
        if ($rc !== 0 || empty($lines)) {
            throw new \PMSS\Tests\SkipTest('No active user slices found');
        }

        $checked = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, 'user-0.slice') !== false) {
                continue; // Skip root
            }

            // Extract unit name (user-1001.slice)
            $parts = preg_split('/\s+/', $line);
            $unit = $parts[0] ?? '';
            if (empty($unit)) continue;

            // 2. Read current properties
            $props = $this->getSystemdProperties($unit, ['MemoryHigh', 'CPUWeight', 'IOWeight']);
            
            // If MemoryHigh is infinity or missing, we can't verify the calculation
            if (empty($props['MemoryHigh']) || $props['MemoryHigh'] === 'infinity') {
                echo "[INFO] Skipping $unit: MemoryHigh not set (unlimited?)\n";
                continue;
            }

            // 3. Calculate expected weight
            // Systemd returns bytes, userCgroup uses MiB
            $ramBytes = (int)$props['MemoryHigh'];
            $ramMiB   = (int)($ramBytes / 1024 / 1024);
            
            $expected = \calculateCgroupWeightFromMemory($ramMiB);
            
            // 4. Assert
            // CPUWeight and IOWeight should match, OR be close if there's rounding variance
            // #TODO Allow for explicit overrides (check if cgroup.policy.php has a static override?)
            
            $this->assertEquals($expected, (int)$props['CPUWeight'], "CPUWeight mismatch for $unit (RAM: {$ramMiB}MiB)");
            $this->assertEquals($expected, (int)$props['IOWeight'], "IOWeight mismatch for $unit (RAM: {$ramMiB}MiB)");
            
            $checked++;
        }
        
        if ($checked === 0) {
            echo "[WARN] No eligible user slices checked.\n";
        } else {
            echo "[OK] Verified weights for $checked user slices.\n";
        }
    }

    /**
     * Helper to fetch properties from systemctl show.
     */
    private function getSystemdProperties(string $unit, array $keys): array
    {
        $cmd = 'systemctl show ' . escapeshellarg($unit) . ' -p ' . implode(',', $keys);
        exec($cmd, $output, $rc);
        $data = [];
        foreach ($output as $row) {
            if (strpos($row, '=') !== false) {
                [$k, $v] = explode('=', $row, 2);
                $data[$k] = trim($v);
            }
        }
        return $data;
    }
}

// Allow running directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $test = new CgroupWeightTest();
    foreach ($test->run() as $res) {
        echo ($res[0] === true ? "[PASS]" : ($res[0] === 'skip' ? "[SKIP]" : "[FAIL]")) . " " . $res[1] . "\n";
        if ($res[2]) echo "  > " . $res[2] . "\n";
    }
}
