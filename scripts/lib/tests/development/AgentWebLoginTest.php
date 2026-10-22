<?php
/**
 * Agent web login on the /user-<account>/ area (ADR 0151).
 *
 * Pins the derived login name, the byte-identical default render, the single
 * changed require line with the host marker, and (when lighttpd and curl are
 * installed) a live Basic-auth round trip with the UTF-8 login name.
 */

namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class AgentWebLoginTest extends TestCase
{
    private const AGENT_LOGIN = 'pm-väinämöinen-KOFNOLICCOANHBJJCJAA';

    private function render(string $template): string
    {
        return \pmssLighttpdRenderUserConfig($template, 'testuser', 31234, 32345, 33456, ['maxProcs' => 2, 'children' => 6], [], false);
    }

    /** Render with the marker either present (a regular file) or absent. */
    private function renderWithMarker(bool $present, ?string $template = null): string
    {
        $marker = $this->pmssMakeTempDir('pmss-agent-login-', 0700).'/agentWebLogin.enabled';
        if ($present) {
            file_put_contents($marker, '');
        }
        $this->pmssTrackEnvOverrides(['PMSS_AGENT_WEB_LOGIN_MARKER' => $marker]);

        return $this->render($template ?? $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd'));
    }

    /** Lines that differ between two renders, as "-old" / "+new". */
    private function changedLines(string $before, string $after): array
    {
        $old = explode("\n", $before);
        $new = explode("\n", $after);
        $this->assertSame(count($old), count($new), 'the marker must not add or remove lines');
        $changed = [];
        foreach ($old as $i => $line) {
            if ($line !== $new[$i]) {
                $changed[] = '-'.$line;
                $changed[] = '+'.$new[$i];
            }
        }
        return $changed;
    }

    public function testNameForSampleAccountIsPinned(): void
    {
        // sha256("testuser") starts ae5deb822e0d71992900; each hex digit 0-f maps to A-P.
        $this->assertSame(self::AGENT_LOGIN, \pmssAgentWebLoginName('testuser'));
        $this->assertSame('pm-väinämöinen-DIPIPKHILKMGBDIPBEPH', \pmssAgentWebLoginName('fixture1'));
    }

    public function testNameIsPrefixPlusTwentyUppercaseLettersAndDiffersPerAccount(): void
    {
        $seen = [];
        foreach (['a', 'testuser', 'fixture1', 'z9', 'abcdefgh', 'user0001'] as $user) {
            $name = \pmssAgentWebLoginName($user);
            $this->assertMatches('/^pm-väinämöinen-[A-Z]{20}$/D', $name);
            $this->assertMatches('/^pm-v\xC3\xA4in\xC3\xA4m\xC3\xB6inen-[A-P]{20}$/D', $name, 'UTF-8 bytes and A-P letters: '.$name);
            $this->assertSame($name, \pmssAgentWebLoginName($user), 'deterministic');
            $seen[$name] = true;
        }
        $this->assertSame(6, count($seen), 'one distinct name per account');
    }

    public function testInvalidAccountNamesGetNoLogin(): void
    {
        foreach (['', 'Testuser', '1abc', 'toolongname', "testuser\n", 'a|user=b', 'a"b', 'a b', '../x'] as $user) {
            $this->assertSame('', \pmssAgentWebLoginName($user), 'invalid: '.json_encode($user));
            $this->assertSame('', \pmssAgentWebLoginRequireSuffix($user, __FILE__), 'no suffix even with a marker: '.json_encode($user));
        }
    }

    public function testRequireSuffixFollowsTheMarker(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-agent-login-', 0700);
        $this->assertSame('', \pmssAgentWebLoginRequireSuffix('testuser', $dir.'/absent'));
        $this->assertSame('', \pmssAgentWebLoginRequireSuffix('testuser', $dir), 'a directory is not the marker');
        file_put_contents($dir.'/agentWebLogin.enabled', '');
        $this->assertSame('|user='.self::AGENT_LOGIN, \pmssAgentWebLoginRequireSuffix('testuser', $dir.'/agentWebLogin.enabled'));
        $this->pmssTrackEnvOverrides(['PMSS_AGENT_WEB_LOGIN_MARKER' => $dir.'/agentWebLogin.enabled']);
        $this->assertSame($dir.'/agentWebLogin.enabled', \pmssAgentWebLoginMarkerPath());
        $this->assertTrue(\pmssAgentWebLoginEnabled());
    }

    public function testDefaultMarkerPathIsUnderSeedboxConfig(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_AGENT_WEB_LOGIN_MARKER' => null]);
        $this->assertSame('/etc/seedbox/config/agentWebLogin.enabled', \pmssAgentWebLoginMarkerPath());
        $this->assertTrue(!is_file($this->pmssRepoPath('etc/seedbox/config/agentWebLogin.enabled')), 'the marker is never shipped');
    }

    public function testRenderWithoutMarkerIsByteIdenticalToThePreviousTemplate(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');
        $this->pmssAssertRepoFileSubstringCount('etc/seedbox/config/template.lighttpd', '##PMSS_AGENT_WEB_LOGIN_REQUIRE##', 1);
        // The template before ADR 0151 is this one without the placeholder.
        $previous = str_replace('##PMSS_AGENT_WEB_LOGIN_REQUIRE##', '', $template);
        $rendered = $this->renderWithMarker(false, $template);

        $this->assertSame($this->renderWithMarker(false, $previous), $rendered);
        $this->assertSame(2, substr_count($rendered, '"require" => "user=testuser"'."\n"));
        $this->assertStringContainsAndOmitsStrings([], ['pm-v', '##PMSS_AGENT_WEB_LOGIN_REQUIRE##'], $rendered);
    }

    public function testRenderWithMarkerChangesOnlyThePanelRequireLine(): void
    {
        $without = $this->renderWithMarker(false);
        $with = $this->renderWithMarker(true);

        $this->assertSame([
            '-        "require" => "user=testuser"',
            '+        "require" => "user=testuser|user='.self::AGENT_LOGIN.'"',
        ], $this->changedLines($without, $with));
        $this->assertOrderedStrings([
            '"/user-testuser/" =>',
            '"require" => "user=testuser|user='.self::AGENT_LOGIN.'"',
            '"/webdav-testuser/" =>',
            '"require" => "user=testuser"'."\n",
        ], $with);
        $this->assertSame(1, substr_count($with, self::AGENT_LOGIN), 'WebDAV stays account-only');
    }

    /** Live check: lighttpd mod_auth with the UTF-8 login in require and in the htpasswd file. */
    public function testLighttpdAcceptsTheUtf8AgentLoginOnThePanelZoneOnly(): void
    {
        $lighttpd = $this->binary(['/usr/sbin/lighttpd', '/usr/local/sbin/lighttpd', '/usr/bin/lighttpd']);
        $curl = $this->binary(['/usr/bin/curl', '/usr/local/bin/curl']);
        $authn = array_merge(glob('/usr/lib/lighttpd/mod_authn_file.so') ?: [], glob('/usr/lib/*/lighttpd/mod_authn_file.so') ?: []);
        if ($lighttpd === null || $curl === null || $authn === []) {
            throw new SkipTest('needs lighttpd, mod_authn_file and curl (lighttpd='.var_export($lighttpd, true)
                .' curl='.var_export($curl, true).' mod_authn_file='.count($authn).')');
        }
        if (!preg_match('/^auth\.require = \(.*?^\)$/ms', $this->renderWithMarker(true), $block)) {
            $this->fail('rendered auth.require block not found');
        }

        $dir = $this->pmssMakeTempDir('pmss-agent-login-live-', 0700);
        foreach (['user-testuser', 'webdav-testuser'] as $zone) {
            mkdir($dir.'/www/'.$zone, 0700, true);
            file_put_contents($dir.'/www/'.$zone.'/index.html', "ok\n");
        }
        $accountPassword = $this->password();
        $agentPassword = $this->password();
        file_put_contents($dir.'/.htpasswd', 'testuser:'.password_hash($accountPassword, PASSWORD_BCRYPT)."\n"
            .self::AGENT_LOGIN.':'.password_hash($agentPassword, PASSWORD_BCRYPT)."\n");
        $port = $this->freePort();
        file_put_contents($dir.'/lighttpd.conf', 'server.modules = ( "mod_auth", "mod_authn_file" )'."\n"
            .'server.bind = "127.0.0.1"'."\n".'server.port = '.$port."\n"
            .'server.document-root = "'.$dir.'/www"'."\n".'server.errorlog = "'.$dir.'/error.log"'."\n"
            .'auth.backend = "htpasswd"'."\n".'auth.backend.htpasswd.userfile = "'.$dir.'/.htpasswd"'."\n"
            .$block[0]."\n");

        $process = proc_open('exec '.escapeshellarg($lighttpd).' -D -f '.escapeshellarg($dir.'/lighttpd.conf'),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir.'/stdout.log', 'w'], 2 => ['file', $dir.'/stderr.log', 'w']], $pipes);
        $this->assertTrue(is_resource($process), 'lighttpd did not start');
        try {
            $this->waitForPort($port, $dir);
            $panel = 'http://127.0.0.1:'.$port.'/user-testuser/index.html';
            $webdav = 'http://127.0.0.1:'.$port.'/webdav-testuser/index.html';
            $cases = [
                ['account login on the panel (bcrypt control)', $panel, 'testuser', $accountPassword, '200'],
                ['UTF-8 agent login on the panel', $panel, self::AGENT_LOGIN, $agentPassword, '200'],
                ['UTF-8 agent login, wrong password', $panel, self::AGENT_LOGIN, $agentPassword.'x', '401'],
                ['ASCII-folded agent name', $panel, str_replace(['ä', 'ö'], ['a', 'o'], self::AGENT_LOGIN), $agentPassword, '401'],
                ['no credentials', $panel, '', '', '401'],
                ['UTF-8 agent login on WebDAV', $webdav, self::AGENT_LOGIN, $agentPassword, '401'],
                ['account login on WebDAV', $webdav, 'testuser', $accountPassword, '200'],
            ];
            foreach ($cases as $i => [$label, $url, $login, $password, $expected]) {
                $this->assertSame($expected, $this->curlStatus($curl, $dir.'/curl-'.$i.'.cfg', $url, $login, $password),
                    $label.'; lighttpd error log: '.substr((string) @file_get_contents($dir.'/error.log'), -800));
            }
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    private function binary(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    /** 16 characters a-z0-9, the agent password shape. */
    private function password(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, 35)];
        }
        return $out;
    }

    private function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertTrue(is_resource($server), 'no free port: '.$error);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);
        return $port;
    }

    private function waitForPort(int $port, string $dir): void
    {
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        $this->fail('lighttpd did not listen: '.substr((string) @file_get_contents($dir.'/stderr.log'), -800)
            .substr((string) @file_get_contents($dir.'/error.log'), -800));
    }

    /**
     * HTTP status from curl; the credentials go through a curl config file (`user = ...`, the
     * same option as -u) so the UTF-8 bytes never pass through a shell or escapeshellarg().
     */
    private function curlStatus(string $curl, string $config, string $url, string $login, string $password): string
    {
        $lines = 'url = "'.$url.'"'."\n".'silent'."\n".'output = "/dev/null"'."\n".'write-out = "%{http_code}"'."\n";
        // Keep the loopback fixture independent of proxy settings and bound failed requests.
        $lines .= 'noproxy = "*"'."\n".'max-time = 5'."\n";
        if ($login !== '') {
            $lines .= 'user = "'.$login.':'.$password.'"'."\n";
        }
        file_put_contents($config, $lines);
        return trim((string) shell_exec(escapeshellarg($curl).' -q -K '.escapeshellarg($config).' 2>/dev/null'));
    }
}
