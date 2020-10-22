<?php

declare(strict_types=1);

namespace Boesing\Laminas\Cache\Storage\Adapter\RedisCluster\Exception;

use Laminas\Cache\Exception\RuntimeException as LaminasCacheRuntimeException;
use RedisCluster;
use RedisClusterException;
use Throwable;

final class RuntimeException extends LaminasCacheRuntimeException
{
    public static function fromClusterException(RedisClusterException $exception, RedisCluster $redis): self
    {
        $message = $redis->getLastError() ?? $exception->getMessage();

        return new self($message, $exception->getCode(), $exception);
    }

    public static function connectionFailed(Throwable $exception): self
    {
        return new self('Could not establish connection to redis cluster', $exception->getCode(), $exception);
    }
}
