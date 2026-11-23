<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/usageAlertDelivery.php';

class UsageAlertsTest extends TestCase
{
    public function testThresholdBoundariesAreDeterministic(): void
    {
        $this->assertSame([], \pmssUsageAlertsConditionsBuild(79.9, 89.9, []));
        $this->assertSame(['traffic'], array_keys(\pmssUsageAlertsConditionsBuild(80.0, null, [])));
        $this->assertSame(['disk'], array_keys(\pmssUsageAlertsConditionsBuild(null, 90.0, [])));
        $this->assertSame(['traffic', 'disk'], array_keys(\pmssUsageAlertsConditionsBuild(125.0, 100.0, [])));
        $this->assertSame([], \pmssUsageAlertsConditionsBuild(null, null, []));
    }

    public function testOnlyFailedServicesTriggerAndLabelsAreSanitized(): void
    {
        $conditions = \pmssUsageAlertsConditionsBuild(null, null, ['apps' => [
            'sonarr' => ['label' => "Sonarr\nInjected", 'state' => 'failed'],
            'radarr' => ['label' => 'Radarr', 'state' => 'stopped'],
        ]]);

        $this->assertSame(['service'], array_keys($conditions));
        $this->assertSame('Monitored services reported down: SonarrInjected.', $conditions['service']);
    }

    public function testRootArtifactMetadataRejectsTenantWriteAccess(): void
    {
        $regular = 0100000;
        $this->assertTrue(\pmssUsageAlertsRootArtifactMetadataIsTrusted(['mode' => $regular | 0664, 'uid' => 0, 'gid' => 0]));
        $this->assertTrue(\pmssUsageAlertsRootArtifactMetadataIsTrusted(['mode' => $regular | 0640, 'uid' => 0, 'gid' => 1000]));
        $this->assertFalse(\pmssUsageAlertsRootArtifactMetadataIsTrusted(['mode' => $regular | 0660, 'uid' => 0, 'gid' => 1000]));
        $this->assertFalse(\pmssUsageAlertsRootArtifactMetadataIsTrusted(['mode' => $regular | 0644, 'uid' => 1000, 'gid' => 1000]));
        $this->assertFalse(\pmssUsageAlertsRootArtifactMetadataIsTrusted(['mode' => 0120777, 'uid' => 0, 'gid' => 0]));
    }

    public function testDeliveryMarkersTrackCrossingAndReset(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-usage-alert-state-');
        $active = ['traffic' => 'traffic warning', 'disk' => 'disk warning'];

        $this->assertSame($active, \pmssUsageAlertsNewConditions('fixtur01', $active, $stateDir));
        $this->assertTrue(\pmssUsageAlertsStateRecord('fixtur01', ['traffic' => $active['traffic']], $stateDir));
        $this->assertSame(['disk' => 'disk warning'], \pmssUsageAlertsNewConditions('fixtur01', $active, $stateDir));
        \pmssUsageAlertsStateClear('fixtur01', ['disk' => 'disk warning'], $stateDir);
        $this->assertFalse(file_exists($stateDir.'/fixtur01.traffic'));
        $this->assertSame($active, \pmssUsageAlertsNewConditions('fixtur01', $active, $stateDir));
    }

    public function testStatePathsRejectUnknownKeysAndInvalidUsers(): void
    {
        $this->assertSame(null, \pmssUsageAlertsStatePath('../fixtur01', 'traffic', '/tmp/state'));
        $this->assertSame(null, \pmssUsageAlertsStatePath('fixtur01', 'unknown', '/tmp/state'));
        $this->assertSame('/tmp/state/fixtur01.disk', \pmssUsageAlertsStatePath('fixtur01', 'disk', '/tmp/state'));
    }

    public function testEnvelopeContainsOnlyCustomerFacingDetails(): void
    {
        $envelope = \pmssUsageAlertEnvelopeBuild(
            'fixtur01',
            'fixtur01@example.com',
            ['traffic' => 'Traffic usage is 80.0% of the monthly allowance.'],
            'host.example.com'
        );

        $this->pmssAssertArraySubsetSame(['from' => 'usage-alerts@host.example.com', 'to' => 'fixtur01@example.com'], $envelope);
        $this->assertStringContainsString('Subject: [PMSS Usage Alert] account fixtur01', $envelope['data']);
        $this->assertStringContainsString('Traffic usage is 80.0%', $envelope['data']);
        $this->assertStringNotContainsString('/home/', $envelope['data']);
    }

