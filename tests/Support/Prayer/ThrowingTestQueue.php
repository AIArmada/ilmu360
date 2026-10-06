<?php

declare(strict_types=1);

namespace Tests\Support\Prayer;

use Illuminate\Contracts\Queue\Queue;
use RuntimeException;

class ThrowingTestQueue implements Queue
{
    public function setContainer($container)
    {
        return $this;
    }

    public function size($queue = null)
    {
        throw new RuntimeException('queue transport down');
    }

    public function pendingSize($queue = null)
    {
        throw new RuntimeException('queue transport down');
    }

    public function delayedSize($queue = null)
    {
        throw new RuntimeException('queue transport down');
    }

    public function reservedSize($queue = null)
    {
        throw new RuntimeException('queue transport down');
    }

    public function creationTimeOfOldestPendingJob($queue = null)
    {
        throw new RuntimeException('queue transport down');
    }

    public function push($job, $data = '', $queue = null)
    {
        throw new RuntimeException('queue transport down');
    }

    public function pushOn($queue, $job, $data = '')
    {
        throw new RuntimeException('queue transport down');
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        throw new RuntimeException('queue transport down');
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        throw new RuntimeException('queue transport down');
    }

    public function laterOn($queue, $delay, $job, $data = '')
    {
        throw new RuntimeException('queue transport down');
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        throw new RuntimeException('queue transport down');
    }

    public function pop($queue = null)
    {
        throw new RuntimeException('queue transport down');
    }

    public function getConnectionName()
    {
        return 'throwing';
    }

    public function setConnectionName($name)
    {
        return $this;
    }
}
