<?php
/**
 * Deluge reverse proxy hardening tests.
 *
 * Goal: Ensure Deluge follows the "nginx is lightweight; per-user lighttpd is heavy"
 * model, while keeping the legacy /deluge-<user>/ URL working by proxying
 * through per-user lighttpd (with a slash-normalizing 308 redirect).
 *
 * These tests are intentionally adversarial and try to catch:
 * - regressions back to nginx -> per-app port proxying
 * - broken legacy URL mapping (path/query loss)
 * - config parsing edge cases for Deluge's web.conf (two JSON objects)
 * - unsafe template drift (missing deprecation markers, missing proxy params)
 */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

if (!function_exists('pmssDelugeReadWebConf')) {
    require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';
}

class DelugeReverseProxyHardeningTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-deluge-proxy-test', 0700]; }

    // =========================================================================
    // SECTION 1: nginx legacy Deluge URL routing (slash redirect + proxy)
    // =========================================================================

    public function testNginxUserTemplateDelugeLegacyHasExactRedirectWithoutSlash(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.nginx-user', [
            'location = /deluge-##username {',
            'return 308 /deluge-##username/$is_args$args;',
        ]);
    }

    public function testNginxUserTemplateDelugeLegacyProxyLocationExists(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.nginx-user', ['location /deluge-##username/ {', 'proxy_pass http://127.0.0.1:##serverPort/deluge-##username/;', 'include /etc/nginx/proxy_params;']);
    }

    public function testNginxUserTemplateDelugeCanonicalCookiePathIsNormalized(): void
    {
        // Normalize doubled cookie paths caused by lighttpd map-urlpath rewrites.
        $this->pmssAssertRepoFileContainsString(
            'etc/seedbox/config/template.nginx-user',
            'proxy_cookie_path ~^/user-##username/deluge/user-##username/deluge/.* /user-##username/deluge/;'
        );
    }

    public function testNginxUserTemplateDelugeLegacyDoesNotUseRegexRedirectForSubpaths(): void
    {
        $this->pmssAssertRepoFileNotContainsString('etc/seedbox/config/template.nginx-user', 'location ~ ^/deluge-##username/(.*)$ {');
    }

    public function testNginxUserTemplateDelugeLegacyRedirectPreservesQueryString(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user');

        // Deluge legacy redirects must preserve args to avoid breaking deep links.
        // Use 308 (permanent + method-preserving) to avoid breaking Deluge's JSON RPC POSTs.
        preg_match_all('/^\\s*return\\s+308\\s+([^;]+);\\s*$/m', $template, $matches);
        $this->assertTrue(!empty($matches[1]), 'Expected at least one return 308 directive');

        $delugeReturns = 0;
        foreach ($matches[1] as $target) {
            if (strpos($target, '/deluge-##username/') === false) {
                continue;
            }
            $delugeReturns++;
            $this->assertTrue(strpos($target, '$is_args$args') !== false, 'Deluge redirect must preserve query string');
        }
        $this->assertTrue($delugeReturns >= 1, 'Expected Deluge slash-normalizing redirect target');
    }

    public function testNginxUserTemplateDelugeLegacyRedirectKeepsDeprecationMarker(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/template.nginx-user', 'Keep for compatibility until at least 2028-01-28');
    }

    public function testNginxUserTemplateDelugeLegacyDoesNotProxyToDelugeWebPort(): void
    {
        // Regression guard: legacy configs proxied directly to deluge-web.
        $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/seedbox/config/template.nginx-user', [], [
            '##delugeWebPort' => 'nginx user template must not use delugeWebPort placeholder',
            'proxy_pass http://127.0.0.1:##delugeWebPort' => 'nginx must not proxy deluge-web directly',
        ]);
    }

    public function testNginxUserTemplateDelugeLegacyRedirectIsNotAnOpenRedirect(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user');

        // The Deluge legacy redirect targets must be local paths (no scheme/host).
        preg_match_all('/^\\s*return\\s+308\\s+([^;]+);\\s*$/m', $template, $matches);
        $this->assertTrue(!empty($matches[1]), 'Expected at least one return 308 directive');

        foreach ($matches[1] as $target) {
            if (strpos($target, '/deluge-##username/') === false) {
                continue;
            }
            $this->assertTrue(strpos($target, 'http') === false, 'Deluge redirect must not include scheme/host');
            $this->assertTrue(strpos($target, '$host') === false, 'Deluge redirect must not reference $host');
            $this->assertTrue(strpos($target, '$scheme') === false, 'Deluge redirect must not reference $scheme');
            $this->assertTrue(strpos($target, '//') !== 0, 'Deluge redirect must not be protocol-relative');
        }
    }

    // =========================================================================
    // SECTION 2: Active base-host routing
    // =========================================================================

    public function testCreateNginxConfigAddsBaseHostnameToDefaultServerName(): void
    {
        // Regression guard: base-host requests (FQDN) must land on the main vhost
        // where /etc/nginx/users/* is included (legacy Deluge redirects live there).
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/nginxConfig/setupDefaultSite.php', ["'server_name localhost;'", "'server_name localhost '.\$subdomainBase.';'"]);
    }

    public function testCreateNginxConfigStillSupportsLegacyDelugeWebPortPlaceholder(): void
    {
        // Backward compat: older nginx user templates may still use ##delugeWebPort.
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/nginxConfig/userConfigsGenerate.php', ["strpos(\$template, '##delugeWebPort')", "\$replacements['##delugeWebPort']"]);
    }

    public function testCreateNginxConfigDelugeWebPortPlaceholderUsesThePortReservation(): void
    {
        $script = $this->pmssReadRepoExecutableSource('scripts/lib/nginxConfig/userConfigsGenerate.php');

        // The legacy placeholder takes the root port-manager reservation, never a file in the home (ADR 0213).
        $this->assertStringContainsAndOmitsStrings([
            "pmssReadRegularFileNetworkPort(\$runtimePortDir.'/deluge-web-'.\$user) ?? 1",
        ], ["'/.delugePort'", "'/.delugeWebPort'"], $script);
    }

    // =========================================================================
    // SECTION 3: lighttpd Deluge proxy fragment (canonical + legacy mapping)
    // =========================================================================

    public function testDelugeLighttpdProxyFragmentContainsCanonicalBase(): void
    {
        $fragment = \pmssLighttpdManagedProxyFragment('deluge', 'testuser', 31111);

        $this->assertStringContainsAllStrings(['^/user-testuser/deluge($|/)', '"port" => 31111', '"host" => "127.0.0.1"'], $fragment);
    }

    public function testDelugeLighttpdProxyFragmentContainsLegacyMapping(): void
    {
        $fragment = \pmssLighttpdManagedProxyFragment('deluge', 'testuser', 31111);

        $this->assertStringContainsAllStrings(['^/deluge-testuser($|/)', '"map-urlpath"', '"/deluge-testuser/"  => "/"', '"/deluge-testuser" => ""'], $fragment);
    }

    public function testDelugeLighttpdProxyFragmentDisablesBasicAuthForDelugePaths(): void
    {
        $fragment = \pmssLighttpdManagedProxyFragment('deluge', 'testuser', 31111);

        $this->assertEquals(2, substr_count($fragment, 'auth.require = ()'));
    }

    public function testDelugeLighttpdProxyFragmentHasDeprecationDate(): void
    {
        $fragment = \pmssLighttpdManagedProxyFragment('deluge', 'testuser', 31111);
        $this->assertStringContainsString('2028-01-28', $fragment);
    }

    public function testDelugeLighttpdProxyFragmentUsesAnchoredRegexesOnly(): void
    {
        $fragment = \pmssLighttpdManagedProxyFragment('deluge', 'testuser', 31111);

        // Regression guard: avoid permissive patterns (e.g., ".*") that could match other paths.
        $this->assertStringContainsAndOmitsStrings(['^/user-testuser/deluge', '^/deluge-testuser'], ['.*'], $fragment);
    }

    public function testDelugeLighttpdProxyFragmentIsLoopbackOnly(): void
    {
        $fragment = \pmssLighttpdManagedProxyFragment('deluge', 'testuser', 31111);

        $this->assertStringContainsAndOmitsStrings(['"host" => "127.0.0.1"'], ['"host" => "0.0.0.0"', '"host" => "::"'], $fragment);
    }

    // =========================================================================
    // SECTION 4: Deluge web.conf parsing (two JSON objects) - adversarial inputs
    // =========================================================================

    public function testDelugeReadWebConfParsesTwoJsonObjects(): void
    {
        $path = $this->tempDir.'/web.conf';
        $meta = '{"file":2,"format":1}';
        $config = '{"base":"/user-testuser/deluge/","port":8112,"x":1}';
        $this->pmssWriteFile($path, $meta.$config);

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue(is_array($parsed), 'Expected parsed array');
        $this->assertEquals(2, $parsed['meta']['file'] ?? null);
        $this->assertEquals(1, $parsed['meta']['format'] ?? null);
        $this->assertEquals(8112, $parsed['config']['port'] ?? null);
        $this->assertEquals('/user-testuser/deluge/', $parsed['config']['base'] ?? null);
    }

    public function testDelugeReadWebConfHandlesWhitespaceAndNewlines(): void
    {
        $path = $this->tempDir.'/web-ws.conf';
        $meta = "  \n\t {\"file\":2,\"format\":1}\n";
        $config = "  \n{\"base\":\"/user-testuser/deluge/\",\"port\":8112}\n";
        $this->pmssWriteFile($path, $meta.$config);

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue(is_array($parsed));
        $this->assertEquals(8112, $parsed['config']['port'] ?? null);
    }

    public function testDelugeSessionsListDetectedMatchesEmptyListLiteral(): void
    {
        $raw = '{"file":2,"format":1}{"sessions":[],"base":"/user-testuser/deluge/"}';
        $this->assertTrue(\pmssDelugeSessionsListDetected($raw));
    }

    public function testDelugeSessionsListDetectedIgnoresObjectForm(): void
    {
        $raw = '{"file":2,"format":1}{"sessions":{},"base":"/user-testuser/deluge/"}';
        $this->assertTrue(\pmssDelugeSessionsListDetected($raw) === false);
    }

    public function testDelugeNormalizeEmptySessionsObjectConvertsArrayToObject(): void
    {
        $config = ['sessions' => []];
        $changed = \pmssDelugeNormalizeEmptySessionsObject($config);

        $this->assertTrue($changed);
        $this->assertTrue(is_object($config['sessions']));
        $this->assertEquals('{}', json_encode($config['sessions']));
    }

    public function testDelugeNormalizeEmptySessionsObjectKeepsNonEmptyMap(): void
    {
        $config = ['sessions' => ['session1' => ['expires' => 1]]];
        $changed = \pmssDelugeNormalizeEmptySessionsObject($config);

        $this->assertTrue($changed === false);
        $this->assertTrue(is_array($config['sessions']));
        $this->assertTrue(isset($config['sessions']['session1']));
    }

    public function testDelugeReadWebConfHandlesBracesInsideStrings(): void
    {
        $path = $this->tempDir.'/web-braces.conf';
        $meta = "{\"file\":2,\"format\":1,\"note\":\"a}b{c\"}";
        $config = "{\"base\":\"/user-testuser/deluge/\",\"port\":8112,\"banner\":\"{hello}\"}";
        $this->pmssWriteFile($path, $meta.$config);

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue(is_array($parsed));
        $this->assertEquals('a}b{c', $parsed['meta']['note'] ?? null);
        $this->assertEquals('{hello}', $parsed['config']['banner'] ?? null);
    }

    public function testDelugeReadWebConfRejectsNonObjectStart(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/web-bad-start.conf', "[]{}");

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null for invalid config');
    }

    public function testDelugeReadWebConfRejectsSingleObjectOnly(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/web-one.conf', '{"file":2,"format":1}');

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null when second JSON object is missing');
    }

    public function testDelugeReadWebConfRejectsUnterminatedFirstObject(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/web-unterminated-first.conf', "{\"file\":2,\"format\":1,\"x\":\"unterminated}{}");

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null for unterminated first JSON object');
    }

    public function testDelugeReadWebConfRejectsSecondObjectWhenNotAnObject(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/web-second-not-object.conf', '{"file":2,"format":1}null');

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null when second JSON is not an object');
    }

    public function testDelugeReadWebConfRejectsMetaWhenNotAnObject(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/web-meta-not-object.conf', '[]{}');

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null when meta JSON is not an object');
    }

    public function testDelugeReadWebConfRejectsTruncatedJson(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/web-trunc.conf', '{"file":2,"format":1}{"base":"/user-testuser/deluge/","port":');

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null for truncated JSON');
    }

    public function testDelugeReadWebConfRejectsPoisonedNullByte(): void
    {
        $path = $this->tempDir.'/web-nullbyte.conf';
        // Raw NUL byte is not valid JSON and should fail parsing.
        $this->pmssWriteFile($path, "{\"file\":2,\"format\":1}\0{\"base\":\"/user-testuser/deluge/\",\"port\":8112}");

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null for invalid JSON containing NUL byte');
    }

    public function testDelugeReadWebConfRejectsUnterminatedString(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/web-unterminated-string.conf', "{\"file\":2,\"format\":1,\"x\":\"unterminated}{}");

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null for unterminated JSON string');
    }

    public function testDelugeReadWebConfRejectsMissingClosingBrace(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/web-missing-brace.conf', "{\"file\":2,\"format\":1{\"base\":\"/user-testuser/deluge/\"}");

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null for missing closing brace');
    }

    public function testDelugeReadWebConfHandlesEscapedQuotes(): void
    {
        $good = "{\"file\":2,\"format\":1,\"note\":\"a\\\"b\\\"c\"}{\"base\":\"/user-testuser/deluge/\",\"port\":8112}";
        $this->pmssWriteRelativeFile($this->tempDir, 'web-escaped.conf', $good);
        $parsed = \pmssDelugeReadWebConf($this->tempDir.'/web-escaped.conf');
        $this->assertTrue(is_array($parsed));
        $this->assertEquals('a"b"c', $parsed['meta']['note'] ?? null);
    }

    public function testDelugeReadWebConfFailsSoftOnUtf8Bom(): void
    {
        $bom = "\xEF\xBB\xBF";
        $path = $this->pmssWriteFile($this->tempDir.'/web-bom.conf', $bom.'{"file":2,"format":1}{"base":"/user-testuser/deluge/","port":8112}');

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null for BOM-prefixed config (fail-soft)');
    }

    public function testDelugeWriteWebConfRoundTrips(): void
    {
        $path = $this->tempDir.'/web-roundtrip.conf';
        $meta = '{"file":2,"format":1}';
        $config = '{"base":"/deluge-testuser/","port":8112}';
        $this->pmssWriteFile($path, $meta.$config);
        @chmod($path, 0640);

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue(is_array($parsed));

        $parsed['config']['base'] = '/user-testuser/deluge/';
        $ok = \pmssDelugeWriteWebConf($path, $parsed['meta'], $parsed['config'], 'root');
        $this->assertTrue($ok, 'Expected write to succeed');

        $parsed2 = \pmssDelugeReadWebConf($path);
        $this->assertTrue(is_array($parsed2));
        $this->assertEquals('/user-testuser/deluge/', $parsed2['config']['base'] ?? null);
        $this->assertEquals(0640, @fileperms($path) & 0777);
    }

    // =========================================================================
    // SECTION 5: Template drift guards
    // =========================================================================

    public function testDelugeWebTemplateBaseIsCanonical(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/seedbox/config/template.deluge.web.conf', ['"base": "/user-##USER/deluge/"'], ['"/deluge-##USER/"']);
    }

    public function testDelugeWebTemplateDisablesFirstLoginWizard(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/template.deluge.web.conf', '"first_login": false');
    }

    public function testDelugeWebTemplateUsesBoundedSessionTimeout(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/template.deluge.web.conf', '"session_timeout": 3600');
    }

    public function testCreateNginxConfigUsesScopedWebdavProxyParamsInWebdavBlocks(): void
    {
        require_once dirname(__DIR__, 3).'/lib/nginxConfig/templates.php';
        $templates = \pmssNginxUserSubdomainTemplates();

        // Keep upload-specific timeout and streaming directives in one WebDAV include.
        $this->assertStringContainsAllStrings(['location /webdav-##user##/', 'include /etc/nginx/webdav_proxy_params;'], $templates['public']);
    }

    public function testWebdavLocationsAllowLargeUploads(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.nginx-user', ['location /webdav-##username/', 'client_max_body_size 0;']);

        require_once dirname(__DIR__, 3).'/lib/nginxConfig/templates.php';
        $templates = \pmssNginxUserSubdomainTemplates();
        $this->assertStringContainsAllStrings(['client_max_body_size 0;'], $templates['public']);
    }
}
