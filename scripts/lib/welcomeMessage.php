<?php
/**
 * Contextual welcome-page message helpers.
 *
 * Provides per-user override support and product-level fallback messages for
 * `etc/skel/www/welcome.php` while keeping lookup logic testable.
 *
 * @license GPL-3.0-only
 */

/**
 * Read a JSON file into an associative array.
 */
function pmssWelcomeReadJson(string $path): array
{
    if (!is_file($path) || is_link($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Resolve the product identifier for the current user.
 */
function pmssWelcomeResolveProductKey(array $userConfig, string $userHome): string
{
    foreach (['product', 'productName'] as $candidateKey) {
        if (!isset($userConfig[$candidateKey]) || !is_string($userConfig[$candidateKey])) {
            continue;
        }
        $value = trim($userConfig[$candidateKey]);
        if ($value !== '') {
            return $value;
        }
    }

    $productFile = rtrim($userHome, '/').'/.product';
    if (is_file($productFile) && !is_link($productFile)) {
        $value = trim((string) @file_get_contents($productFile));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * Select a welcome-message template with precedence:
 * user override -> product-level fallback -> none.
 */
function pmssWelcomeSelectTemplate(array $userConfig, string $productKey, string $productMessagesPath): string
{
    if (isset($userConfig['welcomeMessage']) && is_string($userConfig['welcomeMessage'])) {
        $value = trim($userConfig['welcomeMessage']);
        if ($value !== '') {
            return $userConfig['welcomeMessage'];
        }
    }

    if ($productKey === '') {
        return '';
    }

    $messageMap = pmssWelcomeReadJson($productMessagesPath);
    if (isset($messageMap['products']) && is_array($messageMap['products'])) {
        $messageMap = $messageMap['products'];
    }

    if (isset($messageMap[$productKey]) && is_string($messageMap[$productKey])) {
        return $messageMap[$productKey];
    }

    $lowerProductKey = strtolower($productKey);
    foreach ($messageMap as $mapKey => $mapValue) {
        if (!is_string($mapKey) || !is_string($mapValue)) {
            continue;
        }
        if (strtolower($mapKey) === $lowerProductKey) {
            return $mapValue;
        }
    }

    return '';
}

/**
 * Render a template using safe substitutions for known variables.
 */
function pmssWelcomeRenderTemplate(string $template, array $quotaInfo, array $userConfig, string $username, string $productKey): string
{
    $quota = '';
    if (isset($quotaInfo['totalSpace']) && is_numeric($quotaInfo['totalSpace'])) {
        $quotaGiB = ((float) $quotaInfo['totalSpace']) / 1073741824;
        if ($quotaGiB > 0) {
            $quota = round($quotaGiB, 1).' GiB';
        }
    }

    $ramMiB = '';
    if (isset($userConfig['ramMiB']) && is_numeric($userConfig['ramMiB'])) {
        $ramMiB = (string) ((int) $userConfig['ramMiB']);
    }

    $substitutions = [
        '{{username}}' => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
        '{{quota}}'    => htmlspecialchars($quota, ENT_QUOTES, 'UTF-8'),
        '{{ramMiB}}'   => htmlspecialchars($ramMiB, ENT_QUOTES, 'UTF-8'),
        '{{product}}'  => htmlspecialchars($productKey, ENT_QUOTES, 'UTF-8'),
    ];

    return strtr($template, $substitutions);
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
    $userConfig = pmssWelcomeReadJson(rtrim($userHome, '/').'/.config/pmss-user.json');
    $productKey = pmssWelcomeResolveProductKey($userConfig, $userHome);
    $template = pmssWelcomeSelectTemplate($userConfig, $productKey, $productMessagesPath);
    if ($template === '') {
        return '';
    }

    return pmssWelcomeRenderTemplate($template, $quotaInfo, $userConfig, $username, $productKey);
}
