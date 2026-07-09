<?php
/**
 * Configuration Syntax Validation Test Suite
 *
 * Validates that rendered configuration templates produce syntactically correct
 * configs that the actual daemons will accept. Uses real binaries (lighttpd -t,
 * nginx -t) when available for authoritative validation.
 *
 * These tests catch issues that regex-based validation might miss, such as:
 * - Missing commas in arrays
 * - Duplicate directives from includes
 * - Unbalanced braces or parentheses
 * - Invalid directive values
 *
 * Tests skip gracefully when binaries are not available (e.g., in minimal CI
 * environments), but will run and catch errors in environments with the daemons
 * installed.
 *
 * @see WebdavSecurityTest.php for regex-based config validation (runs everywhere)
 * @see fix commit 005b1fe (2026-01) for the bugs these tests prevent
 */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ConfigSyntaxValidationTest extends TestCase
{
    private $lighttpdBinary;
    private $nginxBinary;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-config-validation', 0700);

        // Locate binaries - check common paths
        $this->lighttpdBinary = $this->findBinary('lighttpd', array(
            '/usr/sbin/lighttpd',
            '/usr/local/sbin/lighttpd',
            '/usr/bin/lighttpd',
        ));

        $this->nginxBinary = $this->findBinary('nginx', array(
            '/usr/sbin/nginx',
            '/usr/local/sbin/nginx',
            '/usr/bin/nginx',
        ));
    }

    private function findBinary(string $name, array $paths): ?string
    {
        // Check explicit paths first
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        // Fall back to which
        $which = trim((string)@shell_exec('which '.escapeshellarg($name).' 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        return null;
    }

    private function assertBalancedTokenCounts(string $value, string $open, string $close, string $label): void
    {
        $openCount = substr_count($value, $open);
        $closeCount = substr_count($value, $close);

        $this->assertEquals(
            $openCount,
            $closeCount,
            "Unbalanced $label: $openCount open vs $closeCount close"
        );
    }

    private function assertOutputOmitsSyntaxErrors(string $output, array $syntaxErrors, string $label): void
    {
        foreach ($syntaxErrors as $error) {
            $this->assertTrue(
                stripos($output, $error) === false,
                "$label config has syntax error: $error\nOutput: $output"
            );
        }
    }

    /**
     * Render lighttpd template with test values.
     */
    private function renderLighttpdTemplate(): string
    {
        $templatePath = $this->pmssRepoPath('etc/seedbox/config/template.lighttpd');
        $template = file_get_contents($templatePath);

        // Substitute placeholders with valid test values
        $rendered = str_replace(
            array('##username', '##serverPort', '##PMSS_WEBDAV_WWW_POLICY##'),
            array('testuser', '30000', ''),
            $template
        );

        return $rendered;
    }

    /**
     * Extract and render WebDAV nginx blocks from createNginxConfig.php.
     */
    private function extractNginxWebdavBlocks(): string
    {
        require_once dirname(__DIR__, 3).'/lib/nginxConfig/templates.php';
        $templates = \pmssNginxUserSubdomainTemplates();

        $serverBlocks = '';
        $webdavProxyParamsPath = $this->pmssRepoPath('etc/seedbox/config/template.nginx-webdav_proxy_params');
        $webdavProxyParams = (string)file_get_contents($webdavProxyParamsPath);
        foreach ($templates as $block) {
            // Only include blocks that have server { } definitions
            if (strpos($block, 'server {') !== false) {
                // Substitute placeholders
                $rendered = str_replace(
                    array('##user##', '##port##', '##host##', '##ssl_block##'),
                    array('testuser', '30000', 'test.example.com', ''),
                    $block
                );
                // Inline WebDAV proxy params so nginx -t validates the real scoped directives.
                $rendered = str_replace('include /etc/nginx/webdav_proxy_params;', rtrim($webdavProxyParams), $rendered);
                // Remove proxy_params includes; we inline minimal proxy params below.
                $rendered = str_replace('include /etc/nginx/proxy_params;', '', $rendered);
                $serverBlocks .= $rendered."\n";
            }
        }

        // Wrap in minimal nginx.conf
        $nginxConf = <<<NGINX
worker_processes 1;
error_log /dev/null;
pid {$this->tempDir}/nginx-test.pid;

events {
    worker_connections 1;
}

http {
    access_log off;

    # Include proxy_params content inline for testing
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header Authorization \$http_authorization;
    proxy_read_timeout 300s;
    proxy_send_timeout 300s;
    client_body_timeout 300s;
    send_timeout 300s;
    proxy_buffering off;

$serverBlocks
}
NGINX;

        return $nginxConf;
    }

    // =========================================================================
    // LIGHTTPD TESTS
    // =========================================================================

    /**
     * TEST: Rendered lighttpd config passes lighttpd -t syntax check.
     *
     * Uses the actual lighttpd binary to validate config syntax. This catches
     * errors like missing commas, invalid directives, and malformed blocks
     * that regex-based tests might miss.
     *
     * Skips if lighttpd binary is not available.
     */
    public function testLighttpdTemplatePassesBinaryValidation(): void
    {
        if ($this->lighttpdBinary === null) {
            throw new \PMSS\Tests\SkipTest('lighttpd binary not found - skipping binary validation');
        }

        $config = $this->renderLighttpdTemplate();
        $configFile = $this->tempDir.'/lighttpd-test.conf';
        file_put_contents($configFile, $config);

        // lighttpd -t -f <config> tests syntax without starting
        // Note: lighttpd -t may still fail due to missing modules/paths, so we
        // check specifically for syntax errors vs runtime errors
        $output = array();
        $rc = 0;
        exec(
            escapeshellcmd($this->lighttpdBinary).' -t -f '.escapeshellarg($configFile).' 2>&1',
            $output,
            $rc
        );
        $outputStr = implode("\n", $output);

        // Syntax errors we care about (the bugs we're preventing)
        $syntaxErrors = array(
            'should have been a list',      // Missing comma in array
            'unknown config-key',           // Invalid directive
            'duplicate config-variable',    // Duplicate directive
            'expected an assignment',       // Parse error
            'unexpected end of file',       // Unbalanced braces
        );

        $this->assertOutputOmitsSyntaxErrors($outputStr, $syntaxErrors, 'lighttpd');
    }

    /**
     * TEST: Lighttpd template has balanced parentheses.
     *
     * Quick sanity check that runs without the binary.
     */
    public function testLighttpdTemplateHasBalancedParentheses(): void
    {
        $this->assertBalancedTokenCounts($this->renderLighttpdTemplate(), '(', ')', 'parentheses');
    }

    /**
     * TEST: Lighttpd template has balanced braces.
     */
    public function testLighttpdTemplateHasBalancedBraces(): void
    {
        $this->assertBalancedTokenCounts($this->renderLighttpdTemplate(), '{', '}', 'braces');
    }

    // =========================================================================
    // NGINX TESTS
    // =========================================================================

    /**
     * TEST: Rendered nginx config passes nginx -t syntax check.
     *
     * Uses the actual nginx binary to validate config syntax. This catches
     * errors like duplicate directives, invalid values, and malformed blocks.
     *
     * Skips if nginx binary is not available.
     */
    public function testNginxWebdavConfigPassesBinaryValidation(): void
    {
        if ($this->nginxBinary === null) {
            throw new \PMSS\Tests\SkipTest('nginx binary not found - skipping binary validation');
        }

        $config = $this->extractNginxWebdavBlocks();
        $configFile = $this->tempDir.'/nginx-test.conf';
        file_put_contents($configFile, $config);

        // nginx -t -c <config> tests syntax without starting
        $output = array();
        $rc = 0;
        exec(
            escapeshellcmd($this->nginxBinary).' -t -c '.escapeshellarg($configFile).' 2>&1',
            $output,
            $rc
        );
        $outputStr = implode("\n", $output);

        // Syntax errors we care about (the bugs we're preventing)
        $syntaxErrors = array(
            'directive is duplicate',       // Duplicate directive (the proxy_params bug)
            'unknown directive',            // Invalid directive
            'unexpected "}"',               // Parse error
            'unexpected end of file',       // Unbalanced braces
            'invalid number of arguments',  // Wrong argument count
        );

        $this->assertOutputOmitsSyntaxErrors($outputStr, $syntaxErrors, 'nginx');
    }

    /**
     * TEST: Nginx WebDAV blocks have balanced braces.
     */
    public function testNginxWebdavBlocksHaveBalancedBraces(): void
    {
        require_once dirname(__DIR__, 3).'/lib/nginxConfig/templates.php';
        $templates = \pmssNginxUserSubdomainTemplates();
        foreach (array_values($templates) as $i => $block) {
            $this->assertBalancedTokenCounts($block, '{', '}', "HEREDOC block $i braces");
        }
    }

    /**
     * TEST: No WebDAV location includes proxy params AND sets timeouts inline.
     *
     * This is the specific bug pattern from 2026-01. Kept here as explicit
     * binary-independent check alongside the WebdavSecurityTest version.
     */
    public function testNoWebdavDuplicateTimeoutDirectives(): void
    {
        require_once dirname(__DIR__, 3).'/lib/nginxConfig/templates.php';
        $script = implode("\n", \pmssNginxUserSubdomainTemplates());

        // Find all location blocks for webdav
        preg_match_all('/location\s+[^{]*webdav[^{]*\{([^}]+)\}/si', $script, $matches);

        foreach ($matches[1] as $i => $block) {
            // Skip redirects (no proxy_pass)
            if (strpos($block, 'proxy_pass') === false) {
                continue;
            }

            $hasInclude = strpos($block, 'include /etc/nginx/') !== false
                       && strpos($block, 'proxy_params') !== false;
            $hasTimeout = preg_match('/\\b(proxy_read_timeout|proxy_send_timeout|client_body_timeout)\\b/', $block) === 1;

            $this->assertTrue(
                !($hasInclude && $hasTimeout),
                "WebDAV block $i has both include proxy params AND inline timeout directives"
            );
        }
    }
}
