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
        $this->pmssTrackEnvOverrides([
            'PMSS_OS_RELEASE_PATH' => $this->osReleasePath,
            'PMSS_APT_SOURCES_PATH' => $this->sourcesPath,
        ]);
        pmssResetOsReleaseCache();
    }

    public function tearDown(): void
    {
        pmssResetOsReleaseCache();
    }

    private function buildSystemStatusDependencies(): array
    {
        $sourcesPath = (string) getenv('PMSS_APT_SOURCES_PATH');
        $commandMap = [];
        $binaryPaths = [
            'nginx' => '/usr/sbin/nginx', 'lighttpd' => '/usr/sbin/lighttpd', 'proftpd' => '/usr/sbin/proftpd',
            'openvpn' => '/usr/sbin/openvpn', 'flexget' => '/opt/flexget/bin/flexget', 'pyload' => '/opt/pyload/bin/pyload',
        ];
        $binaryDetails = [
            'rtorrent' => 'rtorrent 0.9.8', 'nginx' => 'nginx version: nginx/1.22.0', 'lighttpd' => 'lighttpd/1.4.69',
            'php' => 'PHP 8.2.0', 'proftpd' => 'ProFTPD Version 1.3.7', 'openvpn' => 'OpenVPN 2.6.0',
            'tar' => 'tar (GNU tar) 1.34', 'pigz' => 'pigz 2.8', 'gpg' => 'gpg (GnuPG) 2.2.40', 'curl' => 'curl 8.4.0',
            'wget' => 'GNU Wget 1.21.3', 'rsync' => 'rsync  version 3.2.7', 'python3' => 'Python 3.11.2',
            'git' => 'git version 2.39.2', 'flexget' => '3.8.51', 'pyload' => 'pyLoad 0.5.0',
        ];
        foreach (pmssStatusProbeSpecs($sourcesPath)['binaries'] as $binary => $spec) {
            $commandMap["command -v '".$binary."'"] = $binaryPaths[$binary] ?? '/usr/bin/'.$binary;
            $commandMap[(string) $spec['infoCommand']] = $binaryDetails[$binary] ?? '';
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

    private function buildComponentStatusDependencies(): array
    {
        return array_intersect_key($this->buildSystemStatusDependencies(), array_flip(['runCommand', 'pathExists', 'readFile', 'isExecutable']));
    }

    private function runStatusEmitScript(string $script): array
    {
        return $this->pmssExecShellCommandWithTempStderr(
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
                'require_once '.var_export($this->pmssRepoPath('scripts/lib/systemStatus.php'), true).';'.$script
            ),
            [],
            'pmss-status-stderr-'
        );
    }

    public function testComponentChecksStayStableWithHermeticInputs(): void
    {
        $dependencies = $this->buildComponentStatusDependencies();
        $baseRunCommand = $dependencies['runCommand'];
        $dependencies['runCommand'] = static function (string $command) use ($baseRunCommand): string {
            return $command === "command -v 'proftpd'" ? '' : $baseRunCommand($command);
        };
        $dependencies['pathExists'] = static function (string $path): bool {
            return in_array($path, ['/etc/openvpn', '/etc/nginx'], true);
        };
        $dependencies['isExecutable'] = static function (string $path): bool {
            return in_array($path, ['/usr/bin/rtorrent', '/usr/sbin/nginx', '/usr/bin/php', '/usr/sbin/openvpn', '/usr/bin/curl'], true);
        };

        $checks = pmssComponentStatusChecks($dependencies);

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

    public function testComponentChecksUseInjectedSourcesFilePredicate(): void
    {
        $dependencies = $this->buildComponentStatusDependencies();
        $dependencies['isFile'] = static function (string $path): bool {
            return false;
        };

        $checks = pmssComponentStatusChecks($dependencies);

        $this->assertSame(['name' => 'apt.sources', 'status' => 'WARN', 'detail' => 'missing sources.list'], $checks[1]);
    }

    public function testComponentChecksTreatWhitespaceBinaryPathsAsMissing(): void
    {
        $dependencies = $this->buildComponentStatusDependencies();
        $baseRunCommand = $dependencies['runCommand'];
        $dependencies['runCommand'] = static function (string $command) use ($baseRunCommand): string {
            return $command === "command -v 'nginx'" ? " \n\t " : $baseRunCommand($command);
        };

        $checks = pmssComponentStatusChecks($dependencies);

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

    public function testBinaryPathResolveRejectsUnsafeBinaryNamesBeforeShelling(): void
    {
        $commands = [];
        $runCommand = static function (string $command) use (&$commands): string {
            $commands[] = $command;
            return '/usr/bin/unexpected';
        };

        foreach (['', 'nginx;id', '../nginx', "php\n-v"] as $binary) {
            $this->assertSame('', pmssStatusBinaryPathResolve($binary, $runCommand));
        }

        $this->assertSame([], $commands);
    }

    public function testSharedBinaryProbeRendererKeepsSystemAndComponentViewsStable(): void
    {
        $dependencies = $this->buildComponentStatusDependencies();
        $runCommand = $dependencies['runCommand'];
        $isExecutable = $dependencies['isExecutable'];
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

    public function testSharedProbeRendererPreservesCatalogOrderAndViewFiltering(): void
    {
        $specs = [
            'binaries' => [
                'alpha' => ['infoCommand' => 'alpha --version', 'componentName' => 'bin.alpha'],
                'beta' => ['infoCommand' => 'beta --version'],
            ],
            'paths' => [
                ['systemLabel' => 'Alpha config', 'componentName' => 'config.alpha', 'path' => '/alpha'],
                ['systemLabel' => 'Beta config', 'path' => '/beta'],
            ],
        ];
        $runCommand = static function (string $command): string {
            return [
                "command -v 'alpha'" => '/usr/bin/alpha',
                'alpha --version' => 'alpha 1.0',
            ][$command] ?? '';
        };
        $isExecutable = static function (string $path): bool {
            return $path === '/usr/bin/alpha';
        };
        $pathExists = static function (string $path): bool {
            return $path === '/alpha';
        };

        $this->pmssAssertNoPhpWarnings(function () use ($specs, $runCommand, $isExecutable, $pathExists): void {
            $this->assertSame([], pmssStatusProbeChecks([], $runCommand, $isExecutable, $pathExists));
            $this->assertSame(
                [
                    ['name' => 'Binary: alpha', 'status' => 'OK', 'detail' => 'alpha 1.0'],
                    ['name' => 'Binary: beta', 'status' => 'WARN', 'detail' => 'Not found in PATH'],
                    ['name' => 'Alpha config', 'status' => 'OK', 'detail' => '/alpha'],
                    ['name' => 'Beta config', 'status' => 'WARN', 'detail' => '/beta missing'],
                ],
                pmssStatusProbeChecks($specs, $runCommand, $isExecutable, $pathExists)
            );
            $this->assertSame(
                [
                    ['name' => 'bin.alpha', 'status' => 'OK', 'detail' => '/usr/bin/alpha'],
                    ['name' => 'config.alpha', 'status' => 'OK', 'detail' => '/alpha'],
                ],
                pmssStatusProbeChecks($specs, $runCommand, $isExecutable, $pathExists, true)
            );
        });
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

    public function testStatusEmitJsonSubstitutesInvalidUtf8(): void
    {
        $command = $this->runStatusEmitScript(
            'exit(pmssStatusEmit('
            .'[pmssStatus("bin.php", "OK", "ready")],'
            .'"PMSS Status",'
            .'true,'
            .'["results" => [pmssStatus("bin.php", "OK", "bad\xB1detail")]],'
            .'null'
            .'));'
        );

        $this->pmssAssertCommandSucceedsWithoutStderr($command['result'], $command['stderrPath']);
        $this->assertStringContainsAllStrings(['"results"', '\\ufffd'], $command['result']['output']);
    }

    public function testStatusEmitReturnsErrorWhenJsonEncodingFails(): void
    {
        $command = $this->runStatusEmitScript(
            'exit(pmssStatusEmit('
            .'[pmssStatus("bin.php", "OK", "ready")],'
            .'"PMSS Status",'
            .'true,'
            .'["results" => [["name" => "bin.php", "status" => "OK", "detail" => INF]]],'
            .'null,'
            .'JSON_PRETTY_PRINT'
            .'));'
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
        $command = $this->runStatusEmitScript(
            '$checks = '.var_export($checks, true).';'
            .'exit(pmssStatusEmit($checks, "PMSS Status", false, [], null, 0, false, 8, false));'
        );
        $this->pmssAssertCommandSucceedsWithoutStderr($command['result'], $command['stderrPath']);
        $this->assertStringContainsAllStrings(['PMSS Status (', 'Summary: 1 OK, 1 WARN, 1 ERR'], $command['result']['output']);
    }

    public function testStatusEmitTextDefaultsMissingSummaryKeysToZero(): void
    {
        $command = $this->runStatusEmitScript(
            'exit(pmssStatusEmit('
            .'[pmssStatus("bin.php", "OK", "ready")],'
            .'"PMSS Status",'
            .'false,'
            .'[],'
            .'["ok" => 1],'
            .'0,'
            .'false,'
            .'8,'
            .'false'
            .'));'
        );
        $this->pmssAssertCommandSucceedsWithoutStderr($command['result'], $command['stderrPath']);
        $this->assertStringContainsString('Summary: 1 OK, 0 WARN, 0 ERR', $command['result']['output']);
    }

    public function testSystemStatusChecksStayStableWithHermeticInputs(): void
    {
        $checks = pmssSystemStatusChecks($this->buildSystemStatusDependencies());
        $sourcesPath = $this->sourcesPath;

        $this->assertSame(
            [
                'OS codename|OK|bookworm',
                'Binary: rtorrent|OK|rtorrent 0.9.8',
                'Binary: nginx|OK|nginx version: nginx/1.22.0',
                'Binary: lighttpd|OK|lighttpd/1.4.69',
                'Binary: php|OK|PHP 8.2.0',
                'Binary: proftpd|OK|ProFTPD Version 1.3.7',
                'Binary: openvpn|OK|OpenVPN 2.6.0',
                'Binary: tar|OK|tar (GNU tar) 1.34',
                'Binary: pigz|OK|pigz 2.8',
                'Binary: gpg|OK|gpg (GnuPG) 2.2.40',
                'Binary: curl|OK|curl 8.4.0',
                'Binary: wget|OK|GNU Wget 1.21.3',
                'Binary: rsync|OK|rsync  version 3.2.7',
                'Binary: python3|OK|Python 3.11.2',
                'Binary: git|OK|git version 2.39.2',
                'Binary: flexget|OK|3.8.51',
                'Binary: pyload|OK|pyLoad 0.5.0',
                'Apt sources|OK|<sources>',
                'ProFTPD configuration|OK|/etc/proftpd/proftpd.conf',
                'OpenVPN directory|OK|/etc/openvpn',
                'VPN Easy-RSA|OK|/etc/openvpn/easy-rsa',
                'Seedbox localnet|OK|/etc/seedbox/localnet',
                'Nginx directory|OK|/etc/nginx',
                'Seedbox localnet (config)|OK|/etc/seedbox/config/localnet readable via 0664 + traversable dirs',
                'Sources codename match|OK|sources.list references bookworm',
                'OpenVPN client artifacts|OK|openvpn-host-pulsedmedia-com.ovpn, openvpn-host-pulsedmedia-com.crt',
                'Virtualenv: FlexGet binary|OK|/opt/flexget/bin/flexget',
                'Virtualenv: pyLoad binary|OK|/opt/pyload/bin/pyload',
                'CLI symlink: flexget|OK|/usr/local/bin/flexget -> /opt/flexget/bin/flexget',
                'CLI symlink: pyLoad|OK|/usr/local/bin/pyload -> /opt/pyload/bin/pyload',
                'Component: os.codename|OK|bookworm',
                'Component: apt.sources|OK|contains bookworm',
                'Component: bin.rtorrent|OK|/usr/bin/rtorrent',
                'Component: bin.nginx|OK|/usr/sbin/nginx',
                'Component: bin.php|OK|/usr/bin/php',
                'Component: bin.proftpd|OK|/usr/sbin/proftpd',
                'Component: bin.openvpn|OK|/usr/sbin/openvpn',
                'Component: bin.curl|OK|/usr/bin/curl',
                'Component: config.proftpd|OK|/etc/proftpd/proftpd.conf',
                'Component: config.openvpn|OK|/etc/openvpn',
                'Component: config.seedbox.localnet|OK|/etc/seedbox/localnet',
                'Component: config.nginx|OK|/etc/nginx',
            ],
            array_map(static function (array $check) use ($sourcesPath): string {
                $detail = str_replace($sourcesPath, '<sources>', (string) ($check['detail'] ?? ''));
                return $check['name'].'|'.$check['status'].'|'.$detail;
            }, $checks)
        );
    }

    public function testSystemStatusRejectsInvalidOpenvpnHostnameBeforeArtifactProbe(): void
    {
        $dependencies = $this->buildSystemStatusDependencies();
        $readFile = $dependencies['readFile'];
        $isFile = $dependencies['isFile'];
        $dependencies['readFile'] = static function (string $path) use ($readFile): string {
            return $path === '/etc/hostname' ? "bad/host\n" : $readFile($path);
        };
        $dependencies['isFile'] = static function (string $path) use ($isFile): bool {
            if (strpos($path, '/home/openvpn-') === 0) {
                throw new \AssertionError('invalid OpenVPN hostname must not reach artifact paths');
            }
            return $isFile($path);
        };

        $checks = pmssSystemStatusChecks($dependencies);

        $this->assertEquals('OpenVPN client artifacts', $checks[25]['name']);
        $this->assertEquals('WARN', $checks[25]['status']);
        $this->assertEquals('hostname invalid', $checks[25]['detail']);
    }

    public function testSystemStatusReportsMissingOpenvpnArtifactsAfterValidHostname(): void
    {
        $dependencies = $this->buildSystemStatusDependencies();
        $isFile = $dependencies['isFile'];
        $dependencies['isFile'] = static function (string $path) use ($isFile): bool {
            return $path === '/home/openvpn-host-pulsedmedia-com.crt' ? false : $isFile($path);
        };

        $checks = pmssSystemStatusChecks($dependencies);

        $this->assertSame(
            ['name' => 'OpenVPN client artifacts', 'status' => 'WARN', 'detail' => 'missing: openvpn-host-pulsedmedia-com.crt'],
            $checks[25]
        );
    }

    public function testSystemStatusIncludesComponentProjectionVerbatim(): void
    {
        $dependencies = $this->buildSystemStatusDependencies();
        $componentDependencies = $this->buildComponentStatusDependencies();

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
        $checks = pmssComponentStatusChecks($this->buildComponentStatusDependencies());

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

        $this->assertStringContainsAllStrings([
            "require_once __DIR__.'/../lib/cli/optionParser.php';",
            "require_once __DIR__.'/../lib/systemStatus.php';",
            "pmssParseCliTokens(pmssCliArgv(\$argv ?? null));",
            "pmssCliOptionPresent(\$parsed, 'json')",
            'pmssComponentStatusChecks()',
            'pmssStatusEmit(',
        ], $componentSource);
        $this->pmssAssertStringNotContainsString('getopt(', $componentSource);
        $this->assertStringContainsAllStrings(['pmssSystemStatusChecks()', 'pmssStatusEmit('], $systemSource);
        $this->assertStringContainsAllStrings([
            'function pmssStatusEmit(',
            'function pmssSystemStatusChecks(',
            'function pmssComponentStatusChecks(array $dependencies = [])',
        ], $librarySource);
        $this->pmssAssertStringNotContainsString('json_decode($componentJson, true);', $systemSource);
    }
}
