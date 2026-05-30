<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class AddUserFatalPathCharacterizationTest extends TestCase
{
    public function testFatalExitEmitsFatalSummaryAndJsonMarkers(): void
    {
        $result = $this->fatalExitHarness(array(
            'detail' => 'User already exists; refusing to overwrite',
            'message' => 'user_exists',
        ));

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('FATAL: User already exists; refusing to overwrite', $result['output']);
        $this->assertStringContainsString('###ADDUSER:ERROR user=alice', $result['output']);
        $this->assertTrue(strpos($result['output'], 'FATAL: User already exists; refusing to overwrite') < strpos($result['output'], '###ADDUSER:ERROR user=alice'));
        $this->assertTrue(is_array($result['summary_json']), 'Expected fatal exit to emit a JSON summary line');
        $this->assertSame('ERROR', $result['summary_json']['status']);
        $this->assertSame('user_exists', $result['summary_json']['message']);
    }

    public function testFatalExitAddsExtraSummaryFieldsToJsonPayload(): void
    {
        $result = $this->fatalExitHarness(array(
            'detail' => 'Invalid username "alice/evil": slash not allowed',
            'message' => 'invalid_username',
            'extra' => array(
                'reason' => 'invalid_character',
                'detail' => 'slash not allowed',
            ),
        ));

        $this->assertSame('invalid_character', $result['summary_json']['reason']);
        $this->assertSame('slash not allowed', $result['summary_json']['detail']);
        $this->assertSame('invalid_username', $result['summary_json']['message']);
    }

    public function testFatalExitWritesOptionalStderrLineForAutomation(): void
    {
        $result = $this->fatalExitHarness(array(
            'detail' => 'Invalid username "alice/evil": slash not allowed',
            'message' => 'invalid_username',
            'stderr' => 'ERROR: Invalid username "alice/evil": slash not allowed',
        ));

        $this->assertStringContainsString('ERROR: Invalid username "alice/evil": slash not allowed', $result['output']);
        $this->assertStringContainsString('ERROR: Invalid username "alice/evil": slash not allowed', $result['stderr']);
        $this->assertStringContainsString('###ADDUSER_JSON:', $result['output']);
    }

    public function testAddUserWrapperDropsLegacyFatalFunctionExistsGuards(): void
    {
        $source = $this->pmssReadRepoFile('scripts/addUser.php');

        $this->pmssAssertStringNotContainsString("function_exists('logProvisionMessage')", $source);
        $this->pmssAssertStringNotContainsString("function_exists('finalizeProvision')", $source);
        $this->assertStringContainsString('pmssAddUserFatalExit(', $source);
    }

    public function testUserConfigApplyUsesSharedFatalHelperForNginxGuard(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/userConfigApply.php');

        $this->pmssAssertStringNotContainsString("finalizeProvision('FAIL', 'nginx_config_missing', 1)", $source);
        $this->assertStringContainsString("pmssAddUserFatalExit('FAIL', 'nginx config missing after regeneration; aborting provisioning', 'nginx_config_missing');", $source);
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function fatalExitHarness(array $overrides = array()): array
    {
        $options = array_merge(
            array(
                'status' => 'ERROR',
                'user' => 'alice',
                'detail' => 'fatal detail',
                'message' => 'fatal_message',
                'extra' => array(),
                'stderr' => null,
                'steps' => 3,
                'ok' => 2,
                'err' => 1,
                'last_error' => 'step rc=1',
            ),
            $overrides
        );

        $provisionLogPath = $this->pmssMakeTempFile('pmss-adduser-provision-log-');
        $userLogPath = $this->pmssMakeTempFile('pmss-adduser-user-log-');
        $stderrPath = $this->pmssMakeTempFile('pmss-adduser-stderr-');
        $scriptPath = $this->pmssMakeTempPhpPath('pmss-adduser-fatal-', 'harness');
        $libraryPath = $this->pmssRepoPath('scripts/lib/user/add/provisioningRuntime.php');

        file_put_contents($scriptPath, $this->buildFatalExitHarnessScript($libraryPath, $userLogPath, $options));

        $result = $this->pmssExecShellCommand(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($scriptPath),
            array(
                'PMSS_ADDUSER_LOG_PATH' => $provisionLogPath,
                'PMSS_TEST_MODE' => '1',
            ),
            '2>'.escapeshellarg($stderrPath)
        );

        $result['stderr'] = (string) @file_get_contents($stderrPath);
        $result['provision_log'] = (string) @file_get_contents($provisionLogPath);
        $result['user_log_lines'] = @file($userLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array();
        foreach (explode("\n", trim($result['output'])) as $line) {
            if (strpos($line, '###ADDUSER_JSON:') !== 0) {
                continue;
            }

            $result['summary_json'] = $this->pmssDecodeJsonArray(
                substr($line, strlen('###ADDUSER_JSON:')),
                'Expected fatal exit JSON line to decode'
            );
            return $result;
        }

        throw new \AssertionError('Expected fatal exit JSON summary in output: '.$result['output']);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function buildFatalExitHarnessScript(string $libraryPath, string $userLogPath, array $options): string
    {
        return implode("\n", array(
            '<?php',
            "if (!function_exists('pmssLogAppendTimestampedLine')) {",
            "function pmssLogAppendTimestampedLine(string \$path, string \$message, string \$timestampFormat = '[Y-m-d H:i:s] ', string \$prefix = '', ?int \$mode = null): bool",
            '{',
            "    return file_put_contents(\$path, date('Y-m-d H:i:s').' '.\$prefix.\$message.PHP_EOL, FILE_APPEND) !== false;",
            '}',
            '}',
            'function pmssUserBaseContext(string $action, string $phase, string $username, array $extra = array()): array',
            '{',
            "    return array_merge(array('action' => \$action, 'phase' => \$phase, 'username' => \$username), \$extra);",
            '}',
            'function pmssUserWriteLogs(array $payload): void',
            '{',
            '    file_put_contents('.var_export($userLogPath, true).', json_encode($payload, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);',
            '}',
            'require '.var_export($libraryPath, true).';',
            '$user = array('.var_export('name', true).' => '.var_export((string) $options['user'], true).');',
            '$provisionStart = microtime(true) - 1;',
            '$provisionStats = array('.var_export('steps', true).' => '.(int) $options['steps'].', '.var_export('ok', true).' => '.(int) $options['ok'].', '.var_export('err', true).' => '.(int) $options['err'].', '.var_export('last_error', true).' => '.var_export($options['last_error'], true).');',
            '$provisionFinalized = false;',
            'pmssAddUserFatalExit('.var_export((string) $options['status'], true).', '.var_export((string) $options['detail'], true).', '.var_export((string) $options['message'], true).', '.var_export($options['extra'], true).', '.var_export($options['stderr'], true).');',
        ))."\n";
    }

}
