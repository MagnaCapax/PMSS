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
    private $tempDir;
    private $lighttpdBinary;
    private $nginxBinary;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/pmss-config-validation-'.getmypid();
        @mkdir($this->tempDir, 0700, true);

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

    protected function tearDown(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
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

    /**
     * Render lighttpd template with test values.
     */
    private function renderLighttpdTemplate(): string
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.lighttpd';
        $template = file_get_contents($templatePath);

        // Substitute placeholders with valid test values
        $rendered = str_replace(
            array('##username', '##serverPort', '##rclonePort', '##qbittorrentPort', '##PMSS_WEBDAV_WWW_POLICY##'),
            array('testuser', '30000', '30001', '30002', ''),
            $template
        );

        return $rendered;
    }

    /**
     * Create minimal nginx.conf that includes a test server block.
     */
    private function renderNginxConfig(): string
    {
        // Minimal nginx.conf wrapper for testing server blocks
        $nginxConf = <<<'NGINX'
worker_processes 1;
error_log /dev/null;
pid /tmp/pmss-nginx-test.pid;

events {
    worker_connections 1;
}

http {
    access_log off;

    # Test server block
    server {
        listen 127.0.0.1:39999;
        server_name localhost;

        location / {
            return 200 'ok';
        }

        location /webdav-testuser/ {
            proxy_pass http://127.0.0.1:30000/webdav-testuser/;
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Authorization $http_authorization;
            proxy_http_version 1.1;
            proxy_buffering off;

            client_max_body_size 0;
            client_body_timeout 300s;
            send_timeout 300s;
            proxy_read_timeout 300s;
            proxy_send_timeout 300s;
            proxy_request_buffering off;
        }
    }
}
NGINX;

        return $nginxConf;
    }

    /**
     * Extract and render WebDAV nginx blocks from createNginxConfig.php.
     */
    private function extractNginxWebdavBlocks(): string
    {
        $scriptPath = dirname(__DIR__, 3).'/util/createNginxConfig.php';
        $script = file_get_contents($scriptPath);

        // Extract the HEREDOC templates
        preg_match_all('/<<<\'NGINX\'\s*(.*?)\s*NGINX;/s', $script, $matches);

        $serverBlocks = '';
        foreach ($matches[1] as $block) {
            // Only include blocks that have server { } definitions
            if (strpos($block, 'server {') !== false) {
                // Substitute placeholders
                $rendered = str_replace(
                    array('##user##', '##port##', '##host##', '##ssl_block##'),
                    array('testuser', '30000', 'test.example.com', ''),
                    $block
                );
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

        foreach ($syntaxErrors as $error) {
            $this->assertTrue(
                stripos($outputStr, $error) === false,
                "lighttpd config has syntax error: $error\nOutput: $outputStr"
            );
        }
    }

    /**
     * TEST: Lighttpd template has balanced parentheses.
     *
     * Quick sanity check that runs without the binary.
     */
    public function testLighttpdTemplateHasBalancedParentheses(): void
    {
        $config = $this->renderLighttpdTemplate();

        $openParens = substr_count($config, '(');
        $closeParens = substr_count($config, ')');

        $this->assertEquals(
            $openParens,
            $closeParens,
            "Unbalanced parentheses: $openParens open vs $closeParens close"
        );
    }

    /**
     * TEST: Lighttpd template has balanced braces.
     */
    public function testLighttpdTemplateHasBalancedBraces(): void
    {
        $config = $this->renderLighttpdTemplate();

        $openBraces = substr_count($config, '{');
        $closeBraces = substr_count($config, '}');

        $this->assertEquals(
            $openBraces,
            $closeBraces,
            "Unbalanced braces: $openBraces open vs $closeBraces close"
        );
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

        foreach ($syntaxErrors as $error) {
            $this->assertTrue(
                stripos($outputStr, $error) === false,
                "nginx config has syntax error: $error\nOutput: $outputStr"
            );
        }
    }

    /**
     * TEST: Nginx WebDAV blocks have balanced braces.
     */
    public function testNginxWebdavBlocksHaveBalancedBraces(): void
    {
        $scriptPath = dirname(__DIR__, 3).'/util/createNginxConfig.php';
        $script = file_get_contents($scriptPath);

        // Extract HEREDOC templates
        preg_match_all('/<<<\'NGINX\'\s*(.*?)\s*NGINX;/s', $script, $matches);

        foreach ($matches[1] as $i => $block) {
            $openBraces = substr_count($block, '{');
            $closeBraces = substr_count($block, '}');

            $this->assertEquals(
                $openBraces,
                $closeBraces,
                "HEREDOC block $i has unbalanced braces: $openBraces open vs $closeBraces close"
            );
        }
    }

    /**
     * TEST: No WebDAV location includes proxy_params AND sets timeout.
     *
     * This is the specific bug pattern from 2026-01. Kept here as explicit
     * binary-independent check alongside the WebdavSecurityTest version.
     */
    public function testNoWebdavDuplicateTimeoutDirectives(): void
    {
        $scriptPath = dirname(__DIR__, 3).'/util/createNginxConfig.php';
        $script = file_get_contents($scriptPath);

        // Find all location blocks for webdav
        preg_match_all('/location\s+[^{]*webdav[^{]*\{([^}]+)\}/si', $script, $matches);

        foreach ($matches[1] as $i => $block) {
            // Skip redirects (no proxy_pass)
            if (strpos($block, 'proxy_pass') === false) {
                continue;
            }

            $hasInclude = strpos($block, 'include') !== false
                       && strpos($block, 'proxy_params') !== false;
            $hasTimeout = strpos($block, 'proxy_read_timeout') !== false;

            $this->assertTrue(
                !($hasInclude && $hasTimeout),
                "WebDAV block $i has both include proxy_params AND proxy_read_timeout"
            );
        }
    }
}
