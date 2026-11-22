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
            file_put_contents($base.'/config/users/fixtur01.json', json_encode($record));
        }
        $this->pmssWriteFiles($base.'/home', $homeFiles);
        return $base;
    }

    public function testStoreValueWinsOverLegacyHomeFiles(): void
    {
        $base = $this->billingFixture('pmss-billing-trust-', ['billingServiceId' => 123, 'billingClientId' => 456],
            ['.billingServiceId' => '999', '.billingId' => '998', '.billingClientId' => '888']);
        $uid = posix_geteuid();
        $this->assertSame('123', \pmssUserBillingIdentityRead('fixtur01', 'billingServiceId', $base.'/config', $uid));
        $this->assertSame('456', \pmssUserBillingIdentityRead('fixtur01', 'billingClientId', $base.'/config', $uid));
    }

    public function testLegacyHomeFilesAreIgnoredWhenStoreHasNoValue(): void
    {
        $homeFiles = ['.billingServiceId' => '789', '.billingId' => '123', '.billingClientId' => '456'];
        $uid = posix_geteuid();
        // A record without positive IDs, and no record at all, both yield no identity.
        foreach ([['billingServiceId' => 0, 'billingClientId' => 0], null] as $record) {
            $base = $this->billingFixture('pmss-billing-legacy-', $record, $homeFiles);
            $this->assertSame(null, \pmssUserBillingIdentityRead('fixtur01', 'billingServiceId', $base.'/config', $uid));
            $this->assertSame(null, \pmssUserBillingIdentityRead('fixtur01', 'billingClientId', $base.'/config', $uid));
        }
    }

    public function testLegacyAggregateStoreIsReadWhenUserRecordIsMissing(): void
    {
        $base = $this->billingFixture('pmss-billing-aggregate-', null, ['.billingId' => '999']);
        $this->pmssWriteFile($base.'/runtime/users.json', json_encode(['users' => ['fixtur01' => ['billingId' => 321]]]));
        $uid = posix_geteuid();
        $this->assertSame('321', \pmssUserBillingIdentityRead('fixtur01', 'billingServiceId', $base.'/config', $uid));
        $this->assertSame(null, \pmssUserBillingIdentityRead('fixtur01', 'billingClientId', $base.'/config', $uid));
    }

    public function testProvisioningWriterMapsBothBillingMarkersToStore(): void
    {
        if (!defined('PMSS_TEST_MODE')) define('PMSS_TEST_MODE', true);
        require_once dirname(__DIR__, 3).'/util/writeHomeMarker.php';
        $registry = \pmssHomeMarkerRegistry();
        $this->assertSame('billingServiceId', $registry['.billingId']['store']);
        $this->assertSame('billingServiceId', $registry['.billingServiceId']['store']);
        $this->assertSame('billingClientId', $registry['.billingClientId']['store']);

        $store = new \UserConfigStore($this->pmssMakeTempDir('pmss-billing-writer-').'/config');
        // No record yet: the store write fails, so the CLI writes no display copy.
        $this->assertFalse($store->setValue('fixtur01', 'billingServiceId', 123));
        $this->assertTrue($store->set('fixtur01', ['ramMiB' => 512, 'rtorrentPort' => 5000, 'quota' => 5, 'quotaBurst' => 6]));
        $this->assertTrue($store->setValue('fixtur01', $registry['.billingId']['store'], 123));
        $this->assertTrue($store->setValue('fixtur01', $registry['.billingClientId']['store'], 456));
        $this->assertSame(123, $store->get('fixtur01')['billingServiceId']);
        $this->assertSame(456, $store->get('fixtur01')['billingClientId']);
        $this->assertFalse($store->setValue('fixtur01', 'quota', 1));
    }

    public function testMissingProvisionedIdsAreNotRecoveredFromAccountHome(): void
    {
        $base = $this->pmssMakeTempDir('pmss-billing-store-');
        $store = new \UserConfigStore($base.'/config');
        $result = $store->applyFallbacks('fixtur01', [
            'ramMiB' => 1024, 'billingServiceId' => 0, 'billingClientId' => 0,
        ]);
        $this->pmssAssertArraySubsetSame(['billingServiceId' => 0, 'billingClientId' => 0], $result);
    }

    public function testStoreReadRejectsUnsafeInputsAndEntries(): void
    {
        $base = $this->billingFixture('pmss-billing-provisioned-', ['billingServiceId' => 123, 'billingClientId' => 456], []);
        $config = $base.'/config';
        $record = $config.'/users/fixtur01.json';
        $uid = posix_geteuid();
        $this->assertSame(null, \pmssUserBillingIdentityRead('fixtur01', 'other', $config, $uid));
        $this->assertSame(null, \pmssUserBillingIdentityRead('../fixtur02', 'billingServiceId', $config, $uid));
        $this->assertSame(null, \pmssUserBillingIdentityRead('fixtur01', 'billingServiceId', $config, $uid + 1));
        // A hard-linked record is not a single regular entry.
        $this->assertTrue(link($record, $base.'/second.json'));
        $this->assertSame(null, \pmssUserBillingIdentityRead('fixtur01', 'billingServiceId', $config, $uid));
        unlink($base.'/second.json');
        $this->assertSame('123', \pmssUserBillingIdentityRead('fixtur01', 'billingServiceId', $config, $uid));
        unlink($record);
        file_put_contents($base.'/outside', json_encode(['billingServiceId' => 999]));
        symlink($base.'/outside', $record);
        $this->assertSame(null, \pmssUserBillingIdentityRead('fixtur01', 'billingServiceId', $config, $uid));
    }

    public function testStoreReadRejectsUnsupportedValues(): void
    {
        $base = $this->billingFixture('pmss-billing-values-', null, []);
        $record = $base.'/config/users/fixtur01.json';
        $uid = posix_geteuid();
        foreach ([0, -1, '0', '-1', '12x', ' 42', (string) PHP_INT_MAX.'0', 1.5, true, null, []] as $value) {
            file_put_contents($record, json_encode(['billingServiceId' => $value]));
            $this->assertSame(null, \pmssUserBillingIdentityRead('fixtur01', 'billingServiceId', $base.'/config', $uid), var_export($value, true));
        }
        file_put_contents($record, json_encode(['billingServiceId' => '00042']));
        $this->assertSame('00042', \pmssUserBillingIdentityRead('fixtur01', 'billingServiceId', $base.'/config', $uid));
    }
}