    public function testCronAndFailClosedDeliveryOrderingAreWired(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/root.cron', [
            '/scripts/cron/usageAlertsNotify.php',
            '/var/log/pmss/usageAlerts.log',
        ]);
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/lib/user/usageAlertDelivery.php',
            ['if ($recipient === null)', 'pmssSupportMailSend(', 'pmssUsageAlertsStateRecord('],
            'Usage alert delivery wiring missing: ',
            'Notification state must be recorded only after successful mail delivery: '
        );
        $this->pmssAssertRepoFileNotContainsString(
            'scripts/cron/usageAlertsNotify.php',
            '$exception->getMessage()',
            'Usage alert cron must not log transport diagnostics that may contain the recipient.'
        );
    }
    public function testConditionKeysAreSortedNamesOnly(): void
    {
        $active = [
            'traffic' => 'Traffic usage is 85.0% of the monthly allowance.',
            'disk' => 'Disk usage is 95.0% of quota.',
            'service' => 'Monitored services reported down: Sonarr.',
        ];

        $this->assertSame(['disk', 'service', 'traffic'], \pmssUsageAlertsConditionKeys($active));
        $this->assertSame('disk,traffic', implode(',', \pmssUsageAlertsConditionKeys(array_diff_key($active, ['service' => 1]))));
        $this->assertSame([], \pmssUsageAlertsConditionKeys([]));
        $this->assertStringNotContainsString('85.0', implode(',', \pmssUsageAlertsConditionKeys($active)));
    }

    public function testOptedInAccountWithoutRecipientStillReportsItsStatus(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-usage-alert-norecipient-');
        $noRecipient = new class extends \UserConfigStore {
            public function __construct() {}
            public function readNotificationEmail(string $username): ?string { return null; }
        };
        $active = ['traffic' => 'traffic warning', 'disk' => 'disk warning'];

        $this->assertSame('recipient_missing', \pmssUsageAlertsNotifyConditions('fixtur01', $active, $stateDir, $noRecipient));
        $this->assertSame('recipient_missing', \pmssUsageAlertsNotifyConditions('fixtur01', [], $stateDir, $noRecipient));
    }

    public function testOptedInAccountWithRecipientAndNothingActiveIsClear(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-usage-alert-clear-');
        $withRecipient = new class extends \UserConfigStore {
            public function __construct() {}
            public function readNotificationEmail(string $username): ?string { return 'fixtur01@example.com'; }
        };

        $this->assertSame('clear', \pmssUsageAlertsNotifyConditions('fixtur01', [], $stateDir, $withRecipient));
    }

    public function testDisabledAccountKeepsItsLineAndReportsNoConditionKeys(): void
    {
        // No passwd entry for the fixture name, so the account is not opted in whatever its marker says.
        $keys = ['stale'];
        $stateDir = $this->pmssMakeTempDir('pmss-usage-alert-disabled-');
        putenv('PMSS_USAGE_ALERTS_STATE_DIR='.$stateDir);
        try {
            $this->assertSame('disabled', \pmssUsageAlertsNotifyUser('fixtur01', null, null, null, $keys));
        } finally {
            putenv('PMSS_USAGE_ALERTS_STATE_DIR');
        }
        // A disabled account's by-reference keys are never filled in, so the cron adds no conditions= field.
        $this->assertSame(['stale'], $keys);
    }

    public function testCronLogsConditionKeysOnlyForOptedInAccountsAndReadsThemBeforeTheRecipientCheck(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/cron/usageAlertsNotify.php',
            ['$conditionKeys = null;', 'pmssUsageAlertsNotifyUser($user, null, null, null, $conditionKeys)',
                "' result='.\$status", "\$conditionKeys === null ? '' : ' conditions='.implode(',', \$conditionKeys)"],
            'Usage alert cron must log the condition keys after result=: '
        );
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/lib/user/usageAlertDelivery.php',
            ['pmssUsageAlertsOptInEnabled(', "return 'disabled';", 'pmssUsageAlertsConditionsRead(', '$conditionKeys = pmssUsageAlertsConditionKeys($active);',
                'pmssUsageAlertsNotifyConditions(', 'readNotificationEmail(', "return 'recipient_missing';"],
            'Conditions must be read after the opt-in check and before the recipient check: '
        );
    }
    private function conditionsFor(?float $traffic, ?float $disk, array $apps): array
    {
        return \pmssUsageAlertsConditionsBuild($traffic, $disk, ['apps' => $apps]);
    }

    public function testTrafficConditionAloneLogsOnlyItsKey(): void
    {
        $this->assertSame(['traffic'], \pmssUsageAlertsConditionKeys($this->conditionsFor(85.0, 10.0, [])));
    }

    public function testDiskConditionAloneLogsOnlyItsKey(): void
    {
        $this->assertSame(['disk'], \pmssUsageAlertsConditionKeys($this->conditionsFor(10.0, 95.0, [])));
    }

    public function testServiceConditionAloneLogsOnlyItsKey(): void
    {
        $apps = ['sonarr' => ['state' => 'failed', 'label' => 'Sonarr'], 'radarr' => ['state' => 'running']];
        $this->assertSame(['service'], \pmssUsageAlertsConditionKeys($this->conditionsFor(10.0, 10.0, $apps)));
    }

    public function testAllThreeConditionsLogTogetherInSortedOrder(): void
    {
        $apps = ['sonarr' => ['state' => 'failed']];
        $this->assertSame('disk,service,traffic', implode(',', \pmssUsageAlertsConditionKeys($this->conditionsFor(99.0, 99.0, $apps))));
    }

    public function testRecipientAndNewConditionSendsThenARerunIsUnchangedWithTheSameKeys(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-usage-alert-rerun-');
        $store = new class extends \UserConfigStore {
            public function __construct() {}
            public function readNotificationEmail(string $username): ?string { return 'fixtur01@example.com'; }
        };
        $sent = 0;
        $transport = static function () use (&$sent): void { $sent++; };
        $active = ['disk' => 'Disk usage is 95.0% of quota.'];

        $this->assertSame('sent', \pmssUsageAlertsNotifyConditions('fixtur01', $active, $stateDir, $store, $transport, ['x' => 1]));
        // Second cron run, same condition: no second mail; the keys a caller logs are the same.
        $this->assertSame('unchanged', \pmssUsageAlertsNotifyConditions('fixtur01', $active, $stateDir, $store, $transport, ['x' => 1]));
        $this->assertSame(1, $sent);
        $this->assertSame(['disk'], \pmssUsageAlertsConditionKeys($active));
    }

    public function testExistingFourArgumentCallersAreUnaffected(): void
    {
        $stateDir = $this->pmssMakeTempDir('pmss-usage-alert-oldcaller-');
        putenv('PMSS_USAGE_ALERTS_STATE_DIR='.$stateDir);
        try {
            // The pre-change call shape (no fifth argument) still returns the same status string.
            $this->assertSame('disabled', \pmssUsageAlertsNotifyUser('fixtur01', null, null, null));
        } finally {
            putenv('PMSS_USAGE_ALERTS_STATE_DIR');
        }
        $parameter = (new \ReflectionFunction('pmssUsageAlertsNotifyUser'))->getParameters()[4];
        $this->assertSame('conditionKeys', $parameter->getName());
        $this->assertTrue($parameter->isOptional());
        $this->assertTrue($parameter->isPassedByReference());
    }

    public function testLoggedKeysNeverCarryValuesOrAddresses(): void
    {
        $active = ['traffic' => 'Traffic usage is 85.0% of the monthly allowance.', 'disk' => 'Disk usage is 95.0% of quota.'];
        $line = implode(',', \pmssUsageAlertsConditionKeys($active));
        $this->assertSame('disk,traffic', $line);
        $this->assertStringNotContainsString('%', $line);
        $this->assertStringNotContainsString('@', $line);
    }
}
