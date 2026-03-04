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
 * Persist a JSON payload atomically while rejecting symlink targets.
 */
function pmssWelcomeWriteJsonAtomic(string $path, array $payload): bool
{
    if (strpos($path, "\0") !== false) {
        return false;
    }

    $directoryPath = dirname($path);
    if (!is_dir($directoryPath) || is_link($directoryPath)) {
        return false;
    }

    if (file_exists($path) && (!is_file($path) || is_link($path))) {
        return false;
    }

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return false;
    }

    $temporaryPath = @tempnam($directoryPath, basename($path).'.pmss-tmp-');
    if ($temporaryPath === false) {
        return false;
    }

    if (@file_put_contents($temporaryPath, $encoded.PHP_EOL, LOCK_EX) === false) {
        @unlink($temporaryPath);
        return false;
    }

    @chmod($temporaryPath, 0640);
    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        return false;
    }

    @chmod($path, 0640);
    return true;
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
    $useNestedProductsMap = isset($rootMap['products']) && is_array($rootMap['products']);
    $productMap = $useNestedProductsMap ? $rootMap['products'] : $rootMap;

    if (trim($template) === '') {
        unset($productMap[$normalizedProductKey]);
    } else {
        $productMap[$normalizedProductKey] = $template;
    }
    ksort($productMap, SORT_STRING);

    if ($useNestedProductsMap) {
        $rootMap['products'] = $productMap;
    } else {
        $rootMap = $productMap;
    }

    return pmssWelcomeWriteJsonAtomic($productMessagesPath, $rootMap);
}

