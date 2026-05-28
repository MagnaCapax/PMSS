<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/welcomeMessage.php';

class WelcomeMessageTest extends TestCase
{
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

    /** Write the per-user PMSS config fixture for welcome-message resolution tests. */
    private function writeUserConfig(string $home, array $config): void
    {
        @file_put_contents($home.'/.config/pmss-user.json', json_encode($config, JSON_UNESCAPED_SLASHES));
    }

    /** Write a product-message fixture and return its path. */
    private function writeWelcomeMessages(array $messages, string $filename = 'welcomeMessages.json'): string
    {
        $path = $this->tempDir.'/'.$filename;
        @file_put_contents($path, json_encode($messages, JSON_UNESCAPED_SLASHES));
        return $path;
    }

    /** Render the standard alice welcome fixture against the default message store. */
    private function renderWelcome(array $quotaInfo, string $home, string $messagesPath = ''): string
    {
        return \pmssWelcomeMessageForUser(
            $quotaInfo,
            $home,
            'alice',
            $messagesPath !== '' ? $messagesPath : $this->tempDir.'/welcomeMessages.json'
        );
    }

    public function testUserMessageOverridesProductTemplate(): void
    {
            $home = $this->makeUserHome();
            $this->writeUserConfig($home, ['product' => 'free']);
            @file_put_contents($home.'/.config/welcome-message.html', '<p>Hello {{username}} / {{quota}}</p>');
            $this->writeWelcomeMessages(['free' => '<p>fallback</p>']);

            $message = $this->renderWelcome(['totalSpace' => 214748364800], $home);
            $this->assertEquals('<p>Hello alice / 200 GiB</p>', $message);
    }

    public function testProductTemplateRendersWhenNoUserOverrideExists(): void
    {
            $home = $this->makeUserHome();
            $this->writeUserConfig($home, ['product' => 'm1000', 'ramMiB' => 1024]);
            $this->writeWelcomeMessages(['m1000' => '<b>{{product}}/{{ramMiB}}</b>']);

            $message = $this->renderWelcome([], $home);
            $this->assertEquals('<b>m1000/1024</b>', $message);
    }

    public function testProductNameAliasReadsNestedProductsMap(): void
    {
            $home = $this->makeUserHome();
            $this->writeUserConfig($home, ['productName' => 'M900']);
            $this->writeWelcomeMessages(['products' => ['m900' => '<b>{{product}}/{{username}}</b>']]);

            $message = $this->renderWelcome([], $home);
            $this->assertEquals('<b>M900/alice</b>', $message);
    }

    public function testProductLookupIsCaseInsensitive(): void
    {
            $home = $this->makeUserHome();
            $this->writeUserConfig($home, ['product' => 'M500']);
            $this->writeWelcomeMessages(['m500' => 'ok {{product}}']);

            $message = $this->renderWelcome([], $home);
            $this->assertEquals('ok M500', $message);
    }

    public function testProductFieldWinsOverProductNameWhenBothExist(): void
    {
            $home = $this->makeUserHome();
            $this->writeUserConfig($home, ['product' => 'm500', 'productName' => 'm900']);
            $this->writeWelcomeMessages(['m500' => 'primary {{product}}', 'm900' => 'alias {{product}}']);

            $message = $this->renderWelcome([], $home);
            $this->assertEquals('primary m500', $message);
    }

    public function testProductMessageSetPreservesNestedProductsMapShape(): void
    {
            $messagesPath = $this->writeWelcomeMessages([
                'meta' => ['updatedBy' => 'test'],
                'products' => ['free-tier' => '<p>old</p>'],
            ]);

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
            $this->writeUserConfig($home, []);
            $this->writeWelcomeMessages(['free-tier' => 'hi']);

            $message = $this->renderWelcome([], $home);
            $this->assertEquals('hi', $message);
    }

    public function testSubstitutionsAreEscaped(): void
    {
            $home = $this->makeUserHome();
            $this->writeUserConfig($home, []);
            @file_put_contents($home.'/.config/welcome-message.html', 'user={{username}}');

            $message = \pmssWelcomeMessageForUser([], $home, '<script>alert(1)</script>', $this->tempDir.'/missing.json');
            $this->assertEquals('user=&lt;script&gt;alert(1)&lt;/script&gt;', $message);
    }

    public function testLegacyEmbeddedMessageRemainsReadableForBackCompat(): void
    {
            $home = $this->makeUserHome();
            $this->writeUserConfig($home, ['welcomeMessage' => '<p>legacy {{username}}</p>']);

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
            $this->writeUserConfig($home, ['product' => 'free']);

            $messagesTarget = $this->tempDir.'/welcomeMessages-target.json';
            $messagesLink = $this->tempDir.'/welcomeMessages-link.json';
            @file_put_contents($messagesTarget, json_encode(['free' => '<p>fallback</p>'], JSON_UNESCAPED_SLASHES));
            $this->pmssCreateSymlinkOrSkip($messagesTarget, $messagesLink);

            $this->assertEquals('', \pmssWelcomeMessageForUser([], $home, 'alice', $messagesLink));
    }

    public function testMissingConfigurationReturnsEmptyMessage(): void
    {
            $home = $this->makeUserHome();
            $this->writeUserConfig($home, []);

            $message = \pmssWelcomeMessageForUser([], $home, 'alice', $this->tempDir.'/missing.json');
            $this->assertEquals('', $message);
    }

