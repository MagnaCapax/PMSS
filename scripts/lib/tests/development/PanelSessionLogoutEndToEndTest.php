<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * End-to-end cases for the panel logout page. Each request is a real HTTP request over loopback to PHP's
 * built-in web server serving the real customer-tree entry point (etc/skel/www/panelSessionLogout.php, copied
 * into a fixture account), with a real Cookie header and form body. The status, response headers and body are
 * read back, together with the state it left behind (the session file and the agent-UI stop marker).
 */
class PanelSessionLogoutEndToEndTest extends TestCase
{
    private const FILES = ['panelSession.php', 'panelSessionAuth.php', 'panelSessionLogin.php', 'panelSessionLogout.php',
        'panelSessionPage.php', 'panelSessionStore.php'];

    /** Build a fixture account with the skeleton panel files and a stub agent launcher; return its home. */
    private function account(string $username): string
    {
        $root = $this->pmssMakeTempDir('pmss-logout-e2e-');
        $home = $this->pmssEnsureDir($root.'/'.$username);
        $this->pmssEnsureDir($home.'/www');
        $this->pmssEnsureDir($home.'/.lighttpd');
        $this->pmssEnsureDir($home.'/bin');
        foreach (self::FILES as $file) {
            copy($this->pmssRepoPath('etc/skel/www/'.$file), $home.'/www/'.$file);
        }
        // The launcher the logout calls to rotate agent sessions; it only records that it ran.
        $this->pmssWriteFile($home.'/bin/install-hermes', "#!/bin/sh\necho \"\$@\" >> ".escapeshellarg($home.'/stops.log')."\n");
        chmod($home.'/bin/install-hermes', 0755);
        return $home;
    }

    private function writeSession(string $home, int $created, int $seen, string $id = '', string $csrf = ''): array
    {
        $id = $id !== '' ? $id : str_repeat('a', 64);
        $csrf = $csrf !== '' ? $csrf : str_repeat('b', 64);
        $this->pmssWriteFile($home.'/.lighttpd/panel-session', 'id='.$id."\ncsrf=".$csrf."\ncreated=".$created."\nseen=".$seen."\nfailed=0\n");
        return [$id, $csrf];
    }

