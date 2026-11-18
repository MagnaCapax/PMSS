<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/systemdSlicesEnsure.php';

class SystemdSlicesCharacterizationTest extends TestCase
{
    /** Snapshot complete setup traces, including bytes, diagnostics and failure boundaries. */
    public function testSetupPreservesPolicyAndOperationOrder(): void
    {
        $cases = [
            'defaults' => [],
            'test-mode' => ['testMode' => true],
            'small-host' => ['memory' => 512, 'cpu' => 1],
            'undetected-host' => ['memory' => 0, 'cpu' => 0],
            'numeric-policy' => ['policy' => ['memoryHighMiB' => '900', 'memoryMaxMiB' => 999999,
                'cpuWeight' => 300, 'ioWeight' => '400', 'tasksMax' => 8000, 'cpuQuotaPercent' => 125,
                'limitNoFileSoft' => 8192, 'limitNoFileHard' => 4096]],
            'invalid-policy' => ['policy' => ['memoryHighMiB' => 'bad', 'memoryMaxMiB' => 'bad',
                'cpuWeight' => [], 'tasksMax' => 'bad', 'cpuQuotaPercent' => 'bad', 'limitNoFileHard' => -1]],
            'infinity' => ['policy' => ['cpuQuotaPercent' => 'INFINITY', 'limitNoFileHard' => 4096]],
            'missing-template' => ['missingTemplate' => true, 'legacy' => true],
            'unreadable-template' => ['unreadableTemplate' => true, 'policy' => ['ioLatencyMs' => 5]],
            'slice-write-failure' => ['failedWrite' => '/15-pmss.conf'],
            'root-write-failure' => ['failedWrite' => '/99-zz-pmss-unlimited.conf'],
            'legacy-shadow' => ['legacy' => true, 'unlinkFailure' => true],
            'unchanged-shadow' => ['shadow' => true,
                'shadowContent' => "# PMSS: override legacy TasksMax cap (shadow 99-pmss.conf)\n[Slice]\nTasksMax=16384\n"],
            'list-failure' => ['commandRc' => 1],
            'empty-list' => ['units' => ''],
            'v1-io' => ['policy' => ['ioLatencyMs' => 5, 'mounts' => ['/home' => ['ioWeight' => 200, 'readBw' => '5 M', 'readIops' => 9], '/absent' => []]],
                'devices' => ['/home' => '/dev/md0']],
            // Existing defensive v2 behavior is characterized without adding v2 functionality.
            'v2-io' => ['mode' => 'v2', 'policy' => ['ioLatencyMs' => 5, 'mounts' => ['/home' => ['ioWeight' => 200, 'writeBw' => '5M']]],
                'devices' => ['/home' => '/dev/md0']],
            'unsafe-device' => ['mode' => 'v2', 'policy' => ['ioLatencyMs' => 5, 'mounts' => ['/home' => ['ioWeight' => 200]]],
                'devices' => ['/home' => "/dev/bad\npath"]],
            'missing-device' => ['mode' => 'v2', 'policy' => ['ioLatencyMs' => 5]],
        ];
        // SHA-256 of JSON traces captured from the original controller on the testing VM.
        // ADR 0296 inserts one aggregate user.slice drop-in step after the shadow install (it reads the balloon and
        // min_free, then logs both): the 16 cases that reach it were re-captured on the testing VM; missing-template, unreadable-template and
        // slice-write-failure stop before it and keep their original hashes.
        $expected = [
            'defaults' => 'eac3d65cbe2ab194e066c9e06e44ba227a1808ec9162e941514215d84e977b0a',
            'test-mode' => 'd40ce0025bea4e6506489bf4f19914d4a987b2cdd3cfc4ba8598461b1f0f809b',
            'small-host' => 'b3c6b957de1d3de22ad55994c89adefc4f4eccb0adcca4f6e53c82abba05e275',
            'undetected-host' => '73e988f75a65e8ac6491269c8695c260814ea81040df83479e46b9deb4c08368',
            'numeric-policy' => '236b1e036fe8067c695e15f59538e5094ac09386e81be7fa28b0eba0387f49fb',
            'invalid-policy' => '938a2ad5ffc78b6a69b14ac5ac0d1188c820e16abd627eee301d2cae3f302f0f',
            'infinity' => 'b59eb8e738a89326b2145be2ae90fd07cbe12bad5fa15f2ff1a6761163e887da',
            'missing-template' => 'cd2b103f32887feeb9bb980975ca9a62629b39825298bf77f773c2635d4f2868',
            'unreadable-template' => 'b6b9e5f7f50ea4385f35785ece0b5184e13280a4b41bd820c2006fc101b43897',
            'slice-write-failure' => '7825cec234751d44fecde31f204ab80ac7babd693bd5522a43b574a7697816b4',
            'root-write-failure' => '24270730c926fa3bab78c146386ee72109d3be7d0933d0385c8948056dfe2dff',
            'legacy-shadow' => '813797c0df427a969aaf1176ccc32170342bfd912ddacf99c50cd80c87be1a03',
            'unchanged-shadow' => '74c93031cf3d217204ba10b3b6b172ddab6a851f9531e8729750a3de1aa0a9fc',
            'list-failure' => '72854123b02f03e2642923302b2513aec446257494927cb42d7517797b0c8f14',
            'empty-list' => '72854123b02f03e2642923302b2513aec446257494927cb42d7517797b0c8f14',
            'v1-io' => '9245d5385547b27fa7d0551558db3b1d631bc44765a4e9a282344cdc771582e5',
            'v2-io' => '2229d45eda6fb020167898334be00e5b6caf7d3e7222d219fc37abc0786c20d0',
            'unsafe-device' => '756d04f4b3c5fca05530ce03210d516f3a02c03e18b3737aa21ee60e28809cb3',
            'missing-device' => 'f10be30c99dbdccda9c37daa70b75572d94c274148f1d5711d09e416db802c4b',
        ];
        foreach ($cases as $caseName => $options) {
            // Reflect the actual production helpers with in-memory dependencies.
            $script = 'namespace SystemdSlicesCharacterization; require '.var_export($this->pmssRepoPath('scripts/lib/tests/fixtures/systemdSlicesCharacterization.php'), true).';';
            $functions = ['pmssCgroupPolicyIoPairs', 'pmssCgroupPolicyIoPairSpecs', 'pmssCgroupPolicyPositiveValue',
                'pmssConfigTextRows', 'pmssConfigRows', 'pmssConfigLineColumns', 'pmssConfigLineTrimmed', 'pmssTextLines'];
            foreach (get_defined_functions()['user'] as $name) {
                $function = new \ReflectionFunction($name);
                if (strpos(basename($function->getFileName()), 'systemdSlice') === 0) $functions[] = $name;
            }
            foreach ($functions as $name) {
                $script .= \pmssTestingFunctionExport($name, 'SystemdSlicesCharacterization');
            }
            $script .= '$GLOBALS["sliceCase"] = '.var_export($options, true).'; $GLOBALS["sliceTrace"] = [];';
            $script .= 'pmssEnsureSystemdSlices(static function ($message) { record(["log", $message]); });';
            $trace = $this->pmssRunInlinePhpJson($script.'echo json_encode($GLOBALS["sliceTrace"]);');
            $this->assertSame($expected[$caseName], hash('sha256', json_encode($trace)), $caseName.': '.json_encode($trace));
        }
    }

}
