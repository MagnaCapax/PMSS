<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/runtime.php';
require_once dirname(__DIR__, 3).'/lib/update/osRelease.php';
require_once dirname(__DIR__, 3).'/lib/systemStatus.php';

final class SystemStatusCharacterizationTest extends TestCase
{
    /** @var string */
    private $osReleasePath;

    /** @var string */
    private $sourcesPath;

    public function setUp(): void
    {
        $tempDir = $this->pmssMakeTempDir('system-status-');
        $this->osReleasePath = $tempDir.'/os-release';
        $this->sourcesPath = $tempDir.'/sources.list';
        file_put_contents($this->osReleasePath, "ID=debian\nVERSION_ID=12\nVERSION_CODENAME=bookworm\n");
        file_put_contents($this->sourcesPath, "deb http://example.invalid/debian bookworm main\n");
        putenv('PMSS_OS_RELEASE_PATH='.$this->osReleasePath);
        putenv('PMSS_APT_SOURCES_PATH='.$this->sourcesPath);
        pmssResetOsReleaseCache();
    }

    public function tearDown(): void
    {
        pmssResetOsReleaseCache();
        putenv('PMSS_OS_RELEASE_PATH');
        putenv('PMSS_APT_SOURCES_PATH');
        $this->pmssRemoveTree(dirname($this->osReleasePath));
    }

    private function buildSystemStatusDependencies(): array
    {
        $sourcesPath = (string) getenv('PMSS_APT_SOURCES_PATH');
        $commandMap = [];
        foreach ([
            'rtorrent' => ['rtorrent -h 2>&1 | head -n 1', '/usr/bin/rtorrent', 'rtorrent 0.9.8'],
            'nginx' => ['nginx -v 2>&1', '/usr/sbin/nginx', 'nginx version: nginx/1.22.0'],
            'lighttpd' => ['lighttpd -v 2>&1 | head -n 1', '/usr/sbin/lighttpd', 'lighttpd/1.4.69'],
            'php' => ['php -v 2>&1 | head -n 1', '/usr/bin/php', 'PHP 8.2.0'],
            'proftpd' => ['proftpd -v 2>&1 | head -n 1', '/usr/sbin/proftpd', 'ProFTPD Version 1.3.7'],
            'openvpn' => ['openvpn --version 2>&1 | head -n 1', '/usr/sbin/openvpn', 'OpenVPN 2.6.0'],
            'tar' => ['tar --version 2>&1 | head -n 1', '/usr/bin/tar', 'tar (GNU tar) 1.34'],
            'pigz' => ['pigz --version 2>&1 | head -n 1', '/usr/bin/pigz', 'pigz 2.8'],
            'gpg' => ['gpg --version 2>&1 | head -n 1', '/usr/bin/gpg', 'gpg (GnuPG) 2.2.40'],
            'curl' => ['curl --version 2>&1 | head -n 1', '/usr/bin/curl', 'curl 8.4.0'],
            'wget' => ['wget --version 2>&1 | head -n 1', '/usr/bin/wget', 'GNU Wget 1.21.3'],
            'rsync' => ['rsync --version 2>&1 | head -n 1', '/usr/bin/rsync', 'rsync  version 3.2.7'],
            'python3' => ['python3 --version 2>&1 | head -n 1', '/usr/bin/python3', 'Python 3.11.2'],
            'git' => ['git --version 2>&1 | head -n 1', '/usr/bin/git', 'git version 2.39.2'],
            'flexget' => ['flexget --version 2>&1 | head -n 1', '/opt/flexget/bin/flexget', '3.8.51'],
            'pyload' => ['pyload --version 2>&1 | head -n 1', '/opt/pyload/bin/pyload', 'pyLoad 0.5.0'],
        ] as $binary => $spec) {
            $commandMap["command -v '".$binary."'"] = $spec[1];
            $commandMap[$spec[0]] = $spec[2];
        }

        return [
            'runCommand' => static function (string $command) use ($commandMap): string { return $commandMap[$command] ?? ''; },
            'pathExists' => static function (string $path) use ($sourcesPath): bool {
                return in_array($path, [$sourcesPath, '/etc/proftpd/proftpd.conf', '/etc/openvpn', '/etc/openvpn/easy-rsa', '/etc/seedbox/localnet', '/etc/nginx'], true);
            },
            'isFile' => static function (string $path) use ($sourcesPath): bool {
                return in_array($path, [$sourcesPath, '/etc/seedbox/config/localnet', '/home/openvpn-host-pulsedmedia-com.ovpn', '/home/openvpn-host-pulsedmedia-com.crt', '/opt/flexget/bin/flexget', '/opt/pyload/bin/pyload'], true);
            },
            'isDir' => static function (string $path): bool { return in_array($path, ['/etc/seedbox', '/etc/seedbox/config'], true); },
            'isExecutable' => static function (string $path): bool {
                return in_array($path, [
                    '/usr/bin/rtorrent', '/usr/sbin/nginx', '/usr/sbin/lighttpd', '/usr/bin/php', '/usr/sbin/proftpd', '/usr/sbin/openvpn',
                    '/usr/bin/tar', '/usr/bin/pigz', '/usr/bin/gpg', '/usr/bin/curl', '/usr/bin/wget', '/usr/bin/rsync', '/usr/bin/python3',
                    '/usr/bin/git', '/opt/flexget/bin/flexget', '/opt/pyload/bin/pyload',
                ], true);
            },
            'isLink' => static function (string $path): bool { return in_array($path, ['/usr/local/bin/flexget', '/usr/local/bin/pyload'], true); },
            'readLink' => static function (string $path): string {
                return [
                    '/usr/local/bin/flexget' => '/opt/flexget/bin/flexget',
                    '/usr/local/bin/pyload' => '/opt/pyload/bin/pyload',
                ][$path] ?? '';
            },
            'readFile' => static function (string $path) use ($sourcesPath): string {
                return ['/etc/hostname' => "host\n", $sourcesPath => "deb http://example.invalid/debian bookworm main\n"][$path] ?? '';
            },
            'filePerms' => static function (string $path) {
                return ['/etc/seedbox/config/localnet' => 0664, '/etc/seedbox' => 0755, '/etc/seedbox/config' => 0755][$path] ?? false;
            },
        ];
    }

