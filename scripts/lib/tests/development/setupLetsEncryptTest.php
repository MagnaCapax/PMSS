<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/certbotSetup.php';

class SetupLetsEncryptTest extends TestCase
{
    /** Build the shared hermetic certbot setup options used by these tests. */
    private function pmssLetsEncryptTestOptions(array $overrides = []): array
    {
        return array_replace([
            'cronPath' => $this->pmssMakeTempDir('pmss-certbot-cron-').'/certbot',
            'createNginxConfigCommand' => 'create-nginx',
            'nginxRestartCommand' => 'restart-nginx',
            'fileExists' => static function (string $path): bool {
                return false;
            },
            'commandRunner' => static function (string $command, string $description): array {
                return ['rc' => 0, 'stdout' => '', 'stderr' => ''];
            },
        ], $overrides);
    }

    public function testRejectsMissingEmailArgument(): void
    {
        $output = $this->pmssRunRepoPhpScript('scripts/util/setupLetsEncrypt.php');

        $this->assertStringContainsString('You need to pass e-mail address to this script', $output);
    }

    public function testRejectsMalformedEmailWithoutDomain(): void
    {
        $output = $this->pmssRunRepoPhpScript('scripts/util/setupLetsEncrypt.php', ['user@']);

        $this->assertStringContainsString('You need valid e-mail address', $output);
    }

    public function testRejectsWhitespaceBearingEmailArgument(): void
    {
        $output = $this->pmssRunRepoPhpScript('scripts/util/setupLetsEncrypt.php', ['user@example.com --help']);

        $this->assertStringContainsString('You need valid e-mail address', $output);
    }

