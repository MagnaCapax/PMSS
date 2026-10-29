<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/configBackups.php';

/** Lock the legacy filename-order and independent count/age retention rules. */
class ConfigBackupsRetentionCharacterizationTest extends TestCase
{
    public function testRetentionKeepsItsOrderingAndBoundarySemantics(): void
    {
        [$sourceRoot, $backupRoot] = $this->pmssConfigBackupsFixtureRoots();
        // Pruning does not require the original source to exist.
        $source = $sourceRoot.'/etc/nginx/nginx.conf';
        $key = \pmssConfigBackupsPathKey($source);
        $directory = $backupRoot.'/nginx';
        mkdir($directory, 0700);
        $names = [
            'manual__'.$key.'.bak',
            '20260103120000__'.$key.'.bak',
            '20260102120000__'.$key.'__v=z.bak',
            '20260102120000__'.$key.'__v=a.bak',
            '20260101120000__'.$key.'.bak',
        ];
        $other = '20200101000000__etc_nginx_proxy_params.bak';
        $now = mktime(12, 0, 0, 1, 3, 2026);
        $cases = [
            [0, 0, [0, 1, 2, 3, 4]],
            [-1, -1, [0, 1, 2, 3, 4]],
            [1, 0, [0]],
            [3, 0, [0, 1, 2]],
            [0, 86400, [0, 1, 2, 3]],
            [0, 86399, [0, 1]],
            [3, 86400, [0, 1, 2]],
            [1, 1, [0]],
        ];
        foreach ($cases as [$maxCount, $ttlSeconds, $indices]) {
            foreach (array_merge($names, [$other]) as $name) {
                file_put_contents($directory.'/'.$name, $name);
            }
            \pmssPruneCriticalConfigBackups('nginx', $source, [
                'backupRoot' => $backupRoot, 'maxCount' => $maxCount,
                'ttlSeconds' => $ttlSeconds, 'nowTs' => $now,
            ]);
            $expected = [$other];
            foreach ($indices as $index) $expected[] = $names[$index];
            sort($expected, SORT_STRING);
            $actual = array_map('basename', glob($directory.'/*.bak'));
            $this->assertSame($expected, $actual, 'count='.$maxCount.' ttl='.$ttlSeconds);
            foreach ($actual as $name) {
                $this->assertSame($name, file_get_contents($directory.'/'.$name));
                unlink($directory.'/'.$name);
            }
        }
    }

    public function testLegacyPrefixMatchingAndMalformedDatesRemainUnchanged(): void
    {
        [$sourceRoot, $backupRoot] = $this->pmssConfigBackupsFixtureRoots();
        $source = $sourceRoot.'/etc/nginx/nginx.conf';
        $key = \pmssConfigBackupsPathKey($source);
        $directory = $backupRoot.'/nginx';
        mkdir($directory, 0700);
        // The historical glob matches key suffixes too; malformed dates do not age out.
        $suffix = '20200101000000__'.$key.'_extra.bak';
        $undated = 'not-a-date__'.$key.'.bak';
        foreach ([$suffix, $undated] as $name) file_put_contents($directory.'/'.$name, $name);
        \pmssPruneCriticalConfigBackups('nginx', $source, [
            'backupRoot' => $backupRoot, 'maxCount' => 0,
            'ttlSeconds' => 1, 'nowTs' => mktime(12, 0, 0, 1, 3, 2026),
        ]);
        $this->assertSame([$directory.'/'.$undated], glob($directory.'/*.bak'));
        $this->assertSame($undated, file_get_contents($directory.'/'.$undated));
    }
}
