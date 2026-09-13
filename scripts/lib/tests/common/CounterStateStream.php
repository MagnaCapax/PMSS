<?php
namespace PMSS\Tests;

/** In-memory stream fixture for counter-state I/O failures, without disk mutation. */
class CounterStateStream
{
    public $context;
    public $failure = '';
    public $events = [];
    public $contents = 'previous state';

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        $this->failure = substr($path, strpos($path, '://') + 3);
        return true;
    }

    public function stream_seek($offset, $whence): bool
    {
        $this->events[] = 'seek';
        return $this->failure !== 'seek';
    }

    public function stream_tell(): int
    {
        return 0;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_truncate($size): bool
    {
        $this->events[] = 'truncate';
        if ($this->failure === 'truncate') {
            return false;
        }
        $this->contents = '';
        return true;
    }

    public function stream_write($data)
    {
        $this->events[] = 'write';
        if ($this->failure === 'write') {
            return false;
        }
        // PHP may retry a short wrapper write; the next attempt makes no progress.
        $length = $this->failure === 'short' ? ($this->contents === '' ? 1 : 0) : strlen($data);
        $this->contents .= substr($data, 0, $length);
        return $length;
    }

    public function stream_flush(): bool
    {
        $this->events[] = 'flush';
        return $this->failure !== 'flush';
    }
}
