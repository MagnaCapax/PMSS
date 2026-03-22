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

    public function testComponentChecksStayStableWithHermeticInputs(): void
    {
        $checks = pmssComponentStatusChecks(
            static function (string $command): string {
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
            static function (string $path): bool {
                return in_array($path, ['/etc/openvpn', '/etc/nginx'], true);
            },
            static function (string $path): string {
                return is_file($path) ? (string) file_get_contents($path) : '';
            }
        );

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

    public function testCliScriptsUseSharedComponentStatusHelper(): void
    {
        $componentSource = $this->pmssReadRepoFile('scripts/util/componentStatus.php');
        $systemSource = $this->pmssReadRepoFile('scripts/util/systemTest.php');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/systemStatus.php';", $componentSource);
        $this->assertStringContainsString('pmssComponentStatusChecks()', $componentSource);
        $this->assertStringContainsString('pmssComponentStatusChecks()', $systemSource);
        $this->pmssAssertStringNotContainsString('json_decode($componentJson, true);', $systemSource);
    }
}
