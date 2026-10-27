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
        $expected = [
            'defaults' => 'aacd9c9c053afe91940d0b863a6891eb865360d232a5b8fb70efe3a4b402a4b8',
            'test-mode' => '62ce7663e8f4db0278ac5434ae146a1bc31818a415c9d935ee946b1fc6ea1df6',
            'small-host' => '4e5875e6fee6b37b2d338cfe0c406c06b439a19127c16bceb136f3e646094390',
            'undetected-host' => '5b8647926a91ce8ca3b3765eb08e8b29fa32facffc7d19955342578fbe332be2',
            'numeric-policy' => '94a2ce2f64680c3430027bd02416f21da7d314456108bda7ea7fea00157d064c',
            'invalid-policy' => '319cf5be54f2f9ca8435a0f711f6e30e485279b1e2cb8e3eb6f3df95ef960097',
            'infinity' => 'f0c8cf91e4084a0d5b915ad6f690d9c54d2c9fe88cc1afc428e6533ac09a7064',
            'missing-template' => 'cd2b103f32887feeb9bb980975ca9a62629b39825298bf77f773c2635d4f2868',
            'unreadable-template' => 'b6b9e5f7f50ea4385f35785ece0b5184e13280a4b41bd820c2006fc101b43897',
            'slice-write-failure' => '7825cec234751d44fecde31f204ab80ac7babd693bd5522a43b574a7697816b4',
            'root-write-failure' => '77bd1a0f9959e7ad4b62b6fb22b66236e1483072055775dfe66a118c8aa7df73',
            'legacy-shadow' => '8ebe83ccaa0ef9ae8108cbe5b073b583383eae13fe968404e55c9c12a8965514',
            'unchanged-shadow' => '5fd3bced2f3daf96c8f373f6081f5e4faa3418de28347754440cd456f1cfe44e',
            'list-failure' => 'ddde5e444c481492cf6be4cb08b55d06b01d689f1d961f6c3b5adff8348bb68e',
            'empty-list' => 'ddde5e444c481492cf6be4cb08b55d06b01d689f1d961f6c3b5adff8348bb68e',
            'v1-io' => '8909324f3810f56ff9bf52980448b58ec56c51fb45d4e832ef1722d0589918a5',
            'v2-io' => '811feb36fc6d35e5b0dbf4037df6634a477b480e7d411abb26b443a3ed18b871',
            'unsafe-device' => '05075d7896b75b88ae687ec22b6c498335c8f363ddfe5c9958181872c469ec11',
            'missing-device' => '463989cff5e94f36834de4a8351de50ae6213d73687a99a08f8f753e5bb282cd',
        ];
        foreach ($cases as $name => $options) {
            $trace = $this->setupTrace($options);
            $this->assertSame($expected[$name], hash('sha256', json_encode($trace)), $name.': '.json_encode($trace));
        }
    }

    /** Execute reflected production functions with in-memory dependencies, never a copied flow. */
    private function setupTrace(array $options): array
    {
        $script = 'namespace SystemdSlicesCharacterization; require '.var_export($this->pmssRepoPath('scripts/lib/tests/fixtures/systemdSlicesCharacterization.php'), true).';';
        $functions = ['pmssCgroupPolicyIoPairs', 'pmssCgroupPolicyIoPairSpecs', 'pmssCgroupPolicyPositiveValue',
            'pmssConfigTextRows', 'pmssConfigRows', 'pmssConfigLineColumns', 'pmssConfigLineTrimmed', 'pmssTextLines'];
        foreach (get_defined_functions()['user'] as $name) {
            $function = new \ReflectionFunction($name);
            if (strpos(basename($function->getFileName()), 'systemdSlice') === 0) $functions[] = $name;
        }
        foreach ($functions as $name) {
            $function = new \ReflectionFunction($name);
            $source = implode('', array_slice(file($function->getFileName()), $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1));
            $script .= 'eval('.var_export('namespace SystemdSlicesCharacterization; '.$source, true).');';
        }
        $script .= '$GLOBALS["sliceCase"] = '.var_export($options, true).'; $GLOBALS["sliceTrace"] = [];';
        $script .= 'pmssEnsureSystemdSlices(static function ($message) { record(["log", $message]); });';
        return $this->pmssRunInlinePhpJson($script.'echo json_encode($GLOBALS["sliceTrace"]);');
    }
}
