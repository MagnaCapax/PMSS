<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** The CI fix pass gets the failing steps of each job, and every launcher workspace follows one naming convention. */
class CiFailedStepsTest extends TestCase
{
    private const LOG = "2026-11-16T04:10:30.1Z ##[group]Run scripts/testing/test-php.sh\n"
        ."2026-11-16T04:10:30.2Z ##[endgroup]\n"
        ."2026-11-16T04:10:37.9Z Tests: 12, Failures: 0\n"
        ."2026-11-16T04:10:38.0Z ##[group]Run bash scripts/testing/doctrine-lint.sh\n"
        ."2026-11-16T04:10:38.1Z ##[endgroup]\n"
        ."2026-11-16T04:10:41.5Z doctrine lint: ADR Category invalid ('safety'): docs/adr/0282-x.md\n"
        ."2026-11-16T04:10:45.2Z doctrine lint: 1 issue(s) found\n"
        ."2026-11-16T04:10:45.3Z ##[error]Process completed with exit code 1.\n"
        ."2026-11-16T04:10:45.4Z ##[group]Run actions/checkout post\n"
        ."2026-11-16T04:10:45.5Z Post job cleanup.\n";

    private function runSteps(array $logs): array
    {
        $dir = $this->pmssMakeTempDir('pmss-ci-steps-');
        $args = ['php', $this->pmssRepoPath('development/lib/ci-failed-steps.php'), $dir.'/out.log'];
        foreach ($logs as $name => $body) {
            $args[] = $this->pmssWriteFile($dir.'/'.$name, $body);
        }
        $result = $this->pmssExecShellCommand($this->pmssBuildArgvCommand($args));
        $out = is_file($dir.'/out.log') ? (string) file_get_contents($dir.'/out.log') : null;
        return [$result['rc'], $out];
    }

    public function testKeepsTheFailingStepWithItsErrorAndDropsPassingSteps(): void
    {
        [$rc, $out] = $this->runSteps(['job-build.log' => self::LOG]);
        $this->assertSame(0, $rc);
        $this->assertStringContainsAndOmitsStrings([
            "ADR Category invalid ('safety')", 'Run bash scripts/testing/doctrine-lint.sh',
            '##[error]Process completed with exit code 1.',
        ], ['Tests: 12, Failures: 0', 'Post job cleanup.'], (string) $out);
    }

    public function testFailedStepsOfEveryJobAreKeptAndNamed(): void
    {
        $smoke = "T ##[group]Run php -l etc/a.php\nT PHP Parse error in etc/a.php\nT ##[error]Process completed with exit code 255.\n";
        [, $out] = $this->runSteps(['job-build.log' => self::LOG, 'job-smoke.log' => $smoke]);
        $this->assertStringContainsAllStrings(['failed step in job-build.log', 'failed step in job-smoke.log',
            'PHP Parse error in etc/a.php'], (string) $out);
    }

    public function testAGreenLogWritesNoFile(): void
    {
        [$rc, $out] = $this->runSteps(['job-build.log' => "T ##[group]Run scripts/testing/test-php.sh\nT Tests: 3, Failures: 0\n"]);
        $this->assertSame(0, $rc);
        $this->assertTrue($out === null, "no excerpt for a green log");
    }

    public function testAnOversizedStepKeepsItsCommandAndItsEnd(): void
    {
        $big = "T ##[group]Run scripts/testing/test-php.sh\n".str_repeat("T noise line\n", 80000)
            ."T FAIL: SomeTest::testThing\nT ##[error]Process completed with exit code 1.\n";
        [, $out] = $this->runSteps(['job-build.log' => $big]);
        $this->assertTrue(strlen((string) $out) <= 524288 + 200, 'excerpt stays within its cap');
        $this->assertStringContainsAllStrings(['Run scripts/testing/test-php.sh',
            'FAIL: SomeTest::testThing', 'bytes cut'], (string) $out);
    }

    public function testManyFailedStepsStillFitTheCap(): void
    {
        $log = '';
        for ($i = 0; $i < 300; $i++) {
            $log .= "T ##[group]Run step-$i\n".str_repeat("T output of step $i\n", 300)."T ##[error]step $i failed\n";
        }
        [, $out] = $this->runSteps(['job-build.log' => $log]);
        $this->assertTrue(strlen((string) $out) <= 524288 + 300 * 80, 'many sections stay within the cap');
        $this->assertStringContainsString('step 299 failed', (string) $out);
    }

    /** Every launcher names its workspace <name>-codex. */
    public function testEveryLauncherWorkspacePrefixEndsInCodex(): void
    {
        $found = 0;
        foreach (glob($this->pmssRepoPath('development').'/{,lib/}*.sh', GLOB_BRACE) as $file) {
            preg_match_all('/codex_make_temp_workspace\s+([A-Za-z0-9_-]+)\)/', (string) file_get_contents($file), $m);
            foreach ($m[1] as $prefix) {
                $found++;
                $this->assertTrue(preg_match('/^[a-z0-9][a-z0-9-]*-codex$/', $prefix) === 1, basename($file).": workspace prefix $prefix");
            }
        }
        $this->assertTrue($found >= 4, 'launcher workspace prefixes found');
    }
}
