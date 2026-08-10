<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Strategy\Ck;

use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Strategy\AbstractStrategy;

/**
 * ClickHouse Columns 对比策略
 *
 * 基准和实时数据均为 system.columns 行数组（已过滤 database 字段）
 * diff key: "{table}.{name}"
 */
class CkColumnsStrategy extends AbstractStrategy
{
    public function getKey(): string
    {
        return 'columns';
    }

    public function getDefaultCompareFields(): array
    {
        return [
            'type',                 // 类型（含长度，如 Decimal(18,2)）
            'default_kind',         // 默认值类型（DEFAULT / MATERIALIZED / ALIAS）
            'default_expression',   // 默认值表达式
            'comment',              // 备注注释
            'compression_codec',    // 压缩编码
            'is_in_partition_key',  // 是否在分区键中
            'is_in_sorting_key',    // 是否在排序键中
            'is_in_primary_key',    // 是否在主键中
            'is_in_sampling_key',   // 是否在采样键中
        ];
    }

    public function fetchData(ConnectionAdapterInterface $adapter, string $database): array
    {
        $sql = "
            SELECT
                `database`, `table`, `name`, `type`,
                    `default_kind`, `default_expression`, `comment`,
                    `compression_codec`,
                    `is_in_partition_key`, `is_in_sorting_key`,
                    `is_in_primary_key`, `is_in_sampling_key`
            FROM system.columns
            WHERE database = {$this->quoteStringLiteral($database)}
            ORDER BY `database`, `table`, `position`
        ";
        return $adapter->query($sql);
    }

    /**
     * diff key: "{table}.{name}"
     * 按表归组输出
     */
    public function diff(array $baseline, array $live): array
    {
        $keyFn = static function (array $row): string {
            return $row['table'] . '.' . $row['name'];
        };

        // 使用基类通用 diff 实现
        return $this->doDiff($baseline, $live, $keyFn, [$this, 'extractTableFromKey']);
    }
}
