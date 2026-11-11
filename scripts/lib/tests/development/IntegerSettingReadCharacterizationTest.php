<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/integerSetting.php';

/** Locks integerSetting.php, integerSettingRead.php and integerSettingValue.php without host I/O. */
class IntegerSettingReadCharacterizationTest extends TestCase
{
    public function testReadersRetainDefaultsParserInputsAndObservationOrder(): void
    {
        $script = <<<'PHP'
namespace IntegerSettingReadFixture;
function clearstatcache($real, $path) { $GLOBALS['events'][] = ['clear', $real, $path]; }
function lstat($path) { $GLOBALS['events'][] = ['stat', $path]; return $GLOBALS['present'] ? [] : false; }
function pmssReadRegularFileContentsVerified($path, $owner, $max) {
    $GLOBALS['events'][] = ['read', $path, $owner, $max];
    if ($GLOBALS['stage'] === 'read') throw $GLOBALS['failure'];
    return $GLOBALS['contents'];
}
PHP;
        // Export by reflection so the same behavior lock runs against the original facade too.
        foreach (['pmssIntegerSettingFileRead', 'pmssIntegerSettingFileReadOrNull', 'pmssIntegerSettingFileParse'] as $name) {
            if (function_exists($name)) $script .= \pmssTestingFunctionExport($name, 'IntegerSettingReadFixture');
        }
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_SETTING_READ'), true) as [$nullable, $present, $contents, $parsed, $default, $stage, $errorType]) {
    $GLOBALS['present'] = $present; $GLOBALS['contents'] = $contents; $GLOBALS['stage'] = $stage;
    $GLOBALS['events'] = [];
    $GLOBALS['failure'] = $errorType ? new \Error('fixture fault') : new \RuntimeException('fixture fault');
    $parser = static function ($raw, &$error) use ($parsed) {
        $GLOBALS['events'][] = ['parse', $raw, $error];
        $error = 'parser diagnostic';
        if ($GLOBALS['stage'] === 'parse') throw $GLOBALS['failure'];
        return $parsed;
    };
    $result = $caught = null;
    try {
        $result = $nullable ? pmssIntegerSettingFileReadOrNull('/fixture/limit', $parser, $default)
            : pmssIntegerSettingFileRead('/fixture/limit', $parser, $default);
    } catch (\Throwable $error) { $caught = $error; }
    $results[] = [$result, $caught === $GLOBALS['failure'], $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $cases = $expected = [];
        foreach ([false, true] as $nullable) {
            foreach ([
                [false, null, null, 17, null, null],
                [true, null, null, 17, null, null],
                [true, '', null, 17, null, null],
                [true, " \t\r\n", null, -2, null, null],
                [true, " 23GiB\r\n", 23, 17, null, '23GiB'],
                [true, "0\n", 0, 17, null, '0'],
                [true, "bad\n", null, 17, null, 'bad'],
                [true, "23\0", null, 17, null, "23\0"],
                [true, '7', 7.9, 17, null, '7'],
                [true, '-3', -3, 17, null, '-3'],
                [true, '23', 23, 17, 'read', null],
                [true, '23', 23, 17, 'parse', '23'],
            ] as [$present, $contents, $parsed, $default, $stage, $raw]) {
                foreach ([false, true] as $errorType) {
                    $cases[] = [$nullable, $present, $contents, $parsed, $default, $stage, $errorType];
                    $events = $nullable ? [['clear', true, '/fixture/limit'], ['stat', '/fixture/limit']] : [];
                    $result = $nullable && $present ? null : $default;
                    if (!$nullable || $present) {
                        $events[] = ['read', '/fixture/limit', null, 256];
                        if ($raw !== null) $events[] = ['parse', $raw, null];
                        if ($parsed !== null && $stage === null) $result = (int) $parsed;
                    }
                    $expected[] = [$stage === null ? $result : null, $stage !== null, $events];
                }
            }
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_SETTING_READ' => json_encode($cases)]));
    }

    public function testValuePolicyRetainsErrorBytesAndBoundaries(): void
    {
        foreach ([
            [null, null, 'missing'], [false, null, 'missing'], [true, null, 'missing value'],
            ['', null, 'empty'], [[], null, 'invalid type'], [-1, null, 'must be >= 0'],
            [1.5, null, 'must be an integer'], [INF, null, 'out of range'], [NAN, null, 'must be an integer'],
            [(float) PHP_INT_MAX, null, 'out of range'], [PHP_INT_MAX.'0', null, 'out of range'],
            ["12\0", null, 'invalid format'], ['+12', null, 'invalid format'],
            [" 0012 GiB\r\n", 12, null], ['0gib', 0, null], [12.0, 12, null], [PHP_INT_MAX, PHP_INT_MAX, null],
        ] as [$raw, $expected, $message]) {
            $error = 'stale';
            $this->assertSame($expected, \pmssIntegerSettingParseNonNegative($raw, 'GiB', $error));
            $this->assertSame($message, $error);
        }
    }
}
