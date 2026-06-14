<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/user/billingIds.php';

class UserBillingIdsSafetyTest extends TestCase
{
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
}
