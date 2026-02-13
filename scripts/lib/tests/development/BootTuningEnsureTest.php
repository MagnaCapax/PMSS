<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class BootTuningEnsureTest extends TestCase
{
    /** @var string|false */
    private $prevConfigDir = false;

    protected function setUp(): void
    {
        $this->prevConfigDir = getenv('PMSS_CONFIG_DIR');
        $repoRoot = dirname(__DIR__, 4);
        putenv('PMSS_CONFIG_DIR='.$repoRoot.'/etc/seedbox/config');
    }

    protected function tearDown(): void
    {
        if ($this->prevConfigDir === false || $this->prevConfigDir === '') {
            putenv('PMSS_CONFIG_DIR');
            return;
        }
        putenv('PMSS_CONFIG_DIR='.$this->prevConfigDir);
    }

    public function testWritesBootTuningScript(): void
    {
        $dir = $this->makeTempDir('script');
        $messages = [];
        [$script, $service] = $this->runBootTuning($dir, $messages);

        $this->assertTrue(file_exists($script), 'expected boot tuning script to be written');
        $content = (string)file_get_contents($script);
        $this->assertStringContainsString('/sys/kernel/mm/lru_gen/enabled', $content);
        $this->assertStringContainsString('/sys/module/zswap/parameters/enabled', $content);
        $this->assertStringContainsString('/md/stripe_cache_size', $content);
        $this->assertTrue(file_exists($service), 'expected boot tuning service to be written');

        $this->cleanup($dir);
    }

    public function testWritesBootTuningService(): void
    {
        $dir = $this->makeTempDir('service');
        $messages = [];
        [$script, $service] = $this->runBootTuning($dir, $messages);

        $this->assertTrue(file_exists($service), 'expected systemd service to be written');
        $content = (string)file_get_contents($service);
        $this->assertStringContainsString('ExecStart='.$script, $content);
        $this->assertStringContainsString('WantedBy=multi-user.target', $content);

        $this->cleanup($dir);
    }

    public function testScriptPermissionsAreExecutable(): void
    {
        $dir = $this->makeTempDir('perms');
        $messages = [];
        [$script, $service] = $this->runBootTuning($dir, $messages);

        $perms = fileperms($script) & 0777;
        $this->assertEquals(0755, $perms, 'expected boot tuning script to be executable');
        $this->assertTrue(file_exists($service), 'expected service to exist for permissions test');

        $this->cleanup($dir);
    }

    public function testCreatesTargetDirectories(): void
    {
        $base = $this->makeTempDir('dirs');
        $script = $base.'/nested/sbin/pmss-boot-tuning.sh';
        $service = $base.'/nested/systemd/pmss-boot-tuning.service';
        $messages = [];

        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };
        \pmssEnsureBootTuning($logger, $script, $service);

        $this->assertTrue(is_dir(dirname($script)), 'expected script directory to be created');
        $this->assertTrue(is_dir(dirname($service)), 'expected service directory to be created');

        $this->cleanup($base);
    }

    public function testSkipsWhenUpToDate(): void
    {
        $dir = $this->makeTempDir('skip');
        $messages = [];
        $this->runBootTuning($dir, $messages);

        $messages = [];
        $this->runBootTuning($dir, $messages);

        $this->assertTrue(
            $this->messagesContain($messages, 'Boot tuning script already present and up to date'),
            'expected boot tuning script skip log'
        );
        $this->assertTrue(
            $this->messagesContain($messages, 'Boot tuning service already present and up to date'),
            'expected boot tuning service skip log'
        );

        $this->cleanup($dir);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function runBootTuning(string $dir, array &$messages): array
    {
        $script = $dir.'/sbin/pmss-boot-tuning.sh';
        $service = $dir.'/systemd/pmss-boot-tuning.service';
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };
        \pmssEnsureBootTuning($logger, $script, $service);
        return [$script, $service];
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/pmss-boot-tuning-'.bin2hex(random_bytes(4)).'-'.$prefix;
        mkdir($dir, 0700, true);
        return $dir;
    }

    private function messagesContain(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
