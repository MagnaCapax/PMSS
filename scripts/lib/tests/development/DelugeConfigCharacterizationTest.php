<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/delugeManagedConfig.php';

/** Locks the legacy dual-JSON format and mutation boundaries before decomposition. */
final class DelugeConfigCharacterizationTest extends TestCase
{
    public function testDecodePreservesNestedAndEscapedMetadata(): void
    {
        foreach (['', '{}', '}{', '"', '\\', '\\"', "\n", 'ä', '\"}\\{'] as $value) {
            $meta = ['file' => 1, 'nested' => [['value' => $value]]];
            $config = ['label' => $value, 'list' => [false, null, 1, ['x' => '}']]];
            foreach (['', " \t\n\r"] as $padding) {
                $raw = $padding.json_encode($meta).$padding.json_encode($config).$padding;
                $this->assertSame(['meta' => $meta, 'config' => $config], \pmssDelugeConfigDecode($raw));
            }
        }
    }

    public function testDecodeRejectsMalformedAndScalarDocuments(): void
    {
        foreach (['', ' ', '{}', '{}null', '{}true', '{}1', '{}"value"', '[]{}',
            '{}{}{}', '{}{}junk', '{"x":"unterminated}{}', '{"x":}{}',
            '{"x":{}}{', "\0{}{}", "{}{}\0", "{}\0{}", "\v{}{}"] as $raw) {
            $this->assertSame(null, \pmssDelugeConfigDecode($raw), bin2hex($raw));
        }
        $raw = '{"nested":{"braces":"}\\\"{"}}{"key":"value"}';
        for ($length = 0; $length < strlen($raw); $length++) {
            $this->assertSame(null, \pmssDelugeConfigDecode(substr($raw, 0, $length)));
        }
    }

    public function testDecodeRetainsArraySecondDocumentAndDuplicateKeyPrecedence(): void
    {
        $this->assertSame(['meta' => [], 'config' => []], \pmssDelugeConfigDecode('{}[]'));
        $this->assertSame(['meta' => ['x' => 2], 'config' => [1, 2]], \pmssDelugeConfigDecode('{"x":1,"x":2}[1,2]'));
    }

    public function testEncodingBytesAndFailures(): void
    {
        $this->assertSame("{\n    \"file\": 1\n}{\n    \"path\": \"/data/ä\",\n    \"enabled\": true\n}",
            \pmssDelugeConfigEncode(['file' => 1], ['path' => '/data/ä', 'enabled' => true]));
        $this->assertSame(null, \pmssDelugeConfigEncode(['bad' => "\xff"], []));
        $this->assertSame(null, \pmssDelugeConfigEncode([], ['bad' => INF]));
    }

    public function testNoChangeAndInvalidMutationLeaveOriginalBytes(): void
    {
        $path = $this->pmssMakeTempDir('pmss-deluge-characterization-').'/core.conf';
        $raw = ' {"file":1} {"user":"kept"} ';
        file_put_contents($path, $raw);
        foreach ([static function (array $config) { return $config; },
            static function () { return null; }, static function () { return false; },
            static function () { return ['invalid' => INF]; }] as $mutator) {
            $this->assertFalse(\pmssDelugeConfigMutate('alice', $mutator, $path));
            $this->assertSame($raw, file_get_contents($path));
        }
    }

    public function testBadInputNeverInvokesMutatorAndThrowsRemainVisible(): void
    {
        $path = $this->pmssMakeTempDir('pmss-deluge-characterization-bad-').'/core.conf';
        $calls = 0;
        $mutator = static function () use (&$calls) { $calls++; return []; };
        $this->assertFalse(\pmssDelugeConfigMutate('alice', $mutator, $path));
        file_put_contents($path, '{}broken');
        $this->assertFalse(\pmssDelugeConfigMutate('alice', $mutator, $path));
        $this->assertSame(0, $calls);
        file_put_contents($path, '{}{}');
        try {
            \pmssDelugeConfigMutate('alice', static function () { throw new \RuntimeException('mutation rejected'); }, $path);
            $this->assertTrue(false, 'Mutator exception must propagate');
        } catch (\RuntimeException $exception) {
            $this->assertSame('mutation rejected', $exception->getMessage());
        }
        $this->assertSame('{}{}', file_get_contents($path));
    }

    public function testHostlistChangesOnlyCompleteLoopbackLocalclientRows(): void
    {
        $root = $this->pmssMakeTempDir('pmss-deluge-hostlist-characterization-');
        $auth = $this->pmssWriteFile($root.'/auth', "localclient:fixture-token:10\nother:unchanged:5\n");
        $hosts = [
            ['a', '127.0.0.1', 58846, 'localclient', 'old', 'extra'],
            ['b', 'localhost', 58846, 'localclient', 'old'],
            ['c', 'remote.example', 58846, 'localclient', 'old'],
            ['d', 'localhost', 58846, 'other', 'old'],
            ['short', 'localhost', 58846, 'localclient'], null,
        ];
        $meta = ['file' => 1, 'format' => 1, 'custom' => ['keep' => '}']];
        $config = ['hosts' => $hosts, 'unrelated' => ['keep' => true]];
        $path = $this->pmssWriteFile($root.'/hostlist.conf', json_encode($meta).json_encode($config));
        chmod($path, 0640);
        $this->assertTrue(\pmssDelugeHostlistSyncLocalclientPassword('alice', $path, $auth));
        $hosts[0][4] = 'fixture-token';
        $hosts[1][4] = 'fixture-token';
        $this->assertSame(['meta' => $meta, 'config' => ['hosts' => $hosts, 'unrelated' => ['keep' => true]]],
            \pmssDelugeConfigDecode(file_get_contents($path)));
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertSame("localclient:fixture-token:10\nother:unchanged:5\n", file_get_contents($auth));
        $bytes = file_get_contents($path);
        $this->assertFalse(\pmssDelugeHostlistSyncLocalclientPassword('alice', $path, $auth));
        $this->assertSame($bytes, file_get_contents($path));
    }

    public function testMalformedHostContainersRemainUntouched(): void
    {
        $root = $this->pmssMakeTempDir('pmss-deluge-hostlist-malformed-');
        $auth = $this->pmssWriteFile($root.'/auth', "localclient:fixture-token:10\n");
        foreach (['{}{}', '{}{"hosts":null}', '{}{"hosts":false}', '{}{"hosts":"bad"}', '{}{"hosts":[]}'] as $raw) {
            $path = $this->pmssWriteFile($root.'/hostlist.conf', $raw);
            $this->assertFalse(\pmssDelugeHostlistSyncLocalclientPassword('alice', $path, $auth));
            $this->assertSame($raw, file_get_contents($path));
        }
    }
}
