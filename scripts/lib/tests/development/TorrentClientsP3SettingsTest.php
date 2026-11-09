<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/transmissionSettings.php';

final class TorrentClientsP3SettingsTest extends TestCase
{
    private function fixture(string $raw): array
    {
        $root = $this->pmssMakeTempDir('pmss-p3-settings-');
        $home = $root.'/t';
        $path = $this->pmssWriteRelativeFile($home, '.config/transmission-daemon/settings.json', $raw, 0700);
        $writes = (object) ['count' => 0];
        $ops = [
            'write' => static function (string $path, string $content) use ($writes): bool {
                $writes->count++;
                return file_put_contents($path, $content) === strlen($content);
            },
        ] + self::pmssMakeCallbacks(['running' => false, 'prepare' => true]);
        return [$home, $path, $ops, $writes];
    }

    public function testManagedSettingsUseSocketAndEmptyWhitelist(): void
    {
        [$home, $path, $ops] = $this->fixture('{}');
        $this->assertTrue(\pmssTransmissionSettingsApply('t', $home, $ops));
        $settings = $this->pmssReadJsonArrayFile($path);
        $this->pmssAssertArraySubsetSame([
            'rpc-bind-address' => 'unix:'.$home.'/.lighttpd/transmission.sock', 'rpc-socket-mode' => '0700',
            'rpc-whitelist-enabled' => true, 'rpc-whitelist' => '', 'rpc-authentication-required' => false,
            'rpc-url' => '/user-t/transmission/',
        ], $settings);
    }

    public function testKebabKeysStayKebabAndTwinsDisappear(): void
    {
        [$home, $path, $ops] = $this->fixture('{"rpc-bind-address":"old","rpc_bind_address":"other","rpc-password":"old","download-dir":"/data"}');
        $ops['snakeCase'] = false;
        $this->assertTrue(\pmssTransmissionSettingsApply('t', $home, $ops));
        $settings = $this->pmssReadJsonArrayFile($path);
        $this->assertSame('unix:'.$home.'/.lighttpd/transmission.sock', $settings['rpc-bind-address']);
        $this->assertFalse(array_key_exists('rpc_bind_address', $settings));
        $this->assertFalse(array_key_exists('rpc-password', $settings));
        $this->assertSame('/data', $settings['download-dir']);
    }

    public function testSnakeKeysStaySnakeAndTwinsDisappear(): void
    {
        [$home, $path, $ops] = $this->fixture('{"rpc_bind_address":"old","rpc-bind-address":"other"}');
        $this->assertTrue(\pmssTransmissionSettingsApply('t', $home, $ops));
        $settings = $this->pmssReadJsonArrayFile($path);
        $this->assertSame('unix:'.$home.'/.lighttpd/transmission.sock', $settings['rpc_bind_address']);
        $this->assertFalse(array_key_exists('rpc-bind-address', $settings));
    }

    public function testRunningDaemonLeavesSettingsUnchanged(): void
    {
        [$home, $path, $ops, $writes] = $this->fixture('{"rpc-bind-address":"old"}');
        $ops = self::pmssMakeCallbacks(['running' => true]) + $ops;
        $this->assertFalse(\pmssTransmissionSettingsApply('t', $home, $ops));
        $this->assertSame(0, $writes->count);
        $this->assertSame('{"rpc-bind-address":"old"}', file_get_contents($path));
    }

    public function testLinkedOrMalformedSettingsStayUntouched(): void
    {
        [$home, $path, $ops, $writes] = $this->fixture('bad-json');
        $this->assertFalse(\pmssTransmissionSettingsApply('t', $home, $ops));
        unlink($path);
        $this->pmssWriteFile($home.'/outside.json', '{}');
        symlink($home.'/outside.json', $path);
        $this->assertFalse(\pmssTransmissionSettingsApply('t', $home, $ops));
        $this->assertSame(0, $writes->count);
        $this->assertSame('{}', file_get_contents($home.'/outside.json'));
    }

    public function testOversizedSettingsAreRefusedWithoutBeingRead(): void
    {
        // Root runs this for every account; an account-sized file must not be read into memory.
        [$home, $path, $ops, $writes] = $this->fixture('{"pad":"'.str_repeat('x', 1048576).'"}');
        $this->assertFalse(\pmssTransmissionSettingsApply('t', $home, $ops));
        $this->assertSame(0, $writes->count);
        $this->pmssWriteFile($path, '{}');
        $this->assertTrue(\pmssTransmissionSettingsApply('t', $home, $ops));
        $this->assertSame(1, $writes->count);
    }
}
