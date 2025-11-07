<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cli/OptionParser.php';

class CliHelperTest extends TestCase
{
    public function testParsesLongOptionsWithValues(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '--user=alice', '--limit=42', 'extra']);
        $this->assertEquals('alice', $parsed['options']['user'] ?? '');
        $this->assertEquals('42', $parsed['options']['limit'] ?? '');
        $this->assertEquals(['extra'], $parsed['arguments']);
    }

    public function testParsesCollapsedShortFlags(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '-hv']);
        $this->assertTrue(isset($parsed['options']['h']));
        $this->assertTrue(isset($parsed['options']['v']));
    }

    public function testCliOptionHelperPrefersLongThenShort(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '--user=alice', '-l', '50']);
        $value = \pmssCliOption($parsed, 'limit', 'l');
        $this->assertEquals('50', $value);
        $fallback = \pmssCliOption($parsed, 'user', 'u');
        $this->assertEquals('alice', $fallback);
        $defaulted = \pmssCliOption($parsed, 'missing', 'm', 'fallback');
        $this->assertEquals('fallback', $defaulted);
    }

    public function testSupportsSpaceSeparatedLongValues(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '--limit', '64']);
        $this->assertEquals('64', \pmssCliOption($parsed, 'limit', 'l'));
    }
}
