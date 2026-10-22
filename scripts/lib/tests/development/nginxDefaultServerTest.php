<?php
namespace PMSS\Tests;

class NginxDefaultServerTest extends TestCase
{
    public function testSelfSignedCertCommandKeepsValidSubjectAndRejectsShellInput(): void
    {
        require_once dirname(__DIR__, 2).'/nginxConfig/setup.php';

        foreach (['host', 'node.example.test', '192.0.2.1'] as $hostname) {
            $command = \pmssNginxConfigSelfSignedCertCommand($hostname);
            $this->assertSame(
                'openssl req -x509 -nodes -days 365 -newkey rsa:2048 -subj '
                    .escapeshellarg('/C=FI/ST=none/L=none/O=PulsedMedia/CN='.$hostname)
                    .' -keyout /etc/nginx/ssl/nginx.key -out /etc/nginx/ssl/nginx.crt',
                $command
            );
        }

        foreach (['', 'node;id', 'node$(id)', 'node";id', "node\nother", "node\0other", '../node'] as $hostname) {
            $this->assertSame(null, \pmssNginxConfigSelfSignedCertCommand($hostname));
        }
    }

    public function testGlobalConfigWritesReportFailureAndPreserveSuccessfulBytes(): void
    {
        require_once dirname(__DIR__, 2).'/nginxConfig/setup.php';
        $root = $this->pmssMakeTempDir('pmss-nginx-global-write-', 0700);
        $source = $root.'/template';
        $target = $root.'/target';
        $this->pmssWriteFile($source, "template\n");

        $this->assertTrue(\pmssCreateNginxConfigSetupCopy($source, $target));
        $this->assertSame("template\n", file_get_contents($target));
        $this->assertTrue(\pmssCreateNginxConfigSetupWrite($target, "rendered\n"));
        $this->assertSame("rendered\n", file_get_contents($target));

        $this->assertFalse(\pmssCreateNginxConfigSetupCopy($root.'/missing', $target));
        $this->assertSame("rendered\n", file_get_contents($target));
        $this->assertFalse(\pmssCreateNginxConfigSetupWrite($root.'/missing/target', 'config'));
        $this->assertFalse(\pmssCreateNginxConfigSetupWrite($root, 'config'));
    }

