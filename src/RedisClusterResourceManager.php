<?php

declare(strict_types=1);

namespace Boesing\Laminas\Cache\Storage\Adapter\RedisCluster;

use Boesing\Laminas\Cache\Storage\Adapter\RedisCluster\Exception\RuntimeException;
use Laminas\Cache\Storage\Adapter\AbstractAdapter;
use Laminas\Cache\Storage\Plugin\PluginInterface;
use Laminas\Cache\Storage\Plugin\Serializer;
use RedisCluster as RedisClusterFromExtension;
use RedisClusterException;
use ReflectionClass;

use function strpos;

final class RedisClusterResourceManager implements RedisClusterResourceManagerInterface
{
    /** @var RedisClusterOptions */
    private $options;

    /** @psalm-var array<int,int> */
    private $libraryOptions;

    public function __construct(RedisClusterOptions $options)
    {
        $this->options = $options;
    }

    public function getVersion(): string
    {
        $versionFromOptions = $this->options->redisVersion();
        if ($versionFromOptions) {
            return $versionFromOptions;
        }

        $resource = $this->getResource();
        try {
            /**
             * @psalm-var array<string,mixed> $info
             */
            $info = $resource->info($this->options->nodename() ?: $this->options->seeds());
        } catch (RedisClusterException $exception) {
            throw RuntimeException::fromClusterException($exception, $resource);
        }

        $version = $info['redis_version'];
        $this->options->setRedisVersion($version);

        return $version;
    }

    /**
     * @inheritDoc
     */
    public function getResource(): RedisClusterFromExtension
    {
        try {
            $resource = $this->createRedisResource($this->options);
        } catch (RedisClusterException $exception) {
            throw RuntimeException::connectionFailed($exception);
        }

        $libraryOptions = $this->options->libOptions();

        try {
            $resource             = $this->applyLibraryOptions($resource, $libraryOptions);
            $this->libraryOptions = $this->mergeLibraryOptionsFromCluster($libraryOptions, $resource);
        } catch (RedisClusterException $exception) {
            throw RuntimeException::fromClusterException($exception, $resource);
        }

        return $resource;
    }

    private function createRedisResource(RedisClusterOptions $options): RedisClusterFromExtension
    {
        if ($options->hasNodename()) {
            return $this->createRedisResourceFromNodename(
                $options->nodename(),
                $options->timeout(),
                $options->readTimeout(),
                $options->persistent()
            );
        }

        return new RedisClusterFromExtension(
            null,
            $options->seeds(),
            $options->timeout(),
            $options->readTimeout(),
            $options->persistent()
        );
    }

    private function createRedisResourceFromNodename(
        string $nodename,
        float $fallbackTimeout,
        float $fallbackReadTimeout,
        bool $persistent
    ): RedisClusterFromExtension {
        $options     = new RedisClusterOptionsFromIni();
        $seeds       = $options->seeds($nodename);
        $timeout     = $options->timeout($nodename, $fallbackTimeout);
        $readTimeout = $options->readTimeout($nodename, $fallbackReadTimeout);

        return new RedisClusterFromExtension(null, $seeds, $timeout, $readTimeout, $persistent);
    }

    private function applyLibraryOptions(
        RedisClusterFromExtension $resource,
        array $options
    ): RedisClusterFromExtension {
        foreach ($options as $option => $value) {
            $resource->setOption($option, (string) $value);
        }

        return $resource;
    }

    private function mergeLibraryOptionsFromCluster(array $options, RedisClusterFromExtension $resource): array
    {
        $reflection = new ReflectionClass(RedisClusterFromExtension::class);

        foreach ($reflection->getConstants() as $constant => $constantValue) {
            if (strpos($constant, 'OPT_') !== 0 || isset($options[$constantValue])) {
                continue;
            }

            $options[$constantValue] = $resource->getOption($constantValue);
        }

        return $options;
    }

    public function getLibOption(int $option): int
    {
        return $this->libraryOptions[$option] ?? $this->getResource()->getOption($option);
    }

    public function hasSerializationSupport(AbstractAdapter $adapter): bool
    {
        $options        = $this->options;
        $libraryOptions = $options->libOptions();
        $serializer     = $libraryOptions[RedisClusterFromExtension::OPT_SERIALIZER] ??
            RedisClusterFromExtension::SERIALIZER_NONE;

        if ($serializer !== RedisClusterFromExtension::SERIALIZER_NONE) {
            return true;
        }

        /** @var PluginInterface[] $plugins */
        $plugins = $adapter->getPluginRegistry();
        foreach ($plugins as $plugin) {
            if (! $plugin instanceof Serializer) {
                continue;
            }

            return true;
        }

        return false;
    }
}
