<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Check quota recovery state corruption without executing commands or reading quota files. */
class QuotaRecoveryStateInputSafetyTest extends TestCase
{
    public function testCorruptObservationsCannotAuthorizeRecoveryOrHeaderReads(): void
    {
        $user = "user quota on /home (/dev/fixture0) is off\n";
        $group = "group quota on /home (/dev/fixture0) is off\n";
        $off = $user.$group;
        $both = ['user', 'group'];
        $checks = ['quotacheck -vumn /home', 'quotacheck -vgmn /home'];
        // Preserve complete valid lines around corruption, including within the device field.
        $cases = [];
        foreach (["\0".$off, $off."\0", $user."\0\n".$group,
            str_replace('fixture0', "fi\0xture0", $off), $off."diagnostic\0text\n"] as $stdout) {
            $cases[] = [false, $both, 0, $stdout, '', []];
        }
        $cases[] = [false, $both, 0, $off, "\0", []];
        $cases[] = [false, $both, 0, $user, $group."\0", []];
        $cases[] = [false, ['user'], 0, $user, "diagnostic\0text", []];
        // Normal command bytes, stderr merging, line order and absent files stay unchanged.
        $cases[] = [false, $both, 0, $off, '', $checks];
        $cases[] = [false, $both, 0, $group.$user, '', $checks];
        $cases[] = [false, $both, 0, $user, $group, $checks];
        $cases[] = [false, ['user'], 0, $user, '', [$checks[0]]];
        $cases[] = [false, $both, 1, $off, '', []];
        $cases[] = [false, $both, 0, '', '', []];
        $cases[] = [false, $both, 0, $user, '', []];
        $cases[] = [false, [], 0, $off, '', []];
        $cases[] = [false, null, 0, $off, '', []];
        $cases[] = [true, $both, 0, $off."\0", '', ['quotacheck -avugmn']];

        $script = <<<'PHP'
namespace QuotaRecoveryStateFixture;
function pmssQuotaHomeDeclaredTypes($log, $path, $context) { return $GLOBALS['types']; }
function is_file($path) {
    $GLOBALS['headers'][] = $path;
    return false;
}
PHP;
        foreach (['commands.php', 'recovery.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/quota/'.$module, 'QuotaRecoveryStateFixture');
        }
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_QUOTA_RECOVERY_STATE'), true) as [$quotaOffOk, $types, $rc, $stdout, $stderr]) {
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
        $results = $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_QUOTA_RECOVERY_STATE' => json_encode($cases),
        ]);
        $this->assertSame(count($cases), count($results));
        foreach ($cases as $index => [$quotaOffOk, $types, $rc, $stdout, $stderr, $expected]) {
            $queried = !$quotaOffOk && $types !== null && $types !== [];
            $headers = [];
            if (!$quotaOffOk && $expected !== []) {
                foreach ($types as $type) $headers[] = '/fixture/quota/aquota.'.$type;
            } elseif ($queried && $rc === 0 && $stdout === $user && $stderr === '') {
                // A later missing group line retains the earlier harmless user header probe.
                $headers[] = '/fixture/quota/aquota.user';
            }
            $this->assertSame([$expected, $queried ? ['quotaon -p /home'] : [], $headers],
                $results[$index], 'case '.$index);
        }
    }
}
