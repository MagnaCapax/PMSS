<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/welcomeMessage.php';

class WelcomeMessageTest extends TestCase
{
    /** @var string */
    private $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-welcome-message-', 0755);
    }

    private function makeUserHome(): string
    {
        $home = $this->tempDir.'/home/alice';
        @mkdir($home.'/.config', 0755, true);
        return $home;
    }

    public function testUserMessageOverridesProductTemplate(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents(
                $home.'/.config/pmss-user.json',
                json_encode(['product' => 'free'], JSON_UNESCAPED_SLASHES)
            );
            @file_put_contents($home.'/.config/welcome-message.html', '<p>Hello {{username}} / {{quota}}</p>');
            @file_put_contents($this->tempDir.'/welcomeMessages.json', json_encode(['free' => '<p>fallback</p>'], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser(['totalSpace' => 214748364800], $home, 'alice', $this->tempDir.'/welcomeMessages.json');
            $this->assertEquals('<p>Hello alice / 200 GiB</p>', $message);
    }

    public function testProductTemplateRendersWhenNoUserOverrideExists(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode(['product' => 'm1000', 'ramMiB' => 1024], JSON_UNESCAPED_SLASHES));
            @file_put_contents($this->tempDir.'/welcomeMessages.json', json_encode(['m1000' => '<b>{{product}}/{{ramMiB}}</b>'], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/welcomeMessages.json');
            $this->assertEquals('<b>m1000/1024</b>', $message);
    }

    public function testProductNameAliasReadsNestedProductsMap(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode(['productName' => 'M900'], JSON_UNESCAPED_SLASHES));
            @file_put_contents(
                $this->tempDir.'/welcomeMessages.json',
                json_encode(['products' => ['m900' => '<b>{{product}}/{{username}}</b>']], JSON_UNESCAPED_SLASHES)
            );

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/welcomeMessages.json');
            $this->assertEquals('<b>M900/alice</b>', $message);
    }

    public function testProductLookupIsCaseInsensitive(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode(['product' => 'M500'], JSON_UNESCAPED_SLASHES));
            @file_put_contents($this->tempDir.'/welcomeMessages.json', json_encode(['m500' => 'ok {{product}}'], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/welcomeMessages.json');
            $this->assertEquals('ok M500', $message);
    }

    public function testProductFieldWinsOverProductNameWhenBothExist(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents(
                $home.'/.config/pmss-user.json',
                json_encode(['product' => 'm500', 'productName' => 'm900'], JSON_UNESCAPED_SLASHES)
            );
            @file_put_contents(
                $this->tempDir.'/welcomeMessages.json',
                json_encode(['m500' => 'primary {{product}}', 'm900' => 'alias {{product}}'], JSON_UNESCAPED_SLASHES)
            );

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/welcomeMessages.json');
            $this->assertEquals('primary m500', $message);
    }

    public function testProductMessageSetPreservesNestedProductsMapShape(): void
    {
            $messagesPath = $this->tempDir.'/welcomeMessages.json';
            @file_put_contents(
                $messagesPath,
                json_encode(
                    [
                        'meta' => ['updatedBy' => 'test'],
                        'products' => ['free-tier' => '<p>old</p>'],
                    ],
                    JSON_UNESCAPED_SLASHES
                )
            );

            $this->assertTrue(\pmssWelcomeProductMessageSet('m1000', '<p>new</p>', $messagesPath));

            $stored = $this->pmssReadJsonArrayFile($messagesPath, null, 'Expected welcome message store JSON');
            $this->assertEquals('test', $stored['meta']['updatedBy'] ?? '');
            $this->assertEquals('<p>old</p>', $stored['products']['free-tier'] ?? '');
            $this->assertEquals('<p>new</p>', $stored['products']['m1000'] ?? '');
    }

    public function testProductFallsBackToDotProductFile(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.product', "free-tier\n");
            @file_put_contents($home.'/.config/pmss-user.json', json_encode([], JSON_UNESCAPED_SLASHES));
            @file_put_contents($this->tempDir.'/welcomeMessages.json', json_encode(['free-tier' => 'hi'], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/welcomeMessages.json');
            $this->assertEquals('hi', $message);
    }

    public function testSubstitutionsAreEscaped(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode([], JSON_UNESCAPED_SLASHES));
            @file_put_contents($home.'/.config/welcome-message.html', 'user={{username}}');

            $message = \pmssWelcomeMessageForUser([], $home, '<script>alert(1)</script>', $this->tempDir.'/missing.json');
            $this->assertEquals('user=&lt;script&gt;alert(1)&lt;/script&gt;', $message);
    }

    public function testLegacyEmbeddedMessageRemainsReadableForBackCompat(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents(
                $home.'/.config/pmss-user.json',
                json_encode(['welcomeMessage' => '<p>legacy {{username}}</p>'], JSON_UNESCAPED_SLASHES)
            );

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/missing.json');
            $this->assertEquals('<p>legacy alice</p>', $message);
    }

    public function testManagedUserMessageFileReadWriteAndClearRoundTrip(): void
    {
            $home = $this->makeUserHome();

            $this->assertTrue(\pmssWelcomeUserMessageSet('alice', $home, '<p>hello</p>'));
            $this->assertEquals('<p>hello</p>', \pmssWelcomeUserMessageRead($home));

            $path = \pmssWelcomeUserMessagePath($home);
            $this->assertSame($home.'/.config/welcome-message.html', $path);
            $this->assertTrue(is_file($path));

            $this->assertTrue(\pmssWelcomeUserMessageSet('alice', $home, '   '));
            $this->assertEquals('', \pmssWelcomeUserMessageRead($home));
            $this->assertFalse(is_file($path));
    }

    public function testManagedUserMessageSetRejectsTraversalLikeHomePath(): void
    {
            $home = $this->makeUserHome();
            $unsafeHome = $home.'/../escape';

            $this->assertFalse(\pmssWelcomeUserMessageSet('alice', $unsafeHome, '<p>hello</p>'));
            $this->assertFalse(\pmssWelcomeUserMessageSet('alice', $unsafeHome, '   '));
            $this->assertFalse(is_file($this->tempDir.'/home/escape/.config/welcome-message.html'));
    }

    public function testProductMessageLookupRejectsSymlinkedJsonStore(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode(['product' => 'free'], JSON_UNESCAPED_SLASHES));

            $messagesTarget = $this->tempDir.'/welcomeMessages-target.json';
            $messagesLink = $this->tempDir.'/welcomeMessages-link.json';
            @file_put_contents($messagesTarget, json_encode(['free' => '<p>fallback</p>'], JSON_UNESCAPED_SLASHES));
            $this->pmssCreateSymlinkOrSkip($messagesTarget, $messagesLink);

            $this->assertEquals('', \pmssWelcomeMessageForUser([], $home, 'alice', $messagesLink));
    }

    public function testMissingConfigurationReturnsEmptyMessage(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode([], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/missing.json');
            $this->assertEquals('', $message);
    }

    public function testProductMessageSetWritesNestedProductsMap(): void
    {
            $messagesPath = $this->tempDir.'/welcomeMessages.json';
            @file_put_contents($messagesPath, json_encode(['products' => ['m1000' => '<p>legacy</p>']], JSON_UNESCAPED_SLASHES));

            $this->assertTrue(\pmssWelcomeProductMessageSet('free-tier', '<p>hello</p>', $messagesPath));
            $decoded = $this->pmssReadJsonArrayFile($messagesPath, null, 'Message map must decode as array');

            $this->assertEquals('<p>hello</p>', $decoded['products']['free-tier'] ?? null);
            $this->assertEquals('<p>legacy</p>', $decoded['products']['m1000'] ?? null);
    }

    public function testProductMessageSetClearsMessageWhenTemplateIsEmpty(): void
    {
            $messagesPath = $this->tempDir.'/welcomeMessages.json';
            @file_put_contents($messagesPath, json_encode(['free-tier' => '<p>old</p>'], JSON_UNESCAPED_SLASHES));

            $this->assertTrue(\pmssWelcomeProductMessageSet('free-tier', '', $messagesPath));
            $decoded = $this->pmssReadJsonArrayFile($messagesPath, null, 'Message map must decode as array');

            $this->assertTrue(!isset($decoded['free-tier']), 'Entry must be removed when template is empty');
    }

    public function testPlainAndNestedProductMapsRenderIdenticallyAfterRoundTrip(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode(['product' => 'm1000'], JSON_UNESCAPED_SLASHES));

            $plainPath = $this->tempDir.'/welcomeMessages-plain.json';
            $nestedPath = $this->tempDir.'/welcomeMessages-nested.json';
            @file_put_contents($plainPath, json_encode(['free-tier' => 'legacy'], JSON_UNESCAPED_SLASHES));
            @file_put_contents($nestedPath, json_encode(['meta' => ['updatedBy' => 'test'], 'products' => ['free-tier' => 'legacy']], JSON_UNESCAPED_SLASHES));

            $this->assertTrue(\pmssWelcomeProductMessageSet('m1000', '<p>{{product}}/{{username}}</p>', $plainPath));
            $this->assertTrue(\pmssWelcomeProductMessageSet('m1000', '<p>{{product}}/{{username}}</p>', $nestedPath));

            $this->assertEquals(
                '<p>m1000/alice</p>',
                \pmssWelcomeMessageForUser([], $home, 'alice', $plainPath)
            );
            $this->assertEquals(
                '<p>m1000/alice</p>',
                \pmssWelcomeMessageForUser([], $home, 'alice', $nestedPath)
            );

            $plainDecoded = $this->pmssReadJsonArrayFile($plainPath, null, 'Plain product map must remain a plain root map');
            $nestedDecoded = $this->pmssReadJsonArrayFile($nestedPath, null, 'Nested product map must decode as array');

            $this->assertTrue(!isset($plainDecoded['products']), 'Plain product map should not gain a nested products wrapper');
            $this->assertEquals('<p>{{product}}/{{username}}</p>', $plainDecoded['m1000'] ?? null);
            $this->assertEquals('<p>{{product}}/{{username}}</p>', $nestedDecoded['products']['m1000'] ?? null);
    }

    public function testPlainAndNestedProductStoresRenderWithoutRewrite(): void
    {
            $home = $this->makeUserHome();
            @file_put_contents($home.'/.config/pmss-user.json', json_encode(['product' => 'm1000'], JSON_UNESCAPED_SLASHES));

            $plainPath = $this->tempDir.'/welcomeMessages-direct-plain.json';
            $nestedPath = $this->tempDir.'/welcomeMessages-direct-nested.json';
            @file_put_contents($plainPath, json_encode(['m1000' => 'plain {{username}}'], JSON_UNESCAPED_SLASHES));
            @file_put_contents($nestedPath, json_encode(['products' => ['m1000' => 'nested {{username}}']], JSON_UNESCAPED_SLASHES));

            $this->assertEquals('plain alice', \pmssWelcomeMessageForUser([], $home, 'alice', $plainPath));
            $this->assertEquals('nested alice', \pmssWelcomeMessageForUser([], $home, 'alice', $nestedPath));
    }

    public function testCustomerWelcomeMessageRendersPlainAndNestedStores(): void
    {
            $homeRoot = $this->tempDir.'/customer-home';
            $home = $homeRoot.'/alice';
            $script = ''
                .'$lib = '.var_export($this->pmssRepoPath('etc/skel/www/welcomeMessage.php'), true).';'
                .'$home = '.var_export($home, true).';'
                .'$root = '.var_export($homeRoot, true).';'
                .'@mkdir($home."/.config", 0755, true);'
                .'file_put_contents($home."/.config/pmss-user.json", json_encode(["product" => "m1000"]));'
                .'file_put_contents($root."/plain.json", json_encode(["m1000" => "plain {{username}}"]));'
                .'file_put_contents($root."/nested.json", json_encode(["products" => ["m1000" => "nested {{username}}"]]));'
                .'require $lib;'
                .'echo json_encode(["plain" => pmssWelcomeMessageForUser([], $home, "alice", $root."/plain.json"), "nested" => pmssWelcomeMessageForUser([], $home, "alice", $root."/nested.json")]);';

            $this->assertEquals(
                ['plain' => 'plain alice', 'nested' => 'nested alice'],
                $this->pmssRunInlinePhpJson($script, ['PMSS_HOME_DIR' => $homeRoot])
            );
    }

    public function testProductConfigUsesUnifiedWelcomeLibrary(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/productConfig.php', "require_once __DIR__.'/lib/welcomeMessage.php';");
        $this->pmssAssertRepoFileContainsString('scripts/lib/welcomeMessage.php', 'function pmssWelcomeProductMessageSet(');
    }
}
