<?php
/**
 * Deluge reverse proxy hardening tests.
 *
 * Goal: Ensure Deluge follows the "nginx is lightweight; per-user lighttpd is heavy"
 * model, while keeping the legacy /deluge-<user>/ URL working via a 301 redirect
 * to the canonical /user-<user>/deluge/ base.
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
    private $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/pmss-deluge-proxy-test-'.getmypid();
        @mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function assertStringNotContainsString(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertTrue(
            strpos($haystack, $needle) === false,
            $msg !== '' ? $msg : "Expected string NOT to contain '$needle'"
        );
    }

    private function readRepoFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 4).'/'.$relativePath;
        $data = @file_get_contents($path);
        $this->assertTrue(is_string($data), "Failed to read {$relativePath}");
        return (string)$data;
    }

    // =========================================================================
    // SECTION 1: nginx legacy Deluge URL redirect (must be 301 to canonical)
    // =========================================================================

    public function testNginxUserTemplateDelugeLegacyHasExactRedirectWithoutSlash(): void
    {
        $template = $this->readRepoFile('etc/seedbox/config/template.nginx-user');

        $this->assertStringContainsString('location = /deluge-##username {', $template);
        $this->assertStringContainsString('return 301 /user-##username/deluge/$is_args$args;', $template);
    }

    public function testNginxUserTemplateDelugeLegacyHasExactRedirectWithSlash(): void
    {
        $template = $this->readRepoFile('etc/seedbox/config/template.nginx-user');

        $this->assertStringContainsString('location = /deluge-##username/ {', $template);
        $this->assertStringContainsString('return 301 /user-##username/deluge/$is_args$args;', $template);
    }

    public function testNginxUserTemplateDelugeLegacyHasRegexRedirectForSubpaths(): void
    {
        $template = $this->readRepoFile('etc/seedbox/config/template.nginx-user');

        $this->assertStringContainsString('location ~ ^/deluge-##username/(.*)$ {', $template);
        $this->assertStringContainsString('return 301 /user-##username/deluge/$1$is_args$args;', $template);
    }

    public function testNginxUserTemplateDelugeLegacyRedirectPreservesQueryString(): void
    {
        $template = $this->readRepoFile('etc/seedbox/config/template.nginx-user');

        // All Deluge legacy redirects must preserve args to avoid breaking deep links.
        preg_match_all('/^\\s*return\\s+301\\s+([^;]+);\\s*$/m', $template, $matches);
        $this->assertTrue(!empty($matches[1]), 'Expected at least one return 301 directive');

        $delugeReturns = 0;
        foreach ($matches[1] as $target) {
            if (strpos($target, '/user-##username/deluge/') === false) {
                continue;
            }
            $delugeReturns++;
            $this->assertTrue(strpos($target, '$is_args$args') !== false, 'Deluge redirect must preserve query string');
        }
        $this->assertTrue($delugeReturns >= 2, 'Expected multiple Deluge redirect return targets');
    }

    public function testNginxUserTemplateDelugeLegacyRedirectKeepsDeprecationMarker(): void
    {
        $template = $this->readRepoFile('etc/seedbox/config/template.nginx-user');
        $this->assertStringContainsString('Keep for compatibility until at least 2028-01-28', $template);
    }

    public function testNginxUserTemplateDelugeLegacyDoesNotProxyToDelugeWebPort(): void
    {
        $template = $this->readRepoFile('etc/seedbox/config/template.nginx-user');

        // Regression guard: legacy configs proxied directly to deluge-web.
        $this->assertStringNotContainsString('##delugeWebPort', $template, 'nginx user template must not use delugeWebPort placeholder');
        $this->assertStringNotContainsString('proxy_pass http://127.0.0.1:##delugeWebPort', $template, 'nginx must not proxy deluge-web directly');
    }

    public function testNginxUserTemplateDelugeLegacyRedirectIsNotAnOpenRedirect(): void
    {
        $template = $this->readRepoFile('etc/seedbox/config/template.nginx-user');

        // The Deluge legacy redirect targets must be local paths (no scheme/host).
        preg_match_all('/^\\s*return\\s+301\\s+([^;]+);\\s*$/m', $template, $matches);
        $this->assertTrue(!empty($matches[1]), 'Expected at least one return 301 directive');

        foreach ($matches[1] as $target) {
            if (strpos($target, '/user-##username/deluge/') === false) {
                continue;
            }
            $this->assertTrue(strpos($target, 'http') === false, 'Deluge redirect must not include scheme/host');
            $this->assertTrue(strpos($target, '$host') === false, 'Deluge redirect must not reference $host');
            $this->assertTrue(strpos($target, '$scheme') === false, 'Deluge redirect must not reference $scheme');
            $this->assertTrue(strpos($target, '//') !== 0, 'Deluge redirect must not be protocol-relative');
        }
    }

    // =========================================================================
    // SECTION 2: Subdomain routing hardening (avoid double-prefix /user-<user>/)
    // =========================================================================

    public function testCreateNginxConfigPrivateSubdomainProxiesUserPrefixAsIs(): void
    {
        $script = $this->readRepoFile('scripts/util/createNginxConfig.php');

        $this->assertStringContainsString('location ^~ /user-##user##/ {', $script);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:##port##;', $script);
    }

    public function testCreateNginxConfigPrivateSubdomainStillPrefixesRootToUserArea(): void
    {
        $script = $this->readRepoFile('scripts/util/createNginxConfig.php');

        $this->assertStringContainsString('location / {', $script);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:##port##/user-##user##/;', $script);
    }

    public function testCreateNginxConfigPrivateSubdomainAsIsLocationUsesProxyParams(): void
    {
        $script = $this->readRepoFile('scripts/util/createNginxConfig.php');
        $this->assertStringContainsString('location ^~ /user-##user##/', $script);
        $this->assertStringContainsString('include /etc/nginx/proxy_params;', $script);
    }

    public function testCreateNginxConfigPrivateSubdomainDoesNotExposePublicPrefix(): void
    {
        $script = $this->readRepoFile('scripts/util/createNginxConfig.php');

        // The private vhost is intended to expose only /user-<user>/ and /webdav-<user>/.
        $block = $this->extractHeredoc($script, '$privateSubdomainTemplate');
        $this->assertStringNotContainsString('/public-##user##/', $block);
    }

    public function testCreateNginxConfigStillSupportsLegacyDelugeWebPortPlaceholder(): void
    {
        $script = $this->readRepoFile('scripts/util/createNginxConfig.php');

        // Backward compat: older nginx user templates may still use ##delugeWebPort.
        $this->assertStringContainsString('##delugeWebPort', $script);
        $this->assertStringContainsString("strpos(\$userTemplate, '##delugeWebPort')", $script);
    }

    // =========================================================================
    // SECTION 3: lighttpd Deluge proxy fragment (canonical + legacy mapping)
    // =========================================================================

    public function testDelugeLighttpdProxyFragmentContainsCanonicalBase(): void
    {
        $fragment = \pmssDelugeLighttpdProxyFragment('testuser', 31111);

        $this->assertStringContainsString('^/user-testuser/deluge(\\$|/)', $fragment);
        $this->assertStringContainsString('"port" => 31111', $fragment);
        $this->assertStringContainsString('"host" => "127.0.0.1"', $fragment);
    }

    public function testDelugeLighttpdProxyFragmentContainsLegacyMapping(): void
    {
        $fragment = \pmssDelugeLighttpdProxyFragment('testuser', 31111);

        $this->assertStringContainsString('^/deluge-testuser(\\$|/)', $fragment);
        $this->assertStringContainsString('"map-urlpath"', $fragment);
        $this->assertStringContainsString('"/deluge-testuser/"  => "/user-testuser/deluge/"', $fragment);
        $this->assertStringContainsString('"/deluge-testuser" => "/user-testuser/deluge"', $fragment);
    }

    public function testDelugeLighttpdProxyFragmentHasDeprecationDate(): void
    {
        $fragment = \pmssDelugeLighttpdProxyFragment('testuser', 31111);
        $this->assertStringContainsString('2028-01-28', $fragment);
    }

    public function testDelugeLighttpdProxyFragmentUsesAnchoredRegexesOnly(): void
    {
        $fragment = \pmssDelugeLighttpdProxyFragment('testuser', 31111);

        // Regression guard: avoid permissive patterns (e.g., ".*") that could match other paths.
        $this->assertStringNotContainsString('.*', $fragment);
        $this->assertStringContainsString('^/user-testuser/deluge', $fragment);
        $this->assertStringContainsString('^/deluge-testuser', $fragment);
    }

    public function testDelugeLighttpdProxyFragmentIsLoopbackOnly(): void
    {
        $fragment = \pmssDelugeLighttpdProxyFragment('testuser', 31111);

        $this->assertStringContainsString('"host" => "127.0.0.1"', $fragment);
        $this->assertStringNotContainsString('"host" => "0.0.0.0"', $fragment);
        $this->assertStringNotContainsString('"host" => "::"', $fragment);
    }

    // =========================================================================
    // SECTION 4: Deluge web.conf parsing (two JSON objects) - adversarial inputs
    // =========================================================================

    public function testDelugeReadWebConfParsesTwoJsonObjects(): void
    {
        $path = $this->tempDir.'/web.conf';
        $meta = '{"file":2,"format":1}';
        $config = '{"base":"/user-testuser/deluge/","port":8112,"x":1}';
        file_put_contents($path, $meta.$config);

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
        file_put_contents($path, $meta.$config);

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue(is_array($parsed));
        $this->assertEquals(8112, $parsed['config']['port'] ?? null);
    }

    public function testDelugeReadWebConfHandlesBracesInsideStrings(): void
    {
        $path = $this->tempDir.'/web-braces.conf';
        $meta = "{\"file\":2,\"format\":1,\"note\":\"a}b{c\"}";
        $config = "{\"base\":\"/user-testuser/deluge/\",\"port\":8112,\"banner\":\"{hello}\"}";
        file_put_contents($path, $meta.$config);

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue(is_array($parsed));
        $this->assertEquals('a}b{c', $parsed['meta']['note'] ?? null);
        $this->assertEquals('{hello}', $parsed['config']['banner'] ?? null);
    }

    public function testDelugeReadWebConfRejectsNonObjectStart(): void
    {
        $path = $this->tempDir.'/web-bad-start.conf';
        file_put_contents($path, "[]{}");

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null for invalid config');
    }

    public function testDelugeReadWebConfRejectsSingleObjectOnly(): void
    {
        $path = $this->tempDir.'/web-one.conf';
        file_put_contents($path, '{"file":2,"format":1}');

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null when second JSON object is missing');
    }

    public function testDelugeReadWebConfRejectsSecondObjectWhenNotAnObject(): void
    {
        $path = $this->tempDir.'/web-second-not-object.conf';
        file_put_contents($path, '{"file":2,"format":1}null');

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null when second JSON is not an object');
    }

    public function testDelugeReadWebConfRejectsMetaWhenNotAnObject(): void
    {
        $path = $this->tempDir.'/web-meta-not-object.conf';
        file_put_contents($path, '[]{}');

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null when meta JSON is not an object');
    }

    public function testDelugeReadWebConfRejectsTruncatedJson(): void
    {
        $path = $this->tempDir.'/web-trunc.conf';
        file_put_contents($path, '{"file":2,"format":1}{"base":"/user-testuser/deluge/","port":');

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null for truncated JSON');
    }

    public function testDelugeReadWebConfRejectsPoisonedNullByte(): void
    {
        $path = $this->tempDir.'/web-nullbyte.conf';
        // Raw NUL byte is not valid JSON and should fail parsing.
        file_put_contents($path, "{\"file\":2,\"format\":1}\0{\"base\":\"/user-testuser/deluge/\",\"port\":8112}");

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null for invalid JSON containing NUL byte');
    }

    public function testSplitFirstJsonObjectRejectsUnterminatedString(): void
    {
        $bad = "{\"file\":2,\"format\":1,\"x\":\"unterminated}{}";
        $split = \pmssSplitFirstJsonObject($bad);
        $this->assertTrue($split === null, 'Expected null for unterminated JSON string');
    }

    public function testSplitFirstJsonObjectRejectsMissingClosingBrace(): void
    {
        $bad = "{\"file\":2,\"format\":1{\"base\":\"/user-testuser/deluge/\"}";
        $split = \pmssSplitFirstJsonObject($bad);
        $this->assertTrue($split === null, 'Expected null for missing closing brace');
    }

    public function testSplitFirstJsonObjectHandlesEscapedQuotes(): void
    {
        $good = "{\"file\":2,\"format\":1,\"note\":\"a\\\"b\\\"c\"}{\"base\":\"/user-testuser/deluge/\",\"port\":8112}";
        $split = \pmssSplitFirstJsonObject($good);
        $this->assertTrue(is_array($split), 'Expected split array');

        $parsed = \pmssDelugeReadWebConf($this->writeTemp('web-escaped.conf', $good));
        $this->assertTrue(is_array($parsed));
        $this->assertEquals('a"b"c', $parsed['meta']['note'] ?? null);
    }

    public function testSplitFirstJsonObjectFailsSoftOnUtf8Bom(): void
    {
        $bom = "\xEF\xBB\xBF";
        $path = $this->tempDir.'/web-bom.conf';
        file_put_contents($path, $bom.'{"file":2,"format":1}{"base":"/user-testuser/deluge/","port":8112}');

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue($parsed === null, 'Expected null for BOM-prefixed config (fail-soft)');
    }

    public function testDelugeWriteWebConfRoundTrips(): void
    {
        $path = $this->tempDir.'/web-roundtrip.conf';
        $meta = '{"file":2,"format":1}';
        $config = '{"base":"/deluge-testuser/","port":8112}';
        file_put_contents($path, $meta.$config);
        @chmod($path, 0640);

        $parsed = \pmssDelugeReadWebConf($path);
        $this->assertTrue(is_array($parsed));

        $parsed['config']['base'] = '/user-testuser/deluge/';
        $ok = \pmssDelugeWriteWebConf($path, $parsed['meta'], $parsed['config'], 'root');
        $this->assertTrue($ok, 'Expected write to succeed');

        $parsed2 = \pmssDelugeReadWebConf($path);
        $this->assertTrue(is_array($parsed2));
        $this->assertEquals('/user-testuser/deluge/', $parsed2['config']['base'] ?? null);
    }

    private function writeTemp(string $name, string $content): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $content);
        return $path;
    }

    private function extractHeredoc(string $source, string $variableName): string
    {
        $pattern = '/'.preg_quote($variableName, '/').'\\s*=\\s*<<<\\x27NGINX\\x27\\s*(.*?)\\s*NGINX;/s';
        $matches = [];
        preg_match($pattern, $source, $matches);
        $this->assertTrue(isset($matches[1]) && is_string($matches[1]), "Failed to extract heredoc for {$variableName}");
        return (string)$matches[1];
    }

    // =========================================================================
    // SECTION 5: Template drift guards
    // =========================================================================

    public function testDelugeWebTemplateBaseIsCanonical(): void
    {
        $tpl = $this->readRepoFile('etc/seedbox/config/template.deluge.web.conf');
        $this->assertStringContainsString('"base": "/user-##USER/deluge/"', $tpl);
        $this->assertStringNotContainsString('"/deluge-##USER/"', $tpl);
    }

    public function testCreateNginxConfigUsesCentralProxyParamsInWebdavBlocks(): void
    {
        $script = $this->readRepoFile('scripts/util/createNginxConfig.php');

        // If proxy headers/timeouts drift in per-location blocks, nginx can break (duplicate directives).
        $this->assertStringContainsString('location /webdav-##user##/', $script);
        $this->assertStringContainsString('include /etc/nginx/proxy_params;', $script);
    }

    public function testWebdavLocationsAllowLargeUploads(): void
    {
        $userTpl = $this->readRepoFile('etc/seedbox/config/template.nginx-user');
        $this->assertStringContainsString('location /webdav-##username/', $userTpl);
        $this->assertStringContainsString('client_max_body_size 0;', $userTpl);

        $script = $this->readRepoFile('scripts/util/createNginxConfig.php');
        $this->assertStringContainsString('client_max_body_size 0;', $script);
    }
}
