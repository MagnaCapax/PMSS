<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/user/billingIds.php';
require_once dirname(__DIR__, 3).'/lib/user/userConfigStore.php';

class UserBillingIdsSafetyTest extends TestCase
{
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

    public function testProvisionedIdentityIgnoresAccountSwaps(): void
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