    /** Send one HTTP request to the account's logout page; return status, headers, body and the stop count. */
    private function request(string $home, string $method, array $cookies = [], array $post = []): array
    {
        // A free port can be taken by a neighbour between the probe and the bind on a shared test machine, so the
        // server is only trusted once it serves a canary file that exists in this account's tree alone.
        $canary = bin2hex(random_bytes(8));
        $this->pmssWriteFile($home.'/www/canary.txt', $canary);
        $server = null;
        $port = 0;
        for ($attempt = 0; $attempt < 8 && $port === 0; $attempt++) {
            $probe = stream_socket_server('tcp://127.0.0.1:0');
            $candidate = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
            fclose($probe);
            $pipes = [];
            $process = proc_open('exec '.escapeshellarg(PHP_BINARY).' -S 127.0.0.1:'.$candidate.' -t '.escapeshellarg($home.'/www'),
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            for ($try = 0; is_resource($process) && $try < 60; $try++) {
                $served = @file_get_contents('http://127.0.0.1:'.$candidate.'/canary.txt', false, stream_context_create(['http' => ['timeout' => 1]]));
                if ($served === $canary) { $port = $candidate; $server = $process; break; }
                usleep(50000);
            }
            if ($port === 0 && is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
        $this->assertTrue($server !== null, 'the loopback web server must start and serve this account');
        try {
            $headers = '';
            foreach ($cookies as $name => $value) {
                $headers .= 'Cookie: '.$name.'='.$value."\r\n";
            }
            $content = http_build_query($post);
            if ($method === 'POST') {
                $headers .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($content)."\r\n";
            }
            $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => $method === 'POST' ? $content : '',
                'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => 20]]);
            $http_response_header = [];
            $body = (string) @file_get_contents('http://127.0.0.1:'.$port.'/panelSessionLogout.php', false, $context);
            $lines = $http_response_header;
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
        $this->assertTrue(is_array($lines) && count($lines) > 0 && preg_match('~^HTTP/\S+ (\d{3})~', $lines[0], $m) === 1, 'the logout page must answer');
        $stops = is_file($home.'/stops.log') ? count(file($home.'/stops.log')) : 0;
        return ['status' => (int) $m[1], 'headers' => implode("\n", $lines), 'body' => $body, 'stops' => $stops];
    }

    private function assertNothingChanged(array $response, string $home, string $before, string $label): void
    {
        $this->assertSame(302, $response['status'], $label);
        $this->assertStringContainsString('Location: /user-'.basename($home).'/panelSessionLogin.php', $response['headers'], $label);
        $this->assertStringNotContainsString('Set-Cookie', $response['headers'], $label.' must not clear any cookie');
        $this->assertSame(0, $response['stops'], $label.' must not touch the agent UIs');
        $this->assertSame($before, (string) @file_get_contents($home.'/.lighttpd/panel-session'), $label.' must leave the session file alone');
    }

    public function testUc01GetWithLiveCookieShowsTheSignOutFormAndChangesNothing(): void
    {
        $home = $this->account('fixtur01');
        [$id, $csrf] = $this->writeSession($home, time() - 600, time() - 60);
        $before = file_get_contents($home.'/.lighttpd/panel-session');

        $response = $this->request($home, 'GET', ['pmss_panel_session' => $id]);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('method="post"', $response['body']);
        $this->assertStringContainsString('name="csrf" value="'.$csrf.'"', $response['body']);
        $this->assertSame(0, $response['stops']);
        $this->assertSame($before, file_get_contents($home.'/.lighttpd/panel-session'));
    }

    public function testUc02OwnerPostWithCookieAndTokenLogsOutAndStopsTheAgentUis(): void
    {
        $home = $this->account('fixtur01');
        [$id, $csrf] = $this->writeSession($home, time() - 600, time() - 60);

        $response = $this->request($home, 'POST', ['pmss_panel_session' => $id], ['csrf' => $csrf]);

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString('Location: /user-fixtur01/panelSessionLogin.php', $response['headers']);
        $this->assertStringContainsString('Set-Cookie: pmss_panel_session=deleted; Max-Age=0', $response['headers']);
        $this->assertFalse(file_exists($home.'/.lighttpd/panel-session'));
        $this->assertSame(1, $response['stops'], 'the agent session rotation must run once');
    }

    public function testUc03CrossSiteImageGetWithoutCookieChangesNothing(): void
    {
        // An <img src=".../panelSessionLogout.php"> on another site sends a GET with no session cookie.
        $home = $this->account('fixtur01');
        $this->writeSession($home, time() - 600, time() - 60);
        $before = file_get_contents($home.'/.lighttpd/panel-session');

        $this->assertNothingChanged($this->request($home, 'GET'), $home, $before, 'cross-site GET');
    }

    public function testUc04CrossSitePostWithTokenReadFromThePublicLoginPageChangesNothing(): void
    {
        // The login page is public and prints the live session's CSRF token; SameSite=Lax keeps the cookie off a cross-site POST.
        $home = $this->account('fixtur01');
        [, $csrf] = $this->writeSession($home, time() - 600, time() - 60);
        $before = file_get_contents($home.'/.lighttpd/panel-session');

        $this->assertNothingChanged($this->request($home, 'POST', [], ['csrf' => $csrf]), $home, $before, 'cross-site POST with token');
    }

    public function testUc05OwnerPostWithWrongOrMissingTokenIsRefused(): void
    {
        $home = $this->account('fixtur01');
        [$id] = $this->writeSession($home, time() - 600, time() - 60);
        $before = file_get_contents($home.'/.lighttpd/panel-session');

        foreach ([[], ['csrf' => 'wrong'], ['csrf' => str_repeat('c', 64)]] as $post) {
            $response = $this->request($home, 'POST', ['pmss_panel_session' => $id], $post);
            $this->assertSame(403, $response['status']);
            $this->assertStringNotContainsString('Set-Cookie', $response['headers']);
            $this->assertSame(0, $response['stops']);
            $this->assertSame($before, file_get_contents($home.'/.lighttpd/panel-session'));
        }
    }

    public function testUc06ReplayedTokenAfterLogoutChangesNothing(): void
    {
        $home = $this->account('fixtur01');
        [$id, $csrf] = $this->writeSession($home, time() - 600, time() - 60);
        $first = $this->request($home, 'POST', ['pmss_panel_session' => $id], ['csrf' => $csrf]);
        $this->assertSame(1, $first['stops']);
        $this->assertFalse(file_exists($home.'/.lighttpd/panel-session'));

        $replay = $this->request($home, 'POST', ['pmss_panel_session' => $id], ['csrf' => $csrf]);

        $this->assertSame(302, $replay['status']);
        $this->assertStringNotContainsString('Set-Cookie', $replay['headers']);
        $this->assertSame(1, $replay['stops'], 'a replay must not rotate the agent sessions again');
    }

    public function testUc07ExpiredSessionsAreNotTheOwnersSession(): void
    {
        foreach ([[time() - 40000, time() - 60], [time() - 4000, time() - 1900]] as $n => [$created, $seen]) {
            $home = $this->account('fixtur0'.($n + 1));
            [$id, $csrf] = $this->writeSession($home, $created, $seen);
            $before = file_get_contents($home.'/.lighttpd/panel-session');

            $this->assertNothingChanged($this->request($home, 'POST', ['pmss_panel_session' => $id], ['csrf' => $csrf]), $home, $before, 'expired case '.$n);
        }
    }

    public function testUc08AccountWithThePanelLoginFeatureOffHasNoSessionAndNothingHappens(): void
    {
        // The opt-in is off: no ~/.lighttpd/panel-session was ever written. Before this change the page still stopped the agent UIs.
        $home = $this->account('fixtur01');

        foreach (['GET', 'POST'] as $method) {
            $response = $this->request($home, $method, ['pmss_panel_session' => str_repeat('a', 64)], ['csrf' => str_repeat('b', 64)]);
            $this->assertSame(302, $response['status'], $method);
            $this->assertStringNotContainsString('Set-Cookie', $response['headers']);
            $this->assertSame(0, $response['stops'], $method);
            $this->assertFalse(file_exists($home.'/.lighttpd/panel-session'), $method);
        }
    }

    public function testUc09ExistingAccountsReceiveEveryFileTheFixLivesIn(): void
    {
        // The skeleton sync list is what carries a fix to accounts that already exist.
        $sync = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');
        foreach (self::FILES as $file) {
            $this->assertStringContainsString("'www/".$file."',", $sync, $file.' must be delivered to existing accounts');
        }
        // An account that holds the delivered skeleton copies behaves as fixed.
        $home = $this->account('fixtur01');
        $this->assertNothingChanged($this->request($home, 'GET'), $home, '', 'upgraded account, no session');
    }

    public function testUc10AnotherAccountsSessionCookieDoesNotLogThisAccountOut(): void
    {
        $owner = $this->account('fixtur01');
        $other = $this->account('fixtur02');
        $this->writeSession($owner, time() - 600, time() - 60);
        [$otherId, $otherCsrf] = $this->writeSession($other, time() - 600, time() - 60, str_repeat('d', 64), str_repeat('e', 64));
        $ownerBefore = file_get_contents($owner.'/.lighttpd/panel-session');
        $otherBefore = file_get_contents($other.'/.lighttpd/panel-session');

        $response = $this->request($owner, 'POST', ['pmss_panel_session' => $otherId], ['csrf' => $otherCsrf]);

        $this->assertNothingChanged($response, $owner, $ownerBefore, 'foreign cookie');
        $this->assertSame($otherBefore, file_get_contents($other.'/.lighttpd/panel-session'));
    }

    public function testUc11LogoutRepeatsCleanlyForTwoSuccessiveSessions(): void
    {
        $home = $this->account('fixtur01');
        for ($round = 1; $round <= 2; $round++) {
            [$id, $csrf] = $this->writeSession($home, time() - 600, time() - 60, str_repeat((string) $round, 64), str_repeat('f', 64));
            $response = $this->request($home, 'POST', ['pmss_panel_session' => $id], ['csrf' => $csrf]);
            $this->assertSame(302, $response['status'], 'round '.$round);
            $this->assertSame($round, $response['stops'], 'round '.$round);
            $this->assertFalse(file_exists($home.'/.lighttpd/panel-session'), 'round '.$round);
        }
    }
}
