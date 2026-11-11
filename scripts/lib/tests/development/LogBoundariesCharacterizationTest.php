<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';

/** Behavior locks for scripts/lib/log.php and its log/ responsibility modules. */
class LogBoundariesCharacterizationTest extends TestCase
{
    public function testScalarRepresentationsShareConversionWithoutChangingFallbacks(): void
    {
        $object = new class {
            public function __toString(): string { return " \tready\0\n now "; }
        };
        foreach ([
            [null, 'fallback'], [false, 'false'], [true, 'true'], [0, '0'],
            [12, '12'], [1.25, '1.25'], ['', 'fallback'], ["\0\t\r\n", 'fallback'],
            [" a\x1b\0\t b ", 'a b'], ['0', '0'], [[], 'array'],
            [new \stdClass(), 'object'], [$object, 'ready now'],
        ] as [$value, $expected]) {
            $this->assertSame($expected, \pmssLogScalarText($value, 'fallback'));
        }
        $this->assertSame('', \pmssLogScalarText(null));
        $this->assertSame('  fallback  ', \pmssLogScalarText('', '  fallback  '));
    }

    public function testJsonCodecsRetainExactBytesAndFailurePolicies(): void
    {
        $payload = ['path' => '/data/fixture', 'text' => "\xff", 'zero' => 0.0];
        $this->assertSame('{"path":"\\/data\\/fixture","text":"\\ufffd","zero":0}',
            \pmssJsonEncodeSafe($payload));
        $this->assertSame('{"path":"/data/fixture","text":"\\ufffd","zero":0.0}',
            \pmssJsonEncodeSafe($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame(null, \pmssJsonEncodePretty($payload));
        $this->assertSame(null, \pmssJsonEncodePrettyLine($payload));
        $this->assertSame(null, \pmssJsonEncodeSafe(['number' => INF]));
        $this->assertSame(null, \pmssJsonEncodePretty(['number' => NAN]));
        $this->assertSame("{\n    \"path\": \"/data/fixture\"\n}\n",
            \pmssJsonEncodePrettyLine(['path' => '/data/fixture']));
        foreach (['', ' ', 'null', 'false', '1', '"value"', '{', "\xff"] as $text) {
            $this->assertSame(null, \pmssJsonDecodeAssoc($text));
        }
        $this->assertSame([], \pmssJsonDecodeAssoc('{}'));
        $this->assertSame(['key' => [1, null]], \pmssJsonDecodeAssoc('{"key":[1,null]}'));
    }

    public function testJsonFileReadsKeepAcceptedPayloadsAndPathRefusals(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-log-boundaries-');
        $path = $dir.'/payload.json';
        foreach ([
            ['', null], [" \n\t", null], ['0', null], ['false', null], ['null', null],
            ['"value"', null], ['{', null], ['{}', []], ['[]', []],
            ['{"key":[0,false,null]}', ['key' => [0, false, null]]],
        ] as [$bytes, $expected]) {
            $this->pmssWriteFile($path, $bytes);
            foreach ([false, true] as $safePathRequired) {
                json_decode('{');
                $before = json_last_error();
                $this->assertSame($expected, \pmssJsonFileReadAssoc($path, $safePathRequired));
                if (trim($bytes) === '') $this->assertSame($before, json_last_error());
            }
        }
        $this->assertSame(null, \pmssJsonFileReadAssoc($dir.'/missing'));
        $this->assertSame(null, \pmssJsonFileReadAssoc($dir));
        $this->assertSame(null, \pmssJsonFileReadAssoc($path."\0"));
        $this->pmssWriteSymlinks($dir, ['linked.json' => $path]);
        $this->assertSame(null, \pmssJsonFileReadAssoc($dir.'/linked.json'));
    }

    public function testJsonLinesKeepRecordOrderAndPropagateHandlerFailure(): void
    {
        $path = $this->pmssMakeTempDir('pmss-log-lines-boundaries-').'/events.jsonl';
        $this->pmssWriteFile($path, "null\ninvalid\n{\"id\":1}\n[]\n{\"id\":2}");
        $this->assertSame([['id' => 1], [], ['id' => 2]], \pmssJsonLineFileRead($path));
        $this->assertSame(['id' => 2], \pmssJsonLineFileLast($path));
        $failure = new \RuntimeException('handler failure');
        $seen = [];
        try {
            \pmssJsonLineFileEach($path, static function (array $entry) use (&$seen, $failure): void {
                $seen[] = $entry;
                throw $failure;
            });
            $this->fail('handler failure must propagate');
        } catch (\RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }
        $this->assertSame([['id' => 1]], $seen);
        // A subsequent traversal must still return every accepted record.
        $this->assertSame([['id' => 1], [], ['id' => 2]], \pmssJsonLineFileRead($path));
    }
}
