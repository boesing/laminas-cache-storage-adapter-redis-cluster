<?php

declare(strict_types=1);

namespace Boesing\Laminas\Cache\Storage\Adapter\RedisCluster;

use Laminas\Cache\Storage\Adapter\AbstractAdapter;
use RedisCluster as RedisClusterFromExtension;

interface RedisClusterResourceManagerInterface
{
    public function getVersion(): string;

    /**
     * @inheritDoc
     */
    public function getResource(): RedisClusterFromExtension;

    public function getLibOption(int $option): int;

    public function hasSerializationSupport(AbstractAdapter $adapter): bool;
}