    public function testComponentChecksStayStableWithHermeticInputs(): void
    {
        $checks = pmssComponentStatusChecks([
            'runCommand' => static function (string $command): string {
                $map = [
                    "command -v 'rtorrent'" => '/usr/bin/rtorrent',
                    "command -v 'nginx'" => '/usr/sbin/nginx',
                    "command -v 'php'" => '/usr/bin/php',
                    "command -v 'proftpd'" => '',
                    "command -v 'openvpn'" => '/usr/sbin/openvpn',
                    "command -v 'curl'" => '/usr/bin/curl',
                ];
                return $map[$command] ?? '';
            },
            'pathExists' => static function (string $path): bool {
                return in_array($path, ['/etc/openvpn', '/etc/nginx'], true);
            },
            'readFile' => static function (string $path): string {
                return is_file($path) ? (string) file_get_contents($path) : '';
            },
            'isExecutable' => static function (string $path): bool {
                return in_array($path, ['/usr/bin/rtorrent', '/usr/sbin/nginx', '/usr/bin/php', '/usr/sbin/openvpn', '/usr/bin/curl'], true);
            },
        ]);

        $this->assertEquals(
            [
                ['name' => 'os.codename', 'status' => 'OK', 'detail' => 'bookworm'],
                ['name' => 'apt.sources', 'status' => 'OK', 'detail' => 'contains bookworm'],
                ['name' => 'bin.rtorrent', 'status' => 'OK', 'detail' => '/usr/bin/rtorrent'],
                ['name' => 'bin.nginx', 'status' => 'OK', 'detail' => '/usr/sbin/nginx'],
                ['name' => 'bin.php', 'status' => 'OK', 'detail' => '/usr/bin/php'],
                ['name' => 'bin.proftpd', 'status' => 'WARN', 'detail' => ''],
                ['name' => 'bin.openvpn', 'status' => 'OK', 'detail' => '/usr/sbin/openvpn'],
                ['name' => 'bin.curl', 'status' => 'OK', 'detail' => '/usr/bin/curl'],
                ['name' => 'config.proftpd', 'status' => 'WARN', 'detail' => 'missing'],
                ['name' => 'config.openvpn', 'status' => 'OK', 'detail' => '/etc/openvpn'],
                ['name' => 'config.seedbox.localnet', 'status' => 'WARN', 'detail' => 'missing'],
                ['name' => 'config.nginx', 'status' => 'OK', 'detail' => '/etc/nginx'],
            ],
            $checks
        );
    }

