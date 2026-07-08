<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Strategy\Ck;

use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Strategy\AbstractStrategy;

/**
 * ClickHouse Indexes 对比策略
 *
 * 对比 system.tables 中每张表的分区/排序/主键/采样键
 * diff key: "{table}"
 */
class CkIndexesStrategy extends AbstractStrategy
{
    public function getKey(): string
    {
        return 'indexes';
    }

    public function getDefaultCompareFields(): array
    {
        return ['partition_key', 'sorting_key', 'primary_key', 'sampling_key'];
    }

    public function fetchData(ConnectionAdapterInterface $adapter, string $database): array
    {
        // engine 仅作为元数据供 SQL 生成器判断 ALTER 支持，不参与对比
        $fields = implode(', ', array_merge(['database', 'name AS table', 'engine'], $this->getDefaultCompareFields()));
        $sql = "
            SELECT {$fields}
            FROM system.tables
            WHERE database = {$this->quoteStringLiteral($database)}
            ORDER BY name
        ";
        return $adapter->query($sql);
    }

    /**
     * diff key: "{table}"
     * 按表归组输出（与 MysqlTableStrategy 结构一致，便于 MissingTableSimplifier 统一处理）
     */
    public function diff(array $baseline, array $live): array
    {
        $keyFn = static function (array $row): string {
            return $row['table'];
        };

        $baselineMap = $this->buildMap($baseline, $keyFn);
        $liveMap = $this->buildMap($live, $keyFn);

        $onlyInBaseline = [];
        $onlyInLive = [];
        $changed = [];

        foreach ($baselineMap as $table => $bRow) {
            if (!isset($liveMap[$table])) {
                $onlyInBaseline[] = $table;
                continue;
            }
            $lRow = $liveMap[$table];
            $diffs = $this->collectFieldDiffs($bRow, $lRow);
            if (!empty($diffs)) {
                $changed[$table] = $diffs;
            }
        }

        foreach ($liveMap as $table => $lRow) {
            if (!isset($baselineMap[$table])) {
                $onlyInLive[] = $table;
            }
        }

        // 按表归组
        $diffsByTable = [];
        foreach ($onlyInBaseline as $table) {
            $diffsByTable[$table]['only_in_baseline'][] = $table;
        }
        foreach ($onlyInLive as $table) {
            $diffsByTable[$table]['only_in_live'][] = $table;
        }
        foreach ($changed as $table => $attrs) {
            $diffsByTable[$table]['field_changed'] = $attrs;
        }

        $hasDiff = !empty($onlyInBaseline) || !empty($onlyInLive) || !empty($changed);

        return [
            'has_diff' => $hasDiff,
            'summary' => [
                'only_in_baseline' => count($onlyInBaseline),
                'only_in_live' => count($onlyInLive),
                'field_changed' => count($changed),
            ],
            'diffs_by_table' => $diffsByTable,
        ];
    }
}
