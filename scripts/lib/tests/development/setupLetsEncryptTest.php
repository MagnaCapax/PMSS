<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SetupLetsEncryptTest extends TestCase
{
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
        $source = $this->pmssReadRepoFile('scripts/util/setupLetsEncrypt.php');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/userTransfer/cliParse.php';", $source);
        $this->assertStringContainsString('pmssUserTransferHostnameIsValid($domain)', $source);
        $this->assertStringContainsString("strpos(\$domain, '.') === false", $source);
    }

    public function testShellEscapesDomainAndEmailBeforeCertbotInvocation(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/setupLetsEncrypt.php');

        $this->assertStringContainsString('$domainArg = escapeshellarg($domain);', $source);
        $this->assertStringContainsString('$emailArg = escapeshellarg($email);', $source);
        $this->assertStringContainsString("shell_exec('/usr/bin/certbot certonly -d '.\$domainArg.' -n --nginx --agree-tos --email '.\$emailArg)", $source);
    }
}