    public function testComponentChecksWarnWhenCodenameIsMissing(): void
    {
        file_put_contents($this->osReleasePath, "ID=debian\nVERSION_ID=12\n");
        pmssResetOsReleaseCache();

        $checks = pmssComponentStatusChecks();

        $this->assertEquals('WARN', $checks[0]['status']);
        $this->assertEquals('VERSION_CODENAME missing', $checks[0]['detail']);
        $this->assertEquals('OK', $checks[1]['status']);
        $this->assertEquals('contains ', $checks[1]['detail']);
    }

    public function testComponentChecksWarnWhenSourcesMismatch(): void
    {
        file_put_contents($this->sourcesPath, "deb http://example.invalid/debian bullseye main\n");

        $checks = pmssComponentStatusChecks();

        $this->assertEquals('WARN', $checks[1]['status']);
        $this->assertEquals('codename mismatch', $checks[1]['detail']);
    }

    public function testComponentChecksTreatWhitespaceBinaryPathsAsMissing(): void
    {
        $checks = pmssComponentStatusChecks([
            'runCommand' => static function (string $command): string {
                if ($command === "command -v 'nginx'") {
                    return " \n\t ";
                }

                $map = [
                    "command -v 'rtorrent'" => '/usr/bin/rtorrent',
                    "command -v 'php'" => '/usr/bin/php',
                    "command -v 'proftpd'" => '/usr/sbin/proftpd',
                    "command -v 'openvpn'" => '/usr/sbin/openvpn',
                    "command -v 'curl'" => '/usr/bin/curl',
                ];

                return $map[$command] ?? '';
            },
            'pathExists' => static function (string $path): bool {
                return in_array($path, ['/etc/openvpn', '/etc/nginx'], true);
            },
            'readFile' => static function (string $path): string {
                return is_file($path) ? (string) file_get_contents($path) : '';
            },
            'isExecutable' => static function (string $path): bool {
                return in_array($path, ['/usr/bin/rtorrent', '/usr/bin/php', '/usr/sbin/proftpd', '/usr/sbin/openvpn', '/usr/bin/curl'], true);
            },
        ]);

        $this->assertEquals('bin.nginx', $checks[3]['name']);
        $this->assertEquals('WARN', $checks[3]['status']);
        $this->assertEquals('', $checks[3]['detail']);
    }

    public function testBinaryPathResolveRejectsNonExecutableAndNonAbsoluteResults(): void
    {
        $runCommand = static function (string $command): string {
            $map = [
                "command -v 'nginx'" => 'nginx',
                "command -v 'curl'" => '/usr/bin/curl',
                "command -v 'wget'" => '/usr/bin/wget',
            ];

            return $map[$command] ?? '';
        };
        $isExecutable = static function (string $path): bool {
            return $path === '/usr/bin/curl';
        };

        $this->assertSame('', pmssStatusBinaryPathResolve('nginx', $runCommand, $isExecutable));
        $this->assertSame('/usr/bin/curl', pmssStatusBinaryPathResolve('curl', $runCommand, $isExecutable));
        $this->assertSame('', pmssStatusBinaryPathResolve('wget', $runCommand, $isExecutable));
    }

    public function testSharedBinaryProbeRendererKeepsSystemAndComponentViewsStable(): void
    {
        $runCommand = static function (string $command): string {
            $map = [
                "command -v 'nginx'" => '/usr/sbin/nginx',
                'nginx -v 2>&1' => 'nginx version: nginx/1.22.0',
            ];

            return $map[$command] ?? '';
        };
        $isExecutable = static function (string $path): bool {
            return $path === '/usr/sbin/nginx';
        };
        $binarySpec = [
            'infoCommand' => 'nginx -v 2>&1',
            'componentName' => 'bin.nginx',
        ];

        $this->assertSame(
            ['name' => 'Binary: nginx', 'status' => 'OK', 'detail' => 'nginx version: nginx/1.22.0'],
            pmssStatusBinaryProbeCheck('nginx', $binarySpec, $runCommand, $isExecutable)
        );
        $this->assertSame(
            ['name' => 'bin.nginx', 'status' => 'OK', 'detail' => '/usr/sbin/nginx'],
            pmssStatusBinaryProbeCheck('nginx', $binarySpec, $runCommand, $isExecutable, true)
        );
        $this->assertTrue(pmssStatusBinaryProbeCheck('wget', ['infoCommand' => 'wget --version 2>&1 | head -n 1'], $runCommand, $isExecutable, true) === null);
    }

