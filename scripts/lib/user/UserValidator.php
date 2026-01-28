<?php
/**
 * Validation utilities for user metadata.
 */

class UserValidator
{
    public const REQUIRED_FIELDS = ['ramMiB', 'rtorrentPort', 'quota', 'quotaBurst'];

    public static function isValidUsername($username): bool
    {
        return is_string($username) && preg_match('/^[a-zA-Z0-9._-]+$/', $username) === 1;
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
