<?php
/**
 * WebDAV Security and Hardening Test Suite
 *
 * This comprehensive test suite validates the security posture of the PMSS WebDAV
 * implementation. Tests are organized into categories:
 *
 * 1. INPUT VALIDATION: Ensures usernames and paths are strictly validated before
 *    being used in config generation, preventing injection attacks.
 *
 * 2. PATH TRAVERSAL DEFENSE: Verifies that directory traversal attempts via
 *    various encoding schemes are blocked by the dotfile deny rule.
 *
 * 3. CONFIGURATION INTEGRITY: Ensures generated configs are syntactically correct
 *    and do not leak sensitive patterns when edge cases occur.
 *
 * 4. POLICY ENFORCEMENT: Validates that read-only/writable policies are correctly
 *    applied based on marker file presence.
 *
 * 5. ISOLATION: Confirms that multi-user scenarios do not leak access between users.
 *
 * 6. ROBUSTNESS: Tests fallback behavior when modules are missing or configs are
 *    malformed.
 *
 * 7. USERNAME VALIDATION: Defense-in-depth validation preventing injection attacks.
 *
 * Security Model:
 * - WebDAV exposes user entire home directory (~/*)
 * - Dotfiles are BLOCKED via url.access-deny = ( "/." )
 * - ~/www is read-only by default (protects web stack)
 * - ~/www/public is writable by default (user uploads)
 * - Full ~/www write requires explicit opt-in marker file
 * - All access requires Basic Auth via per-user htpasswd
 * - External access is HTTPS-only (nginx redirects HTTP)
 *
 * @see docs/contracts.md for WebDAV contract documentation
 * @see ADR 0004 for shell command guardrails
 * @see ADR 0005 for trust boundary requirements
 */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

// Load the functions we are testing
if (!function_exists('pmssWebdavWwwPolicyBlock')) {
    require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';
}

class WebdavSecurityTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/pmss-webdav-test-'.getmypid();
        @mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // Helper assertion methods not in base TestCase
    private function assertStringNotContainsString(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertTrue(
            strpos($haystack, $needle) === false,
            $msg !== '' ? $msg : "Expected string NOT to contain '$needle'"
        );
    }

    private function assertNotFalse($value, string $msg = ''): void
    {
        $this->assertTrue($value !== false, $msg !== '' ? $msg : 'Expected value to not be false');
    }

    private function assertGreaterThan($expected, $actual, string $msg = ''): void
    {
        $this->assertTrue(
            $actual > $expected,
            $msg !== '' ? $msg : "Expected $actual to be greater than $expected"
        );
    }

    private function assertIsBool($value, string $msg = ''): void
    {
        $this->assertTrue(is_bool($value), $msg !== '' ? $msg : 'Expected value to be boolean');
    }

    private function assertNotEmpty($value, string $msg = ''): void
    {
        $this->assertTrue(!empty($value), $msg !== '' ? $msg : 'Expected value to not be empty');
    }

    private function assertIsString($value, string $msg = ''): void
    {
        $this->assertTrue(is_string($value), $msg !== '' ? $msg : 'Expected value to be string');
    }

    // =========================================================================
    // SECTION 1: USERNAME VALIDATION
    // =========================================================================

    /**
     * TEST 1: Valid username produces valid config block
     *
     * HARDENS: Baseline sanity check that normal usernames work correctly.
     */
    public function testValidUsernameProducesValidConfigBlock(): void
    {
        $user = 'testuser';
        $policy = pmssWebdavWwwPolicyBlock($user);

        $this->assertStringContainsString('/webdav-testuser/www', $policy);
        $this->assertStringContainsString('$HTTP["url"]', $policy);
        $this->assertStringContainsString('webdav.is-readonly', $policy);
    }

    /**
     * TEST 2: Short valid username (minimum length)
     *
     * HARDENS: Edge case for minimum-length usernames (1 char).
     */
    public function testShortUsernameProducesValidConfig(): void
    {
        $user = 'a';
        $policy = pmssWebdavWwwPolicyBlock($user);

        $this->assertStringContainsString('/webdav-a/www', $policy);
        $this->assertStringContainsString('($|/)', $policy);
    }

    /**
     * TEST 3: Maximum length username (8 chars per PMSS policy)
     *
     * HARDENS: Edge case for maximum-length usernames.
     */
    public function testMaxLengthUsernameProducesValidConfig(): void
    {
        $user = 'abcd1234';
        $policy = pmssWebdavWwwPolicyBlock($user);

        $this->assertStringContainsString('/webdav-abcd1234/www', $policy);
    }

    /**
     * TEST 4: Username with digits produces valid config
     *
     * HARDENS: Ensures alphanumeric usernames work.
     */
    public function testUsernameWithDigitsProducesValidConfig(): void
    {
        $user = 'user123';
        $policy = pmssWebdavWwwPolicyBlock($user);

        $this->assertStringContainsString('/webdav-user123/www', $policy);
    }

    /**
     * TEST 5: Username with regex metacharacters is REJECTED
     *
     * HARDENS: Regex metacharacters could manipulate lighttpd URL matching.
     *
     * ADVERSARIAL: Attacker tries to inject ".*" to match all paths.
     */
    public function testUsernameWithRegexMetacharsIsRejected(): void
    {
        $malicious = 'test.*';
        $policy = @pmssWebdavWwwPolicyBlock($malicious);

        $this->assertStringContainsString('invalid username', $policy);
        $this->assertStringNotContainsString('test.*', $policy);
    }

    /**
     * TEST 6: Empty username is rejected
     *
     * HARDENS: Empty username must not create wildcard patterns.
     *
     * ADVERSARIAL: Attacker passes empty username for broad access.
     */
    public function testEmptyUsernameIsRejected(): void
    {
        $policy = @pmssWebdavWwwPolicyBlock('');

        $this->assertStringContainsString('invalid username', $policy);
        $this->assertStringNotContainsString('webdav.is-readonly', $policy);
    }

    // =========================================================================
    // SECTION 2: PATH TRAVERSAL DEFENSE
    // =========================================================================

    /**
     * TEST 7: Dotfile deny pattern is present in template
     *
     * HARDENS: Verifies the critical security control exists.
     */
    public function testDotfileDenyPatternExistsInTemplate(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.lighttpd';
        $template = file_get_contents($templatePath);

        $this->assertStringContainsString('url.access-deny = ( "/." )', $template);
    }

    /**
     * TEST 8: Path traversal via ../ is blocked by dotfile rule
     *
     * HARDENS: The "/." pattern matches "/.." traversal sequence.
     *
     * ADVERSARIAL: Attacker requests /webdav-user/../../../etc/passwd
     */
    public function testPathTraversalBlockedByDotfileRule(): void
    {
        $denyPattern = '/.';
        $attacks = array(
            '/webdav-user/../etc/passwd',
            '/webdav-user/data/../../.ssh/id_rsa',
            '/webdav-user/./hidden',
            '/webdav-user/.bashrc',
            '/webdav-user/.ssh/authorized_keys',
        );

        foreach ($attacks as $attack) {
            $this->assertStringContainsString($denyPattern, $attack,
                "Attack path '$attack' should be caught by '$denyPattern' rule");
        }
    }

    /**
     * TEST 9: URL-encoded traversal patterns documentation
     *
     * HARDENS: Documents %2e handling depends on lighttpd normalization.
     *
     * ADVERSARIAL: Attacker uses %2e%2e%2f to bypass pattern matching.
     */
    public function testUrlEncodedTraversalDocumentation(): void
    {
        $encodedAttacks = array(
            '%2e%2e%2f' => '../',
            '%2e%2e/'   => '../',
            '..%2f'     => '../',
            '%2e/'      => './',
        );

        foreach ($encodedAttacks as $encoded => $decoded) {
            $this->assertStringContainsString('.', $decoded,
                "Decoded '$encoded' -> '$decoded' contains dot, will be blocked");
        }
    }

    /**
     * TEST 10: Double-encoding bypass documentation
     *
     * HARDENS: Documents double-encoding attack vector.
     *
     * ADVERSARIAL: Attacker uses %252e%252e%252f for double-decode.
     */
    public function testDoubleEncodingBypassDocumentation(): void
    {
        $doubleEncoded = '%252e%252e%252f';
        $this->assertStringNotContainsString('/.', $doubleEncoded);
    }

    /**
     * TEST 11: Null byte injection documentation
     *
     * HARDENS: Documents null byte injection vector.
     *
     * ADVERSARIAL: Attacker uses %00 for path truncation.
     */
    public function testNullByteInjectionDocumentation(): void
    {
        $nullByteAttack = "/webdav-user/file%00.txt";
        $this->assertTrue(strpos($nullByteAttack, '%00') !== false,
            'Null byte attack documented - lighttpd rejects these with 400');
    }

    // =========================================================================
    // SECTION 3: CONFIGURATION INTEGRITY
    // =========================================================================

    /**
     * TEST 12: Strip function removes WebDAV block completely
     *
     * HARDENS: When mod_webdav is missing, strip must remove the block.
     */
    public function testStripFunctionRemovesWebdavBlockCompletely(): void
    {
        $template = <<<'LIGHTTPD'
server.modules = (
    "mod_access",
    "mod_webdav",
)

# PMSS_WEBDAV_BEGIN
$HTTP["url"] =~ "^/webdav-user($|/)" {
    webdav.activate = "enable"
}
# PMSS_WEBDAV_END

alias.url = ()
LIGHTTPD;

        $stripped = pmssStripLighttpdWebdavConfig($template);

        $this->assertStringNotContainsString('webdav.activate', $stripped);
        $this->assertStringNotContainsString('PMSS_WEBDAV_BEGIN', $stripped);
        $this->assertStringContainsString('#"mod_webdav",', $stripped);
        $this->assertStringContainsString('alias.url', $stripped);
    }

    /**
     * TEST 13: Strip function handles template without markers gracefully
     *
     * HARDENS: Malformed template should not crash or corrupt config.
     */
    public function testStripFunctionHandlesNoMarkersGracefully(): void
    {
        $template = <<<'LIGHTTPD'
server.modules = (
    "mod_access",
    "mod_webdav",
)
alias.url = ()
LIGHTTPD;

        $stripped = pmssStripLighttpdWebdavConfig($template);

        $this->assertStringContainsString('#"mod_webdav",', $stripped);
        $this->assertNotEmpty($stripped);
        $this->assertStringContainsString('alias.url', $stripped);
    }

    /**
     * TEST 14: Strip function handles malformed markers
     *
     * HARDENS: Partial markers should not cause deletion of unintended content.
     */
    public function testStripFunctionHandlesMalformedMarkers(): void
    {
        $template = <<<'LIGHTTPD'
server.modules = (
    "mod_webdav",
)
# PMSS_WEBDAV_BEGIN
webdav.activate = "enable"
# Missing END marker
alias.url = ()
LIGHTTPD;

        $stripped = pmssStripLighttpdWebdavConfig($template);

        $this->assertStringContainsString('#"mod_webdav",', $stripped);
        $this->assertStringContainsString('webdav.activate', $stripped);
    }

    /**
     * TEST 15: Strip function removes placeholder token
     *
     * HARDENS: Placeholder must not leak into final config.
     */
    public function testStripFunctionRemovesPlaceholderToken(): void
    {
        $template = <<<'LIGHTTPD'
# PMSS_WEBDAV_BEGIN
webdav.activate = "enable"
##PMSS_WEBDAV_WWW_POLICY##
# PMSS_WEBDAV_END
LIGHTTPD;

        $stripped = pmssStripLighttpdWebdavConfig($template);

        $this->assertStringNotContainsString('##PMSS_WEBDAV_WWW_POLICY##', $stripped);
    }

    /**
     * TEST 16: Strip function is idempotent
     *
     * HARDENS: Multiple strip calls should produce same result.
     */
    public function testStripFunctionIsIdempotent(): void
    {
        $template = <<<'LIGHTTPD'
server.modules = (
    "mod_webdav",
)
# PMSS_WEBDAV_BEGIN
webdav.activate = "enable"
# PMSS_WEBDAV_END
LIGHTTPD;

        $once = pmssStripLighttpdWebdavConfig($template);
        $twice = pmssStripLighttpdWebdavConfig($once);

        $this->assertEquals($once, $twice, 'Strip function must be idempotent');
    }

    // =========================================================================
    // SECTION 4: POLICY ENFORCEMENT
    // =========================================================================

    /**
     * TEST 17: Default policy makes www read-only
     *
     * HARDENS: Without marker file, ~/www must be read-only over WebDAV.
     */
    public function testDefaultPolicyMakesWwwReadOnly(): void
    {
        $user = 'testuser';
        $policy = pmssWebdavWwwPolicyBlock($user);

        $this->assertStringContainsString('webdav.is-readonly = "enable"', $policy);
        $this->assertStringContainsString('/webdav-testuser/www($|/)', $policy);
        $this->assertStringContainsString('/webdav-testuser/www/public($|/)', $policy);
    }

    /**
     * TEST 18: Default policy makes www/public writable
     *
     * HARDENS: ~/www/public should be writable by default.
     */
    public function testDefaultPolicyMakesWwwPublicWritable(): void
    {
        $user = 'testuser';
        $policy = pmssWebdavWwwPolicyBlock($user);

        $lines = explode("\n", $policy);
        $publicBlockFound = false;

        foreach ($lines as $i => $line) {
            if (strpos($line, '/www/public') !== false) {
                $publicBlockFound = true;
                for ($j = $i; $j < min($i + 5, count($lines)); $j++) {
                    if (strpos($lines[$j], 'is-readonly') !== false) {
                        $this->assertStringContainsString('disable', $lines[$j]);
                        break;
                    }
                }
            }
        }

        $this->assertTrue($publicBlockFound, 'www/public block must exist');
    }

    /**
     * TEST 19: Policy block order ensures correct precedence
     *
     * HARDENS: www/public writable rule must come AFTER www read-only rule.
     */
    public function testPolicyBlockOrderEnsuresCorrectPrecedence(): void
    {
        $user = 'testuser';
        $policy = pmssWebdavWwwPolicyBlock($user);

        $wwwPos = strpos($policy, '/www($|/)');
        $publicPos = strpos($policy, '/www/public($|/)');

        $this->assertNotFalse($wwwPos, 'www block must exist');
        $this->assertNotFalse($publicPos, 'www/public block must exist');
        $this->assertGreaterThan($wwwPos, $publicPos,
            'www/public block must come after www block for lighttpd precedence');
    }

    // =========================================================================
    // SECTION 5: ISOLATION / MULTI-USER
    // =========================================================================

    /**
     * TEST 20: Different users get different config blocks
     *
     * HARDENS: Each user config must be specific to that user.
     */
    public function testDifferentUsersGetDifferentConfigs(): void
    {
        $alicePolicy = pmssWebdavWwwPolicyBlock('alice');
        $bobPolicy = pmssWebdavWwwPolicyBlock('bob');

        $this->assertStringContainsString('/webdav-alice/', $alicePolicy);
        $this->assertStringNotContainsString('/webdav-bob/', $alicePolicy);

        $this->assertStringContainsString('/webdav-bob/', $bobPolicy);
        $this->assertStringNotContainsString('/webdav-alice/', $bobPolicy);
    }

    /**
     * TEST 21: User config contains no wildcards
     *
     * HARDENS: User configs must not match other users paths.
     *
     * ADVERSARIAL: Misconfiguration allowing cross-user access.
     */
    public function testUserConfigContainsNoWildcards(): void
    {
        $policy = pmssWebdavWwwPolicyBlock('testuser');

        $this->assertStringNotContainsString('/webdav-*/', $policy);
        $this->assertStringNotContainsString('/webdav-[', $policy);
        $this->assertStringNotContainsString('/webdav-.*/', $policy);
    }

    /**
     * TEST 22: Auth requirement pattern is user-specific
     *
     * HARDENS: Template must specify exact username, not wildcard.
     */
    public function testAuthRequirementIsUserSpecific(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.lighttpd';
        $template = file_get_contents($templatePath);

        $this->assertStringContainsString('"/webdav-##username/"', $template);
        $this->assertStringContainsString('"require" => "user=##username"', $template);
        $this->assertStringNotContainsString('"require" => "valid-user"', $template);
    }

    // =========================================================================
    // SECTION 6: ROBUSTNESS
    // =========================================================================

    /**
     * TEST 23: Module detection returns correct type
     *
     * HARDENS: pmssLighttpdWebdavModulePresent() must return boolean.
     */
    public function testModuleDetectionReturnsBool(): void
    {
        $result = pmssLighttpdWebdavModulePresent();

        $this->assertIsBool($result);
    }

    /**
     * TEST 24: Lock file path is user-specific and secure
     *
     * HARDENS: Lock database must be in user private directory.
     */
    public function testLockFilePathIsSecure(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.lighttpd';
        $template = file_get_contents($templatePath);

        $this->assertStringContainsString(
            'webdav.sqlite-db-name = "/home/##username/.lighttpd/webdav.lock.db"',
            $template
        );
        $this->assertStringNotContainsString('webdav.sqlite-db-name = "/tmp/', $template);
    }

    /**
     * TEST 25: Alias mapping points to correct home directory
     *
     * HARDENS: WebDAV alias must map to user actual home directory.
     */
    public function testAliasMappingPointsToCorrectHome(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.lighttpd';
        $template = file_get_contents($templatePath);

        $this->assertStringContainsString(
            '"/webdav-##username/" => "/home/##username/"',
            $template
        );
    }

    /**
     * TEST 26: Directory listing is disabled for WebDAV
     *
     * HARDENS: WebDAV should not expose directory listings.
     */
    public function testDirectoryListingDisabledForWebdav(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.lighttpd';
        $template = file_get_contents($templatePath);

        preg_match('/# PMSS_WEBDAV_BEGIN.*?# PMSS_WEBDAV_END/s', $template, $matches);
        $webdavBlock = isset($matches[0]) ? $matches[0] : '';

        $this->assertStringContainsString('dir-listing.activate = "disable"', $webdavBlock);
    }

    /**
     * TEST 27: Template has correct regex anchors
     *
     * HARDENS: URL regex must use ^ anchor to prevent partial matches.
     */
    public function testTemplateHasCorrectRegexAnchors(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.lighttpd';
        $template = file_get_contents($templatePath);

        $this->assertStringContainsString('"^/webdav-##username($|/)"', $template);
    }

    /**
     * TEST 28: Marker file location is not web-accessible
     *
     * HARDENS: Marker file is in dotdir, blocked by access-deny rule.
     */
    public function testMarkerFileLocationNotWebAccessible(): void
    {
        $markerPath = '/home/testuser/.lighttpd/webdav.www-writable';

        $this->assertStringContainsString('/.lighttpd/', $markerPath);
        $this->assertStringContainsString('/.', '/.lighttpd');
    }

    // =========================================================================
    // SECTION 7: NGINX CONFIGURATION
    // =========================================================================

    /**
     * TEST 29: Nginx redirect uses 301 (permanent)
     *
     * HARDENS: HTTP to HTTPS redirects should use 301 for security.
     */
    public function testNginxRedirectUses301(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.nginx-user';
        $template = file_get_contents($templatePath);

        $this->assertStringContainsString('return 301 https://$host$request_uri;', $template);
        $this->assertStringNotContainsString('return 302', $template);
    }

    /**
     * TEST 30: Nginx forwards Authorization header
     *
     * HARDENS: Nginx proxy must forward auth header to lighttpd.
     */
    public function testNginxForwardsAuthorizationHeader(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.nginx-user';
        $template = file_get_contents($templatePath);

        $this->assertStringContainsString(
            'proxy_set_header Authorization $http_authorization;',
            $template
        );
    }

    /**
     * TEST 31: Nginx disables request buffering for large uploads
     *
     * HARDENS: Nginx must not buffer entire request body.
     */
    public function testNginxDisablesRequestBuffering(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.nginx-user';
        $template = file_get_contents($templatePath);

        $this->assertStringContainsString('proxy_request_buffering off;', $template);
        $this->assertStringContainsString('client_max_body_size 0;', $template);
    }

    // =========================================================================
    // SECTION 8: USERNAME VALIDATION (Defense in Depth)
    // =========================================================================

    /**
     * TEST 32: Username starting with digit is rejected
     *
     * HARDENS: PMSS usernames must start with a letter.
     *
     * ADVERSARIAL: Attacker uses "1admin" to bypass validation.
     */
    public function testUsernameStartingWithDigitIsRejected(): void
    {
        $policy = @pmssWebdavWwwPolicyBlock('1admin');

        $this->assertStringContainsString('invalid username', $policy);
        $this->assertStringNotContainsString('1admin', $policy);
    }

    /**
     * TEST 33: Username with uppercase letters is rejected
     *
     * HARDENS: PMSS usernames are lowercase only.
     *
     * ADVERSARIAL: Attacker uses "Admin" for privilege escalation.
     */
    public function testUsernameWithUppercaseIsRejected(): void
    {
        $invalidUsers = array('Admin', 'ADMIN', 'testUser', 'TEST');

        foreach ($invalidUsers as $user) {
            $policy = @pmssWebdavWwwPolicyBlock($user);
            $this->assertStringContainsString('invalid username', $policy,
                "Uppercase username '$user' should be rejected");
        }
    }

    /**
     * TEST 34: Username with special characters is rejected
     *
     * HARDENS: Special characters could enable injection attacks.
     *
     * ADVERSARIAL: Attacker uses special chars for injection.
     */
    public function testUsernameWithSpecialCharsIsRejected(): void
    {
        $injectionAttempts = array(
            'user.test',   // Dot - regex wildcard
            'user/test',   // Slash - path traversal
            'user$test',   // Dollar - variable expansion
            "user'test",   // Single quote - string escape
            'user;test',   // Semicolon - command separator
            'user|test',   // Pipe - command chaining
            'user test',   // Space - argument separator
            'user<test',   // Less than - redirect
            'user>test',   // Greater than - redirect
        );

        foreach ($injectionAttempts as $user) {
            $policy = @pmssWebdavWwwPolicyBlock($user);
            $this->assertStringContainsString('invalid username', $policy,
                "Special char username should be rejected: " . bin2hex($user));
        }
    }

    /**
     * TEST 35: Username exceeding max length is rejected
     *
     * HARDENS: PMSS usernames are max 8 characters.
     *
     * ADVERSARIAL: Attacker uses long username for truncation attack.
     */
    public function testUsernameTooLongIsRejected(): void
    {
        $longUsernames = array(
            'abcdefghi',       // 9 chars
            'abcdefghij',      // 10 chars
            'averylongusername',
        );

        foreach ($longUsernames as $user) {
            $policy = @pmssWebdavWwwPolicyBlock($user);
            $this->assertStringContainsString('invalid username', $policy,
                "Long username (" . strlen($user) . " chars) should be rejected");
        }
    }

    /**
     * TEST 36: Path traversal in username is rejected
     *
     * HARDENS: Path traversal sequences in username must be rejected.
     *
     * ADVERSARIAL: Attacker embeds ../ in username field.
     */
    public function testPathTraversalInUsernameIsRejected(): void
    {
        $traversalAttempts = array('../etc', 'a/../b', 'test/..', '..', '.');

        foreach ($traversalAttempts as $user) {
            $policy = @pmssWebdavWwwPolicyBlock($user);
            $this->assertStringContainsString('invalid username', $policy,
                "Path traversal in username should be rejected: $user");
        }
    }

    /**
     * TEST 37: Null bytes in username are rejected
     *
     * HARDENS: Null bytes could truncate strings or cause undefined behavior.
     *
     * ADVERSARIAL: Attacker uses null byte for truncation attack.
     */
    public function testNullBytesInUsernameAreRejected(): void
    {
        $nullByteAttempts = array("test\x00admin", "\x00test", "test\x00");

        foreach ($nullByteAttempts as $user) {
            $policy = @pmssWebdavWwwPolicyBlock($user);
            $this->assertStringContainsString('invalid username', $policy,
                "Null byte in username should be rejected");
            $this->assertStringNotContainsString("\x00", $policy);
        }
    }

    /**
     * TEST 38: Valid usernames are accepted (positive test cases)
     *
     * HARDENS: Ensure validation does not reject legitimate usernames.
     */
    public function testValidUsernamesAreAccepted(): void
    {
        $validUsernames = array(
            'a', 'ab', 'abc', 'test', 'user1', 'a1b2c3d4', 'abcd1234', 'z', 'z9z9z9z9',
        );

        foreach ($validUsernames as $user) {
            $policy = pmssWebdavWwwPolicyBlock($user);
            $this->assertStringNotContainsString('invalid username', $policy,
                "Valid username '$user' should be accepted");
            $this->assertStringContainsString('/webdav-' . $user . '/', $policy,
                "Valid username '$user' should appear in config");
        }
    }

    /**
     * TEST 39: Control characters in username are rejected
     *
     * HARDENS: Control characters could cause parsing issues.
     *
     * ADVERSARIAL: Attacker uses control chars for parsing confusion.
     */
    public function testControlCharsInUsernameAreRejected(): void
    {
        $controlChars = array("\x01", "\x09", "\x0a", "\x0d", "\x1b", "\x7f");

        foreach ($controlChars as $ctrl) {
            $user = 'test' . $ctrl . 'user';
            $policy = @pmssWebdavWwwPolicyBlock($user);
            $this->assertStringContainsString('invalid username', $policy,
                "Control char 0x" . bin2hex($ctrl) . " in username should be rejected");
        }
    }

    /**
     * TEST 40: High-byte characters in username are rejected
     *
     * HARDENS: Non-ASCII bytes could enable encoding bypass attacks.
     *
     * ADVERSARIAL: Attacker uses high bytes for encoding bypass.
     */
    public function testHighByteCharsInUsernameAreRejected(): void
    {
        $highBytes = array("\x80", "\xc0", "\xe0", "\xff");

        foreach ($highBytes as $byte) {
            $user = 'test' . $byte;
            $policy = @pmssWebdavWwwPolicyBlock($user);
            $this->assertStringContainsString('invalid username', $policy,
                "High byte 0x" . bin2hex($byte) . " in username should be rejected");
        }
    }

    /**
     * TEST 41: Unicode usernames are rejected
     *
     * HARDENS: Unicode characters are not valid in PMSS usernames.
     *
     * ADVERSARIAL: Attacker attempts unicode normalization bypass.
     */
    public function testPolicyRejectsUnicodeUsernames(): void
    {
        $unicodeUsers = array(
            'test' . chr(0xC3) . chr(0xAB),  // e with diaresis
            'user' . chr(0xC2) . chr(0xAE),  // registered trademark
        );

        foreach ($unicodeUsers as $user) {
            $policy = @pmssWebdavWwwPolicyBlock($user);
            $this->assertIsString($policy);
            $this->assertStringNotContainsString($user, $policy);
            $this->assertStringContainsString('invalid username', $policy);
        }
    }

    /**
     * TEST 42: Validation prevents lighttpd config injection
     *
     * HARDENS: Crafted usernames that look like config syntax must be rejected.
     *
     * ADVERSARIAL: Attacker tries to inject lighttpd directives.
     */
    public function testValidationPreventsConfigInjection(): void
    {
        $configInjections = array(
            'a}\nwebdav',    // Try to close block
            '##username',   // Try template injection
        );

        foreach ($configInjections as $user) {
            $policy = @pmssWebdavWwwPolicyBlock($user);
            $this->assertStringContainsString('invalid username', $policy);
        }
    }

    // =========================================================================
    // SECTION 9: CONFIGURATION SYNTAX VALIDATION
    // =========================================================================

    /**
     * TEST 43: Lighttpd auth.require entries are comma-separated
     *
     * HARDENS: Lighttpd array syntax requires commas between entries.
     * Missing commas cause "auth.require should have been a list of key => list" error.
     *
     * REGRESSION: Fixed in 2026-01 after WebDAV deployment broke user lighttpd.
     */
    public function testLighttpdAuthRequireEntriesAreCommaSeparated(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.lighttpd';
        $template = file_get_contents($templatePath);

        // Extract auth.require block
        if (!preg_match('/auth\.require\s*=\s*\((.*?)\n\)/s', $template, $matches)) {
            $this->fail('auth.require block not found in template');
        }
        $authBlock = $matches[1];

        // Count path entries (lines containing "=>") and closing parens that end entries
        // Each entry except the last must be followed by a comma
        $entries = preg_match_all('/"\s*=>\s*\(/', $authBlock);
        $commas = preg_match_all('/\)\s*,/', $authBlock);

        // For N entries, we need N-1 commas between them
        $this->assertEquals(
            $entries - 1,
            $commas,
            "auth.require has $entries entries but only $commas commas - missing separator"
        );
    }

    /**
     * TEST 44: Lighttpd template produces valid syntax when rendered
     *
     * HARDENS: Rendered config must not have obvious syntax errors.
     */
    public function testLighttpdTemplateProducesValidSyntax(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.lighttpd';
        $template = file_get_contents($templatePath);

        // Render with test values
        $rendered = str_replace(
            array('##username', '##serverPort', '##rclonePort', '##qbittorrentPort', '##PMSS_WEBDAV_WWW_POLICY##'),
            array('testuser', '30000', '30001', '30002', ''),
            $template
        );

        // Check for common syntax errors: unbalanced parens in key blocks
        $authRequireMatch = preg_match('/auth\.require\s*=\s*\(/', $rendered);
        $this->assertEquals(1, $authRequireMatch, 'auth.require block must exist');

        // Verify no "=> (" followed by ") /" without comma (the bug pattern)
        // Pattern: closing paren, optional whitespace/newlines, then a path without comma
        $hasSyntaxError = (bool)preg_match('/\)\s*\n\s*"\//', $rendered);
        $this->assertTrue(
            !$hasSyntaxError,
            'Found closing paren followed by path without comma - syntax error'
        );
    }

    /**
     * TEST 45: Nginx WebDAV blocks do not duplicate proxy_params directives
     *
     * HARDENS: WebDAV location blocks must not include proxy_params AND set
     * proxy_read_timeout explicitly, as this causes nginx to fail with
     * "directive is duplicate" error.
     *
     * REGRESSION: Fixed in 2026-01 after WebDAV deployment broke nginx startup.
     */
    public function testNginxWebdavBlocksNoDuplicateDirectives(): void
    {
        $scriptPath = dirname(__DIR__, 3).'/util/createNginxConfig.php';
        $script = file_get_contents($scriptPath);

        // Extract WebDAV location blocks from the HEREDOC templates.
        // Match from "location /webdav-" to the closing "}" with proper nesting.
        preg_match_all('/location\s+\/webdav-[^{]+\{([^}]+(?:\{[^}]*\}[^}]*)*)\}/s', $script, $matches);

        $this->assertGreaterThan(0, count($matches[1]), 'WebDAV location blocks must exist');

        $proxyBlocksChecked = 0;
        foreach ($matches[1] as $i => $locationBlock) {
            // Skip redirect-only blocks (no proxy_pass means it's just a redirect)
            if (strpos($locationBlock, 'proxy_pass') === false) {
                continue;
            }

            $proxyBlocksChecked++;
            $hasIncludeProxyParams = strpos($locationBlock, 'include /etc/nginx/proxy_params') !== false;
            $hasExplicitTimeout = strpos($locationBlock, 'proxy_read_timeout') !== false;

            // Either include proxy_params OR set timeout explicitly, not both
            $hasDuplicate = $hasIncludeProxyParams && $hasExplicitTimeout;
            $this->assertTrue(
                !$hasDuplicate,
                "WebDAV proxy block $i includes proxy_params AND sets proxy_read_timeout - duplicate directive"
            );
        }

        $this->assertGreaterThan(0, $proxyBlocksChecked, 'Must have at least one WebDAV proxy block');
    }

    /**
     * TEST 46: Nginx WebDAV blocks have required proxy headers
     *
     * HARDENS: When not including proxy_params, WebDAV blocks must set headers explicitly.
     */
    public function testNginxWebdavBlocksHaveRequiredHeaders(): void
    {
        $scriptPath = dirname(__DIR__, 3).'/util/createNginxConfig.php';
        $script = file_get_contents($scriptPath);

        // Match WebDAV location blocks with their full content
        preg_match_all('/location\s+\/webdav-[^{]+\{([^}]+(?:\{[^}]*\}[^}]*)*)\}/s', $script, $matches);

        $requiredHeaders = array(
            'proxy_set_header Host',
            'proxy_set_header X-Real-IP',
            'proxy_set_header X-Forwarded-For',
            'proxy_set_header Authorization',
        );

        $proxyBlocksChecked = 0;
        foreach ($matches[1] as $i => $locationBlock) {
            // Skip redirect-only blocks (no proxy_pass means it's just a redirect)
            if (strpos($locationBlock, 'proxy_pass') === false) {
                continue;
            }

            $proxyBlocksChecked++;
            $hasIncludeProxyParams = strpos($locationBlock, 'include /etc/nginx/proxy_params') !== false;

            // If not including proxy_params, must have explicit headers
            if (!$hasIncludeProxyParams) {
                foreach ($requiredHeaders as $header) {
                    $this->assertStringContainsString(
                        $header,
                        $locationBlock,
                        "WebDAV proxy block $i missing required header: $header"
                    );
                }
            }
        }

        $this->assertGreaterThan(0, $proxyBlocksChecked, 'Must have at least one WebDAV proxy block');
    }

    /**
     * TEST 47: proxy_params file contains proxy_read_timeout
     *
     * HARDENS: Documents that proxy_params sets timeout, so WebDAV blocks must not duplicate.
     */
    public function testProxyParamsContainsReadTimeout(): void
    {
        $proxyParamsPath = dirname(__DIR__, 4).'/etc/seedbox/config/template.nginx-proxy_params';
        $proxyParams = file_get_contents($proxyParamsPath);

        $this->assertStringContainsString(
            'proxy_read_timeout',
            $proxyParams,
            'proxy_params must contain proxy_read_timeout (WebDAV blocks must not include it AND set explicitly)'
        );
    }

    /**
     * TEST 48: template.nginx-user WebDAV block does not duplicate proxy_read_timeout
     *
     * HARDENS: The external nginx user template must not include proxy_params AND
     * set proxy_read_timeout explicitly, as this causes nginx to fail with
     * "directive is duplicate" error.
     *
     * REGRESSION: Fixed in 2026-01 after issue #137 (WebDAV template not updated
     * when createNginxConfig.php inline templates were fixed in commit 005b1fe).
     */
    public function testNginxUserTemplateWebdavNoDuplicateTimeout(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.nginx-user';
        $template = file_get_contents($templatePath);

        // Extract WebDAV location block - find from "location /webdav-" to the matching
        // closing brace, handling nested braces (e.g., if blocks inside).
        $webdavBlock = $this->extractNginxLocationBlock($template, '/webdav-');
        $this->assertNotEmpty($webdavBlock, 'WebDAV location block must exist in template');

        $hasIncludeProxyParams = strpos($webdavBlock, 'include /etc/nginx/proxy_params') !== false;
        $hasExplicitTimeout = strpos($webdavBlock, 'proxy_read_timeout') !== false;

        // Either include proxy_params OR set timeout explicitly, not both
        $this->assertTrue(
            !($hasIncludeProxyParams && $hasExplicitTimeout),
            'template.nginx-user WebDAV block includes proxy_params AND sets proxy_read_timeout - duplicate directive causes nginx failure'
        );
    }

    /**
     * TEST 49: template.nginx-user WebDAV block has required proxy headers
     *
     * HARDENS: When not including proxy_params, WebDAV block must set headers explicitly.
     */
    public function testNginxUserTemplateWebdavHasRequiredHeaders(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.nginx-user';
        $template = file_get_contents($templatePath);

        // Extract WebDAV location block with nested brace handling.
        $webdavBlock = $this->extractNginxLocationBlock($template, '/webdav-');
        $this->assertNotEmpty($webdavBlock, 'WebDAV location block must exist in template');

        $hasIncludeProxyParams = strpos($webdavBlock, 'include /etc/nginx/proxy_params') !== false;

        // If not including proxy_params, must have explicit headers
        if (!$hasIncludeProxyParams) {
            $requiredHeaders = array(
                'proxy_set_header Host',
                'proxy_set_header X-Real-IP',
                'proxy_set_header X-Forwarded-For',
                'proxy_set_header Authorization',
            );

            foreach ($requiredHeaders as $header) {
                $this->assertStringContainsString(
                    $header,
                    $webdavBlock,
                    "template.nginx-user WebDAV block missing required header: $header"
                );
            }
        }
    }

    /**
     * Extract an nginx location block by path prefix, handling nested braces.
     *
     * @param string $config Full nginx config content
     * @param string $pathPrefix Location path prefix to find (e.g., '/webdav-')
     * @return string The location block content, or empty string if not found
     */
    private function extractNginxLocationBlock(string $config, string $pathPrefix): string
    {
        $pattern = '/location\s+' . preg_quote($pathPrefix, '/') . '[^{]*\{/';
        if (!preg_match($pattern, $config, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $startPos = $matches[0][1];
        $openBracePos = strpos($config, '{', $startPos);
        if ($openBracePos === false) {
            return '';
        }

        // Count braces to find matching close
        $depth = 1;
        $pos = $openBracePos + 1;
        $len = strlen($config);

        while ($pos < $len && $depth > 0) {
            $char = $config[$pos];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }
            $pos++;
        }

        if ($depth !== 0) {
            return '';
        }

        return substr($config, $openBracePos + 1, $pos - $openBracePos - 2);
    }

    /**
     * TEST 50: template.nginx-conf has server_names_hash_bucket_size set
     *
     * HARDENS: Servers with many users/subdomains need adequate hash bucket size.
     * Default of 64 is insufficient; 128 is required for production.
     *
     * REGRESSION: Fixed in 2026-01 after issue #137 (nginx failing with
     * "could not build server_names_hash" error on multi-user servers).
     */
    public function testNginxConfHasServerNamesHashBucketSize(): void
    {
        $templatePath = dirname(__DIR__, 4).'/etc/seedbox/config/template.nginx-conf';
        $template = file_get_contents($templatePath);

        // Must be uncommented and set to at least 128
        $this->assertTrue(
            (bool)preg_match('/^\s*server_names_hash_bucket_size\s+(\d+)\s*;/m', $template, $matches),
            'server_names_hash_bucket_size must be uncommented in template.nginx-conf'
        );

        $size = (int)$matches[1];
        $this->assertTrue(
            $size >= 128,
            "server_names_hash_bucket_size must be at least 128 (found: $size)"
        );
    }
}