    public function testSharedBinaryProbeRendererDefaultsMissingInfoCommandToPresentWithoutWarnings(): void
    {
        $commands = [];
        $runCommand = static function (string $command) use (&$commands): string {
            $commands[] = $command;

            return $command === "command -v 'nginx'" ? '/usr/sbin/nginx' : '';
        };
        $result = null;

        $this->pmssAssertNoPhpWarnings(function () use (&$result, $runCommand): void {
            $result = pmssStatusBinaryProbeCheck(
                'nginx',
                ['componentName' => 'bin.nginx'],
                $runCommand,
                static function (string $path): bool {
                    return $path === '/usr/sbin/nginx';
                }
            );
        });

        $this->assertSame(
            ['name' => 'Binary: nginx', 'status' => 'OK', 'detail' => 'present'],
            $result
        );
        $this->assertSame(["command -v 'nginx'"], $commands);
    }

    public function testSharedProbeCollectorPreservesCatalogOrderWhileFilteringNulls(): void
    {
        $checks = pmssStatusCollectProbeChecks(
            [
                'binaries' => [
                    'alpha' => ['label' => 'first'],
                    'beta' => ['skip' => true],
                ],
                'paths' => [
                    ['path' => '/one', 'label' => 'third'],
                    ['path' => '/two', 'skip' => true],
                ],
            ],
            static function (string $binary, array $binarySpec): ?array {
                if (isset($binarySpec['skip'])) {
                    return null;
                }

                return pmssStatus('Binary: '.$binary, 'OK', (string) $binarySpec['label']);
            },
            static function (array $pathSpec): ?array {
                if (isset($pathSpec['skip'])) {
                    return null;
                }

                return pmssStatus('Path: '.$pathSpec['path'], 'WARN', (string) $pathSpec['label']);
            }
        );

        $this->assertSame(
            [
                ['name' => 'Binary: alpha', 'status' => 'OK', 'detail' => 'first'],
                ['name' => 'Path: /one', 'status' => 'WARN', 'detail' => 'third'],
            ],
            $checks
        );
    }

    public function testSharedProbeCollectorTreatsMissingCatalogBucketsAsEmptyArrays(): void
    {
        $checks = null;

        $this->pmssAssertNoPhpWarnings(function () use (&$checks): void {
            $checks = pmssStatusCollectProbeChecks(
                [],
                static function (string $binary, array $binarySpec): ?array {
                    return pmssStatus('Binary: '.$binary, 'OK', 'unexpected');
                },
                static function (array $pathSpec): ?array {
                    return pmssStatus('Path: '.(string) ($pathSpec['path'] ?? ''), 'OK', 'unexpected');
                }
            );
        });

        $this->assertSame([], $checks);
    }

    public function testStatusSummaryCountsOkWarnAndErr(): void
    {
        $summary = pmssStatusSummary([
            pmssStatus('one', 'OK'),
            pmssStatus('two', 'WARN'),
            pmssStatus('three', 'ERR'),
            pmssStatus('four', 'OK'),
        ]);

        $this->assertEquals(['ok' => 2, 'warn' => 1, 'err' => 1], $summary);
    }

