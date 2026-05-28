<?php
/**
 * Validation utilities for user metadata.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/identity.php';

class UserValidator
{
    public const REQUIRED_FIELDS = ['ramMiB', 'rtorrentPort', 'quota', 'quotaBurst'];

    public static function isValidUsername($username): bool
    {
        if (!is_string($username)) {
            return false;
        }
        $normalized = pmssNormalizeUsername($username);
        return $normalized === $username
            && preg_match('/^[a-z0-9._-]+$/', $normalized) === 1;
    }

    public static function validatePayload(array $data): bool
    {
        // Back-compat: accept legacy rtorrentRam as the account RAM value.
        if (!array_key_exists('ramMiB', $data) && array_key_exists('rtorrentRam', $data)) {
            $data['ramMiB'] = $data['rtorrentRam'];
        }
        foreach (self::REQUIRED_FIELDS as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }
        return true;
    }

    public static function normalisedPayload(array $data): array
    {
        ksort($data);
        return $data;
    }
}
