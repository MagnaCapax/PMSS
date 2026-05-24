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
require_once dirname(__DIR__, 2).'/cgroup/Manager.php';

use PMSS\Cgroup\Manager;

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

        // 1. Discover users via listUsers.php
        $usersRaw = shell_exec('/scripts/listUsers.php');
        if (empty($usersRaw)) {
            throw new \PMSS\Tests\SkipTest('No users returned by /scripts/listUsers.php');
        }
        $users = array_filter(explode("\n", trim($usersRaw)));

        $checked = 0;
        foreach ($users as $user) {
            $user = trim($user);
            if ($user === '') continue;

            // 2. Check user config existence
            if (!is_dir("/home/$user")) {
                echo "[WARN] User $user returned by listUsers but home directory missing.\n";
                continue;
            }

            // 3. Resolve UID and slice
            $uid = (int)shell_exec('id -u ' . escapeshellarg($user));
            if ($uid < 1000) {
                echo "[WARN] User $user has invalid UID $uid.\n";
                continue;
            }
            $unit = "user-$uid.slice";

            // 4. Check if slice is active
            exec('systemctl is-active ' . escapeshellarg($unit), $dummy, $rc);
            if ($rc !== 0) {
                echo "[INFO] Skipping $user ($unit): Slice not active.\n";
                continue;
            }

            // 5. Read current properties
            $props = $this->getSystemdProperties($unit, ['MemoryHigh', 'CPUWeight', 'IOWeight']);
            
            // If MemoryHigh is infinity or missing, we can't verify the calculation
            if (empty($props['MemoryHigh']) || $props['MemoryHigh'] === 'infinity') {
                echo "[INFO] Skipping $user: MemoryHigh not set (unlimited?)\n";
                continue;
            }

            // 6. Calculate expected weight
            // Systemd returns bytes, userConfigCgroup uses MiB
            $ramBytes = (int)$props['MemoryHigh'];
            $ramMiB   = (int)($ramBytes / 1024 / 1024);
            
            $expected = Manager::calculateWeightFromMemory($ramMiB);
            // Derived IOWeight is capped at the BFQ-effective ceiling; CPUWeight keeps the full curve.
            $expectedIoWeight = min($expected, 200);
            
            // 7. Assert
            $this->assertEquals($expected, (int)$props['CPUWeight'], "CPUWeight mismatch for $user (RAM: {$ramMiB}MiB)");
            $this->assertEquals($expectedIoWeight, (int)$props['IOWeight'], "IOWeight mismatch for $user (RAM: {$ramMiB}MiB)");
            
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
