<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PmssLogrotatePolicyTest extends TestCase
{
    public function testHighVolumeSharedPmssLogsRotateBySize(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/template.logrotate.pmss',
            [
                '/var/log/pmss/users.log /var/log/pmss/users.jsonl /var/log/pmss/trafficStats.log',
                'daily',
                'rotate 7',
                'size 100M',
                'copytruncate',
                'su root root',
            ],
            'logrotate policy is missing: '
        );
    }

    public function testWatchdogLogsStayCoveredByCheckWildcard(): void
    {
        $policy = $this->pmssReadRepoFile('etc/seedbox/config/template.logrotate.pmss');

        $this->assertMatches(
            '#/var/log/pmss/check\*\.log\s*\{[^}]*maxsize 64M[^}]*copytruncate#s',
            $policy,
            'checkInstances and checkLighttpdInstances must stay size-bounded'
        );
    }

    public function testUpdateStep2RefreshesAndVerifiesLogrotatePolicy(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertStringContainsAllStrings([
            "\$logrotateTarget = '/etc/logrotate.d/pmss-update';",
            "install -m 0644 -T %s %s",
            "Verifying PMSS logrotate policy matches template",
            "cmp -s %s %s",
            "PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED",
            "'logrotate_policy_install_or_verify_failed'",
        ], $src);

        $installPos = strpos($src, "\$logrotateInstallRc = runStep(");
        $verifyPos = strpos($src, "\$logrotateVerifyRc = runStep(");
        $failurePos = strpos($src, "'logrotate_policy_install_or_verify_failed'");

        $this->assertTrue($installPos !== false, 'Missing logrotate install step');
        $this->assertTrue($verifyPos !== false, 'Missing logrotate verification step');
        $this->assertTrue($failurePos !== false, 'Missing logrotate failure classification');
        $this->assertTrue($verifyPos > $installPos, 'Logrotate verification must run after install');
        $this->assertTrue($failurePos > $verifyPos, 'Logrotate failure handling must follow verification');
    }
}
