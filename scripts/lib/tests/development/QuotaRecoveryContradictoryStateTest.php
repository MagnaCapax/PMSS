<?php
/** Verify contradictory quota state cannot authorize a recovery recount. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class QuotaRecoveryContradictoryStateTest extends TestCase
{
    public function testContradictionsRetainRefusalAndValidReportsKeepCommands(): void
    {
        $off = "user quota on /home (/dev/fixture0) is off\n";
        $group = "group quota on /home (/dev/fixture0) is off\n";
        $both = ['user', 'group'];
        $checks = ['quotacheck -vumn /home', 'quotacheck -vgmn /home'];
        $userHeader = '/fixture/quota/aquota.user';
        $headers = [$userHeader, '/fixture/quota/aquota.group'];
        $cases = [];
        foreach (['user', 'group'] as $type) {
            $on = $type." quota on /home (/dev/fixture0) is on\n";
            // Reject either ordering, another device and a conflicting stderr report.
            foreach ([[$on.$off.$group, ''], [$off.$group.$on, ''],
                [$off.$group, $on], [$on, $off.$group],
                [$off.$group.str_replace('fixture0', 'fixture1', $on), ''],
                [$off.$group.rtrim($on, "\n"), '']] as [$stdout, $stderr]) {
                $cases[] = [false, $both, 0, $stdout, $stderr, [], $type === 'user' ? [] : [$userHeader]];
            }
            // The same protection applies when fstab declares only the affected type.
            $singleOff = $type === 'user' ? $off : $group;
            $cases[] = [false, [$type], 0, $singleOff.$on, '', [], []];
            $cases[] = [false, [$type], 0, $on.$singleOff, '', [], []];
        }
        // Valid report order, duplicates, stderr merging and absent quota files stay supported.
        foreach ([[$off.$group, ''], [$group.$off, ''], [$off.$group.$off, ''],
            [$off, $group], [$off.rtrim($group, "\n"), ''],
            [$off.$group."user quota on /srv (/dev/fixture1) is on\n", '']] as [$stdout, $stderr]) {
            $cases[] = [false, $both, 0, $stdout, $stderr, $checks, $headers];
        }
        $cases[] = [false, ['user'], 0, $off, '', [$checks[0]], [$userHeader]];
        $cases[] = [false, $both, 1, $off.$group, '', [], []];
        $cases[] = [false, $both, 0, '', '', [], []];
        $cases[] = [true, $both, 0, $off."user quota on /home (/dev/fixture0) is on\n", '', ['quotacheck -avugmn'], []];

        $script = <<<'PHP'
namespace QuotaContradictoryStateFixture;
function pmssQuotaHomeDeclaredTypes($log, $path, $context) { return $GLOBALS['types']; }
function is_file($path) {
    $GLOBALS['headers'][] = $path;
    return false;
}
PHP;
        foreach (['commands.php', 'recovery.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/quota/'.$module, 'QuotaContradictoryStateFixture');
        }
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_QUOTA_CONTRADICTIONS'), true) as [$quotaOffOk, $types, $rc, $stdout, $stderr]) {
    $GLOBALS['types'] = $types;
    $GLOBALS['headers'] = [];
    $commands = [];
    $runner = static function ($command) use (&$commands, $rc, $stdout, $stderr) {
        $commands[] = $command;
        return ['rc' => $rc, 'stdout' => $stdout, 'stderr' => $stderr];
    };
    $checks = pmssQuotaFixCheckCommands($quotaOffOk, $runner, null, '/fixture/fstab', '/fixture/quota');
    $results[] = [$checks, $commands, $GLOBALS['headers']];
}
echo json_encode($results);
PHP;
        // Namespaced I/O probes leave all live files and quota commands untouched.
        $results = $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_QUOTA_CONTRADICTIONS' => json_encode($cases),
        ]);
        $this->assertSame(count($cases), count($results));
        foreach ($cases as $index => [$quotaOffOk, $types, $rc, $stdout, $stderr, $expected, $expectedHeaders]) {
            $this->assertSame([$expected, $quotaOffOk ? [] : ['quotaon -p /home'], $expectedHeaders],
                $results[$index], 'case '.$index);
        }
    }
}