    public function testStatusJsonEncodeSubstitutesInvalidUtf8(): void
    {
        $json = pmssStatusJsonEncode([
            'results' => [
                pmssStatus('bin.php', 'OK', "bad\xB1detail"),
            ],
        ]);

        $this->assertStringContainsString('"results"', $json);
        $this->assertStringContainsString('\\ufffd', $json);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error());
    }

    public function testStatusEmitReturnsErrorWhenJsonEncodingFails(): void
    {
        $script = 'require_once '.var_export($this->pmssRepoPath('scripts/lib/systemStatus.php'), true).';'
            .'exit(pmssStatusEmit('
            .'[pmssStatus("bin.php", "OK", "ready")],'
            .'"PMSS Status",'
            .'true,'
            .'["results" => [["name" => "bin.php", "status" => "OK", "detail" => INF]]],'
            .'null,'
            .'JSON_PRETTY_PRINT'
            .'));';

        $command = $this->pmssExecShellCommandWithTempStderr(
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script),
            [],
            'pmss-status-stderr-'
        );
        $this->pmssAssertCommandFailsToStderr($command['result'], $command['stderrPath'], "Failed to encode status JSON.\n");
    }

    public function testStatusEmitTextHandlesMalformedEntryFieldsWithoutFatal(): void
    {
        $checks = [
            'garbage',
            ['status' => 'WARN', 'detail' => ['nested' => 'ignored']],
            ['name' => ['nested' => 'ignored'], 'status' => 'ERR', 'detail' => 'broken'],
        ];
        $script = 'require_once '.var_export($this->pmssRepoPath('scripts/lib/systemStatus.php'), true).';'
            .'$checks = '.var_export($checks, true).';'
            .'exit(pmssStatusEmit($checks, "PMSS Status", false, [], null, 0, false, 8, false));';

        $command = $this->pmssExecShellCommandWithTempStderr(
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script),
            [],
            'pmss-status-stderr-'
        );
        $this->assertEquals(0, $command['result']['rc']);
        $this->assertStringContainsString('PMSS Status (', $command['result']['output']);
        $this->assertStringContainsString('Summary: 1 OK, 1 WARN, 1 ERR', $command['result']['output']);
        $this->assertEquals('', (string) file_get_contents($command['stderrPath']));
    }

    public function testStatusEmitTextDefaultsMissingSummaryKeysToZero(): void
    {
        $script = 'require_once '.var_export($this->pmssRepoPath('scripts/lib/systemStatus.php'), true).';'
            .'exit(pmssStatusEmit('
            .'[pmssStatus("bin.php", "OK", "ready")],'
            .'"PMSS Status",'
            .'false,'
            .'[],'
            .'["ok" => 1],'
            .'0,'
            .'false,'
            .'8,'
            .'false'
            .'));';

        $command = $this->pmssExecShellCommandWithTempStderr(
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script),
            [],
            'pmss-status-stderr-'
        );
        $this->assertEquals(0, $command['result']['rc']);
        $this->assertStringContainsString('Summary: 1 OK, 0 WARN, 0 ERR', $command['result']['output']);
        $this->assertEquals('', (string) file_get_contents($command['stderrPath']));
    }

    public function testSystemStatusChecksStayStableWithHermeticInputs(): void
    {
        $checks = pmssSystemStatusChecks($this->buildSystemStatusDependencies());

        $this->assertEquals('OS codename', $checks[0]['name']);
        $this->assertEquals('OK', $checks[0]['status']);
        $this->assertEquals('bookworm', $checks[0]['detail']);
        $this->assertEquals('Binary: rtorrent', $checks[1]['name']);
        $this->assertEquals('rtorrent 0.9.8', $checks[1]['detail']);
        $this->assertEquals('Seedbox localnet (config)', $checks[23]['name']);
        $this->assertEquals('OK', $checks[23]['status']);
        $this->assertEquals('Sources codename match', $checks[24]['name']);
        $this->assertEquals('OK', $checks[24]['status']);
        $this->assertEquals('OpenVPN client artifacts', $checks[25]['name']);
        $this->assertEquals('OK', $checks[25]['status']);
        $this->assertEquals('CLI symlink: pyLoad', $checks[29]['name']);
        $this->assertEquals('OK', $checks[29]['status']);
        $this->assertEquals('Component: os.codename', $checks[30]['name']);
        $this->assertEquals('Component: config.nginx', $checks[41]['name']);
        $this->assertEquals(42, count($checks));
    }

    public function testSystemStatusCheckOrderStaysStableWithHermeticInputs(): void
    {
        $checks = pmssSystemStatusChecks($this->buildSystemStatusDependencies());

        $this->assertEquals(
            [
                'OS codename',
                'Binary: rtorrent',
                'Binary: nginx',
                'Binary: lighttpd',
                'Binary: php',
                'Binary: proftpd',
                'Binary: openvpn',
                'Binary: tar',
                'Binary: pigz',
                'Binary: gpg',
                'Binary: curl',
                'Binary: wget',
                'Binary: rsync',
                'Binary: python3',
                'Binary: git',
                'Binary: flexget',
                'Binary: pyload',
                'Apt sources',
                'ProFTPD configuration',
                'OpenVPN directory',
                'VPN Easy-RSA',
                'Seedbox localnet',
                'Nginx directory',
                'Seedbox localnet (config)',
                'Sources codename match',
                'OpenVPN client artifacts',
                'Virtualenv: FlexGet binary',
                'Virtualenv: pyLoad binary',
                'CLI symlink: flexget',
                'CLI symlink: pyLoad',
                'Component: os.codename',
                'Component: apt.sources',
                'Component: bin.rtorrent',
                'Component: bin.nginx',
                'Component: bin.php',
                'Component: bin.proftpd',
                'Component: bin.openvpn',
                'Component: bin.curl',
                'Component: config.proftpd',
                'Component: config.openvpn',
                'Component: config.seedbox.localnet',
                'Component: config.nginx',
            ],
            array_column($checks, 'name')
        );
    }

    public function testSystemStatusIncludesComponentProjectionVerbatim(): void
    {
        $dependencies = $this->buildSystemStatusDependencies();
        $componentDependencies = [
            'runCommand' => $dependencies['runCommand'],
            'pathExists' => $dependencies['pathExists'],
            'readFile' => $dependencies['readFile'],
            'isExecutable' => $dependencies['isExecutable'],
        ];

        $systemChecks = pmssSystemStatusChecks($dependencies);
        $componentChecks = pmssComponentStatusChecks($componentDependencies);
        $expectedProjection = array_map(
            static function (array $entry): array {
                return [
                    'name' => 'Component: '.$entry['name'],
                    'status' => $entry['status'],
                    'detail' => $entry['detail'],
                ];
            },
            $componentChecks
        );

        $this->assertSame($expectedProjection, array_slice($systemChecks, -count($expectedProjection)));
    }

    public function testComponentStatusCheckOrderStaysStableWithHermeticInputs(): void
    {
        $dependencies = $this->buildSystemStatusDependencies();
        $checks = pmssComponentStatusChecks([
            'runCommand' => $dependencies['runCommand'],
            'pathExists' => $dependencies['pathExists'],
            'readFile' => $dependencies['readFile'],
            'isExecutable' => $dependencies['isExecutable'],
        ]);

        $this->assertSame(
            [
                'os.codename',
                'apt.sources',
                'bin.rtorrent',
                'bin.nginx',
                'bin.php',
                'bin.proftpd',
                'bin.openvpn',
                'bin.curl',
                'config.proftpd',
                'config.openvpn',
                'config.seedbox.localnet',
                'config.nginx',
            ],
            array_column($checks, 'name')
        );
    }

    public function testSystemStatusWarnsWhenSymlinkTargetCannotBeRead(): void
    {
        $dependencies = $this->buildSystemStatusDependencies();
        $dependencies['readLink'] = static function (string $path): string {
            if ($path === '/usr/local/bin/flexget') {
                return '';
            }

            return [
                '/usr/local/bin/flexget' => '/opt/flexget/bin/flexget',
                '/usr/local/bin/pyload' => '/opt/pyload/bin/pyload',
            ][$path] ?? '';
        };

        $checks = pmssSystemStatusChecks($dependencies);

        $this->assertEquals('CLI symlink: flexget', $checks[28]['name']);
        $this->assertEquals('WARN', $checks[28]['status']);
        $this->assertEquals('/usr/local/bin/flexget symlink target unreadable', $checks[28]['detail']);
    }

    public function testCliScriptsUseSharedComponentStatusHelper(): void
    {
        $componentSource = $this->pmssReadRepoFile('scripts/util/componentStatus.php');
        $systemSource = $this->pmssReadRepoFile('scripts/util/systemTest.php');
        $librarySource = $this->pmssReadRepoFile('scripts/lib/systemStatus.php');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/cli/optionParser.php';", $componentSource);
        $this->assertStringContainsString("require_once __DIR__.'/../lib/systemStatus.php';", $componentSource);
        $this->assertStringContainsString("pmssParseCliTokens(\$argv ?? (\$_SERVER['argv'] ?? []));", $componentSource);
        $this->pmssAssertStringNotContainsString('getopt(', $componentSource);
        $this->assertStringContainsString("pmssCliOptionPresent(\$parsed, 'json')", $componentSource);
        $this->assertStringContainsString('pmssComponentStatusChecks()', $componentSource);
        $this->assertStringContainsString('pmssSystemStatusChecks()', $systemSource);
        $this->assertStringContainsString('function pmssStatusJsonEncode(', $librarySource);
        $this->assertStringContainsString('function pmssStatusEmit(', $librarySource);
        $this->assertStringContainsString('function pmssSystemStatusChecks(', $librarySource);
        $this->assertStringContainsString('function pmssComponentStatusChecks(array $dependencies = [])', $librarySource);
        $this->assertStringContainsString('pmssStatusEmit(', $componentSource);
        $this->assertStringContainsString('pmssStatusEmit(', $systemSource);
        $this->pmssAssertStringNotContainsString('json_decode($componentJson, true);', $systemSource);
    }
}
