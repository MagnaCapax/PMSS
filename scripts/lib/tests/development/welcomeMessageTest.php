<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/welcomeMessage.php';

class WelcomeMessageTest extends TestCase
{
    /** @var string */
    private $tempDir = '';

    private function setUpTempDir(): void
    {
        $base = sys_get_temp_dir().'/pmss-welcome-message-tests';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $this->tempDir = $base.'/run-'.bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);
    }

    private function tearDownTempDir(): void
    {
        if ($this->tempDir === '' || !is_dir($this->tempDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($this->tempDir);
        $this->tempDir = '';
    }

    private function makeUserHome(): string
    {
        $home = $this->tempDir.'/home/alice';
        @mkdir($home.'/.config', 0755, true);
        return $home;
    }

    public function testUserMessageOverridesProductTemplate(): void
    {
        $this->setUpTempDir();
        try {
            $home = $this->makeUserHome();
            @file_put_contents(
                $home.'/.config/pmss-user.json',
                json_encode(['welcomeMessage' => '<p>Hello {{username}} / {{quota}}</p>', 'product' => 'free'], JSON_UNESCAPED_SLASHES)
            );
            @file_put_contents($this->tempDir.'/welcomeMessages.json', json_encode(['free' => '<p>fallback</p>'], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser(['totalSpace' => 214748364800], $home, 'alice', $this->tempDir.'/welcomeMessages.json');
            $this->assertEquals('<p>Hello alice / 200 GiB</p>', $message);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testProductTemplateRendersWhenNoUserOverrideExists(): void
    {
        $this->setUpTempDir();
        try {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode(['product' => 'm1000', 'ramMiB' => 1024], JSON_UNESCAPED_SLASHES));
            @file_put_contents($this->tempDir.'/welcomeMessages.json', json_encode(['m1000' => '<b>{{product}}/{{ramMiB}}</b>'], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/welcomeMessages.json');
            $this->assertEquals('<b>m1000/1024</b>', $message);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testProductLookupIsCaseInsensitive(): void
    {
        $this->setUpTempDir();
        try {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode(['product' => 'M500'], JSON_UNESCAPED_SLASHES));
            @file_put_contents($this->tempDir.'/welcomeMessages.json', json_encode(['m500' => 'ok {{product}}'], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/welcomeMessages.json');
            $this->assertEquals('ok M500', $message);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testProductFallsBackToDotProductFile(): void
    {
        $this->setUpTempDir();
        try {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.product', "free-tier\n");
            @file_put_contents($home.'/.config/pmss-user.json', json_encode([], JSON_UNESCAPED_SLASHES));
            @file_put_contents($this->tempDir.'/welcomeMessages.json', json_encode(['free-tier' => 'hi'], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/welcomeMessages.json');
            $this->assertEquals('hi', $message);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testSubstitutionsAreEscaped(): void
    {
        $this->setUpTempDir();
        try {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode(['welcomeMessage' => 'user={{username}}'], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser([], $home, '<script>alert(1)</script>', $this->tempDir.'/missing.json');
            $this->assertEquals('user=&lt;script&gt;alert(1)&lt;/script&gt;', $message);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testMissingConfigurationReturnsEmptyMessage(): void
    {
        $this->setUpTempDir();
        try {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode([], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/missing.json');
            $this->assertEquals('', $message);
        } finally {
            $this->tearDownTempDir();
        }
    }
}
