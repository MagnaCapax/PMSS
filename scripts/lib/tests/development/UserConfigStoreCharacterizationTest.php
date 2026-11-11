<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigStore.php';

/** Lock the public store's values, key order and canonical/legacy precedence. */
class UserConfigStoreCharacterizationTest extends TestCase
{
    /** Expected empty-record projection, in the historical SORT_STRING order. */
    private function defaults(): array
    {
        return ['billingClientId' => 0, 'billingServiceId' => 0, 'dockerEnabled' => true,
            'lighttpdEnabled' => true, 'panelSessionLogin' => false, 'scheduledConfigBackup' => false,
            'suspended' => false, 'trafficLimit' => 0];
    }

    /** Exercise normalization through get(), so the same fixture also characterizes the old class. */
    private function readPayload(array $payload): array
    {
        $root = $this->pmssMakeTempDir('pmss-config-characterization-');
        $this->pmssWriteFile($root.'/config/users/fixtur01.json', json_encode($payload));
        return (new \UserConfigStore($root.'/config'))->get('fixtur01');
    }

    public function testEmptyAndLegacyRecordProjectionsHaveExactKeysAndValues(): void
    {
        $this->assertSame($this->defaults(), $this->readPayload([]));
        $this->assertSame([
            'IOWeight' => 'invalid', 'billingClientId' => 0, 'billingServiceId' => 12,
            'dockerEnabled' => false, 'lighttpdEnabled' => true, 'panelSessionLogin' => false,
            'quota' => 1, 'ramMiB' => 244, 'rtorrentRam' => '244', 'scheduledConfigBackup' => false,
            'suspended' => true, 'trafficLimit' => 0, 'unknown' => ['keep' => true],
        ], $this->readPayload(['rtorrentRam' => '244', 'billingId' => '0012', 'billingClientId' => false,
            'quota' => '1.5', 'IOWeight' => 'invalid', 'movedUntil' => '2026-02-30',
            'suspended' => 'false', 'trafficLimit' => 999, 'unknown' => ['keep' => true]]));
    }

    public function testFeatureDefaultsAndExplicitValuesRetainTheirDifferentPolicies(): void
    {
        foreach ([null, '', 'FALSE', '0', ' No ', 'off', 0, false, [], 'yes', 1, ['x']] as $value) {
            $enabled = in_array($value, ['yes', 1, ['x']], true);
            $payload = $this->readPayload(array_fill_keys(
                ['dockerEnabled', 'lighttpdEnabled', 'panelSessionLogin', 'scheduledConfigBackup'], $value));
            foreach (['dockerEnabled', 'lighttpdEnabled', 'panelSessionLogin', 'scheduledConfigBackup'] as $key) {
                $this->assertSame($enabled, $payload[$key], $key.' '.json_encode($value));
            }
        }
        foreach ([0 => true, 244 => false, 245 => true, 512 => true] as $ram => $enabled) {
            $this->assertSame($enabled, $this->readPayload(['ramMiB' => $ram])['dockerEnabled']);
        }
    }

    public function testBillingFallbackNumericCoercionAndMovedDatesRetainTheirBoundaries(): void
    {
        foreach ([null, '', 'invalid', 0, -1] as $serviceId) {
            $row = $this->readPayload(['billingServiceId' => $serviceId, 'billingId' => '42']);
            $this->assertSame(42, $row['billingServiceId']);
            $this->assertFalse(array_key_exists('billingId', $row));
        }
        $row = $this->readPayload(['billingServiceId' => 7, 'billingId' => 42, 'billingClientId' => '2.9',
            'ramMiB' => null, 'rtorrentRam' => '256', 'movedUntil' => '2028-02-29']);
        $this->pmssAssertArraySubsetSame(['billingServiceId' => 7, 'billingClientId' => 2,
            'ramMiB' => 256, 'movedUntil' => '2028-02-29'], $row);
        foreach (['2026-02-29', '2026-1-01', "2026-01-01\n", null, 20260101] as $date) {
            $this->assertFalse(array_key_exists('movedUntil', $this->readPayload(['movedUntil' => $date])));
        }
    }

    public function testCanonicalReadFallsBackOnlyWhenThereIsNoArrayRecord(): void
    {
        $root = $this->pmssMakeTempDir('pmss-config-precedence-');
        $this->pmssWriteFile($root.'/runtime/users.json', json_encode(['users' => ['fixtur01' => ['quota' => 17]]]));
        $store = new \UserConfigStore($root.'/config');
        foreach (['broken', 'null', '42', '"scalar"', 'false'] as $raw) {
            $this->pmssWriteFile($root.'/config/users/fixtur01.json', $raw);
            $this->assertSame(17, $store->get('fixtur01')['quota']);
        }
        $this->pmssWriteFile($root.'/config/users/fixtur01.json', '{}');
        $this->assertSame($this->defaults(), $store->get('fixtur01'));
        $this->assertSame(null, $store->get('../fixtur01'));
        $this->assertSame(null, $store->get('fixtur09'));
    }

    public function testBothAggregateShapesMergeCanonicalRecordsAndSortNames(): void
    {
        $root = $this->pmssMakeTempDir('pmss-config-map-');
        $legacy = ['fixtur02' => ['quota' => 2], 'fixtur01' => ['quota' => 1],
            '../fixtur03' => ['quota' => 3], 'fixtur04' => false];
        $this->pmssWriteFiles($root.'/config/users', ['fixtur01.json' => '{"quota":9}', 'fixtur02.json' => 'broken']);
        $store = new \UserConfigStore($root.'/config');
        foreach ([$legacy, ['users' => $legacy]] as $aggregate) {
            $this->pmssWriteFile($root.'/runtime/users.json', json_encode($aggregate));
            $rows = $store->loadAll();
            $this->assertSame(['fixtur01', 'fixtur02'], array_keys($rows));
            $this->assertSame(9, $rows['fixtur01']['quota']);
            $this->assertSame(2, $rows['fixtur02']['quota']);
        }
    }

    public function testFallbackRamStillPassesThroughTheLowMemoryGate(): void
    {
        $store = new class extends \UserConfigStore {
            public $calls = [];
            public function resolveRamMiB(string $username): int { $this->calls[] = $username; return 244; }
        };
        $row = $store->applyFallbacks(' FIXTUR01 ', ['ramMiB' => 0]);
        $this->assertSame(['fixtur01'], $store->calls);
        $this->assertSame(244, $row['ramMiB']);
        $this->assertSame(false, $row['dockerEnabled']);
        $store->applyFallbacks('../fixtur01', []);
        $store->applyFallbacks('fixtur01', ['ramMiB' => 512]);
        $this->assertSame(['fixtur01'], $store->calls);
    }
}
