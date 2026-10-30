<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/bootstrap.php';

class ServiceBootstrapCharacterizationTest extends TestCase
{
    /** Freeze the complete legacy lists independently of the implementation. */
    public function testLegacyNormalizationPreservesBytesDuplicatesAndWarnings(): void
    {
        $ciphers = 'Ciphers aes128-gcm@openssh.com,aes256-gcm@openssh.com,chacha20-poly1305@openssh.com,aes128-ctr,aes192-ctr,aes256-ctr,aes128-cbc,aes192-cbc,aes256-cbc';
        $kex = 'KexAlgorithms curve25519-sha256@libssh.org,ecdh-sha2-nistp256,ecdh-sha2-nistp384,ecdh-sha2-nistp521,diffie-hellman-group-exchange-sha256,diffie-hellman-group-exchange-sha1,diffie-hellman-group14-sha1,diffie-hellman-group1-sha1';
        $macs = 'MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com,umac-128-etm@openssh.com,hmac-sha2-512,hmac-sha2-256,hmac-sha1,hmac-sha1-96,hmac-md5,hmac-md5-96';
        foreach ([
            ['', "\n$ciphers\n$kex\n$macs\n"],
            ["Port 22\r\n", "Port 22\n\n$ciphers\n$kex\n$macs\n"],
            [" cIpHeRs +fixture\nCiphers other\nMACs fixture", "$ciphers\n$ciphers\n$macs\n$kex\n"],
            ["# Ciphers fixture\nCiphers\n", "# Ciphers fixture\nCiphers\n\n$ciphers\n$kex\n$macs\n"],
            ["$ciphers\n$kex\n$macs\n", "$ciphers\n$kex\n$macs\n"],
            ["Port 22\rOther setting", "Port 22\rOther setting\n$ciphers\n$kex\n$macs\n"],
        ] as $index => [$input, $expected]) {
            $this->assertSame($expected, \pmssSshdLegacyParserTemplateNormalize($input), (string) $index);
        }
        $messages = [];
        $input = " HostKeyAlgorithms +fixture\r\nPubkeyAcceptedKeyTypes fixture\n#HostKeyAlgorithms keep\n";
        $this->assertSame("#  HostKeyAlgorithms +fixture\n# PubkeyAcceptedKeyTypes fixture\n#HostKeyAlgorithms keep\n\n$ciphers\n$kex\n$macs\n",
            \pmssSshdLegacyParserTemplateNormalize($input, $this->pmssMakeArrayLogger($messages)));
        $this->assertSame([
            '[WARN] Legacy-parser fallback is commenting out security-affecting sshd directive: HostKeyAlgorithms +fixture',
            '[WARN] Legacy-parser fallback is commenting out security-affecting sshd directive: PubkeyAcceptedKeyTypes fixture',
        ], $messages);
    }

    /** Lock validation retries, no-change/unreadable paths, failed writes and final refusal. */
    public function testValidationKeepsFailureAndRetryTraces(): void
    {
        $path = '/fixture/sshd_config';
        $initial = ['command', 'Validating sshd configuration syntax', 'fixture-validate'];
        $retry = ['command', 'Re-validating sshd syntax after legacy-parser fallback', 'fixture-validate'];
        $original = "HostKeyAlgorithms +fixture\n";
        $updated = \pmssSshdLegacyParserTemplateNormalize($original);
        $unchanged = \pmssSshdLegacyParserTemplateNormalize('');
        foreach ([
            [[0], null, true, true, [$initial]],
            [[127], $original, true, false, [$initial, ['log', '[ERR] sshd binary unavailable (rc=127); skipping legacy-parser fallback and sshd restart']]],
            [[1, 0], null, true, true, [$initial, ['read', $path], ['log', '[WARN] Cannot read sshd_config for legacy-parser normalization'], $retry]],
            [[1, 0], $unchanged, true, true, [$initial, ['read', $path], ['log', '[INFO] sshd legacy-parser normalization made no changes'], $retry]],
            [[1, 0], $original, true, true, [$initial, ['read', $path], ['log', '[WARN] Applying sshd legacy-parser compatibility fallback'], ['config', $path, $original, $updated, 'sshd config legacy parser fallback'], $retry]],
            [[1, 2], $original, false, false, [$initial, ['read', $path], ['log', '[WARN] Applying sshd legacy-parser compatibility fallback'], ['config', $path, $original, $updated, 'sshd config legacy parser fallback'], ['log', '[ERR] Failed to write sshd legacy-parser fallback configuration'], $retry, ['log', '[ERR] sshd validation failed after legacy-parser fallback; skipping sshd restart']]],
        ] as [$codes, $config, $write, $expected, $trace]) {
            $state = ['returnCodes' => $codes, 'files' => [$path => $config], 'configWrite' => $write];
            $this->assertSame(['result' => $expected, 'trace' => $trace], $this->trace(
                ['pmssSshdLegacyParserTemplateNormalize', 'pmssSshdValidateConfigWithLegacyFallback'], $state,
                'pmssSshdValidateConfigWithLegacyFallback("/fixture/sshd_config", "fixture-validate")'
            ));
        }
    }