    public function testProductMessageSetWritesNestedProductsMap(): void
    {
            $messagesPath = $this->writeWelcomeMessages(['products' => ['m1000' => '<p>legacy</p>']]);

            $this->assertTrue(\pmssWelcomeProductMessageSet('free-tier', '<p>hello</p>', $messagesPath));
            $decoded = $this->pmssReadJsonArrayFile($messagesPath, null, 'Message map must decode as array');

            $this->assertEquals('<p>hello</p>', $decoded['products']['free-tier'] ?? null);
            $this->assertEquals('<p>legacy</p>', $decoded['products']['m1000'] ?? null);
    }

    public function testProductMessageSetClearsMessageWhenTemplateIsEmpty(): void
    {
            $messagesPath = $this->writeWelcomeMessages(['free-tier' => '<p>old</p>']);

            $this->assertTrue(\pmssWelcomeProductMessageSet('free-tier', '', $messagesPath));
            $decoded = $this->pmssReadJsonArrayFile($messagesPath, null, 'Message map must decode as array');

            $this->assertTrue(!isset($decoded['free-tier']), 'Entry must be removed when template is empty');
    }

    public function testPlainAndNestedProductMapsRenderIdenticallyAfterRoundTrip(): void
    {
            $home = $this->makeUserHome();
            $this->writeUserConfig($home, ['product' => 'm1000']);

            $plainPath = $this->writeWelcomeMessages(['free-tier' => 'legacy'], 'welcomeMessages-plain.json');
            $nestedPath = $this->writeWelcomeMessages(['meta' => ['updatedBy' => 'test'], 'products' => ['free-tier' => 'legacy']], 'welcomeMessages-nested.json');

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
            $this->writeUserConfig($home, ['product' => 'm1000']);

            $plainPath = $this->writeWelcomeMessages(['m1000' => 'plain {{username}}'], 'welcomeMessages-direct-plain.json');
            $nestedPath = $this->writeWelcomeMessages(['products' => ['m1000' => 'nested {{username}}']], 'welcomeMessages-direct-nested.json');

            $this->assertEquals('plain alice', \pmssWelcomeMessageForUser([], $home, 'alice', $plainPath));
            $this->assertEquals('nested alice', \pmssWelcomeMessageForUser([], $home, 'alice', $nestedPath));
    }

    public function testCustomerWelcomeMessageRendersPlainAndNestedStores(): void
    {
            $homeRoot = $this->tempDir.'/customer-home';
            $home = $homeRoot.'/alice';
            $overrideHome = $homeRoot.'/bob';
            $script = ''
                .'$lib = '.var_export($this->pmssRepoPath('etc/skel/www/welcomeMessage.php'), true).';'
                .'$home = '.var_export($home, true).';'
                .'$overrideHome = '.var_export($overrideHome, true).';'
                .'$root = '.var_export($homeRoot, true).';'
                .'@mkdir($home."/.config", 0755, true);'
                .'@mkdir($overrideHome."/.config", 0755, true);'
                .'file_put_contents($home."/.config/pmss-user.json", json_encode(["product" => "m1000"]));'
                .'file_put_contents($overrideHome."/.config/pmss-user.json", json_encode(["product" => "m1000"]));'
                .'file_put_contents($overrideHome."/.config/welcome-message.html", "custom {{username}} / {{quota}}");'
                .'file_put_contents($root."/plain.json", json_encode(["m1000" => "plain {{username}}"]));'
                .'file_put_contents($root."/nested.json", json_encode(["products" => ["m1000" => "nested {{username}}"]]));'
                .'require $lib;'
                .'echo json_encode(['
                .'"plain" => pmssWelcomeMessageForUser([], $home, "alice", $root."/plain.json"),'
                .'"nested" => pmssWelcomeMessageForUser([], $home, "alice", $root."/nested.json"),'
                .'"override" => pmssWelcomeMessageForUser(["totalSpace" => 1073741824], $overrideHome, "bob", $root."/missing.json"),'
                .'"productSet" => function_exists("pmssWelcomeProductMessageSet"),'
                .'"userSet" => function_exists("pmssWelcomeUserMessageSet")'
                .']);';

            $this->assertEquals(
                [
                    'plain' => 'plain alice',
                    'nested' => 'nested alice',
                    'override' => 'custom bob / 1 GiB',
                    'productSet' => false,
                    'userSet' => false,
                ],
                $this->pmssRunInlinePhpJson($script, ['PMSS_HOME_DIR' => $homeRoot])
            );
    }

    public function testCustomerWelcomeMessageHelperStaysReadOnly(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcomeMessage.php');

        $this->pmssAssertRepoFileNotContainsStrings('etc/skel/www/welcomeMessage.php', [
            'function pmssWelcomeProductMessageSet(',
            'function pmssWelcomeUserMessageSet(',
            'pmssReplaceUserFile',
            'pmssPathTargetIsSafe',
            'pmssEnsureSafeDir',
            'pmssUserFilePathIsSafe',
        ]);
        $this->assertStringContainsString('pmssWelcomeMessageCustomerPathIsSafe($path)', $source);
    }

    public function testProductConfigUsesUnifiedWelcomeLibrary(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/productConfig.php', "require_once __DIR__.'/lib/welcomeMessage.php';");
        $this->pmssAssertRepoFileContainsString('scripts/lib/welcomeMessage.php', 'function pmssWelcomeProductMessageSet(');
    }
}
