<?php
/**
 * Behavioural test for the mod_magnet panel session gate.
 *
 * PanelSessionGateSourceTest only matches source text, which is exactly how the
 * invalid Lua pattern on line 25 shipped green (issue #963): the PCRE idiom
 * "($|/)" is the literal text "$|/" in a Lua pattern, so panel_user() returned
 * nil on every real path and the whole gate was inert. This test executes the
 * real panelSessionGate.lua under a stubbed `lighty` global and asserts the
 * decision it reaches, so a regression in the match or the branch order fails
 * the suite rather than passing on a source string.
 *
 * Requires a Lua interpreter (lua5.4/lua5.3/lua5.1/lua). When none is present the
 * test skips rather than fails, so a host without Lua stays green; CI and the dev
 * container install lua5.4 (Dockerfile, .github/workflows/ci.yml).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PanelSessionGateExecutionTest extends TestCase
{
    /** Locate an available Lua interpreter, or null when none is installed. */
    private function luaBinary(): ?string
    {
        foreach (['lua5.4', 'lua5.3', 'lua5.1', 'lua', 'luajit'] as $candidate) {
            if (trim((string) @shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null')) !== '') {
                return $candidate;
            }
        }
        return null;
    }

    /** Emit a Lua-safe double-quoted string literal for a test-controlled value. */
    private function luaQuote(string $value): string
    {
        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\') {
                $out .= '\\\\';
            } elseif ($char === '"') {
                $out .= '\\"';
            } elseif ($char === "\n") {
                $out .= '\\n';
            } elseif ($char === "\r") {
                $out .= '\\r';
            } else {
                $out .= $char;
            }
        }
        return '"'.$out.'"';
    }

    /**
     * Run the gate for one request and return its outcome.
     *
     * @param array<string,string> $headers
     * @return array{rc:string,remote_user:string,auth:string,err:string}
     */
    private function runGate(string $luaBinary, string $path, array $headers): array
    {
        $gate = $this->pmssRepoPath('scripts/lib/lighttpd/panelSessionGate.lua');

        $requestLines = '';
        foreach ($headers as $name => $value) {
            $requestLines .= '  ['.$this->luaQuote((string) $name).'] = '.$this->luaQuote((string) $value).",\n";
        }

        // Stub only what the gate reads; silence its log_event print() so stdout
        // carries just our markers. dofile() returns the chunk's `return` value.
        $driver = "print = function() end\n"
            ."local reqEnv = {}\n"
            ."lighty = {\n"
            ."  req_env = reqEnv,\n"
            ."  request = {\n".$requestLines."  },\n"
            ."  env = { [\"uri.path\"] = ".$this->luaQuote($path)." },\n"
            ."  header = {},\n"
            ."}\n"
            ."local ok, rc = pcall(dofile, ".$this->luaQuote($gate).")\n"
            ."if not ok then io.write(\"ERR=\"..tostring(rc)..\"\\n\") os.exit(0) end\n"
            ."io.write(\"RC=\"..tostring(rc)..\"\\n\")\n"
            ."io.write(\"REMOTE_USER=\"..tostring(reqEnv[\"REMOTE_USER\"])..\"\\n\")\n"
            ."io.write(\"AUTH=\"..tostring(reqEnv[\"PMSS_PANEL_AUTH\"])..\"\\n\")\n";

        $dir = $this->pmssMakeTempDir('panelgate');
        $driverPath = $dir.'/driver.lua';
        file_put_contents($driverPath, $driver);

        $output = (string) @shell_exec($luaBinary.' '.escapeshellarg($driverPath).' 2>&1');
        $result = ['rc' => '', 'remote_user' => '', 'auth' => '', 'err' => ''];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (strpos($line, 'RC=') === 0) {
                $result['rc'] = substr($line, 3);
            } elseif (strpos($line, 'REMOTE_USER=') === 0) {
                $result['remote_user'] = substr($line, 12);
            } elseif (strpos($line, 'AUTH=') === 0) {
                $result['auth'] = substr($line, 5);
            } elseif (strpos($line, 'ERR=') === 0) {
                $result['err'] = substr($line, 4);
            }
        }
        return $result;
    }

    public function testGateResolvesPanelUserAndReachesTheLoginHandler(): void
    {
        $lua = $this->luaBinary();
        if ($lua === null) {
            throw new SkipTest('no Lua interpreter (lua5.4/lua5.3/lua5.1/lua) available');
        }

        // The public login handler under a matched user: the gate resolves the
        // user and hands the request on. Under the old pattern panel_user() was
        // nil here and REMOTE_USER was never set.
        $result = $this->runGate($lua, '/user-bob/panelSessionLogin.php', []);
        $this->assertSame('', $result['err'], 'gate raised a Lua error: '.$result['err']);
        $this->assertSame('bob', $result['remote_user'], 'login handler must resolve the panel user');
        $this->assertSame('login', $result['auth']);
    }

    public function testGateRedirectsHtmlVisitorWithoutSessionToLogin(): void
    {
        $lua = $this->luaBinary();
        if ($lua === null) {
            throw new SkipTest('no Lua interpreter (lua5.4/lua5.3/lua5.1/lua) available');
        }

        // No cookie, no Authorization, Accept: text/html -> redirect to login (302).
        $result = $this->runGate($lua, '/user-bob/', ['Accept' => 'text/html']);
        $this->assertSame('302', $result['rc'], 'html visitor with no session must be redirected to login');

        // The bare "/user-bob" form (no trailing slash) must match too — this is the
        // end-of-string case the original "$" anchor was meant to cover.
        $result = $this->runGate($lua, '/user-bob', ['Accept' => 'text/html']);
        $this->assertSame('302', $result['rc'], 'bare /user-<name> must also be recognised');
    }

    public function testGateDelegatesWhenBasicCredentialsArePresent(): void
    {
        $lua = $this->luaBinary();
        if ($lua === null) {
            throw new SkipTest('no Lua interpreter (lua5.4/lua5.3/lua5.1/lua) available');
        }

        // A request already carrying Basic credentials is handed to the htpasswd
        // path (return nil), not redirected.
        $result = $this->runGate($lua, '/user-bob/', ['Authorization' => 'Basic Zm9vOmJhcg==']);
        $this->assertSame('nil', $result['rc'], 'a request with Basic credentials must delegate');
    }

    public function testGateIgnoresNonPanelPaths(): void
    {
        $lua = $this->luaBinary();
        if ($lua === null) {
            throw new SkipTest('no Lua interpreter (lua5.4/lua5.3/lua5.1/lua) available');
        }

        // A path that is not /user-<name> must delegate untouched and never set
        // REMOTE_USER, so the gate cannot authenticate the wrong account.
        $result = $this->runGate($lua, '/webdav-bob/file', ['Accept' => 'text/html']);
        $this->assertSame('nil', $result['rc']);
        $this->assertSame('nil', $result['remote_user'], 'non-panel paths must not set REMOTE_USER');
    }
}
