<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Strategy\Ck;

use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Strategy\AbstractStrategy;

/**
 * ClickHouse Projections 对比策略
 *
 * 基准和实时数据均为 system.projection_parts_columns 行数组
 * diff key: "{table}.{projection}.{name}"  (name = 字段名)
 */
class CkProjectionsStrategy extends AbstractStrategy
{
    public function getKey(): string
    {
        return 'projections';
    }

    public function getDefaultCompareFields(): array
    {
        return ['type'];
    }

    public function fetchData(ConnectionAdapterInterface $adapter, string $database): array
    {
        $sql = "
            SELECT DISTINCT
                `database`, `table`,
                splitByChar('.', name)[1] AS projection,
                `column` AS column_name,
                `type`
            FROM system.projection_parts_columns
            WHERE database = {$this->quoteStringLiteral($database)} AND `active` = 1
            ORDER BY `database`, `table`, projection, column_name
        ";
        return $adapter->query($sql);
    }

    /**
     * diff key: "{table}.{projection}.{column_name}"
     * 按投影归组输出
     */
    public function diff(array $baseline, array $live): array
    {
        $keyFn = static function (array $row): string {
            return $row['table'] . '.' . $row['projection'] . '.' . ($row['column_name'] ?? $row['name'] ?? '');
        };

        // 使用基类通用 diff 实现，但返回结构需要调整为 diffs_by_projection
        $result = $this->doDiff($baseline, $live, $keyFn, [$this, 'extractProjectionFromKey']);

        // 将 diffs_by_table 重命名为 diffs_by_projection
        return [
            'has_diff' => $result['has_diff'],
            'summary' => $result['summary'],
            'diffs_by_projection' => $result['diffs_by_table'],
        ];
    }
}
