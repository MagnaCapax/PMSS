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
 * Read a JSON file into an associative array.
 */
function pmssWelcomeReadJson(string $path): array
{
    if (!is_file($path) || is_link($path) || !is_string($raw = @file_get_contents($path)) || trim($raw) === '') {
        return [];
    }

    return is_array($decoded = json_decode($raw, true)) ? $decoded : [];
}

/**
 * Read the product template map regardless of root-file shape.
 */
function pmssWelcomeProductMessageMap(array $rootMap): array
{
    return is_array($rootMap['products'] ?? null) ? $rootMap['products'] : $rootMap;
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

    $rootMap = pmssWelcomeReadJson($productMessagesPath);
    $productMap = pmssWelcomeProductMessageMap($rootMap);

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
 * Resolve and render the contextual welcome message for a user.
 */
function pmssWelcomeMessageForUser(
    array $quotaInfo,
    string $userHome,
    string $username,
    string $productMessagesPath = '/etc/seedbox/config/welcomeMessages.json'
): string {
    $userHome = rtrim($userHome, '/');
    $userConfig = pmssWelcomeReadJson($userHome.'/.config/pmss-user.json');
    $productKey = '';
    foreach (['product', 'productName'] as $candidateKey) {
        if (is_string($candidateValue = $userConfig[$candidateKey] ?? null) && ($productKey = trim($candidateValue)) !== '') {
            break;
        }
    }
    if ($productKey === '' && is_file($productFile = $userHome.'/.product') && !is_link($productFile)) {
        $productKey = trim((string) @file_get_contents($productFile));
    }

    $template = is_string($userConfig['welcomeMessage'] ?? null) && trim($userConfig['welcomeMessage']) !== ''
        ? $userConfig['welcomeMessage']
        : '';
    if ($template === '' && $productKey !== '') {
        $messageMap = pmssWelcomeProductMessageMap(pmssWelcomeReadJson($productMessagesPath));
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
