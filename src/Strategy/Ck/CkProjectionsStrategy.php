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
            WHERE database = '{$database}' AND `active` = 1
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

        $baselineMap = $this->buildMap($baseline, $keyFn);
        $liveMap = $this->buildMap($live, $keyFn);

        $onlyInBaseline = [];
        $onlyInLive = [];
        $changed = [];

        foreach ($baselineMap as $key => $bRow) {
            if (!isset($liveMap[$key])) {
                $onlyInBaseline[] = $key;
                continue;
            }
            $lRow = $liveMap[$key];
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

        // 按 "table.projection" 归组
        $diffsByProjection = [];
        foreach ($onlyInBaseline as $key) {
            $parts = explode('.', $key, 3);
            $projKey = $parts[0] . '.' . $parts[1];
            $diffsByProjection[$projKey]['only_in_baseline'][] = $key;
        }
        foreach ($onlyInLive as $key) {
            $parts = explode('.', $key, 3);
            $projKey = $parts[0] . '.' . $parts[1];
            $diffsByProjection[$projKey]['only_in_live'][] = $key;
        }
        foreach ($changed as $key => $diffs) {
            $parts = explode('.', $key, 3);
            $projKey = $parts[0] . '.' . $parts[1];
            $diffsByProjection[$projKey]['field_changed'][$key] = $diffs;
        }

        $hasDiff = !empty($onlyInBaseline) || !empty($onlyInLive) || !empty($changed);

        return [
            'has_diff' => $hasDiff,
            'summary' => [
                'only_in_baseline' => count($onlyInBaseline),
                'only_in_live' => count($onlyInLive),
                'field_changed' => count($changed),
            ],
            'diffs_by_projection' => $diffsByProjection,
        ];
    }
}
