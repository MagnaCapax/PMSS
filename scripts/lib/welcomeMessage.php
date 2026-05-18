<?php
/**
 * Contextual welcome-page message helpers.
 *
 * Provides per-user override support and product-level fallback messages for
 * `etc/skel/www/welcome.php` while keeping lookup logic testable.
 *
 * @license GPL-3.0-only
 */

if (!function_exists('pmssReplaceUserFile') && is_file(__DIR__.'/lighttpd/userFileWrite.php')) {
    require_once __DIR__.'/lighttpd/userFileWrite.php';
}

/**
 * Set or clear a product-level welcome message template.
 */
function pmssWelcomeProductMessageSet(
    string $productKey,
    string $template,
    string $productMessagesPath = '/etc/seedbox/config/welcomeMessages.json'
): bool {
    $normalizedProductKey = trim($productKey);
    if ($normalizedProductKey === '') {
        return false;
    }

    $rootMap = pmssJsonFileReadAssoc($productMessagesPath, true) ?? [];
    $productMap = is_array($rootMap['products'] ?? null) ? $rootMap['products'] : $rootMap;

    if (trim($template) === '') {
        unset($productMap[$normalizedProductKey]);
    } else {
        $productMap[$normalizedProductKey] = $template;
    }
    ksort($productMap, SORT_STRING);

    $rootMap = is_array($rootMap['products'] ?? null)
        ? array_replace($rootMap, ['products' => $productMap])
        : $productMap;

    if (!is_string($encoded = json_encode($rootMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        return false;
    }

    return function_exists('pmssReplaceUserFile')
        && pmssReplaceUserFile(
            $productMessagesPath,
            $encoded.PHP_EOL,
            static function (string $temporaryPath): void {
                @chmod($temporaryPath, 0640);
            }
        );
}

/**
 * Resolve the per-user welcome-message override path.
 */
function pmssWelcomeUserMessagePath(string $userHome): string
{
    return rtrim($userHome, '/').'/.config/welcome-message.html';
}

/**
 * Read a per-user welcome-message override from the managed file path.
 */
function pmssWelcomeUserMessageRead(string $userHome): string
{
    $path = pmssWelcomeUserMessagePath($userHome);
    if (!function_exists('pmssUserFilePathIsSafe') || !pmssUserFilePathIsSafe($path) || !is_file($path) || is_link($path)) {
        return '';
    }

    $content = @file_get_contents($path);
    return (is_string($content) && trim($content) !== '') ? $content : '';
}

/**
 * Persist or clear a per-user welcome-message override beside other local config.
 */
function pmssWelcomeUserMessageSet(string $username, string $userHome, string $template): bool
{
    $username = trim($username);
    $userHome = rtrim($userHome, '/');
    if ($username === '' || $userHome === '') {
        return false;
    }

    $path = pmssWelcomeUserMessagePath($userHome);
    if (!function_exists('pmssPathTargetIsSafe') || !pmssPathTargetIsSafe($path, false)) {
        return false;
    }

    if (trim($template) === '') {
        return !is_link($path) && (!is_file($path) || @unlink($path));
    }

    $configDir = $userHome.'/.config';
    if (!function_exists('pmssEnsureSafeDir') || !pmssEnsureSafeDir($configDir, 0755)) {
        return false;
    }
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @chown($configDir, $username);
        @chgrp($configDir, $username);
    }

    return function_exists('pmssReplaceUserFile')
        && pmssReplaceUserFile(
            $path,
            $template,
            static function (string $temporaryPath) use ($username): void {
                @chmod($temporaryPath, 0640);
                if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
                    @chgrp($temporaryPath, $username);
                }
            }
        );
}

/**
 * Resolve and render the contextual welcome message for a user.
 */
function pmssWelcomeMessageForUser(
    array $quotaInfo,
    string $userHome,
    string $username,
    string $productMessagesPath = '/etc/seedbox/config/welcomeMessages.json'
): string {
    $userHome = rtrim($userHome, '/');
    $userConfig = pmssJsonFileReadAssoc($userHome.'/.config/pmss-user.json', true) ?? [];
    $productKey = '';
    foreach (['product', 'productName'] as $candidateKey) {
        if (is_string($candidateValue = $userConfig[$candidateKey] ?? null) && ($productKey = trim($candidateValue)) !== '') {
            break;
        }
    }
    if ($productKey === '' && is_file($productFile = $userHome.'/.product') && !is_link($productFile)) {
        if (function_exists('pmssUserFilePathIsSafe') && pmssUserFilePathIsSafe($productFile)) {
            $productKey = trim((string) @file_get_contents($productFile));
        }
    }

    $template = pmssWelcomeUserMessageRead($userHome);
    if ($template === '') {
        $template = is_string($userConfig['welcomeMessage'] ?? null) && trim($userConfig['welcomeMessage']) !== ''
            ? $userConfig['welcomeMessage']
            : '';
    }
    if ($template === '' && $productKey !== '') {
        $messageRootMap = pmssJsonFileReadAssoc($productMessagesPath, true) ?? [];
        $messageMap = is_array($messageRootMap['products'] ?? null) ? $messageRootMap['products'] : $messageRootMap;
        if (!is_string($template = $messageMap[$productKey] ?? null)) {
            $template = '';
            foreach ($messageMap as $mapKey => $mapValue) {
                if (!is_string($mapKey) || !is_string($mapValue) || strcasecmp($mapKey, $productKey) !== 0) {
                    continue;
                }

                $template = $mapValue;
                break;
            }
        }
    }
    if ($template === '') {
        return '';
    }

    $quota = is_numeric($quotaInfo['totalSpace'] ?? null) && ($quotaGiB = ((float) $quotaInfo['totalSpace']) / 1073741824) > 0
        ? round($quotaGiB, 1).' GiB'
        : '';

    return strtr($template, [
        '{{username}}' => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
        '{{quota}}'    => htmlspecialchars($quota, ENT_QUOTES, 'UTF-8'),
        '{{ramMiB}}'   => htmlspecialchars(is_numeric($userConfig['ramMiB'] ?? null) ? (string) ((int) $userConfig['ramMiB']) : '', ENT_QUOTES, 'UTF-8'),
        '{{product}}'  => htmlspecialchars($productKey, ENT_QUOTES, 'UTF-8'),
    ]);
}
