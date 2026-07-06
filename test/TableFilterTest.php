<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Test;

use PHPUnit\Framework\TestCase;
use TxAdmin\SchemaCompare\Processor\TableFilter;

/**
 * TableFilter 单元测试
 */
class TableFilterTest extends TestCase
{
    private TableFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new TableFilter();
    }

    /**
     * 测试空排除列表不过滤
     */
    public function testEmptyExcludedTables(): void
    {
        $structure = [
            'columns' => [
                ['table' => 'users', 'name' => 'id'],
                ['table' => 'orders', 'name' => 'id'],
            ],
        ];

        $result = $this->filter->process($structure);

        $this->assertCount(2, $result['columns']);
    }

    /**
     * 测试按表名过滤
     */
    public function testFilterByTableName(): void
    {
        $this->filter->setExcludedTables(['orders']);

        $structure = [
            'columns' => [
                ['table' => 'users', 'name' => 'id'],
                ['table' => 'orders', 'name' => 'id'],
                ['table' => 'users', 'name' => 'name'],
            ],
        ];

        $result = $this->filter->process($structure);

        $this->assertCount(2, $result['columns']);
        $this->assertSame('users', $result['columns'][0]['table']);
        $this->assertSame('users', $result['columns'][1]['table']);
    }

    /**
     * 测试支持多种表名字段
     */
    public function testSupportMultipleTableNameFields(): void
    {
        $this->filter->setExcludedTables(['logs']);

        // 使用 'table' 别名
        $structure1 = [
            'columns' => [
                ['table' => 'users', 'name' => 'id'],
                ['table' => 'logs', 'name' => 'id'],
            ],
        ];
        $result1 = $this->filter->process($structure1);
        $this->assertCount(1, $result1['columns']);

        // 使用 'table_name' 字段
        $structure2 = [
            'columns' => [
                ['table_name' => 'users', 'name' => 'id'],
                ['table_name' => 'logs', 'name' => 'id'],
            ],
        ];
        $result2 = $this->filter->process($structure2);
        $this->assertCount(1, $result2['columns']);

        // 使用 'TABLE_NAME' 字段
        $structure3 = [
            'columns' => [
                ['TABLE_NAME' => 'users', 'name' => 'id'],
                ['TABLE_NAME' => 'logs', 'name' => 'id'],
            ],
        ];
        $result3 = $this->filter->process($structure3);
        $this->assertCount(1, $result3['columns']);
    }

    /**
     * 测试空表名不过滤
     */
    public function testEmptyTableNameNotFiltered(): void
    {
        $this->filter->setExcludedTables(['orders']);

        $structure = [
            'columns' => [
                ['table' => '', 'name' => 'id'],
                ['table' => 'orders', 'name' => 'id'],
            ],
        ];

        $result = $this->filter->process($structure);

        $this->assertCount(1, $result['columns']);
        $this->assertSame('', $result['columns'][0]['table']);
    }

    /**
     * 测试多维度结构
     */
    public function testMultiDimensionStructure(): void
    {
        $this->filter->setExcludedTables(['temp_table']);

        $structure = [
            'columns' => [
                ['table' => 'users', 'name' => 'id'],
                ['table' => 'temp_table', 'name' => 'id'],
            ],
            'indexes' => [
                ['table' => 'users', 'index_name' => 'PRIMARY'],
                ['table' => 'temp_table', 'index_name' => 'idx_temp'],
            ],
        ];

        $result = $this->filter->process($structure);

        $this->assertCount(1, $result['columns']);
        $this->assertCount(1, $result['indexes']);
        $this->assertSame('users', $result['columns'][0]['table']);
        $this->assertSame('users', $result['indexes'][0]['table']);
    }

    /**
     * 测试链式调用
     */
    public function testFluentInterface(): void
    {
        $result = $this->filter
            ->setExcludedTables(['orders'])
            ->process([
                'columns' => [
                    ['table' => 'users', 'name' => 'id'],
                    ['table' => 'orders', 'name' => 'id'],
                ],
            ]);

        $this->assertCount(1, $result['columns']);
    }
}
