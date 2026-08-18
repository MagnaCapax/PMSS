<?php
namespace PMSS\Tests;
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class DbusPolicyHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        // Strict test mode keeps pmssSystemdActionSkipReason from shelling out to systemctl reload.
        $this->pmssTrackEnvOverrides($this->pmssTestModeEnv(), true);
    }

    public function testRenderDeniesDestinationButPreservesSelfQuery(): void
    {
        $xml = \pmssDbusLoginPolicyRender();

        // The whole login1 destination is denied for the default (non-root) context — this is
        // what closes the Introspect+Properties roster-reconstruction bypass that a member-only
        // deny would leave open.
        $this->assertStringContainsString('<policy context="default">', $xml);
        $this->assertStringContainsString('<deny send_destination="org.freedesktop.login1"/>', $xml);

        // Only the self-scoped PID lookups are re-allowed (own session/user under hidepid=2).
        foreach (\pmssDbusLoginSelfQueryMembers() as $selfMember) {
            $this->assertStringContainsString(
                '<allow send_destination="org.freedesktop.login1"'."\n"
                .'                       send_interface="org.freedesktop.login1.Manager"'."\n"
                .'                       send_member="'.$selfMember.'"/>',
                $xml,
                'preserves self-query '.$selfMember
            );
        }

        // The bypass-enabling interfaces/members must NOT be re-allowed for the default context.
        foreach (['Introspectable', 'Properties', 'ListSessions', 'GetSession"'] as $mustNotReAllow) {
            $this->assertTrue(
                strpos($xml, '<allow send_destination="org.freedesktop.login1"'."\n".'                       send_interface="org.freedesktop.DBus.'.$mustNotReAllow) === false
                    && strpos($xml, 'send_member="'.$mustNotReAllow) === false,
                'does not re-allow '.$mustNotReAllow
            );
        }

        // No root stanza is emitted — root is spared by the base vendor policy's own user="root" stanza.
        $this->assertTrue(strpos($xml, 'user="root"') === false, 'does not touch root policy');
    }

    public function testInstallIsIdempotentAndContentMatchesRender(): void
    {
        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);
        $dir = $this->pmssMakeTempDir('pmss-dbus-policy-', 0700);
        $this->pmssTrackEnvOverrides(['PMSS_DBUS_SYSTEM_POLICY_DIR' => $dir], true);

        \pmssEnsureDbusLoginEnumerationPolicy($logger);
        $target = $dir.'/'.\pmssDbusLoginPolicyBasename();
        $this->assertTrue(is_file($target), 'policy file installed');
        $this->assertSame(\pmssDbusLoginPolicyRender(), (string) file_get_contents($target), 'file content matches render');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Installed logind D-Bus enumeration policy'), 'install logged');

        // Second run with unchanged content must skip the write (idempotent).
        $messages2 = [];
        \pmssEnsureDbusLoginEnumerationPolicy($this->pmssMakeArrayLogger($messages2));
        $this->assertTrue($this->pmssMessagesContain($messages2, 'already present and up to date'), 'idempotent skip logged');
    }

    public function testSystemd1RenderDeniesEnumerationButPreservesSelfScoped(): void
    {
        $xml = \pmssDbusSystemd1PolicyRender();

        // Default (non-root) context only; this is a METHOD-LEVEL deny-list, NOT a
        // destination-wide deny — the panel + tooling call systemd1 legitimately.
        $this->assertStringContainsString('<policy context="default">', $xml);

        // Every enumeration method that leaks units / process command lines is denied.
        foreach (\pmssDbusSystemd1EnumerationMembers() as $member) {
            $this->assertStringContainsString(
                '<deny send_destination="org.freedesktop.systemd1"'."\n"
                .'                      send_interface="org.freedesktop.systemd1.Manager"'."\n"
                .'                      send_member="'.$member.'"/>',
                $xml,
                'denies enumeration method '.$member
            );
        }

        // The leak-bearing process-enumeration methods must be in the deny set.
        $denied = \pmssDbusSystemd1EnumerationMembers();
        foreach (['ListUnits', 'GetProcesses', 'GetUnitProcesses', 'Dump'] as $mustDeny) {
            $this->assertTrue(in_array($mustDeny, $denied, true), $mustDeny.' is in the deny set');
        }

        // Self-scoped / panel methods must NOT be denied (would break the panel).
        foreach (['GetUnit', 'GetUnitByPID', 'GetAll', 'Get'] as $mustNotDeny) {
            $this->assertStringNotContainsString('send_member="'.$mustNotDeny.'"', $xml, 'does not deny '.$mustNotDeny);
        }

        // Root is spared by the base vendor policy's own user="root" stanza.
        $this->assertStringNotContainsString('user="root"', $xml, 'does not touch root policy');
    }

    public function testManagedDbusRendersAreWellFormedXml(): void
    {
        // A malformed D-Bus policy makes dbus reject the file and can break
        // systemd/logind host-wide — this is the #1 deployment regression.
        foreach (['login1' => \pmssDbusLoginPolicyRender(), 'systemd1' => \pmssDbusSystemd1PolicyRender()] as $name => $xml) {
            $prev = libxml_use_internal_errors(true);
            $doc = new \DOMDocument();
            $ok = $doc->loadXML($xml, LIBXML_NONET);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $detail = '';
            foreach ($errors as $e) { $detail .= ' '.trim($e->message); }
            $this->assertTrue($ok, $name.' policy is well-formed XML;'.$detail);
        }
    }

    public function testRunSystemdUsersTmpfilesRenderRestrictsMode(): void
    {
        $body = \pmssRunSystemdUsersTmpfilesRender();
        // tmpfiles `d` restricts the /run/systemd/users DIRECTORY to 0750 (robust: the dir
        // is not recreated per-login, so non-root cannot traverse in to read any per-UID file).
        $this->assertStringContainsString('d /run/systemd/users 0750 root root', $body);
        $this->assertStringNotContainsString('0644', $body);
        $this->assertStringNotContainsString('0755', $body);
    }

    public function testTmpfilesInstallIsIdempotent(): void
    {
        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);
        $dir = $this->pmssMakeTempDir('pmss-tmpfiles-', 0700);
        $this->pmssTrackEnvOverrides(['PMSS_TMPFILES_DIR' => $dir], true);

        \pmssEnsureRunSystemdUsersTmpfiles($logger);
        $target = $dir.'/'.\pmssRunSystemdUsersTmpfilesBasename();
        $this->assertTrue(is_file($target), 'tmpfiles drop-in installed');
        $this->assertSame(\pmssRunSystemdUsersTmpfilesRender(), (string) file_get_contents($target), 'content matches render');

        $messages2 = [];
        \pmssEnsureRunSystemdUsersTmpfiles($this->pmssMakeArrayLogger($messages2));
        $this->assertTrue($this->pmssMessagesContain($messages2, 'already present and up to date'), 'idempotent skip logged');
    }

    public function testRunSystemdSessionsTmpfilesRenderRestrictsMode(): void
    {
        $body = \pmssRunSystemdSessionsTmpfilesRender();
        // tmpfiles `d` restricts the /run/systemd/sessions DIRECTORY to 0750 (holds REMOTE_HOST/client IP).
        $this->assertStringContainsString('d /run/systemd/sessions 0750 root root', $body);
        $this->assertStringNotContainsString('0644', $body);
        $this->assertStringNotContainsString('0755', $body);
    }

    public function testSessionsTmpfilesInstallIsIdempotent(): void
    {
        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);
        $dir = $this->pmssMakeTempDir('pmss-tmpfiles-sessions-', 0700);
        $this->pmssTrackEnvOverrides(['PMSS_TMPFILES_DIR' => $dir], true);

        \pmssEnsureRunSystemdSessionsTmpfiles($logger);
        $target = $dir.'/'.\pmssRunSystemdSessionsTmpfilesBasename();
        $this->assertTrue(is_file($target), 'sessions tmpfiles drop-in installed');
        $this->assertSame(\pmssRunSystemdSessionsTmpfilesRender(), (string) file_get_contents($target), 'content matches render');

        $messages2 = [];
        \pmssEnsureRunSystemdSessionsTmpfiles($this->pmssMakeArrayLogger($messages2));
        $this->assertTrue($this->pmssMessagesContain($messages2, 'already present and up to date'), 'idempotent skip logged');
    }
}
