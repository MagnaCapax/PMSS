<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/user/billingIds.php';
require_once dirname(__DIR__, 3).'/lib/user/userConfigStore.php';

class UserBillingIdsSafetyTest extends TestCase
{
    public function testTrustedStoreWinsAndLegacyHomeIsIgnored(): void
    {
        $base = $this->pmssMakeTempDir('pmss-billing-trust-');
        mkdir($base.'/config/users', 0755, true);
        mkdir($base.'/home');
        file_put_contents($base.'/config/users/alice.json', json_encode(['billingServiceId' => 123, 'billingClientId' => 456]));
        file_put_contents($base.'/home/.billingId', '999');
        file_put_contents($base.'/home/.billingClientId', '888');
        $warnings = [];
        $warn = static function ($user, $message) use (&$warnings): void { $warnings[] = [$user, $message]; };
        $uid = posix_geteuid();
        $this->assertSame('123', \pmssUserBillingIdentityRead('alice', $base.'/home', 'billingServiceId', $base.'/config', $uid, $warn, false));
        $this->assertSame('456', \pmssUserBillingIdentityRead('alice', $base.'/home', 'billingClientId', $base.'/config', $uid, $warn, false));
        $this->assertSame([], $warnings);
    }

    public function testAbsentTrustedIdRetainsLegacyHomeOrderAndWarnsOnce(): void
    {
        $base = $this->pmssMakeTempDir('pmss-billing-legacy-');
        mkdir($base.'/config/users', 0755, true);
        mkdir($base.'/home');
        file_put_contents($base.'/config/users/alice.json', json_encode(['billingServiceId' => 0, 'billingClientId' => 0]));
        file_put_contents($base.'/home/.billingId', '123');
        file_put_contents($base.'/home/.billingClientId', '456');
        $warnings = [];
        $warn = static function ($user, $message) use (&$warnings): void { $warnings[] = [$user, $message]; };
        $uid = posix_geteuid();
        $this->assertSame('123', \pmssUserBillingIdentityRead('alice', $base.'/home', 'billingServiceId', $base.'/config', $uid, $warn, false));
        $this->assertSame('456', \pmssUserBillingIdentityRead('alice', $base.'/home', 'billingClientId', $base.'/config', $uid, $warn, false));
        $this->assertSame([['alice', 'billing id from legacy home file']], $warnings);
        file_put_contents($base.'/home/.billingServiceId', '789');
        $this->assertSame('789', \pmssUserBillingIdentityRead('alice', $base.'/home', 'billingServiceId', $base.'/config', $uid, $warn, false));
    }

