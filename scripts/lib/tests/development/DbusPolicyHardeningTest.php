<?php
namespace PMSS\Tests;
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class DbusPolicyHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        // Strict test mode keeps all service reloads and tmpfiles applies hermetic.
        $this->pmssTrackEnvOverrides($this->pmssTestModeEnv(), true);
    }

    /** Install the complete policy set and return each artifact body by basename. */
    private function installDisclosureArtifacts(array &$messages): array
    {
        $dbusDir = $this->pmssMakeTempDir('pmss-dbus-policy-', 0700);
        $tmpfilesDir = $this->pmssMakeTempDir('pmss-tmpfiles-', 0700);
        $this->pmssTrackEnvOverrides([
            'PMSS_DBUS_SYSTEM_POLICY_DIR' => $dbusDir,
            'PMSS_TMPFILES_DIR' => $tmpfilesDir,
        ], true);

        \pmssEnsureSystemdDbusDisclosureHardening($this->pmssMakeArrayLogger($messages));

        $bodies = [];
        foreach ([$dbusDir, $tmpfilesDir] as $dir) {
            foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $basename) {
                $body = @file_get_contents($dir.'/'.$basename);
                $this->assertTrue(is_string($body), 'artifact installed: '.$basename);
                $bodies[$basename] = (string) $body;
            }
        }
        return $bodies;
    }

    public function testDisclosureHardeningArtifactSnapshot(): void
    {
        $messages = [];
        $bodies = $this->installDisclosureArtifacts($messages);
        $snapshots = [
            '10-pmss-systemd1-restrict.conf' => [2215, '33127e72e838b6de039066dab788216a5d7792111043bea8f046a5f023eea6ab'],
            '10-pmss-login1-restrict.conf' => [1102, 'bcda38506feaf4553b1afe7bdc7a65fa070f8bc376f8524284af07f7649adc4d'],
            'pmss-run-systemd-users.conf' => [216, '45dd3b70e3f8393a0f45c1e90dcd28e9efcbd1f53f4584595a35ab61524d5a3d'],
            'pmss-run-systemd-sessions.conf' => [238, 'b672da9aafb26c4391db5e57d63f4b6038a22c00817f5cb5374173d8c5c74e30'],
        ];

        $expectedNames = array_keys($snapshots);
        $actualNames = array_keys($bodies);
        sort($expectedNames);
        sort($actualNames);
        $this->assertSame($expectedNames, $actualNames, 'artifact names');
        foreach ($snapshots as $basename => $snapshot) {
            $this->assertSame($snapshot[0], strlen($bodies[$basename]), 'artifact length: '.$basename);
            $this->assertSame($snapshot[1], hash('sha256', $bodies[$basename]), 'artifact bytes: '.$basename);
        }
    }

    public function testLoginPolicyDeniesDestinationButPreservesSelfQueries(): void
    {
        $messages = [];
        $xml = $this->installDisclosureArtifacts($messages)['10-pmss-login1-restrict.conf'];
        $this->assertStringContainsString('<policy context="default">', $xml);
        $this->assertStringContainsString('<deny send_destination="org.freedesktop.login1"/>', $xml);

        foreach (['GetSessionByPID', 'GetUserByPID'] as $member) {
            $this->assertStringContainsString('send_member="'.$member.'"/>', $xml, 'preserves self-query '.$member);
        }
        foreach (['Introspectable', 'Properties', 'ListSessions', 'GetSession"'] as $member) {
            $this->assertStringNotContainsString('send_member="'.$member, $xml, 'does not re-allow '.$member);
        }
        $this->assertStringNotContainsString('user="root"', $xml, 'does not touch root policy');
    }

    public function testSystemdPolicyDeniesOnlyEnumerationMembers(): void
    {
        $messages = [];
        $xml = $this->installDisclosureArtifacts($messages)['10-pmss-systemd1-restrict.conf'];
        foreach (['ListUnits', 'ListUnitsByPatterns', 'ListUnitsFiltered', 'ListUnitsByNames', 'GetProcesses', 'GetUnitProcesses', 'Dump', 'DumpByFileDescriptor'] as $member) {
            $this->assertStringContainsString('send_member="'.$member.'"/>', $xml, 'denies enumeration method '.$member);
        }
        foreach (['GetUnit', 'GetUnitByPID', 'GetAll', 'Get'] as $member) {
            $this->assertStringNotContainsString('send_member="'.$member.'"', $xml, 'does not deny '.$member);
        }
        $this->assertStringNotContainsString('user="root"', $xml, 'does not touch root policy');
    }

    public function testManagedDbusPoliciesAreWellFormedXml(): void
    {
        $messages = [];
        $bodies = $this->installDisclosureArtifacts($messages);
        foreach (['10-pmss-login1-restrict.conf', '10-pmss-systemd1-restrict.conf'] as $basename) {
            $previous = libxml_use_internal_errors(true);
            $document = new \DOMDocument();
            $ok = $document->loadXML($bodies[$basename], LIBXML_NONET);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $detail = '';
            foreach ($errors as $error) {
                $detail .= ' '.trim($error->message);
            }
            $this->assertTrue($ok, $basename.' is well-formed XML;'.$detail);
        }
    }

    public function testTmpfilesPoliciesRestrictRuntimeDirectories(): void
    {
        $messages = [];
        $bodies = $this->installDisclosureArtifacts($messages);
        foreach (['users', 'sessions'] as $leaf) {
            $body = $bodies['pmss-run-systemd-'.$leaf.'.conf'];
            $this->assertStringContainsString('d /run/systemd/'.$leaf.' 0750 root root', $body);
            $this->assertStringNotContainsString('0644', $body);
            $this->assertStringNotContainsString('0755', $body);
        }
    }

    public function testCompletePolicyInstallIsIdempotent(): void
    {
        $messages = [];
        $this->installDisclosureArtifacts($messages);

        $secondRun = [];
        \pmssEnsureSystemdDbusDisclosureHardening($this->pmssMakeArrayLogger($secondRun));
        foreach (['systemd1 D-Bus enumeration policy', 'logind D-Bus enumeration policy', '/run/systemd/users tmpfiles policy', '/run/systemd/sessions tmpfiles policy'] as $label) {
            $this->assertTrue($this->pmssMessagesContain($secondRun, $label.' already present and up to date'), 'idempotent skip: '.$label);
        }
    }
}
