<?php

declare(strict_types=1);

namespace Boesing\Laminas\Cache\Storage\Adapter\RedisClusterIntegration\CacheItemPool;

use Boesing\Laminas\Cache\Storage\Adapter\RedisClusterIntegration\RedisClusterStorageCreationTrait;
use Cache\IntegrationTests\CachePoolTest;
use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
use Psr\Cache\CacheItemPoolInterface;
use RedisCluster;

use function get_class;
use function sprintf;

final class RedisClusterWithoutSerializerTest extends CachePoolTest
{
    use RedisClusterStorageCreationTrait;

    /**
     * @return CacheItemPoolInterface that is used in the tests
     */
    public function createCachePool()
    {
        $storage = $this->createRedisClusterStorage(RedisCluster::SERIALIZER_NONE, true);
        $this->skippedTests['testHasItemReturnsFalseWhenDeferredItemIsExpired'] = sprintf(
            '%s storage doesn\'t support driver deferred',
            get_class($storage)
        );

        return new CacheItemPoolDecorator($storage);
    }
}
