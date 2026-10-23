<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/virsilipasKey.php';

class SetVirsilipasKeyTest extends TestCase
{
    private const KEY = 'vsk-abcdefghijklmnop0123';
    private const NEW_KEY = 'vsk-qrstuvwxyzABCDEF4567';

    private function home(): string
    {
        return $this->pmssMakeTempDir('pmss-vkey-', 0700);
    }

    public function testKeyShape(): void
    {
        $this->assertTrue(\pmssVirsilipasKeyIsValid(self::KEY));
        foreach (['', 'vsk-short', 'sk-abcdefghijklmnop0123', self::KEY."\n", self::KEY.' x', self::KEY.';id',
            'vsk-'.str_repeat('a', 129)] as $bad) {
            $this->assertFalse(\pmssVirsilipasKeyIsValid($bad), json_encode($bad));
        }
    }

    public function testCreateWritesOwnerOnlyFileAndRepeatIsEqual(): void
    {
        $home = $this->home();
        $this->assertSame('written', \pmssVirsilipasKeyApply($home, self::KEY, true));
        $path = $home.'/.virsilipasKey';
        $this->assertSame("endpoint=https://virsilipas.mcx.fi/v1\nkey=".self::KEY."\n", file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame('equal', \pmssVirsilipasKeyApply($home, self::KEY, true));
        $this->assertSame([], glob($home.'/.virsilipasKey.*') ?: [], 'no temp file left behind');
    }

    public function testUpdateOnlyNeverCreatesButReplacesOurs(): void
    {
        $home = $this->home();
        $this->assertSame('no-file', \pmssVirsilipasKeyApply($home, self::KEY, false));
        $this->assertFalse(file_exists($home.'/.virsilipasKey'), 'a deleted file stays deleted');
        \pmssVirsilipasKeyApply($home, self::KEY, true);
        $this->assertSame('written', \pmssVirsilipasKeyApply($home, self::NEW_KEY, false));
        $this->assertStringContainsString('key='.self::NEW_KEY."\n", file_get_contents($home.'/.virsilipasKey'));
    }

    public function testCustomerOwnEndpointIsKept(): void
    {
        $home = $this->home();
        $own = "endpoint=https://example.org/v1\nkey=sk-their-own\n";
        file_put_contents($home.'/.virsilipasKey', $own);
        foreach ([true, false] as $create) {
            $this->assertSame('kept-own-endpoint', \pmssVirsilipasKeyApply($home, self::KEY, $create));
        }
        $this->assertSame($own, file_get_contents($home.'/.virsilipasKey'));
    }

    public function testHandMadeFileOfOursIsReplaced(): void
    {
        $home = $this->home();
        file_put_contents($home.'/.virsilipasKey', 'key='.self::KEY."\r\n");
        $this->assertSame('written', \pmssVirsilipasKeyApply($home, self::NEW_KEY, false));
        file_put_contents($home.'/.virsilipasKey', "endpoint=https://virsilipas.mcx.fi/v1/\nkey=".self::KEY."\n");
        $this->assertSame('written', \pmssVirsilipasKeyApply($home, self::NEW_KEY, false));
    }

    public function testSymlinkAndDirectoryAreLeftAlone(): void
    {
        $home = $this->home();
        $target = $home.'/target';
        file_put_contents($target, 'untouched');
        symlink($target, $home.'/.virsilipasKey');
        $this->assertSame('symlink', \pmssVirsilipasKeyApply($home, self::KEY, true));
        $this->assertSame('untouched', file_get_contents($target));
        unlink($home.'/.virsilipasKey');
        mkdir($home.'/.virsilipasKey');
        $this->assertSame('not-regular', \pmssVirsilipasKeyApply($home, self::KEY, true));
    }

    public function testCliNeverPrintsTheKey(): void
    {
        $script = __DIR__.'/../../../util/setVirsilipasKey.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' --jsonl --create nosuchuser9 '
            .escapeshellarg(self::KEY).' 2>&1', $out, $rc);
        $text = implode("\n", $out);
        $this->assertStringNotContainsString(self::KEY, $text);
        $this->assertTrue($rc !== 0, 'non-root or missing user must not report success');
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' --jsonl --create 2>&1', $out2, $rc2);
        $this->assertSame(1, $rc2);
    }
}