    public function testTrustedClientIdWinsWhileServiceStillUsesLegacy(): void
    {
        $base = $this->pmssMakeTempDir('pmss-billing-mixed-');
        mkdir($base.'/config/users', 0755, true);
        mkdir($base.'/home');
        file_put_contents($base.'/config/users/bob.json', json_encode(['billingServiceId' => 0, 'billingClientId' => 456]));
        file_put_contents($base.'/home/.billingId', '123');
        file_put_contents($base.'/home/.billingClientId', '999');
        $uid = posix_geteuid();
        $this->assertSame('123', \pmssUserBillingIdentityRead('bob', $base.'/home', 'billingServiceId', $base.'/config', $uid, null, false));
        $this->assertSame('456', \pmssUserBillingIdentityRead('bob', $base.'/home', 'billingClientId', $base.'/config', $uid, null, false));
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

    public function testProvisionedIdentityTakesPrecedenceOverHomeMarkers(): void
    {
        $base = $this->pmssMakeTempDir('pmss-billing-provisioned-');
        $config = $base.'/config';
        mkdir($config);
        mkdir($config.'/users');
        $record = $config.'/users/alice.json';
        file_put_contents($record, json_encode(['billingServiceId' => 123, 'billingClientId' => 456]));
        $uid = posix_geteuid();
        $this->assertSame('123', \pmssUserBillingProvisionedDigitsRead('alice', 'billingServiceId', $config, $uid));
        $this->assertSame('456', \pmssUserBillingProvisionedDigitsRead('alice', 'billingClientId', $config, $uid));
        $this->assertSame(null, \pmssUserBillingProvisionedDigitsRead('alice', 'other', $config, $uid));
        $this->assertSame(null, \pmssUserBillingProvisionedDigitsRead('../bob', 'billingServiceId', $config, $uid));
        $this->assertSame(null, \pmssUserBillingProvisionedDigitsRead('alice', 'billingServiceId', $config, $uid + 1));
        file_put_contents($record, json_encode(['billingServiceId' => 0]));
        $this->assertSame(null, \pmssUserBillingProvisionedDigitsRead('alice', 'billingServiceId', $config, $uid));
        unlink($record);
        symlink($base.'/outside', $record);
        file_put_contents($base.'/outside', json_encode(['billingServiceId' => 999]));
        $this->assertSame(null, \pmssUserBillingProvisionedDigitsRead('alice', 'billingServiceId', $config, $uid));
    }
    public function testBillingFileNameGuardAcceptsKnownDotFiles(): void
    {
        foreach (['.billingServiceId', '.billingId', '.billingClientId'] as $fileName) {
            $this->assertTrue(\pmssUserBillingFileNameIsSafe($fileName), 'expected safe billing file name: '.$fileName);
        }
    }

    public function testBillingFileNameGuardRejectsPathLikeNames(): void
    {
        $invalid = ['', '../outside', '/absolute', '.nested/id', ".billingId\0suffix", 'billingId', '.'];
        foreach ($invalid as $fileName) {
            $this->assertFalse(\pmssUserBillingFileNameIsSafe($fileName), 'expected unsafe billing file name: '.$fileName);
        }
    }

    public function testBillingDigitsReadSkipsUnsafeFileNamesBeforeFallback(): void
    {
        $root = $this->pmssMakeTempDir('pmss-billing-ids-');
        $home = $root.'/user';
        $this->pmssEnsureDir($home);
        file_put_contents($root.'/outside', "444\n");
        file_put_contents($home.'/.billingServiceId', "555\n");

        $this->assertSame('555', \pmssUserBillingDigitsRead($home, ['../outside', '.billingServiceId']));
    }

    public function testBillingDigitsReadDoesNotTraverseOutsideHome(): void
    {
        $root = $this->pmssMakeTempDir('pmss-billing-ids-');
        $home = $root.'/user';
        $this->pmssEnsureDir($home);
        file_put_contents($root.'/outside', "777\n");

        $this->assertSame(null, \pmssUserBillingDigitsRead($home, ['../outside']));
    }

    public function testBillingDigitsReadRejectsUnsupportedEntriesAndValues(): void
    {
        $home = $this->pmssMakeTempDir('pmss-billing-values-');
        $path = $home.'/.billingServiceId';
        foreach (['0', '-1', '12x', "42\0", (string) PHP_INT_MAX.'0', str_repeat('1', 257)] as $value) {
            file_put_contents($path, $value);
            $this->assertSame(null, \pmssUserBillingServiceIdDigitsRead($home), $value);
        }
        file_put_contents($path, "00042\n");
        $this->assertSame('00042', \pmssUserBillingServiceIdDigitsRead($home));

        $second = $home.'/.billingClientId';
        $this->assertTrue(link($path, $second));
        $this->assertSame(null, \pmssUserBillingServiceIdDigitsRead($home));
        $this->assertSame(null, \pmssUserBillingClientIdDigitsRead($home));
    }

    public function testManagedOwnerRequirementUsesTheOpenedEntry(): void
    {
        $home = $this->pmssMakeTempDir('pmss-billing-owner-');
        $path = $home.'/.billingClientId';
        file_put_contents($path, "123\n");
        $this->assertSame('123', \pmssUserBillingClientIdDigitsRead($home));
        $this->assertSame(fileowner($path) === 0 ? '123' : null, \pmssUserBillingClientIdDigitsRead($home, true));
    }
}
