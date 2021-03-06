<?php

declare(strict_types=1);

namespace Boesing\Laminas\Cache\Storage\Adapter\RedisClusterTest;

use Boesing\Laminas\Cache\Storage\Adapter\RedisCluster\Exception\InvalidConfigurationException;
use Boesing\Laminas\Cache\Storage\Adapter\RedisCluster\RedisClusterOptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RedisClusterOptionsTest extends TestCase
{
    /**
     * @test
     */
    public function canHandleOptionsWithNodename()
    {
        $options = new RedisClusterOptions([
            'nodename'      => 'foo',
            'timeout'       => 1.0,
            'read_timeout'  => 2.0,
            'persistent'    => false,
            'redis_version' => '1.0',
        ]);

        $this->assertEquals($options->nodename(), 'foo');
        $this->assertEquals($options->timeout(), 1.0);
        $this->assertEquals($options->readTimeout(), 2.0);
        $this->assertEquals($options->persistent(), false);
        $this->assertEquals($options->redisVersion(), '1.0');
    }

    /**
     * @test
     */
    public function canHandleOptionsWithSeeds()
    {
        $options = new RedisClusterOptions([
            'seeds'         => ['localhost:1234'],
            'timeout'       => 1.0,
            'read_timeout'  => 2.0,
            'persistent'    => false,
            'redis_version' => '1.0',
        ]);

        $this->assertEquals($options->seeds(), ['localhost:1234']);
        $this->assertEquals($options->timeout(), 1.0);
        $this->assertEquals($options->readTimeout(), 2.0);
        $this->assertEquals($options->persistent(), false);
        $this->assertEquals($options->redisVersion(), '1.0');
    }

    /**
     * @test
     */
    public function willDetectSeedsAndNodenameConfiguration()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Please provide either `nodename` or `seeds` configuration, not both.');
        new RedisClusterOptions([
            'seeds'    => ['localhost:1234'],
            'nodename' => 'foo',
        ]);
    }

    /**
     * @test
     */
    public function willValidateVersionFormat()
    {
        $this->expectException(InvalidArgumentException::class);
        new RedisClusterOptions([
            'redis_version' => 'foo',
        ]);
    }

    /**
     * @test
     */
    public function willValidateEmptyVersion()
    {
        $this->expectException(InvalidArgumentException::class);
        new RedisClusterOptions([
            'redis_version' => '',
        ]);
    }

    public function willDetectMissingRequiredValues()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Missing either `nodename` or `seeds`.');
        new RedisClusterOptions();
    }
}
