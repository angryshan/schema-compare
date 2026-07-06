<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Test;

use PHPUnit\Framework\TestCase;
use TxAdmin\SchemaCompare\AbstractSchemaDriver;
use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Contracts\SchemaStrategyInterface;
use TxAdmin\SchemaCompare\Exceptions\SchemaCompareException;

/**
 * AbstractSchemaDriver 单元测试
 */
class AbstractSchemaDriverTest extends TestCase
{
    /**
     * 测试跨库对比抛出异常
     */
    public function testCrossDatabaseComparisonThrowsException(): void
    {
        $adapter = $this->createMock(ConnectionAdapterInterface::class);

        $driver = new class($adapter) extends AbstractSchemaDriver {
            public function __construct($adapter)
            {
                parent::__construct($adapter, 'test');
            }

            protected function defaultStrategies(): array
            {
                return [];
            }

            // 暴露 protected 方法用于测试
            public function testExtractDatabaseFromBaseline(array $baseline): ?string
            {
                return $this->extractDatabaseFromBaseline($baseline);
            }
        };

        // 基准数据来自 db1
        $baseline = [
            'columns' => [
                ['table' => 'users', 'name' => 'id', 'database' => 'db1'],
            ],
        ];

        // 尝试对比 db2，应抛出异常
        $this->expectException(SchemaCompareException::class);
        $this->expectExceptionMessage("db1");
        $this->expectExceptionMessage("db2");

        $driver->compareFromArray($baseline, 'db2');
    }

    /**
     * 测试同库对比正常通过
     */
    public function testSameDatabaseComparisonSucceeds(): void
    {
        $adapter = $this->createMock(ConnectionAdapterInterface::class);
        $adapter->method('query')->willReturn([]);

        $driver = new class($adapter) extends AbstractSchemaDriver {
            public function __construct($adapter)
            {
                parent::__construct($adapter, 'test');
            }

            protected function defaultStrategies(): array
            {
                return [];
            }
        };

        // 基准数据来自 db1
        $baseline = [
            'columns' => [
                ['table' => 'users', 'name' => 'id', 'database' => 'db1'],
            ],
        ];

        // 对比 db1，应正常通过
        $result = $driver->compareFromArray($baseline, 'db1');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('has_diff', $result);
    }

    /**
     * 测试 extractDatabaseFromBaseline 多维度检查
     */
    public function testExtractDatabaseFromBaselineMultiDimension(): void
    {
        $adapter = $this->createMock(ConnectionAdapterInterface::class);

        $driver = new class($adapter) extends AbstractSchemaDriver {
            public function __construct($adapter)
            {
                parent::__construct($adapter, 'test');
            }

            protected function defaultStrategies(): array
            {
                return [];
            }

            public function testExtractDatabaseFromBaseline(array $baseline): ?string
            {
                return $this->extractDatabaseFromBaseline($baseline);
            }
        };

        // columns 维度为空，indexes 维度有数据
        $baseline = [
            'columns' => [],
            'indexes' => [
                ['table' => 'users', 'index_name' => 'PRIMARY', 'database' => 'mydb'],
            ],
        ];

        $dbName = $driver->testExtractDatabaseFromBaseline($baseline);

        $this->assertSame('mydb', $dbName);
    }

    /**
     * 测试 extractDatabaseFromBaseline 返回 null
     */
    public function testExtractDatabaseFromBaselineReturnsNull(): void
    {
        $adapter = $this->createMock(ConnectionAdapterInterface::class);

        $driver = new class($adapter) extends AbstractSchemaDriver {
            public function __construct($adapter)
            {
                parent::__construct($adapter, 'test');
            }

            protected function defaultStrategies(): array
            {
                return [];
            }

            public function testExtractDatabaseFromBaseline(array $baseline): ?string
            {
                return $this->extractDatabaseFromBaseline($baseline);
            }
        };

        // 没有 database 字段
        $baseline = [
            'columns' => [
                ['table' => 'users', 'name' => 'id'],
            ],
        ];

        $dbName = $driver->testExtractDatabaseFromBaseline($baseline);

        $this->assertNull($dbName);
    }

    /**
     * 测试 expectedBaselineDb 强制校验
     */
    public function testExpectedBaselineDbValidation(): void
    {
        $adapter = $this->createMock(ConnectionAdapterInterface::class);

        $driver = new class($adapter) extends AbstractSchemaDriver {
            public function __construct($adapter)
            {
                parent::__construct($adapter, 'test');
            }

            protected function defaultStrategies(): array
            {
                return [];
            }
        };

        // 基准数据来自 db1
        $baseline = [
            'columns' => [
                ['table' => 'users', 'name' => 'id', 'database' => 'db1'],
            ],
        ];

        // 强制期望 db2，应抛出异常（即使基准是 db1）
        $this->expectException(SchemaCompareException::class);
        $this->expectExceptionMessage("db2");

        $driver->compareFromArray($baseline, 'db1', 'db2');
    }
}
