<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Every log root.cron appends to under /var/log/pmss must be rotated by template.logrotate.pmss. */
class CronLogRotationCoverageTest extends TestCase
{
    public function testEveryRootCronLogTargetHasARotationStanza(): void
    {
        $root = dirname(__DIR__, 4);
        $template = (string) file_get_contents($root.'/etc/seedbox/config/template.logrotate.pmss');
        preg_match_all('#^([^\s{\#][^{]*)\{#m', $template, $heads);
        $patterns = [];
        foreach ($heads[1] as $head) {
            foreach (preg_split('/\s+/', trim($head)) as $pattern) {
                if ($pattern !== '') $patterns[] = $pattern;
            }
        }
        $this->assertTrue($patterns !== [], 'no logrotate stanzas parsed');

        $targets = [];
        foreach ((array) file($root.'/etc/seedbox/config/root.cron', FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match_all('#>>\s*(/var/log/pmss/\S+)#', (string) $line, $found)) {
                foreach ($found[1] as $target) $targets[$target] = true;
            }
        }
        $this->assertTrue(count($targets) > 10, 'too few root.cron log targets parsed: '.count($targets));

        $uncovered = [];
        foreach (array_keys($targets) as $target) {
            $covered = false;
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $target)) { $covered = true; break; }
            }
            if (!$covered) $uncovered[] = $target;
        }
        $this->assertSame([], $uncovered, 'root.cron log targets with no rotation stanza');
    }
}
