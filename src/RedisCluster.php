<?php

declare(strict_types=1);

namespace Boesing\Laminas\Cache\Storage\Adapter\RedisCluster;

use Laminas\Cache\Storage\Adapter\AbstractAdapter;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\ClearByNamespaceInterface;
use Laminas\Cache\Storage\ClearByPrefixInterface;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\EventManager\EventManagerInterface;
use Redis;
use RedisCluster as RedisClusterFromExtension;
use RedisClusterException;
use RedisException;
use stdClass;
use Traversable;

use function assert;
use function count;
use function version_compare;

final class RedisCluster extends AbstractAdapter implements
    ClearByNamespaceInterface,
    ClearByPrefixInterface,
    FlushableInterface
{
    /** @var RedisClusterFromExtension|null */
    private $resource;

    /** @var string|null */
    private $namespacePrefix;

    /**
     * @param null|array|Traversable|RedisClusterOptions $options
     * @psalm-param array<string,mixed>|RedisClusterOptions|Traversable<string,mixed> $options
     */
    public function __construct($options = null)
    {
        parent::__construct($options);
        $eventManager = $this->getEventManager();
        assert($eventManager instanceof EventManagerInterface);

        $eventManager->attach('option', function (): void {
            $this->resource         = null;
            $this->capabilities     = null;
            $this->capabilityMarker = null;
            $this->namespacePrefix  = null;
        });
    }

    /**
     * @param array|Traversable|RedisClusterOptions $options
     * @psalm-param RedisClusterOptions|array<string,mixed>|Traversable<string,mixed> $options
     * @return $this
     */
    public function setOptions($options)
    {
        if (! $options instanceof RedisClusterOptions) {
            $options = new RedisClusterOptions($options);
        }

        $options->setAdapter($this);

        parent::setOptions($options);
        return $this;
    }

    /**
     * In RedisCluster, it is totally okay if just one master is being flushed. If one master is not reachable, it will
     * re-sync if that master is coming back online.
     *
     * @inheritDoc
     */
    public function flush(): bool
    {
        $resource                     = $this->getRedisResource();
        $anyMasterSuccessfullyFlushed = false;
        $masters                      = $resource->_masters();

        foreach ($masters as [$host, $port]) {
            $redis = new Redis();
            try {
                $redis->connect($host, $port);
            } catch (RedisException $exception) {
                continue;
            }

            if (! $redis->flushDB()) {
                return false;
            }

            $anyMasterSuccessfullyFlushed = true;
        }

        return $anyMasterSuccessfullyFlushed;
    }

    private function getRedisResource(): RedisClusterFromExtension
    {
        if ($this->resource instanceof RedisClusterFromExtension) {
            return $this->resource;
        }

        $options         = $this->getOptions();
        $resourceManager = $options->getResourceManager();

        try {
            return $this->resource = $resourceManager->getResource();
        } catch (RedisClusterException $exception) {
            throw Exception\RuntimeException::connectionFailed($exception);
        }
    }

    public function getOptions(): RedisClusterOptions
    {
        /** @var RedisClusterOptions $options */
        $options = parent::getOptions();

        return $options;
    }

    /**
     * @param string $namespace
     */
    public function clearByNamespace($namespace): bool
    {
        $namespace = (string) $namespace;
        if ($namespace === '') {
            throw new Exception\InvalidArgumentException('Invalid namespace provided');
        }

        return $this->searchAndDelete('', $namespace);
    }

    /**
     * @param string $prefix
     */
    public function clearByPrefix($prefix): bool
    {
        $prefix = (string) $prefix;
        if ($prefix === '') {
            throw new Exception\InvalidArgumentException('No prefix given');
        }

        $options = $this->getOptions();

        return $this->searchAndDelete($prefix, $options->getNamespace());
    }

    /**
     * @param string     $normalizedKey
     * @param bool|null  $success
     * @param mixed|null $casToken
     * @return mixed|null
     */
    protected function internalGetItem(&$normalizedKey, &$success = null, &$casToken = null)
    {
        $redis         = $this->getRedisResource();
        $namespacedKey = $this->key($normalizedKey);
        try {
            $value = $redis->get($namespacedKey);

            if ($value === false && ! $this->internalSerializerUsed($redis, $namespacedKey)) {
                $success = false;

                return null;
            }
        } catch (RedisClusterException $exception) {
            throw $this->clusterException($exception, $redis);
        }

        $success  = true;
        $casToken = $value;

        return $value;
    }

    /**
     * @psalm-param list<string> $normalizedKeys
     * @psalm-return array<string,mixed>
     */
    protected function internalGetItems(array &$normalizedKeys): array
    {
        $namespacedKeys = [];
        foreach ($normalizedKeys as $normalizedKey) {
            $namespacedKeys[] = $this->key((string) $normalizedKey);
        }

        $redis = $this->getRedisResource();

        try {
            $resultsByIndex = $redis->mget($namespacedKeys);
        } catch (RedisClusterException $exception) {
            throw $this->clusterException($exception, $redis);
        }

        $result = [];
        foreach ($resultsByIndex as $normalizedKeyIndex => $value) {
            $normalizedKey = $normalizedKeys[$normalizedKeyIndex];
            if ($value === false && ! $this->internalSerializerUsed($redis, $normalizedKey)) {
                continue;
            }

            $result[$normalizedKey] = $value;
        }

        return $result;
    }

    private function key(string $key): string
    {
        if ($this->namespacePrefix !== null) {
            return $this->namespacePrefix . $key;
        }

        $options               = $this->getOptions();
        $namespace             = $options->getNamespace();
        $this->namespacePrefix = $namespace;
        if ($namespace !== '') {
            $this->namespacePrefix = $namespace . $options->getNamespaceSeparator();
        }

        return $this->namespacePrefix . $key;
    }

    /**
     * @param string $normalizedKey
     * @param mixed  $value
     */
    protected function internalSetItem(&$normalizedKey, &$value): bool
    {
        $redis   = $this->getRedisResource();
        $options = $this->getOptions();
        $ttl     = $options->getTtl();

        $namespacedKey = $this->key($normalizedKey);
        try {
            if ($ttl) {
                return $redis->setex($namespacedKey, $ttl, $value);
            }

            return $redis->set($namespacedKey, $value);
        } catch (RedisClusterException $exception) {
            throw $this->clusterException($exception, $redis);
        }
    }

    /**
     * @param string $normalizedKey
     */
    protected function internalRemoveItem(&$normalizedKey): bool
    {
        $redis = $this->getRedisResource();

        try {
            return $redis->del($this->key($normalizedKey)) === 1;
        } catch (RedisClusterException $exception) {
            throw $this->clusterException($exception, $redis);
        }
    }

    /**
     * @psalm-param list<string> $normalizedKeys
     * @psalm-return list<string>
     */
    protected function internalRemoveItems(array &$normalizedKeys): array
    {
        $namespacedKeys = [];
        foreach ($normalizedKeys as $normalizedKey) {
            $namespacedKeys[] = $this->key((string) $normalizedKey);
        }

        $redis = $this->getRedisResource();

        try {
            $deletionSuccessful = $redis->del($namespacedKeys) === count($namespacedKeys);
            if ($deletionSuccessful) {
                return [];
            }

            foreach ($namespacedKeys as $index => $namespacedKey) {
                if ($redis->exists($namespacedKey) === 0) {
                    unset($namespacedKeys[$index]);
                }
            }
        } catch (RedisClusterException $exception) {
            throw $this->clusterException($exception, $redis);
        }

        return $namespacedKeys;
    }

    /**
     * @param string $normalizedKey
     */
    protected function internalHasItem(&$normalizedKey): bool
    {
        $redis = $this->getRedisResource();

        try {
            return (bool) $redis->exists($this->key($normalizedKey));
        } catch (RedisClusterException $exception) {
            throw $this->clusterException($exception, $redis);
        }
    }

    /**
     * @psalm-param array<string,mixed> $normalizedKeyValuePairs
     * @psalm-return list<string>
     */
    protected function internalSetItems(array &$normalizedKeyValuePairs): array
    {
        $redis = $this->getRedisResource();
        $ttl   = (int) $this->getOptions()->getTtl();

        $namespacedKeyValuePairs = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            $namespacedKeyValuePairs[$this->key((string) $normalizedKey)] = $value;
        }

        $successByKey = [];

        try {
            foreach ($namespacedKeyValuePairs as $key => $value) {
                if ($ttl) {
                    $successByKey[$key] = $redis->setex($key, $ttl, $value);
                    continue;
                }

                $successByKey[$key] = $redis->set($key, $value);
            }
        } catch (RedisClusterException $exception) {
            throw $this->clusterException($exception, $redis);
        }

        $statuses = [];
        foreach ($successByKey as $key => $success) {
            if ($success) {
                continue;
            }

            $statuses[] = $key;
        }

        return $statuses;
    }

    /**
     * @inheritDoc
     */
    protected function internalGetCapabilities(): Capabilities
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }

        $this->capabilityMarker = new stdClass();
        $options                = $this->getOptions();
        $resourceManager        = $options->getResourceManager();
        $serializer             = $resourceManager->hasSerializationSupport($this);
        $redisVersion           = $resourceManager->getVersion();
        $redisVersionLessThanV2 = version_compare($redisVersion, '2.0', '<');
        $minTtl                 = $redisVersionLessThanV2 ? 0 : 1;
        $supportedMetadata      = ! $redisVersionLessThanV2 ? ['ttl'] : [];

        $this->capabilities = new Capabilities(
            $this,
            $this->capabilityMarker,
            [
                'supportedDatatypes' => $this->supportedDatatypes($serializer),
                'supportedMetadata'  => $supportedMetadata,
                'minTtl'             => $minTtl,
                'maxTtl'             => 0,
                'staticTtl'          => true,
                'ttlPrecision'       => 1,
                'useRequestTime'     => false,
                'maxKeyLength'       => 255,
                'namespaceIsPrefix'  => true,
            ]
        );

        return $this->capabilities;
    }

    /**
     * @psalm-return array<string,mixed>
     */
    private function supportedDatatypes(bool $serializer): array
    {
        if ($serializer) {
            return [
                'NULL'     => true,
                'boolean'  => true,
                'integer'  => true,
                'double'   => true,
                'string'   => true,
                'array'    => 'array',
                'object'   => 'object',
                'resource' => false,
            ];
        }

        return [
            'NULL'     => 'string',
            'boolean'  => 'string',
            'integer'  => 'string',
            'double'   => 'string',
            'string'   => true,
            'array'    => false,
            'object'   => false,
            'resource' => false,
        ];
    }

    private function getLibOption(int $option): int
    {
        $options         = $this->getOptions();
        $resourceManager = $options->getResourceManager();
        return $resourceManager->getLibOption($option);
    }

    private function searchAndDelete(string $prefix, string $namespace): bool
    {
        $redis   = $this->getRedisResource();
        $options = $this->getOptions();

        $prefix = $namespace === '' ? '' : $namespace . $options->getNamespaceSeparator() . $prefix;

        $keys = $redis->keys($prefix . '*');
        if (! $keys) {
            return true;
        }

        return $redis->del($keys) === count($keys);
    }

    private function clusterException(
        RedisClusterException $exception,
        RedisClusterFromExtension $redis
    ): Exception\RuntimeException {
        return Exception\RuntimeException::fromClusterException($exception, $redis);
    }

    private function internalSerializerUsed(RedisClusterFromExtension $redis, string $key): bool
    {
        $serializer = $this->getLibOption(RedisClusterFromExtension::OPT_SERIALIZER);
        if ($serializer === RedisClusterFromExtension::SERIALIZER_NONE) {
            return false;
        }

        try {
            return (bool) $redis->exists($key);
        } catch (RedisClusterException $exception) {
            throw $this->clusterException($exception, $redis);
        }
    }
}
