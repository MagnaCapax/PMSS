<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Check quota observations and header completion without live quota I/O. */
class QuotaRecoveryStateInputSafetyTest extends TestCase
{
    public function testHeaderCompletionGuardsFreshFileRecovery(): void
    {
        // Exercise scripts/lib/quota/recovery.php with memory streams and no quota commands.
        $script = <<<'PHP'
namespace QuotaHeaderCompletionFixture;
function is_file($path) { return true; }
function fopen($path, $mode) { return $GLOBALS['stream'] = \fopen('php://temp', 'w+'); }
function fread($handle, $length) {
    if ($GLOBALS['readFailure'] !== null) throw $GLOBALS['readFailure'];
    return $GLOBALS['header'];
}
function fclose($handle) {
    ++$GLOBALS['closes'];
    \fclose($handle);
    if ($GLOBALS['closeFailure'] !== null) throw $GLOBALS['closeFailure'];
    return $GLOBALS['closed'];
}
function pmssQuotaHomeDeclaredTypes(...$args) { return ['user']; }
function pmssQuotaCommandRun(...$args) {
    return ['rc' => 0, 'output' => 'user quota on /home (/dev/fixture0) is off'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/quota/recovery.php', 'QuotaHeaderCompletionFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([hex2bin('111fc0d900000000'), hex2bin('111fc0d901000000'),
    hex2bin('111fc0d902000000'), hex2bin('2719c0d900000000'), str_repeat("\0", 8), '', 'short', false] as $header) {
    foreach ([true, false] as $closed) {
        foreach (['none', 'read', 'close', 'both'] as $fault) {
            foreach ([\RuntimeException::class, \Error::class] as $kind) {
                $GLOBALS['header'] = $header; $GLOBALS['closed'] = $closed; $GLOBALS['closes'] = 0;
                $GLOBALS['readFailure'] = in_array($fault, ['read', 'both'], true) ? new $kind('read') : null;
                $GLOBALS['closeFailure'] = in_array($fault, ['close', 'both'], true) ? new $kind('close') : null;
                $commands = $caught = null;
                try { $commands = pmssQuotaFixCheckCommands(false, null, null, '/fixture/fstab', '/fixture/quota'); }
                catch (\Throwable $error) { $caught = $error; }
                $expectedFailure = $GLOBALS['closeFailure'] ?? $GLOBALS['readFailure'];
                $results[] = [$commands, $caught === $expectedFailure, $GLOBALS['closes'], is_resource($GLOBALS['stream'])];
            }
        }
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $index = 0;
        // Valid headers and read errors retain limits; corrupt/short strings allow fresh recovery.
        foreach ([false, false, true, true, true, true, true, false] as $invalid) {
            foreach ([true, false] as $closed) {
                foreach (['none', 'read', 'close', 'both'] as $fault) {
                    foreach ([0, 1] as $kind) {
                        $expected = $fault === 'none' ? ['quotacheck -vumn'.($closed && $invalid ? 'cb' : '').' /home'] : null;
                        $this->assertSame([$expected, true, 1, false], $results[$index], 'case '.$index);
                        ++$index;
                    }
                }
            }
        }
        $this->assertSame($index, count($results));
    }

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
