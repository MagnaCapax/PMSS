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
            "require_once __DIR__.'/../lib/userTransfer/cliParse.php';",
            'pmssUserTransferHostnameIsValid($domain)',
            "strpos(\$domain, '.') === false",
        ]);
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
            '@file_put_contents($cronPath, pmssSetupLetsEncryptRenewalCronContents()) === false',
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
        $this->assertStringContainsString(
            "/usr/bin/certbot 'certonly' '-d' 'example.com' '-n' '--nginx' '--agree-tos' '--email' 'user@example.com'",
            $commands[1]['command']
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

        try {
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
            $this->fail('Expected nginx restart failure to throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Restart nginx failed (rc=1): restart failed', $exception->getMessage());
        }
    }

    public function testSharedSetupBootstrapsMissingBusterCertbotBinary(): void
    {
        $commands = [];

        \pmssSetupLetsEncryptRun('example.com', 'user@example.com', 'buster', $this->pmssLetsEncryptTestOptions([
            'commandRunner' => static function (string $command, string $description) use (&$commands): array {
                $commands[] = $description;
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
            $commands
        );
    }
}
