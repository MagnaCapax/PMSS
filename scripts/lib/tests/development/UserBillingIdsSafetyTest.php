<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/user/billingIds.php';
require_once dirname(__DIR__, 3).'/lib/user/userConfigStore.php';

class UserBillingIdsSafetyTest extends TestCase
{
    /** Build a config dir with an optional per-user record and account home markers. */
    private function billingFixture(string $prefix, ?array $record, array $homeFiles): string
    {
        $base = $this->pmssMakeTempDir($prefix);
        $this->pmssEnsureDir($base.'/config/users');
        $this->pmssEnsureDir($base.'/home');
        if ($record !== null) {
            file_put_contents($base.'/config/users/alice.json', json_encode($record));
        }
        foreach ($homeFiles as $name => $value) {
            file_put_contents($base.'/home/'.$name, $value);
        }
        return $base;
    }

    public function testStoreValueWinsOverLegacyHomeFiles(): void
    {
        $base = $this->billingFixture('pmss-billing-trust-', ['billingServiceId' => 123, 'billingClientId' => 456],
            ['.billingServiceId' => '999', '.billingId' => '998', '.billingClientId' => '888']);
        $uid = posix_geteuid();
        $this->assertSame('123', \pmssUserBillingIdentityRead('alice', 'billingServiceId', $base.'/config', $uid));
        $this->assertSame('456', \pmssUserBillingIdentityRead('alice', 'billingClientId', $base.'/config', $uid));
    }

    public function testLegacyHomeFilesAreIgnoredWhenStoreHasNoValue(): void
    {
        $homeFiles = ['.billingServiceId' => '789', '.billingId' => '123', '.billingClientId' => '456'];
        $uid = posix_geteuid();
        // A record without positive IDs, and no record at all, both yield no identity.
        foreach ([['billingServiceId' => 0, 'billingClientId' => 0], null] as $record) {
            $base = $this->billingFixture('pmss-billing-legacy-', $record, $homeFiles);
            $this->assertSame(null, \pmssUserBillingIdentityRead('alice', 'billingServiceId', $base.'/config', $uid));
            $this->assertSame(null, \pmssUserBillingIdentityRead('alice', 'billingClientId', $base.'/config', $uid));
        }
    }

    public function testLegacyAggregateStoreIsReadWhenUserRecordIsMissing(): void
    {
        $base = $this->billingFixture('pmss-billing-aggregate-', null, ['.billingId' => '999']);
        $this->pmssWriteFile($base.'/runtime/users.json', json_encode(['users' => ['alice' => ['billingId' => 321]]]));
        $uid = posix_geteuid();
        $this->assertSame('321', \pmssUserBillingIdentityRead('alice', 'billingServiceId', $base.'/config', $uid));
        $this->assertSame(null, \pmssUserBillingIdentityRead('alice', 'billingClientId', $base.'/config', $uid));
    }

    public function testProvisioningWriterMapsBothBillingMarkersToStore(): void
    {
        if (!defined('PMSS_TEST_MODE')) define('PMSS_TEST_MODE', true);
        require_once dirname(__DIR__, 3).'/util/writeHomeMarker.php';
        $store = new class extends \UserConfigStore {
            public $payload = ['billingServiceId' => 0, 'billingClientId' => 0];
            public function get(string $user): ?array { return $this->payload; }
            public function set(string $user, array $payload): bool { $this->payload = $payload; return true; }
        };
        $this->assertTrue(\pmssWriteHomeMarkerBillingStoreSet($store, 'alice', '.billingId', 123));
        $this->assertTrue(\pmssWriteHomeMarkerBillingStoreSet($store, 'alice', '.billingClientId', 456));
        $this->assertSame(123, $store->payload['billingServiceId']);
        $this->assertSame(456, $store->payload['billingClientId']);
        $this->assertTrue(\pmssHomeMarkerIsKnown('.billingClientId'));
    }

    public function testMissingProvisionedIdsAreNotRecoveredFromAccountHome(): void
    {
        $base = $this->pmssMakeTempDir('pmss-billing-store-');
        $store = new \UserConfigStore($base.'/config');
        $result = $store->applyFallbacks('alice', [
            'ramMiB' => 1024, 'billingServiceId' => 0, 'billingClientId' => 0,
        ]);
        $this->assertSame(0, $result['billingServiceId']);
        $this->assertSame(0, $result['billingClientId']);
    }

    public function testStoreReadRejectsUnsafeInputsAndEntries(): void
    {
        $base = $this->billingFixture('pmss-billing-provisioned-', ['billingServiceId' => 123, 'billingClientId' => 456], []);
        $config = $base.'/config';
        $record = $config.'/users/alice.json';
        $uid = posix_geteuid();
        $this->assertSame(null, \pmssUserBillingIdentityRead('alice', 'other', $config, $uid));
        $this->assertSame(null, \pmssUserBillingIdentityRead('../bob', 'billingServiceId', $config, $uid));
        $this->assertSame(null, \pmssUserBillingIdentityRead('alice', 'billingServiceId', $config, $uid + 1));
        // A hard-linked record is not a single regular entry.
        $this->assertTrue(link($record, $base.'/second.json'));
        $this->assertSame(null, \pmssUserBillingIdentityRead('alice', 'billingServiceId', $config, $uid));
        unlink($base.'/second.json');
        $this->assertSame('123', \pmssUserBillingIdentityRead('alice', 'billingServiceId', $config, $uid));
        unlink($record);
        file_put_contents($base.'/outside', json_encode(['billingServiceId' => 999]));
        symlink($base.'/outside', $record);
        $this->assertSame(null, \pmssUserBillingIdentityRead('alice', 'billingServiceId', $config, $uid));
    }

    public function testStoreReadRejectsUnsupportedValues(): void
    {
        $base = $this->billingFixture('pmss-billing-values-', null, []);
        $record = $base.'/config/users/alice.json';
        $uid = posix_geteuid();
        foreach ([0, -1, '0', '-1', '12x', ' 42', (string) PHP_INT_MAX.'0', 1.5, true, null, []] as $value) {
            file_put_contents($record, json_encode(['billingServiceId' => $value]));
            $this->assertSame(null, \pmssUserBillingIdentityRead('alice', 'billingServiceId', $base.'/config', $uid), var_export($value, true));
        }
        file_put_contents($record, json_encode(['billingServiceId' => '00042']));
        $this->assertSame('00042', \pmssUserBillingIdentityRead('alice', 'billingServiceId', $base.'/config', $uid));
    }
}
