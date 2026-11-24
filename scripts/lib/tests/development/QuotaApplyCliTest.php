<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/quotaApplyCli.php';

/** userQuotaApply.php: kernel limits at the host file ratio, then quota and quotaBurst in the store (ADR 0342). */
class QuotaApplyCliTest extends TestCase
{
    private $configDir;
    private $stateDir;

    public function setUp(): void
    {
        $this->configDir = $this->pmssMakeTempDir('pmss-quota-apply-config-');
        $this->stateDir = $this->pmssMakeTempDir('pmss-quota-apply-state-');
        $this->pmssEnsureDir($this->configDir.'/users');
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $this->configDir, 'PMSS_STATE_DIR' => $this->stateDir], true);
    }

    private function store(): \UserConfigStore
    {
        return new \UserConfigStore($this->configDir);
    }

    private function storeRecord(array $extra = []): void
    {
        $this->assertTrue($this->store()->set('fixtur01', $extra + ['ramMiB' => 512, 'rtorrentPort' => 4242, 'quota' => 900, 'quotaBurst' => 1125]));
    }

    /** Resolver stub: any non-empty name is an account, returned as given. */
    private function account(): callable
    {
        return static function (string $raw): ?array {
            return $raw === '' ? null : ['name' => $raw, 'uid' => 1001];
        };
    }

    /** Run the CLI body with a recording apply stub; returns [exit code, applied calls]. Diagnostics go to stderr. */
    private function runCli(array $args, bool $applyOk = true): array
    {
        $applied = [];
        $rc = \pmssUserQuotaApplyCli(array_merge(['userQuotaApply.php'], $args), $this->account(),
            static function (array $user) use (&$applied, $applyOk): bool {
                $applied[] = $user;
                return $applyOk;
            }, $this->store());
        return [$rc, $applied];
    }

    /** Run the CLI body with the real apply (dry run, so setquota is only recorded). */
    private function runReal(array $args, ?callable $resolve): int
    {
        ob_start();
        $rc = \pmssUserQuotaApplyCli(array_merge(['userQuotaApply.php'], $args), $resolve, null, $this->store());
        ob_end_clean();
        return $rc;
    }

    private function dryRunHome(): void
    {
        $this->pmssMakeTrackedHomeRoot('pmss-quota-apply-home-');
        $this->pmssTrackEnvOverrides(['PMSS_DRY_RUN' => '1'], true);
        $this->pmssResetRuntimeProfile();
    }

    public function testAppliesThenStoresQuotaAndBurstKeepingEveryOtherKey(): void
    {
        $this->storeRecord(['customNote' => 'keep-me', 'dockerEnabled' => true]);
        [$rc, $applied] = $this->runCli(['fixtur01', '1000']);
        $this->assertSame(0, $rc);
        $this->assertSame([['name' => 'fixtur01', 'quota' => 1000]], $applied);
        $stored = $this->store()->get('fixtur01');
        $this->assertSame([1000, 1250, 512, 4242, 'keep-me', true],
            [$stored['quota'], $stored['quotaBurst'], $stored['ramMiB'], $stored['rtorrentPort'], $stored['customNote'], $stored['dockerEnabled']]);
        // Odd GiB rounds like userConfig.php: round(101 * 1.25) = 126.
        $this->assertSame(0, $this->runCli(['fixtur01', '101'])[0]);
        $this->assertSame(126, $this->store()->get('fixtur01')['quotaBurst']);
    }

    public function testRealApplyWritesBlockLimitsAndFileLimitsAtTheHostRatio(): void
    {
        $this->dryRunHome();
        $this->pmssWriteFile($this->configDir.'/quotaFilesPerGiB', '2000');
        $this->storeRecord();
        $this->assertSame(0, $this->runReal(['fixtur01', '1000'], $this->account()));
        $this->assertStringContainsString("setquota 'fixtur01' 1048576000 1310720000 2000000 2500000 -a", implode("\n", $this->pmssProfileCommands()));
        $this->assertSame(1000, $this->store()->get('fixtur01')['quota']);
    }

    public function testFailedApplyStoresNothing(): void
    {
        $this->storeRecord();
        [$rc, $applied] = $this->runCli(['fixtur01', '1000'], false);
        $this->assertSame([1, 1], [$rc, count($applied)]);
        $stored = $this->store()->get('fixtur01');
        $this->assertSame([900, 1125], [$stored['quota'], $stored['quotaBurst']]);
    }

    public function testStoreWriteFailureIsExitThreeAfterTheApply(): void
    {
        // No stored record: the store refuses a payload without the required baseline keys.
        [$rc, $applied] = $this->runCli(['fixtur01', '1000']);
        $this->assertSame([3, 1], [$rc, count($applied)]);
        $this->assertSame(null, $this->store()->get('fixtur01'));
    }

    public function testBadArgumentsExitTwoWithoutApplying(): void
    {
        $this->storeRecord();
        foreach ([[], ['fixtur01'], ['fixtur01', '1000', 'x'], ['fixtur01', '0'], ['fixtur01', '-5'], ['fixtur01', '1.5'],
            ['fixtur01', '1e3'], ['fixtur01', ' 5'], ['fixtur01', '5 '], ['fixtur01', ''], ['fixtur01', '99999999'], ['fixtur01', '0x10'], ['', '5']] as $args) {
            [$rc, $applied] = $this->runCli($args);
            $this->assertSame([2, []], [$rc, $applied], json_encode($args));
        }
        $this->assertSame(900, $this->store()->get('fixtur01')['quota']);
    }

    public function testHostileUsernamesAreRefusedBeforeAnyCommand(): void
    {
        $this->dryRunHome();
        $this->storeRecord();
        foreach (['fixtur01; touch /tmp/pwn', '$(id)', '`id`', "fixtur01\ntouch", 'fix tur', '../fixtur01', '-r', 'FIXTUR01 x', '/etc'] as $name) {
            // The real account resolver and the real apply: nothing may reach a command line.
            $this->assertSame(2, $this->runReal([$name, '5'], null), json_encode($name));
        }
        $this->assertSame('', implode("\n", $this->pmssProfileCommands()));
        // Even a resolver that let a hostile name through cannot make it more than one quoted argument: the apply refuses it.
        $hostile = static function (string $raw): array {
            return ['name' => 'fixtur01; touch /tmp/pwn', 'uid' => 1001];
        };
        $this->assertSame(1, $this->runReal(['x', '5'], $hostile));
        $this->assertSame('', implode("\n", $this->pmssProfileCommands()));
        // A well-formed name is passed as one single-quoted argument.
        $this->assertSame(0, $this->runReal(['fixtur01', '5'], $this->account()));
        $this->assertStringContainsString("setquota 'fixtur01' ", implode("\n", $this->pmssProfileCommands()));
    }

    public function testAccountResolverRequiresAnExistingAccount(): void
    {
        $this->assertSame(null, \pmssUserQuotaApplyAccount('nosuchacct'));
        $this->assertSame(null, \pmssUserQuotaApplyAccount('root'));
    }

    public function testUtilIsThinAndTheLibraryStartsNoProcess(): void
    {
        $util = $this->pmssRepoPath('scripts/util/userQuotaApply.php');
        $this->assertTrue(is_executable($util));
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/userQuotaApply.php', ["pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserQuotaApplyCli')"], ['runStep(', 'exec(']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/quotaApplyCli.php', ['userApplyDiskQuota', 'persist('], ['runStep(', 'exec(', 'kill', 'proc_open', 'shell_exec']);
    }
}