    public function testRegexExhaustionPreservesTemplateBytes(): void
    {
        require_once dirname(__DIR__, 2).'/nginxConfig/setup.php';

        $limit = ini_get('pcre.backtrack_limit');
        try {
            // Force the actual PCRE failure without writing a service config.
            ini_set('pcre.backtrack_limit', '0');
            foreach ([
                "server {\n    listen 80;\n}\n",
                "server {\n    listen 443 ssl;\n}\n",
                "listen 80;\nlisten 443 ssl;\n",
                "listen 80 default_server;\n",
                "listen 443 ssl default_server;\n",
            ] as $input) {
                $this->assertSame($input, \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($input));
                $this->assertSame(PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
            }
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }

    public function testSetupDirectoryFailuresUseStderrAndKeepExistingEntries(): void
    {
        $root = $this->pmssMakeTempDir('pmss-nginx-setup-dir-', 0700);
        $this->pmssWriteFile($root.'/file', 'keep');
        $stderr = $root.'/stderr';
        $paths = [$root, $root.'/nested/dir', $root.'/file', $root.'/file/child',
            $root.'/file/child/deeper', '', $root."/nul\0dir"];
        $script = '$results = []; foreach ('.var_export($paths, true).' as $path) {'
            .' $results[] = pmssCreateNginxConfigSetupDirectory($path); } echo json_encode($results);';
        $results = $this->pmssRunRepoInlinePhpRequireJson('scripts/lib/nginxConfig/setup.php',
            $script, [], '2>'.escapeshellarg($stderr));
        $this->assertSame([true, true, false, false, false, false, false], $results);
        $warnings = '';
        foreach (array_slice($paths, 2) as $path) {
            $warnings .= '[WARN] Failed to prepare directory for nginx config: '.$path.PHP_EOL;
        }
        $this->assertSame($warnings, file_get_contents($stderr));
        $this->assertSame('keep', file_get_contents($root.'/file'));
        $this->assertSame(0700, fileperms($root) & 0777);
        $this->assertTrue(is_dir($root.'/nested/dir'));
    }

    public function testSetupDefaultCleanupRetainsGuardsAndReportsRemovalFailure(): void
    {
        $root = $this->pmssMakeTempDir('pmss-nginx-setup-remove-', 0700);
        $this->pmssWriteFile($root.'/remove.conf', 'obsolete');
        $this->pmssWriteFile($root.'/keep.conf', 'keep');
        mkdir($root.'/directory');
        symlink($root.'/keep.conf', $root.'/linked.conf');
        symlink($root.'/missing', $root.'/dangling.conf');
        symlink($root, $root.'/redirected');
        mkdir($root.'/locked');
        $this->pmssWriteFile($root.'/locked/default.conf', 'retry later');
        chmod($root.'/locked', 0500);
        try {
            // Root can unlink despite mode 0500; exercise this failure only without that privilege.
            $denied = !is_writable($root.'/locked');
            $paths = [$root.'/remove.conf', $root.'/missing', $root.'/directory',
                $root.'/linked.conf', $root.'/dangling.conf', $root.'/redirected/keep.conf'];
            if ($denied) $paths[] = $root.'/locked/default.conf';
            $script = 'foreach ('.var_export($paths, true).' as $path) {'
                .' pmssCreateNginxConfigSetupRemoveDefault($path); } echo "continued";';
            $stderr = $root.'/stderr';
            $output = $this->pmssRunRepoInlinePhpRequire('scripts/lib/nginxConfig/setup.php',
                $script, [], '2>'.escapeshellarg($stderr));
            $this->assertSame('continued', $output);
            $this->assertSame($denied
                ? '[WARN] Failed to remove obsolete account UI default nginx config: '.$root.'/locked/default.conf'.PHP_EOL
                : '', file_get_contents($stderr));
            $this->assertFalse(file_exists($root.'/remove.conf'));
            $this->assertSame('keep', file_get_contents($root.'/keep.conf'));
            $this->assertSame('retry later', file_get_contents($root.'/locked/default.conf'));
            $this->assertTrue(is_dir($root.'/directory'));
            $this->assertTrue(is_link($root.'/linked.conf') && is_link($root.'/dangling.conf'));
        } finally {
            chmod($root.'/locked', 0700);
        }
    }

    public function testNormalRenderingPreservesExactConfigContract(): void
    {
        require_once dirname(__DIR__, 2).'/nginxConfig/setup.php';

        foreach ([
            '' => '',
            "# no listeners\n" => "# no listeners\n",
            "listen 8080;\n" => "listen 8080;\n",
            "listen 443;\n" => "listen 443;\n",
            "listen 80 default_server;\n" => "listen 80 default_server;\n",
            "listen 443 ssl default_server;\n" => "listen 443 ssl default_server;\n",
            "  listen 80; # http\n  listen 443 ssl; # https\n"
                => "  listen 80 default_server; # http\n  listen 443 ssl default_server; # https\n",
        ] as $input => $expected) {
            $this->assertSame($expected, \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($input));
        }
    }

    public function testConfigSetupEnforcesHttpDefaultServerEvenOnStaleTemplates(): void
    {
        require_once dirname(__DIR__, 2).'/nginxConfig/setup.php';

        $input = "server {\n    listen 80;\n}\n";
        $output = \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($input);
        $this->assertMatches('/\\blisten\\s+80\\s+default_server\\s*;/', $output);
        $this->assertEquals($output, \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($output));
    }

    public function testConfigSetupEnforcesHttpsDefaultServerEvenOnStaleTemplates(): void
    {
        require_once dirname(__DIR__, 2).'/nginxConfig/setup.php';

        $input = "server {\n    listen 443 ssl;\n}\n";
        $output = \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($input);
        $this->assertMatches('/\\blisten\\s+443\\s+ssl\\s+default_server\\s*;/', $output);
        $this->assertEquals($output, \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($output));
    }

    public function testDefaultSiteTemplateDefinesHttpDefaultServer(): void
    {
        $path = 'etc/seedbox/config/template.nginx-site-default';
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Failed to read '.$path);
        $this->assertMatches('/\\blisten\\s+80\\s+default_server\\s*;/', $contents);
    }

    public function testDefaultSiteTemplateDefinesHttpsDefaultServer(): void
    {
        $path = 'etc/seedbox/config/template.nginx-site-default';
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Failed to read '.$path);
        $this->assertMatches('/\\blisten\\s+443\\s+ssl\\s+default_server\\s*;/', $contents);
    }

    public function testDefaultSiteTemplateDoesNotUseDeprecatedSslOnDirective(): void
    {
        $path = 'etc/seedbox/config/template.nginx-site-default';
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Failed to read '.$path);
        $this->assertTrue(strpos($contents, 'ssl on;') === false, 'Deprecated \"ssl on;\" directive should be removed');
    }
}
