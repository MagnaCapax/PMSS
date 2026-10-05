<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class LocaleBaselineWriteTest extends TestCase
{
    public function testCompleteLocaleWritePreservesContent(): void
    {
        $path = $this->pmssMakeTempDir('pmss-locale-write-').'/locale.gen';
        $content = "en_US.UTF-8 UTF-8\n";

        $this->assertTrue(\pmssLocaleGenWriteComplete($path, $content));
        $this->assertSame($content, $this->pmssReadFileOrEmpty($path));
    }

    public function testIncompleteLocaleWritesAreRejected(): void
    {
        $content = "en_US.UTF-8 UTF-8\n";
        foreach ([false, 0, strlen($content) - 1] as $result) {
            $this->assertFalse(\pmssLocaleGenWriteComplete('/unused/locale.gen', $content, static function () use ($result) {
                return $result;
            }));
        }
    }
}
