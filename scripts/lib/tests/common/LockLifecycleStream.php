<?php
namespace PMSS\Tests;

/** In-memory lock stream that exposes cleanup after native-boundary exceptions. */
class LockLifecycleStream
{
    public $context;
    public static $failure;
    public static $throwable;
    public static $last;
    public $closed = false;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        self::$last = $this;
        return true;
    }

    public function url_stat($path, $flags): array
    {
        return ['mode' => 0100600, 'dev' => 1, 'ino' => 1];
    }

    public function stream_stat(): array
    {
        if (self::$failure === 'stat') throw self::$throwable;
        return $this->url_stat('', 0);
    }

    public function stream_lock($operation): bool
    {
        $phase = $operation === LOCK_UN ? 'unlock' : 'lock';
        if (self::$failure === $phase) throw self::$throwable;
        return true;
    }

    public function stream_close(): void
    {
        $this->closed = true;
    }
}
