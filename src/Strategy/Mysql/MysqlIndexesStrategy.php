<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Strategy\Mysql;

use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Strategy\AbstractStrategy;

/**
 * MySQL Indexes 对比策略
 *
 * 基准和实时数据均为 information_schema.STATISTICS 行数组
 * 联合索引每个列一行，通过 SEQ_IN_INDEX 区分位置
 * diff key: "{table}.{index_name}.{seq_in_index}"
 *
 * 默认排除分表（按月表 _yymm / 哈希分表 _000 等），只对比基础表
 */
class MysqlIndexesStrategy extends AbstractStrategy
{
    /**
     * 是否排除分表（表名以 _数字 结尾的表）
     * @var bool
     */
    public bool $excludeSplitTables = true;

    public function getKey(): string
    {
        return 'indexes';
    }

    public function getDefaultCompareFields(): array
    {
        return [
            'column_name',   // 索引列名
            'non_unique',    // 是否非唯一（1=普通索引，0=唯一索引）
            'index_type',    // 索引类型（BTREE / FULLTEXT / HASH）
            'collation',     // 排序方向（A=升序，D=降序，NULL=无序）
            'sub_part',      // 前缀索引长度（NULL 表示整列索引）
        ];
    }

    public function fetchData(ConnectionAdapterInterface $adapter, string $database): array
    {
        $sql = "
            SELECT
                TABLE_SCHEMA AS `database`,
                TABLE_NAME AS `table`,
                INDEX_NAME AS `index_name`,
                SEQ_IN_INDEX AS `seq_in_index`,
                COLUMN_NAME AS `column_name`,
                NON_UNIQUE AS `non_unique`,
                INDEX_TYPE AS `index_type`,
                COLLATION AS `collation`,
                SUB_PART AS `sub_part`
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = {$this->quoteStringLiteral($database)}
            {$this->splitTableFilter()}
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
        ";
        return $adapter->query($sql);
    }

    /**
     * diff key: "{table}.{index_name}.{seq_in_index}"
     * 按索引归组输出
     */
    public function diff(array $baseline, array $live): array
    {
        $keyFn = static function (array $row): string {
            return $row['table'] . '.' . $row['index_name'] . '.' . (string) $row['seq_in_index'];
        };

        // 使用基类通用 diff 实现，但返回结构需要调整为 diffs_by_index
        $result = $this->doDiff($baseline, $live, $keyFn, [$this, 'extractIndexFromKey']);

        // 将 diffs_by_table 重命名为 diffs_by_index
        return [
            'has_diff' => $result['has_diff'],
            'summary' => $result['summary'],
            'diffs_by_index' => $result['diffs_by_table'],
        ];
    }

    /**
     * 生成分表过滤 SQL 片段
     * 匹配 table-splitter 的所有分表后缀：_yymm / _YYYYWW / _YYYY / _000 / _0
     */
    protected function splitTableFilter(): string
    {
        return $this->excludeSplitTables
            ? "AND TABLE_NAME NOT REGEXP '_[0-9]+$'"
            : '';
    }
}
