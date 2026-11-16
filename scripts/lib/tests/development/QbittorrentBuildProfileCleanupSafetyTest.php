<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/qbittorrentBuildProbe.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentBuildProbe.php';

/** Inner profile cleanup faults never enumerate or remove real filesystem entries. */
class QbittorrentBuildProfileCleanupSafetyTest extends TestCase
{
    public function testBothProfilesAttemptRootRemovalAfterTraversalAndEntryFaults(): void
    {
        $script = <<<'PHP'
namespace BuildProfileCleanupFixture;
class UnexpectedValueException extends \UnexpectedValueException {}
class FilesystemIterator { const SKIP_DOTS = 4096; }
function observe($stage, $result = true) {
    $GLOBALS['events'][] = $stage;
    if (isset($GLOBALS['faults'][$stage])) throw $GLOBALS['faults'][$stage];
    return $GLOBALS['falseStage'] === $stage ? false : $result;
}
class RecursiveDirectoryIterator {
    public function __construct($path, $flags) {
        $GLOBALS['directory'] = [$path, $flags];
        observe('directory-open');
    }
}
class Entry {
    private $name;
    public function __construct($name) { $this->name = $name; }
    public function getPathname() { return observe('path:'.$this->name, '/fixture/profile/'.$this->name); }
}
class RecursiveIteratorIterator implements \IteratorAggregate {
    const CHILD_FIRST = 2;
    public function __construct($directory, $flags) {
        $GLOBALS['order'] = $flags;
        observe('iterator-open');
    }
    public function getIterator(): \Traversable {
        observe('walk');
        foreach ($GLOBALS['entries'] as $name) yield new Entry($name);
        observe('walk-end');
    }
}
function is_link($path) { return observe('link:'.basename($path), basename($path) === 'link'); }
function is_dir($path) { return observe('type:'.basename($path), basename($path) === 'directory'); }
function unlink($path) { $GLOBALS['removed'][] = $path; return observe('unlink:'.basename($path)); }
function rmdir($path) { $GLOBALS['removed'][] = $path; return observe('rmdir:'.basename($path)); }
PHP;
        $cleaners = ['pmssQbittorrentBuildProbeClean', 'pmssQbittorrentFrontendBuildProbeClean'];
        foreach ($cleaners as $cleaner) {
            $script .= \pmssTestingFunctionExport($cleaner, 'BuildProfileCleanupFixture');
        }
        $script .= <<<'PHP'
$rows = [];
$normal = ['directory-open', 'iterator-open', 'walk', 'path:link', 'link:link', 'unlink:link',
    'path:file', 'link:file', 'type:file', 'unlink:file', 'path:directory', 'link:directory',
    'type:directory', 'rmdir:directory', 'walk-end', 'rmdir:profile'];
foreach (['pmssQbittorrentBuildProbeClean', 'pmssQbittorrentFrontendBuildProbeClean'] as $cleaner) {
    $cases = [['success', null, null], ['empty', null, null]];
    foreach (['unlink:link', 'unlink:file', 'rmdir:directory', 'rmdir:profile'] as $stage) {
        $cases[] = ['false', $stage, null];
    }
    foreach ($normal as $stage) {
        foreach ([\RuntimeException::class, \Error::class, UnexpectedValueException::class] as $kind) {
            $cases[] = ['throw', $stage, $kind];
            if ($stage !== 'rmdir:profile') $cases[] = ['combined', $stage, $kind];
        }
    }
    foreach ($cases as [$mode, $stage, $kind]) {
        $GLOBALS['events'] = $GLOBALS['removed'] = $GLOBALS['faults'] = [];
        $GLOBALS['directory'] = $GLOBALS['order'] = null;
        $GLOBALS['entries'] = $mode === 'empty' ? [] : ['link', 'file', 'directory'];
        $GLOBALS['falseStage'] = $mode === 'false' ? $stage : null;
        $expectedFault = null;
        if ($kind !== null) {
            $failure = $GLOBALS['faults'][$stage] = new $kind('fixture fault');
            // The existing unreadable-directory exception is swallowed only in the traversal body.
            if ($kind !== UnexpectedValueException::class || $stage === 'rmdir:profile') $expectedFault = $failure;
        }
        if ($mode === 'combined') {
            $expectedFault = $GLOBALS['faults']['rmdir:profile'] = new \Error('root cleanup fault');
        }
        $caught = $result = null;
        try { $result = (__NAMESPACE__.'\\'.$cleaner)('/fixture/profile'); }
        catch (\Throwable $error) { $caught = $error; }
        $rows[] = [$cleaner, $mode, $stage, $caught === $expectedFault, $result,
            $GLOBALS['events'], $GLOBALS['removed'], $GLOBALS['directory'], $GLOBALS['order']];
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(198, count($rows));
        $normal = ['directory-open', 'iterator-open', 'walk', 'path:link', 'link:link', 'unlink:link',
            'path:file', 'link:file', 'type:file', 'unlink:file', 'path:directory', 'link:directory',
            'type:directory', 'rmdir:directory', 'walk-end', 'rmdir:profile'];
        foreach ($rows as [$cleaner, $mode, $stage, $sameFault, $result, $events, $removed, $directory, $order]) {
            $expected = $normal;
            if ($mode === 'empty') $expected = ['directory-open', 'iterator-open', 'walk', 'walk-end', 'rmdir:profile'];
            if (in_array($mode, ['throw', 'combined'], true)) {
                $expected = array_slice($normal, 0, array_search($stage, $normal, true) + 1);
                if ($stage !== 'rmdir:profile') $expected[] = 'rmdir:profile';
            }
            $expectedRemoved = [];
            foreach ($expected as $event) {
                if (strpos($event, 'unlink:') === 0 || strpos($event, 'rmdir:') === 0) {
                    $name = substr($event, strpos($event, ':') + 1);
                    $expectedRemoved[] = '/fixture/profile'.($name === 'profile' ? '' : '/'.$name);
                }
            }
            $label = $cleaner.':'.$mode.':'.$stage;
            $this->assertSame([true, null, $expected, $expectedRemoved], [$sameFault, $result, $events, $removed], $label);
            $this->assertSame(['/fixture/profile', \FilesystemIterator::SKIP_DOTS], $directory, $label);
            $this->assertSame($stage === 'directory-open' ? null : \RecursiveIteratorIterator::CHILD_FIRST, $order, $label);
        }
    }
}
