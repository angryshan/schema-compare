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
                    default_kind`, `default_expression`, `comment`,
                    `compression_codec`,
                    `is_in_partition_key`, `is_in_sorting_key`,
                    `is_in_primary_key`, `is_in_sampling_key`
            FROM system.columns
            WHERE database = '{$database}'
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

        $baselineMap = $this->buildMap($baseline, $keyFn);
        $liveMap     = $this->buildMap($live, $keyFn);

        $onlyInBaseline = [];
        $onlyInLive     = [];
        $changed        = [];

        foreach ($baselineMap as $key => $bRow) {
            if (!isset($liveMap[$key])) {
                $onlyInBaseline[] = $key;
                continue;
            }
            $lRow  = $liveMap[$key];
            $diffs = [];
            foreach ($this->compareFields as $field) {
                $bVal = (string) ($bRow[$field] ?? '');
                $lVal = (string) ($lRow[$field] ?? '');
                if ($bVal !== $lVal) {
                    $diffs[$field] = ['baseline' => $bVal, 'live' => $lVal];
                }
            }
            if (!empty($diffs)) {
                $changed[$key] = $diffs;
            }
        }

        foreach ($liveMap as $key => $lRow) {
            if (!isset($baselineMap[$key])) {
                $onlyInLive[] = $key;
            }
        }

        // 按表归组
        $diffsByTable = [];
        foreach ($onlyInBaseline as $key) {
            [$table,] = explode('.', $key, 2);
            $diffsByTable[$table]['only_in_baseline'][] = $key;
        }
        foreach ($onlyInLive as $key) {
            [$table,] = explode('.', $key, 2);
            $diffsByTable[$table]['only_in_live'][] = $key;
        }
        foreach ($changed as $key => $diffs) {
            [$table,] = explode('.', $key, 2);
            $diffsByTable[$table]['field_changed'][$key] = $diffs;
        }

        $hasDiff = !empty($onlyInBaseline) || !empty($onlyInLive) || !empty($changed);

        return [
            'has_diff'      => $hasDiff,
            'summary'       => [
                'only_in_baseline' => count($onlyInBaseline),
                'only_in_live'     => count($onlyInLive),
                'field_changed'    => count($changed),
            ],
            'diffs_by_table' => $diffsByTable,
        ];
    }
}
