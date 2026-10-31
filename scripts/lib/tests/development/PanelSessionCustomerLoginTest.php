<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 4).'/etc/skel/www/panelSession.php';

class PanelSessionCustomerLoginTest extends TestCase
{
    private function fixturePaths(string $username = 'fixtur01'): array
    {
        $home = $this->pmssEnsureDir($this->pmssMakeTempDir('pmss-panel-session-home-').'/'.$username);
        $this->pmssEnsureDir($home.'/.lighttpd');
        return \pmssPanelSessionPaths($home);
    }

    private function writeHtpasswd(array $paths, string $username = 'fixtur01', string $password = 'secret'): string
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
        $this->assertTrue(\pmssPanelSessionPasswordValid($paths, 'fixtur01', 'secret'));
        $this->assertFalse(\pmssPanelSessionPasswordValid($paths, 'fixtur02', 'secret'));
    }

    public function testHtpasswdApr1MatchesIndependentOpenSslVectors(): void
    {
        // OpenSSL 3.5.7 passwd, apr1 mode, fixed salts, passwords supplied on stdin.
        $vectors = [
            ['secret', '$apr1$abcdefgh$h9FWgUz3n9YxylKLlR5SQ/'],
            ['päss \'$"; `echo fixture` \\ end', '$apr1$abcdefgh$zNhXk28cvJIaE69oK8Unf/'],
            ['', '$apr1$abcdefgh$L.PT565ESX4Tp2bqNs7Ie.'],
            ['1234567890123456', '$apr1$abcdefgh$0BN03pNFq3IjPZPHbhtmm/'],
            ['12345678901234567', '$apr1$abcdefgh$mL6v8ElrVdzrp4WqWzYvo0'],
            ['secret', '$apr1$x$12YdXEY0/hH4rDNkmZupO0'],
            ['secret', '$apr1$./0Az9zZ$nY/zy0S9c9IfGuchVT52s1'],
        ];
        foreach ($vectors as [$password, $hash]) {
            $this->assertTrue(\pmssPanelSessionPasswordMatchesHash($password, $hash));
            $this->assertFalse(\pmssPanelSessionPasswordMatchesHash($password.'wrong', $hash));
            $this->assertFalse(\pmssPanelSessionPasswordMatchesHash($password."\0ignored", $hash));
            $this->assertFalse(\pmssPanelSessionPasswordMatchesHash($password, substr($hash, 0, -1).'z'));
        }
    }

    public function testHtpasswdBcryptAndSha256RemainSupported(): void
    {
        foreach ([password_hash('secret', PASSWORD_BCRYPT), crypt('secret', '$5$pmsspaneltest$')] as $hash) {
            $this->assertTrue(\pmssPanelSessionPasswordMatchesHash('secret', $hash));
            $this->assertFalse(\pmssPanelSessionPasswordMatchesHash('wrong', $hash));
        }
    }

    public function testHtpasswdApr1RejectsMalformedHashes(): void
    {
        $hash = '$apr1$abcdefgh$h9FWgUz3n9YxylKLlR5SQ/';
        foreach (['', '*0', '*1', '$apr1$', '$apr1$$h9FWgUz3n9YxylKLlR5SQ/',
            '$apr1$abcdefghi$h9FWgUz3n9YxylKLlR5SQ/', '$apr1$abcdefg!$h9FWgUz3n9YxylKLlR5SQ/',
            substr($hash, 0, -1), $hash.'x', $hash.'$', substr($hash, 0, -1).'!',
            $hash."\r", $hash."\n", $hash."\0"] as $malformed) {
            $this->assertFalse(\pmssPanelSessionPasswordMatchesHash('secret', $malformed));
        }
    }

    public function testApr1LoginPreservesPasswordCsrfAndSessionGuards(): void
    {
        $paths = $this->fixturePaths();
        $password = 'päss \'$"; `echo fixture` \\ end';
        $hash = '$apr1$abcdefgh$zNhXk28cvJIaE69oK8Unf/';
        $this->pmssWriteFile($paths['htpasswd'], 'fixtur01:'.$hash."\n");
        $csrf = \pmssPanelSessionCsrfEnsure($paths, 1700000000);
        $this->assertTrue(\pmssPanelSessionPasswordValid($paths, 'fixtur01', $password));
        $this->assertFalse(\pmssPanelSessionPasswordValid($paths, 'fixtur02', $password));
        foreach ([['password' => $password], ['csrf' => $csrf, 'password' => 'wrong']] as $request) {
            $failed = \pmssPanelSessionLoginAttempt($request, $paths, 'fixtur01', 1700000001);
            $this->assertFalse($failed['ok']);
            $this->assertSame(isset($request['csrf']) ? 401 : 403, $failed['status']);
            $this->assertSame([], $failed['headers']);
        }
        $this->assertSame(2, \pmssPanelSessionRead($paths)['failed']);
        $oldId = str_repeat('a', 64);
        $this->assertTrue(\pmssPanelSessionWrite($paths, array_replace(\pmssPanelSessionRead($paths), ['id' => $oldId])));
        $result = \pmssPanelSessionLoginAttempt(['csrf' => $csrf, 'password' => $password], $paths, 'fixtur01', 1700000002);
        $this->assertTrue($result['ok']);
        $this->assertSame(302, $result['status']);
        $this->assertSame($oldId, $result['oldSessionId']);
        $this->assertTrue($result['sessionId'] !== $oldId);
        $this->assertSame($result['sessionId'], \pmssPanelSessionRead($paths)['id']);
        $this->assertSame(0, \pmssPanelSessionRead($paths)['failed']);
        $this->assertSame(0600, fileperms($paths['session']) & 0777);
        $log = file_get_contents($paths['log']);
        $this->assertStringNotContainsString($password, $log);
        $this->assertStringNotContainsString($hash, $log);
    }

    public function testLoginRequiresCsrfTokenOnPost(): void
    {
        $paths = $this->fixturePaths();
        $this->writeHtpasswd($paths);

        $result = \pmssPanelSessionLoginAttempt(['password' => 'secret'], $paths, 'fixtur01', 1700000000);

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
        ], $paths, 'fixtur01', 1700000001);

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
            'return' => '/user-fixtur01/stats.php',
        ], $paths, 'fixtur01', 1700000000);
        $session = \pmssPanelSessionRead($paths);

        $this->assertTrue((bool) $result['ok']);
        $this->assertSame(302, $result['status']);
        $this->assertSame($oldSessionId, $result['oldSessionId']);
        $this->assertMatches('/^[0-9a-f]{64}$/D', $result['sessionId']);
        $this->assertTrue($result['sessionId'] !== $oldSessionId, 'login must regenerate the session id');
        $this->assertSame($result['sessionId'], $session['id']);
        $this->assertSame(0, $session['failed']);
        $this->assertSame('/user-fixtur01/stats.php', $result['headers']['Location']);
        $this->assertSame(0600, fileperms($paths['session']) & 0777);
    }

    public function testSessionCookieHeadersCarryRequiredBrowserFlagsWithoutDomain(): void
    {
        $header = \pmssPanelSessionCookieHeader('fixtur01', str_repeat('c', 64), 1700000000);
        $delete = \pmssPanelSessionCookieDeleteHeader('fixtur01');

        foreach ([$header, $delete] as $cookieHeader) {
            $this->assertStringContainsAllStrings([
                'pmss_panel_session=',
                'Path=/user-fixtur01/',
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
        $this->assertSame('/user-fixtur01/stats.php', \pmssPanelSessionReturnUrl('fixtur01', '/user-fixtur01/stats.php'));
        $this->assertSame('/user-fixtur01/', \pmssPanelSessionReturnUrl('fixtur01', '/user-fixtur02/stats.php'));
        $this->assertSame('/user-fixtur01/', \pmssPanelSessionReturnUrl('fixtur01', 'https://example.test/user-fixtur01/'));
        $this->assertSame('/user-fixtur01/', \pmssPanelSessionReturnUrl('fixtur01', "//example.test/user-fixtur01/"));
    }
}