    /** Self repair precedes enumeration; both repair sites share success and failure semantics. */
    public function testOomRepairKeepsReadWriteOrderAndResultAccounting(): void
    {
        $self = '/fixture/proc/self/oom_score_adj';
        $score = '/fixture/proc/101/oom_score_adj';
        $prefix = [['read', $self], ['write', $self, '0']];
        $process = [['scan', '/fixture/proc'], ['read', $score], ['read', '/fixture/proc/101/cgroup'], ['write', $score, '0']];
        foreach ([[1, true, 2, 0], [false, true, 0, 2], [1, false, 0, 2], [0, true, 2, 0]] as [$writeResult, $readback, $reset, $failed]) {
            $trace = $prefix;
            if ($writeResult !== false) $trace[] = ['read', $self];
            $trace = array_merge($trace, $process);
            if ($writeResult !== false) $trace[] = ['read', $score];
            $trace[] = ['log', ($failed ? '[WARN]' : '[INFO]').' Reset inherited OOM immunity: '.$reset.' process(es) -1000 -> 0'.($failed ? ' ('.$failed.' failed)' : '')];
            $this->assertSame(['result' => $reset, 'trace' => $trace], $this->trace(['pmssResetInheritedOomImmunity'], [
                'files' => [$self => " -1000\n", $score => '-1000', '/fixture/proc/101/cgroup' => '0::/user.slice/user-1000.slice'],
                'entries' => ['.', '..', 'self', '101', 'not-a-pid'], 'directories' => ['/fixture/proc/101'],
                'writeResult' => $writeResult, 'readback' => $readback,
            ], 'pmssResetInheritedOomImmunity("/fixture/proc")'));
        }
        $this->assertSame(['result' => 0, 'trace' => [['log', '[SKIP] Reset inherited OOM immunity (dry run)']]],
            $this->trace(['pmssResetInheritedOomImmunity'], ['dryRun' => true], 'pmssResetInheritedOomImmunity("/fixture/proc")'));
    }

    /** Copy real function definitions into the existing child-PHP fault-injection mechanism. */
    private function trace(array $functions, array $state, string $call): array
    {
        $namespace = 'ServiceBootstrapCharacterization';
        $script = 'namespace '.$namespace.'; require '.var_export($this->pmssRepoPath('scripts/lib/tests/fixtures/serviceBootstrapCharacterization.php'), true).';';
        foreach ($functions as $name) {
            $function = new \ReflectionFunction($name);
            $source = implode('', array_slice(file($function->getFileName()), $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1));
            // String log callbacks need the same namespace as ordinary direct calls.
            $source = str_replace("'logMessage'", var_export($namespace.'\\logMessage', true), $source);
            $script .= 'eval('.var_export('namespace '.$namespace.'; '.$source, true).');';
        }
        $state += ['trace' => [], 'dryRun' => false];
        foreach ($state as $key => $value) $script .= '$GLOBALS['.var_export($key, true).'] = '.var_export($value, true).';';
        return $this->pmssRunInlinePhpJson($script.'$result = '.$call.'; echo json_encode(["result" => $result, "trace" => $GLOBALS["trace"]]);');
    }
}
