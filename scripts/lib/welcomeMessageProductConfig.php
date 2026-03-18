<?php
/**
 * Product-level welcome message configuration helpers.
 *
 * Stores product message templates in `/etc/seedbox/config/welcomeMessages.json`.
 * The file may either be a plain `{product: template}` map or a root object
 * with a nested `products` map.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/welcomeMessage.php';

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
    $productMap = is_array($rootMap['products'] ?? null) ? $rootMap['products'] : $rootMap;

    if (trim($template) === '') {
        unset($productMap[$normalizedProductKey]);
    } else {
        $productMap[$normalizedProductKey] = $template;
    }
    ksort($productMap, SORT_STRING);

    if (is_array($rootMap['products'] ?? null)) {
        $rootMap['products'] = $productMap;
    } else {
        $rootMap = $productMap;
    }

    $directoryPath = dirname($productMessagesPath);
    if (
        strpos($productMessagesPath, "\0") !== false
        || !is_dir($directoryPath)
        || is_link($directoryPath)
        || (file_exists($productMessagesPath) && (!is_file($productMessagesPath) || is_link($productMessagesPath)))
    ) {
        return false;
    }

    if (!is_string($encoded = json_encode($rootMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        return false;
    }

    $temporaryPath = @tempnam($directoryPath, basename($productMessagesPath).'.pmss-tmp-');
    if ($temporaryPath === false) {
        return false;
    }

    if (@file_put_contents($temporaryPath, $encoded.PHP_EOL, LOCK_EX) === false) {
        @unlink($temporaryPath);
        return false;
    }

    @chmod($temporaryPath, 0640);
    if (!@rename($temporaryPath, $productMessagesPath)) {
        @unlink($temporaryPath);
        return false;
    }

    @chmod($productMessagesPath, 0640);
    return true;
}
