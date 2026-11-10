<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/welcomeMessage.php';

class WelcomeMessageTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-welcome-message-', 0755]; }

    /** Render the standard fixtur01 welcome fixture against the default message store. */
    private function renderWelcome(array $quotaInfo, string $home, string $messagesPath = ''): string
    {
        return \pmssWelcomeMessageForUser(
            $quotaInfo,
            $home,
            'fixtur01',
            $messagesPath !== '' ? $messagesPath : $this->tempDir.'/welcomeMessages.json'
        );
    }

    public function testUserMessageOverridesProductTemplate(): void
    {
            $home = dirname($this->pmssEnsureDir($this->tempDir.'/home/fixtur01/.config'));
            $this->pmssWriteRelativeFile($home, '.config/pmss-user.json', json_encode(['product' => 'free'], JSON_UNESCAPED_SLASHES));
            $this->pmssWriteFile($home.'/.config/welcome-message.html', '<p>Hello {{username}} / {{quota}}</p>');
            $this->pmssWriteRelativeFile($this->tempDir, 'welcomeMessages.json', json_encode(['free' => '<p>fallback</p>'], JSON_UNESCAPED_SLASHES));

            $message = $this->renderWelcome(['totalSpace' => 214748364800], $home);
            $this->assertEquals('<p>Hello fixtur01 / 200 GiB</p>', $message);
    }

    public function testProductLookupCasesRenderExpectedMessages(): void
    {
        foreach ([
            'direct product template' => [
                ['product' => 'm1000', 'ramMiB' => 1024],
                ['m1000' => '<b>{{product}}/{{ramMiB}}</b>'],
                '<b>m1000/1024</b>',
            ],
            'productName alias in nested map' => [
                ['productName' => 'M900'],
                ['products' => ['m900' => '<b>{{product}}/{{username}}</b>']],
                '<b>M900/fixtur01</b>',
            ],
            'case-insensitive product key' => [
                ['product' => 'M500'],
                ['m500' => 'ok {{product}}'],
                'ok M500',
            ],
            'product field wins over alias' => [
                ['product' => 'm500', 'productName' => 'm900'],
                ['m500' => 'primary {{product}}', 'm900' => 'alias {{product}}'],
                'primary m500',
            ],
            'dot product fallback' => [
                [],
                ['free-tier' => 'hi'],
                'hi',
                'free-tier',
            ],
            'missing configuration' => [
                [],
                null,
                '',
            ],
        ] as $label => $case) {
            $home = dirname($this->pmssEnsureDir($this->tempDir.'/home/fixtur01/.config'));
            $this->pmssWriteRelativeFile($home, '.config/pmss-user.json', json_encode($case[0], JSON_UNESCAPED_SLASHES));
            if (isset($case[3])) {
                $this->pmssWriteFile($home.'/.product', $case[3]."\n");
            }
            if (is_array($case[1])) {
                $this->pmssWriteRelativeFile($this->tempDir, 'welcomeMessages.json', json_encode($case[1], JSON_UNESCAPED_SLASHES));
            }

            $messagePath = is_array($case[1]) ? '' : $this->tempDir.'/missing.json';
            $this->assertEquals($case[2], $this->renderWelcome([], $home, $messagePath), $label);
        }
    }

    public function testProductMessageSetPreservesNestedProductsMapShape(): void
    {
            $messagesPath = $this->pmssWriteRelativeFile($this->tempDir, 'welcomeMessages.json', json_encode([
                'meta' => ['updatedBy' => 'test'],
                'products' => ['free-tier' => '<p>old</p>'],
            ], JSON_UNESCAPED_SLASHES));

            $this->assertTrue(\pmssWelcomeProductMessageSet('m1000', '<p>new</p>', $messagesPath));

            $stored = $this->pmssReadJsonArrayFile($messagesPath, null, 'Expected welcome message store JSON');
            $this->assertEquals('test', $stored['meta']['updatedBy'] ?? '');
            $this->assertEquals('<p>old</p>', $stored['products']['free-tier'] ?? '');
            $this->assertEquals('<p>new</p>', $stored['products']['m1000'] ?? '');
    }

    public function testSubstitutionsAreEscaped(): void
    {
            $home = dirname($this->pmssEnsureDir($this->tempDir.'/home/fixtur01/.config'));
            $this->pmssWriteRelativeFile($home, '.config/pmss-user.json', json_encode([], JSON_UNESCAPED_SLASHES));
            $this->pmssWriteFile($home.'/.config/welcome-message.html', 'user={{username}}');

            $message = \pmssWelcomeMessageForUser([], $home, '<script>alert(1)</script>', $this->tempDir.'/missing.json');
            $this->assertEquals('user=&lt;script&gt;alert(1)&lt;/script&gt;', $message);
    }

    public function testLegacyEmbeddedMessageRemainsReadableForBackCompat(): void
    {
            $home = dirname($this->pmssEnsureDir($this->tempDir.'/home/fixtur01/.config'));
            $this->pmssWriteRelativeFile($home, '.config/pmss-user.json',
                json_encode(['welcomeMessage' => '<p>legacy {{username}}</p>'], JSON_UNESCAPED_SLASHES));

            $message = \pmssWelcomeMessageForUser([], $home, 'fixtur01', $this->tempDir.'/missing.json');
            $this->assertEquals('<p>legacy fixtur01</p>', $message);
    }

    public function testManagedUserMessageFileReadWriteAndClearRoundTrip(): void
    {
            $home = dirname($this->pmssEnsureDir($this->tempDir.'/home/fixtur01/.config'));

            $this->assertTrue(\pmssWelcomeUserMessageSet('fixtur01', $home, '<p>hello</p>'));
            $this->assertEquals('<p>hello</p>', \pmssWelcomeUserMessageRead($home));

            $path = \pmssWelcomeUserMessagePath($home);
            $this->assertSame($home.'/.config/welcome-message.html', $path);
            $this->assertTrue(is_file($path));

            $this->assertTrue(\pmssWelcomeUserMessageSet('fixtur01', $home, '   '));
            $this->assertEquals('', \pmssWelcomeUserMessageRead($home));
            $this->assertFalse(is_file($path));
    }

    public function testManagedUserMessageSetRejectsTraversalLikeHomePath(): void
    {
            $home = dirname($this->pmssEnsureDir($this->tempDir.'/home/fixtur01/.config'));
            $unsafeHome = $home.'/../escape';

            $this->assertFalse(\pmssWelcomeUserMessageSet('fixtur01', $unsafeHome, '<p>hello</p>'));
            $this->assertFalse(\pmssWelcomeUserMessageSet('fixtur01', $unsafeHome, '   '));
            $this->assertFalse(is_file($this->tempDir.'/home/escape/.config/welcome-message.html'));
    }

    public function testProductMessageLookupRejectsSymlinkedJsonStore(): void
    {
            $home = dirname($this->pmssEnsureDir($this->tempDir.'/home/fixtur01/.config'));
            $this->pmssWriteRelativeFile($home, '.config/pmss-user.json', json_encode(['product' => 'free'], JSON_UNESCAPED_SLASHES));

            $messagesTarget = $this->tempDir.'/welcomeMessages-target.json';
            $messagesLink = $this->tempDir.'/welcomeMessages-link.json';
            $this->pmssWriteFile($messagesTarget, json_encode(['free' => '<p>fallback</p>'], JSON_UNESCAPED_SLASHES));
            $this->pmssCreateSymlinkOrSkip($messagesTarget, $messagesLink);

            $this->assertEquals('', \pmssWelcomeMessageForUser([], $home, 'fixtur01', $messagesLink));
    }

    public function testProductMessageSetWritesNestedProductsMap(): void
    {
            $messagesPath = $this->pmssWriteRelativeFile($this->tempDir, 'welcomeMessages.json',
                json_encode(['products' => ['m1000' => '<p>legacy</p>']], JSON_UNESCAPED_SLASHES));

            $this->assertTrue(\pmssWelcomeProductMessageSet('free-tier', '<p>hello</p>', $messagesPath));
            $decoded = $this->pmssReadJsonArrayFile($messagesPath, null, 'Message map must decode as array');

            $this->assertEquals('<p>hello</p>', $decoded['products']['free-tier'] ?? null);
            $this->assertEquals('<p>legacy</p>', $decoded['products']['m1000'] ?? null);
    }

    public function testProductMessageSetClearsMessageWhenTemplateIsEmpty(): void
    {
            $messagesPath = $this->pmssWriteRelativeFile($this->tempDir, 'welcomeMessages.json', json_encode(['free-tier' => '<p>old</p>'], JSON_UNESCAPED_SLASHES));

            $this->assertTrue(\pmssWelcomeProductMessageSet('free-tier', '', $messagesPath));
            $decoded = $this->pmssReadJsonArrayFile($messagesPath, null, 'Message map must decode as array');

            $this->assertTrue(!isset($decoded['free-tier']), 'Entry must be removed when template is empty');
    }

    public function testPlainAndNestedProductMapsRenderIdenticallyAfterRoundTrip(): void
    {
            $home = dirname($this->pmssEnsureDir($this->tempDir.'/home/fixtur01/.config'));
            $this->pmssWriteRelativeFile($home, '.config/pmss-user.json', json_encode(['product' => 'm1000'], JSON_UNESCAPED_SLASHES));

            $plainPath = $this->pmssWriteRelativeFile($this->tempDir, 'welcomeMessages-plain.json',
                json_encode(['free-tier' => 'legacy'], JSON_UNESCAPED_SLASHES));
            $nestedPath = $this->pmssWriteRelativeFile($this->tempDir, 'welcomeMessages-nested.json',
                json_encode(['meta' => ['updatedBy' => 'test'], 'products' => ['free-tier' => 'legacy']], JSON_UNESCAPED_SLASHES));

            $this->assertTrue(\pmssWelcomeProductMessageSet('m1000', '<p>{{product}}/{{username}}</p>', $plainPath));
            $this->assertTrue(\pmssWelcomeProductMessageSet('m1000', '<p>{{product}}/{{username}}</p>', $nestedPath));

            $this->assertEquals(
                '<p>m1000/fixtur01</p>',
                \pmssWelcomeMessageForUser([], $home, 'fixtur01', $plainPath)
            );
            $this->assertEquals(
                '<p>m1000/fixtur01</p>',
                \pmssWelcomeMessageForUser([], $home, 'fixtur01', $nestedPath)
            );

            $plainDecoded = $this->pmssReadJsonArrayFile($plainPath, null, 'Plain product map must remain a plain root map');
            $nestedDecoded = $this->pmssReadJsonArrayFile($nestedPath, null, 'Nested product map must decode as array');

            $this->assertTrue(!isset($plainDecoded['products']), 'Plain product map should not gain a nested products wrapper');
            $this->assertEquals('<p>{{product}}/{{username}}</p>', $plainDecoded['m1000'] ?? null);
            $this->assertEquals('<p>{{product}}/{{username}}</p>', $nestedDecoded['products']['m1000'] ?? null);
    }

    public function testPlainAndNestedProductStoresRenderWithoutRewrite(): void
    {
            $home = dirname($this->pmssEnsureDir($this->tempDir.'/home/fixtur01/.config'));
            $this->pmssWriteRelativeFile($home, '.config/pmss-user.json', json_encode(['product' => 'm1000'], JSON_UNESCAPED_SLASHES));

            $plainPath = $this->pmssWriteRelativeFile($this->tempDir, 'welcomeMessages-direct-plain.json',
                json_encode(['m1000' => 'plain {{username}}'], JSON_UNESCAPED_SLASHES));
            $nestedPath = $this->pmssWriteRelativeFile($this->tempDir, 'welcomeMessages-direct-nested.json',
                json_encode(['products' => ['m1000' => 'nested {{username}}']], JSON_UNESCAPED_SLASHES));

            $this->assertEquals('plain fixtur01', \pmssWelcomeMessageForUser([], $home, 'fixtur01', $plainPath));
            $this->assertEquals('nested fixtur01', \pmssWelcomeMessageForUser([], $home, 'fixtur01', $nestedPath));
    }

    public function testCustomerWelcomeMessageRendersPlainAndNestedStores(): void
    {
            $homeRoot = $this->tempDir.'/customer-home';
            $home = $homeRoot.'/fixtur01';
            $overrideHome = $homeRoot.'/fixtur02';
            // Publish fixtures in the parent; the customer subprocess only loads and renders.
            $this->pmssWriteFiles($homeRoot, [
                'fixtur01/.config/pmss-user.json' => json_encode(['product' => 'm1000']),
                'fixtur02/.config/pmss-user.json' => json_encode(['product' => 'm1000']),
                'fixtur02/.config/welcome-message.html' => 'custom {{username}} / {{quota}}',
                'plain.json' => json_encode(['m1000' => 'plain {{username}}']),
                'nested.json' => json_encode(['products' => ['m1000' => 'nested {{username}}']]),
            ]);
            $script = ''
                .'$lib = '.var_export($this->pmssRepoPath('etc/skel/www/welcomeMessage.php'), true).';'
                .'$home = '.var_export($home, true).';'
                .'$overrideHome = '.var_export($overrideHome, true).';'
                .'$root = '.var_export($homeRoot, true).';'
                .'require $lib;'
                .'echo json_encode(['
                .'"plain" => pmssWelcomeMessageForUser([], $home, "fixtur01", $root."/plain.json"),'
                .'"nested" => pmssWelcomeMessageForUser([], $home, "fixtur01", $root."/nested.json"),'
                .'"override" => pmssWelcomeMessageForUser(["totalSpace" => 1073741824], $overrideHome, "fixtur02", $root."/missing.json"),'
                .'"productSet" => function_exists("pmssWelcomeProductMessageSet"),'
                .'"userSet" => function_exists("pmssWelcomeUserMessageSet")'
                .']);';

            $this->assertEquals(
                [
                    'plain' => 'plain fixtur01',
                    'nested' => 'nested fixtur01',
                    'override' => 'custom fixtur02 / 1 GiB',
                    'productSet' => false,
                    'userSet' => false,
                ],
                $this->pmssRunInlinePhpJson($script, ['PMSS_HOME_DIR' => $homeRoot])
            );
    }

    public function testCustomerWelcomeMessageHelperStaysReadOnly(): void
    {
        $this->pmssAssertRepoFileNotContainsStrings('etc/skel/www/welcomeMessage.php', [
            'function pmssWelcomeProductMessageSet(',
            'function pmssWelcomeUserMessageSet(',
            'pmssReplaceUserFile',
            'pmssPathTargetIsSafe',
            'pmssEnsureSafeDir',
            'pmssUserFilePathIsSafe',
        ]);
        $this->assertStringContainsString('pmssCustomerPathIsSafe($path)', $this->pmssReadScriptsIncSources());
    }

    public function testProductConfigUsesUnifiedWelcomeLibrary(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/productConfig.php', "require_once __DIR__.'/lib/welcomeMessage.php';");
        $this->pmssAssertRepoFileContainsString('scripts/lib/welcomeMessage.php', 'function pmssWelcomeProductMessageSet(');
    }
}
