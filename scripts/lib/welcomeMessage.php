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
    if (!is_file($path) || is_link($path) || !is_string($raw = @file_get_contents($path)) || trim($raw) === '') {
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
        if (is_string($userConfig[$candidateKey] ?? null) && ($value = trim($userConfig[$candidateKey])) !== '') {
            return $value;
        }
    }

    $productFile = rtrim($userHome, '/').'/.product';
    if (is_file($productFile) && !is_link($productFile) && ($value = trim((string) @file_get_contents($productFile))) !== '') {
        return $value;
    }

    return '';
}

/**
 * Select a welcome-message template with precedence:
 * user override -> product-level fallback -> none.
 */
function pmssWelcomeSelectTemplate(array $userConfig, string $productKey, string $productMessagesPath): string
{
    if (is_string($userConfig['welcomeMessage'] ?? null) && trim($userConfig['welcomeMessage']) !== '') {
        return $userConfig['welcomeMessage'];
    }

    if ($productKey === '') {
        return '';
    }

    $messageMap = pmssWelcomeReadJson($productMessagesPath);
    $messageMap = is_array($messageMap['products'] ?? null) ? $messageMap['products'] : $messageMap;

    if (is_string($messageMap[$productKey] ?? null)) {
        return $messageMap[$productKey];
    }

    $lowerProductKey = strtolower($productKey);
    foreach ($messageMap as $mapKey => $mapValue) {
        if (is_string($mapKey) && is_string($mapValue) && strtolower($mapKey) === $lowerProductKey) {
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
    if (is_numeric($quotaInfo['totalSpace'] ?? null) && ($quotaGiB = ((float) $quotaInfo['totalSpace']) / 1073741824) > 0) {
        $quota = round($quotaGiB, 1).' GiB';
    }

    return strtr($template, [
        '{{username}}' => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
        '{{quota}}'    => htmlspecialchars($quota, ENT_QUOTES, 'UTF-8'),
        '{{ramMiB}}'   => htmlspecialchars(is_numeric($userConfig['ramMiB'] ?? null) ? (string) ((int) $userConfig['ramMiB']) : '', ENT_QUOTES, 'UTF-8'),
        '{{product}}'  => htmlspecialchars($productKey, ENT_QUOTES, 'UTF-8'),
    ]);
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
    if (($template = pmssWelcomeSelectTemplate($userConfig, $productKey, $productMessagesPath)) === '') {
        return '';
    }

    return pmssWelcomeRenderTemplate($template, $quotaInfo, $userConfig, $username, $productKey);
}
