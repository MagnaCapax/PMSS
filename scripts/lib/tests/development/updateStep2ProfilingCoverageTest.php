<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2ProfilingCoverageTest extends TestCase
{
    /** Lock the externally visible phase order while orchestration stays table-driven. */
    public function testUpdateStep2ProfiledPhaseOrderSnapshot(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/update-step2.php');
        preg_match_all("/^\\s+\\['([^']+)', '(pmss[^']+)'(?:,|\\])/m", $source, $matches, PREG_SET_ORDER);
        $steps = [];
        foreach ($matches as $match) {
            $steps[] = $match[1].'|'.$match[2];
        }
        $this->assertSame(25, count($steps), 'Profiled batch step count changed');
        $this->assertSame('b6cb367121af2552c0c81f4a05d906936a4412c38396f39a4fa889d8e55e793b', hash('sha256', implode("\n", $steps)), 'Profiled batch order or callable changed');
    }

    public function testUpdateStep2UsesProfiledWrappersForModuleCalls(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/runtime/profile.php' => ['required' => ['function pmssRunProfiledStep(', 'function pmssRunProfiledCallable(', 'function pmssRunProfiledCallableBatch(']],
            'scripts/util/update-step2.php' => [
                'required' => [
                    'Preparing noninteractive apt defaults',
                    "foreach (array('DEBIAN_FRONTEND=noninteractive', 'APT_LISTCHANGES_FRONTEND=none', 'UCF_FORCE_CONFOLD=1', 'UCF_FORCE_CONFNEW=0', 'UCF_FORCE_CONFDEF=1', 'NEEDRESTART_MODE=a') as \$envDefault) { putenv(\$envDefault); }",
                    'Refreshing package repositories',
                    'Applying distro dpkg baseline selections',
                    'pmssRunProfiledCallableBatch([',
                    'Configuring web stack',
                    'Updating all user environments',
                    'Ensuring network template baseline',
                ],
                'forbidden' => [
                    '#TODO profiling (GH #120)' => 'Profiling TODO marker should be removed once coverage is wired',
                    'function pmssUpdateStep2Run'.'ClassifiedCallable(' => 'Classified callable handling should be folded into pmssRunProfiledCallable',
                ],
                'ordered' => [[
                    'needles' => [
                        "runStep('Refreshing root cron configuration'",
                        "pmssRunProfiledCallable('Refreshing MOTD'",
                        'pmssProfileSummary();',
                    ],
                    'missingPrefix' => 'Missing final profiling step: ',
                    'orderPrefix' => 'Profile summary should run after: ',
                ], [
                    'needles' => [
                        "pmssRunProfiledCallable('Completing pending dpkg configurations'",
                        "runStep('Attempting apt fix-broken install (pre-package phase)'",
                        "pmssRunProfiledCallable('Refreshing package repositories'",
                        "\$dpkgBaselineOk = pmssRunProfiledCallable('Applying distro dpkg baseline selections'",
                        "putenv('PMSS_PACKAGE_PHASE=complete')",
                    ],
                    'missingPrefix' => 'Missing package phase step: ',
                    'orderPrefix' => 'Package phase order changed at: ',
                ]],
            ],
        ]);
    }
}
