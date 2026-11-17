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
}
