<?php
namespace PMSS\Tests;

// Tests for Devristo\Torrent\File helper class
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/devristo/File.php';

use Devristo\Torrent\File as TorrentFile;

class DevristoFileTest extends TestCase
{
    public function testConstructorThrowsOnInvalidData(): void
    {
        $threw = false;
        try {
            $data = ['name' => 'a']; // missing length and/or path
            new TorrentFile($data);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Constructor should reject invalid data');
    }

    public function testGettersWithPathArray(): void
    {
        $data = ['length' => 123, 'path' => ['dir', 'sub', 'file.bin']];
        $f = new TorrentFile($data);
        $this->assertEquals('file.bin', $f->getName());
        $this->assertEquals('dir/sub/file.bin', $f->getPath());
        $this->assertEquals(['dir', 'sub'], $f->getParentDirectories());
        $this->assertEquals(123, $f->getSize());
    }

    public function testGettersWithNameAndMd5(): void
    {
        $data = ['length' => 9, 'name' => 'readme.txt', 'md5sum' => 'abc'];
        $f = new TorrentFile($data);
        $this->assertEquals('readme.txt', $f->getName());
        $this->assertEquals('readme.txt', $f->getPath());
        $this->assertEquals([], $f->getParentDirectories()); // no 'path' => empty array
        $this->assertEquals('abc', $f->getMd5Sum());
    }

    public function testToStringUsesName(): void
    {
        $data = ['length' => 1, 'name' => 'x'];
        $f = new TorrentFile($data);
        $this->assertEquals('x', (string)$f);
    }
}
