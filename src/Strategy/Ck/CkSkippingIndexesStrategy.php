<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Strategy\Ck;

use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Strategy\AbstractStrategy;

/**
 * ClickHouse Data Skipping Indexes（跳数索引）对比策略
 *
 * 对比 system.data_skipping_indices 中的跳数索引定义
 * diff key: "{table}.{index_name}"
 *
 * 跳数索引用于加速查询，常见类型：
 * - minmax: 存储列的最小/最大值
 * - set: 存储列的唯一值集合（最多 n 个）
 * - bloom_filter: 布隆过滤器，用于等值查询
 * - tokenbf_v1: 分词布隆过滤器，用于 LIKE 查询
 * - ngrambf_v1: n-gram 布隆过滤器
 */
class CkSkippingIndexesStrategy extends AbstractStrategy
{
    public function getKey(): string
    {
        return 'skipping_indexes';
    }

    public function getDefaultCompareFields(): array
    {
        return [
            'type',           // 索引类型（minmax/set/bloom_filter/tokenbf_v1/ngrambf_v1 等）
            'expr',           // 索引表达式
            'granularity',    // 粒度（每多少个 granule 创建一个索引条目）
        ];
    }

    public function fetchData(ConnectionAdapterInterface $adapter, string $database): array
    {
        $sql = "
            SELECT
                `database`,
                `table`,
                `name` AS index_name,
                `type`,
                `expr`,
                `granularity`
            FROM system.data_skipping_indices
            WHERE database = {$this->quoteStringLiteral($database)}
            ORDER BY `database`, `table`, `name`
        ";
        return $adapter->query($sql);
    }

    /**
     * diff key: "{table}.{index_name}"
     * 按表归组输出
     */
    public function diff(array $baseline, array $live): array
    {
        $keyFn = static function (array $row): string {
            return $row['table'] . '.' . $row['index_name'];
        };

        // 使用基类通用 diff 实现
        return $this->doDiff($baseline, $live, $keyFn, [$this, 'extractTableFromKey']);
    }
}
