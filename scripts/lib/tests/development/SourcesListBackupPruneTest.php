<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/environment.php';

/**
 * #1216: the MediaArea prune no longer fights the apt templates, and the copies it
 * used to leave behind are pruned down to the newest one.
 */
class SourcesListBackupPruneTest extends TestCase
{
    public function testKeepsOnlyTheNewestTimestampedCopy(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-apt-backups-');
        foreach (['20260208071027', '20261027201326', '20261028201328'] as $stamp) {
            file_put_contents($dir.'/sources.list.pmss-backup-'.$stamp, "deb x\n");
        }
        file_put_contents($dir.'/sources.list.pmss-backup', "safe-write copy\n");
        file_put_contents($dir.'/sources.list.pmss-backup-keepme', "other\n");
        $target = $dir.'/outside-target';
        file_put_contents($target, "intact\n");
        symlink($target, $dir.'/sources.list.pmss-backup-20200101000000');

        $this->assertSame(2, \pmssPruneSourcesListBackups($dir));

        $this->assertTrue(is_file($dir.'/sources.list.pmss-backup-20261028201328'), 'newest copy kept');
        $this->assertTrue(!file_exists($dir.'/sources.list.pmss-backup-20260208071027'), 'oldest copy removed');
        $this->assertTrue(!file_exists($dir.'/sources.list.pmss-backup-20261027201326'), 'middle copy removed');
        $this->assertSame("safe-write copy\n", file_get_contents($dir.'/sources.list.pmss-backup'));
        $this->assertTrue(is_file($dir.'/sources.list.pmss-backup-keepme'), 'non-timestamp name left alone');
        $this->assertTrue(is_link($dir.'/sources.list.pmss-backup-20200101000000'), 'symlink left alone');
        $this->assertSame("intact\n", file_get_contents($target));
    }

    public function testNoCopiesIsANoOp(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-apt-backups-');
        $this->assertSame(0, \pmssPruneSourcesListBackups($dir));
        file_put_contents($dir.'/sources.list.pmss-backup-20261028201328', "deb x\n");
        $this->assertSame(0, \pmssPruneSourcesListBackups($dir));
        $this->assertTrue(is_file($dir.'/sources.list.pmss-backup-20261028201328'), 'a single copy is the newest and stays');
    }

    public function testMediaAreaLineIsLeftToTheTemplates(): void
    {
        $root = dirname(__DIR__, 4);
        $env = (string) file_get_contents($root.'/scripts/lib/update/environment.php');
        $this->pmssAssertStringNotContainsString('PMSS-disabled mediaarea', $env, 'the prune must not comment out the template line');
        // Buster's MediaArea repository answers 404; the supported suites keep theirs.
        $this->pmssAssertStringNotContainsString('mediaarea', (string) file_get_contents($root.'/etc/seedbox/config/template.sources.buster'));
        foreach (['bullseye', 'bookworm', 'trixie'] as $suite) {
            $this->assertTrue(
                strpos((string) file_get_contents($root.'/etc/seedbox/config/template.sources.'.$suite), 'mediaarea.net/repo/deb/debian '.$suite.' main') !== false,
                $suite.' template keeps its MediaArea line'
            );
        }
    }
}
