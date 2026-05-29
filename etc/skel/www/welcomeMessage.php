<?php
/**
 * Contextual welcome-page message helpers.
 *
 * Provides per-user override support and product-level fallback messages for
 * `etc/skel/www/welcome.php` while keeping lookup logic testable.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/../.scriptsInc.php';

/**
 * Read a JSON file into an associative array.
 */
function pmssWelcomeReadJson(string $path): array
{
    if ($path === '' || !pmssWelcomeMessageCustomerPathIsSafe($path) || !is_file($path) || is_link($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    return is_array($decoded = json_decode($raw, true)) ? $decoded : [];
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
    if (!pmssWelcomeMessageCustomerPathIsSafe($path) || !is_file($path) || is_link($path)) {
        return '';
    }

    $content = @file_get_contents($path);
    return (is_string($content) && trim($content) !== '') ? $content : '';
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
        if (pmssWelcomeMessageCustomerPathIsSafe($productFile)) {
            $productKey = trim((string) @file_get_contents($productFile));
        }
    }

    $template = pmssWelcomeUserMessageRead($userHome);
    if ($template === '' && is_string($userConfig['welcomeMessage'] ?? null) && trim($userConfig['welcomeMessage']) !== '') {
        $template = $userConfig['welcomeMessage'];
    }
    if ($template === '' && $productKey !== '') {
        $messageRootMap = pmssWelcomeReadJson($productMessagesPath);
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