    public function testRequiresSharedHostnameValidationForLetsEncryptDomain(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/setupLetsEncrypt.php', [
            "require_once __DIR__.'/../lib/runtime.php';",
            'pmssHostnameIsValid($domain, false)',
            "strpos(\$domain, '.') === false",
        ]);
        $this->pmssAssertRepoFileNotContainsString('scripts/util/setupLetsEncrypt.php', 'userTransfer/cliParse.php');
    }

    public function testCliDelegatesToSharedSetupLibraryWithoutShellExec(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/setupLetsEncrypt.php', [
            "require_once __DIR__.'/../lib/certbotSetup.php';",
            'pmssSetupLetsEncryptRun($domain, $email, $codename);',
        ]);
        $this->pmssAssertRepoFileNotContainsString('scripts/util/setupLetsEncrypt.php', 'shell_exec(');
    }

    public function testSharedSetupUsesCheckedCommandsAndPhpCronWrites(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/certbotSetup.php', [
            'pmssCommandCapture($command)',
            'pmssAtomicWriteFile($cronPath, pmssSetupLetsEncryptRenewalCronContents(), 0644)',
        ]);
        $this->pmssAssertRepoFileNotContainsString('scripts/lib/certbotSetup.php', 'shell_exec(');
    }

    public function testSharedSetupBuildsShellEscapedCertbotCommand(): void
    {
        $commands = [];
        list($ignored, $stdout) = $this->pmssCaptureStdout(function () use (&$commands): void {
            \pmssSetupLetsEncryptRun('example.com', 'user@example.com', 'bullseye', $this->pmssLetsEncryptTestOptions([
                'commandRunner' => static function (string $command, string $description) use (&$commands): array {
                    $commands[] = ['command' => $command, 'description' => $description];
                    return ['rc' => 0, 'stdout' => $description."\n", 'stderr' => ''];
                },
            ]));
        });

        $this->assertStringContainsString('Install certbot packages', $stdout);
        $this->assertStringContainsString('Request Let\'s Encrypt certificate', $stdout);
        $this->assertEquals('Request Let\'s Encrypt certificate', array_column($commands, 'description')[1] ?? '');
        $certRequestCommand = array_column($commands, 'command')[1] ?? '';
        $this->assertStringContainsString(
            "/usr/bin/certbot 'certonly' '-d' 'example.com' '-n' '--nginx' '--agree-tos' '--email' 'user@example.com'",
            $certRequestCommand
        );
    }

    public function testSharedSetupSkipsCertRequestWhenLiveCertificateExists(): void
    {
        $commands = [];
        $cronPath = $this->pmssMakeTempDir('pmss-certbot-cron-existing-').'/certbot';
        file_put_contents($cronPath, "existing\n");

        list($ignored, $stdout) = $this->pmssCaptureStdout(function () use (&$commands, $cronPath): void {
            \pmssSetupLetsEncryptRun('example.com', 'user@example.com', 'bullseye', $this->pmssLetsEncryptTestOptions([
                'cronPath' => $cronPath,
                'fileExists' => static function (string $path) use ($cronPath): bool {
                    return $path === '/etc/letsencrypt/live/example.com' || $path === $cronPath;
                },
                'commandRunner' => static function (string $command, string $description) use (&$commands): array {
                    $commands[] = ['command' => $command, 'description' => $description];
                    return ['rc' => 0, 'stdout' => $description."\n", 'stderr' => ''];
                },
            ]));
        });

        $this->assertStringContainsString('Install certbot packages', $stdout);
        $this->assertStringNotContainsString('Request Let\'s Encrypt certificate', $stdout);
        $this->assertEquals(
            ['Install certbot packages', 'Rebuild nginx configuration', 'Restart nginx'],
            array_column($commands, 'description')
        );
    }

    public function testSharedSetupWritesRenewalCronWhenMissing(): void
    {
        $cronPath = $this->pmssMakeTempDir('pmss-certbot-cron-missing-').'/certbot';

        \pmssSetupLetsEncryptRun('example.com', 'user@example.com', 'bullseye', $this->pmssLetsEncryptTestOptions([
            'cronPath' => $cronPath,
            'fileExists' => static function (string $path): bool {
                return $path === '/etc/letsencrypt/live/example.com';
            },
        ]));

        $this->assertEquals(\pmssSetupLetsEncryptRenewalCronContents(), (string) file_get_contents($cronPath));
        $this->assertEquals('644', substr(sprintf('%o', fileperms($cronPath)), -3));
    }

    public function testSharedSetupFailsLoudlyWhenNginxRestartFails(): void
    {
        $cronPath = $this->pmssMakeTempDir('pmss-certbot-cron-fail-').'/certbot';

        $this->assertThrowsRuntime(function () use ($cronPath): void {
            \pmssSetupLetsEncryptRun('example.com', 'user@example.com', 'bullseye', $this->pmssLetsEncryptTestOptions([
                'cronPath' => $cronPath,
                'fileExists' => static function (string $path): bool {
                    return $path === '/etc/letsencrypt/live/example.com';
                },
                'commandRunner' => static function (string $command, string $description): array {
                    if ($description === 'Restart nginx') {
                        return ['rc' => 1, 'stdout' => '', 'stderr' => 'restart failed'];
                    }
                    return ['rc' => 0, 'stdout' => '', 'stderr' => ''];
                },
            ]));
        }, 'Restart nginx failed (rc=1): restart failed');
    }

    public function testSharedSetupRejectsInvalidCommandRunnerPayload(): void
    {
        $this->assertThrowsRuntime(function (): void {
            \pmssSetupLetsEncryptRun('example.com', 'user@example.com', 'bullseye', $this->pmssLetsEncryptTestOptions([
                'commandRunner' => static function (string $command, string $description) {
                    return 'not-a-command-result';
                },
            ]));
        }, 'Install certbot packages returned invalid command result');
    }

    public function testSharedSetupBootstrapsMissingBusterCertbotBinary(): void
    {
        $commands = [];

        \pmssSetupLetsEncryptRun('example.com', 'user@example.com', 'buster', $this->pmssLetsEncryptTestOptions([
            'commandRunner' => static function (string $command, string $description) use (&$commands): array {
                $commands[] = ['command' => $command, 'description' => $description];
                return ['rc' => 0, 'stdout' => '', 'stderr' => ''];
            },
        ]));

        $this->assertEquals(
            [
                'Create certbot virtualenv',
                'Upgrade certbot pip',
                'Install certbot virtualenv packages',
                'Link certbot binary',
                'Request Let\'s Encrypt certificate',
                'Rebuild nginx configuration',
                'Restart nginx',
            ],
            array_column($commands, 'description')
        );
        $this->assertStringContainsAllStrings(
            [
                "python3 '-m' 'venv' '/opt/certbot/'",
                "/opt/certbot/bin/pip 'install' '--upgrade' 'pip'",
                "/opt/certbot/bin/pip 'install' 'certbot' 'certbot-nginx'",
                "ln '-s' '/opt/certbot/bin/certbot' '/usr/bin/certbot'",
            ],
            implode("\n", array_column($commands, 'command'))
        );
    }

    public function testSharedSetupRebuildsBrokenBusterVenvWhenVersionCheckFails(): void
    {
        // Post-dist-upgrade: /opt/certbot exists but the Python it was built against is gone.
        // The version check must catch this and force a rebuild, otherwise renewals fail
        // silently for 90 days until the cert expires (Refs #484).
        $commands = [];

        \pmssSetupLetsEncryptRun('example.com', 'user@example.com', 'buster', $this->pmssLetsEncryptTestOptions([
            'fileExists' => static function (string $path): bool {
                return $path === '/opt/certbot/bin/certbot' || $path === '/usr/bin/certbot';
            },
            'commandRunner' => static function (string $command, string $description) use (&$commands): array {
                $commands[] = $description;
                if ($description === 'Verify certbot virtualenv functional') {
                    return ['rc' => 1, 'stdout' => '', 'stderr' => 'ModuleNotFoundError'];
                }
                return ['rc' => 0, 'stdout' => '', 'stderr' => ''];
            },
        ]));

        $this->assertEquals(
            [
                'Verify certbot virtualenv functional',
                'Remove broken certbot virtualenv',
                'Create certbot virtualenv',
                'Upgrade certbot pip',
                'Install certbot virtualenv packages',
                'Request Let\'s Encrypt certificate',
                'Rebuild nginx configuration',
                'Restart nginx',
            ],
            $commands
        );
    }

    public function testSharedSetupSkipsRebuildWhenBusterVenvHealthCheckPasses(): void
    {
        // Healthy venv with cert already present: version check returns rc=0 and no
        // rebuild commands are emitted; cert request also skipped because liveCertPath exists.
        $commands = [];

        \pmssSetupLetsEncryptRun('example.com', 'user@example.com', 'buster', $this->pmssLetsEncryptTestOptions([
            'fileExists' => static function (string $path): bool {
                return $path === '/opt/certbot/bin/certbot'
                    || $path === '/usr/bin/certbot'
                    || strpos($path, '/etc/letsencrypt/live/') === 0;
            },
            'commandRunner' => static function (string $command, string $description) use (&$commands): array {
                $commands[] = $description;
                return ['rc' => 0, 'stdout' => 'certbot 2.1.0', 'stderr' => ''];
            },
        ]));

        $this->assertEquals(
            [
                'Verify certbot virtualenv functional',
                'Rebuild nginx configuration',
                'Restart nginx',
            ],
            $commands
        );
    }

    public function testSharedSetupBusterPathDeclaresFunctionalHealthCheck(): void
    {
        // Static guard against regression: any future refactor of the buster branch
        // must preserve the certbot-functionality check, not just the existence check.
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/certbotSetup.php', [
            "'Verify certbot virtualenv functional'",
            "'Remove broken certbot virtualenv'",
            '$venvFunctional',
        ]);
    }

    public function testRenewalCronUsesPython3NotPython2(): void
    {
        // Static guard against regression: the renewal-cron jitter command MUST use
        // `python3` (present on every PMSS-supported Debian release). `python`
        // is Python 2, absent on bookworm+; the `&&` chain made certbot renew
        // silently never run. See Refs #484 adversarial review supplement.
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/certbotSetup.php', [
            "python3 -c 'import random; import time; time.sleep",
        ]);
        $this->pmssAssertRepoFileNotContainsString(
            'scripts/lib/certbotSetup.php',
            "python -c 'import random; import time; time.sleep"
        );
    }
}
