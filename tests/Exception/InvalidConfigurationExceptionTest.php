<?php

declare(strict_types=1);

namespace Boesing\Laminas\Cache\Storage\Adapter\RedisClusterTest;

use Boesing\Laminas\Cache\Storage\Adapter\RedisCluster\Exception\InvalidConfigurationException;
use Laminas\Cache\Exception\ExceptionInterface;
use PHPUnit\Framework\TestCase;

final class InvalidConfigurationExceptionTest extends TestCase
{
    /**
     * @test
     */
    public function instanceOfLaminasCacheException()
    {
        $exception = new InvalidConfigurationException();
        $this->assertInstanceOf(ExceptionInterface::class, $exception);
    }
}
