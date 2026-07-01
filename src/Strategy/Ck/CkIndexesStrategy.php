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
        $fields = implode(', ', array_merge(['database', 'name AS table'], $this->getDefaultCompareFields()));
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

        $hasDiff = !empty($onlyInBaseline) || !empty($onlyInLive) || !empty($changed);

        return [
            'has_diff' => $hasDiff,
            'summary' => [
                'only_in_baseline' => count($onlyInBaseline),
                'only_in_live' => count($onlyInLive),
                'field_changed' => count($changed),
            ],
            'only_in_baseline' => $onlyInBaseline,
            'only_in_live' => $onlyInLive,
            'changed' => $changed,
        ];
    }
}
