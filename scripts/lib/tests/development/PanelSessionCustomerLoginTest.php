<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 4).'/etc/skel/www/panelSession.php';

class PanelSessionCustomerLoginTest extends TestCase
{
    private function fixturePaths(string $username = 'alice'): array
    {
        $home = $this->pmssEnsureDir($this->pmssMakeTempDir('pmss-panel-session-home-').'/'.$username);
        $this->pmssEnsureDir($home.'/.lighttpd');
        return \pmssPanelSessionPaths($home);
    }

    private function writeHtpasswd(array $paths, string $username = 'alice', string $password = 'secret'): string
    {
        $hash = crypt($password, '$6$rounds=5000$pmsspaneltest$');
        $this->assertTrue(is_string($hash) && $hash !== '');
        $this->pmssWriteFile($paths['htpasswd'], $username.':'.$hash."\n");
        return $hash;
    }

    public function testHtpasswdCryptCompareAcceptsExpectedPasswordOnly(): void
    {
        $paths = $this->fixturePaths();
        $hash = $this->writeHtpasswd($paths);

        $this->assertTrue(\pmssPanelSessionPasswordMatchesHash('secret', $hash));
        $this->assertFalse(\pmssPanelSessionPasswordMatchesHash('wrong', $hash));
        $this->assertTrue(\pmssPanelSessionPasswordValid($paths, 'alice', 'secret'));
        $this->assertFalse(\pmssPanelSessionPasswordValid($paths, 'bob', 'secret'));
    }

    public function testLoginRequiresCsrfTokenOnPost(): void
    {
        $paths = $this->fixturePaths();
        $this->writeHtpasswd($paths);

        $result = \pmssPanelSessionLoginAttempt(['password' => 'secret'], $paths, 'alice', 1700000000);

        $this->assertFalse((bool) $result['ok']);
        $this->assertSame(403, $result['status']);
        $this->assertSame([], $result['headers']);
        $this->assertSame(1, \pmssPanelSessionRead($paths)['failed']);
    }

    public function testLoginRejectsWrongPasswordAndIncrementsFailedCounter(): void
    {
        $paths = $this->fixturePaths();
        $this->writeHtpasswd($paths);
        $csrf = \pmssPanelSessionCsrfEnsure($paths, 1700000000);

        $result = \pmssPanelSessionLoginAttempt([
            'csrf' => $csrf,
            'password' => 'wrong',
        ], $paths, 'alice', 1700000001);

        $this->assertFalse((bool) $result['ok']);
        $this->assertSame(401, $result['status']);
        $this->assertSame([], $result['headers']);
        $this->assertSame(1, \pmssPanelSessionRead($paths)['failed']);
    }

    public function testSuccessfulLoginRegeneratesSessionIdAndWritesMode600SessionFile(): void
    {
        $paths = $this->fixturePaths();
        $this->writeHtpasswd($paths);
        $oldSessionId = str_repeat('a', 64);
        $csrf = str_repeat('b', 64);
        $this->assertTrue(\pmssPanelSessionWrite($paths, [
            'id' => $oldSessionId,
            'csrf' => $csrf,
            'created' => 1699999000,
            'seen' => 1699999000,
            'failed' => 3,
        ]));

        $result = \pmssPanelSessionLoginAttempt([
            'csrf' => $csrf,
            'password' => 'secret',
            'return' => '/user-alice/stats.php',
        ], $paths, 'alice', 1700000000);
        $session = \pmssPanelSessionRead($paths);

        $this->assertTrue((bool) $result['ok']);
        $this->assertSame(302, $result['status']);
        $this->assertSame($oldSessionId, $result['oldSessionId']);
        $this->assertMatches('/^[0-9a-f]{64}$/D', $result['sessionId']);
        $this->assertTrue($result['sessionId'] !== $oldSessionId, 'login must regenerate the session id');
        $this->assertSame($result['sessionId'], $session['id']);
        $this->assertSame(0, $session['failed']);
        $this->assertSame('/user-alice/stats.php', $result['headers']['Location']);
        $this->assertSame(0600, fileperms($paths['session']) & 0777);
    }

    public function testSessionCookieHeadersCarryRequiredBrowserFlagsWithoutDomain(): void
    {
        $header = \pmssPanelSessionCookieHeader('alice', str_repeat('c', 64), 1700000000);
        $delete = \pmssPanelSessionCookieDeleteHeader('alice');

        foreach ([$header, $delete] as $cookieHeader) {
            $this->assertStringContainsAllStrings([
                'pmss_panel_session=',
                'Path=/user-alice/',
                'Secure',
                'HttpOnly',
                'SameSite=Lax',
            ], $cookieHeader);
            $this->assertStringNotContainsString('Domain=', $cookieHeader);
        }
        $this->assertStringContainsString('Max-Age=28800', $header);
        $this->assertStringContainsString('Max-Age=0', $delete);
    }

    public function testReturnUrlIsScopedToTheSameUserPanel(): void
    {
        $this->assertSame('/user-alice/stats.php', \pmssPanelSessionReturnUrl('alice', '/user-alice/stats.php'));
        $this->assertSame('/user-alice/', \pmssPanelSessionReturnUrl('alice', '/user-bob/stats.php'));
        $this->assertSame('/user-alice/', \pmssPanelSessionReturnUrl('alice', 'https://example.test/user-alice/'));
        $this->assertSame('/user-alice/', \pmssPanelSessionReturnUrl('alice', "//example.test/user-alice/"));
    }
}
