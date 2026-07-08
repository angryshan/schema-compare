<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Strategy\Ck;

use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Strategy\AbstractStrategy;

/**
 * ClickHouse Table 对比策略
 *
 * 对比 system.tables 中表的存在性及表级属性（引擎、注释）
 * diff key: "{table}"
 *
 * 与 CkIndexesStrategy 的区别：
 *   - CkIndexesStrategy 对比分区键/排序键/主键/采样键（索引级属性）
 *   - CkTableStrategy 对比引擎/注释等表级属性，并明确检查表是否存在
 */
class CkTableStrategy extends AbstractStrategy
{
    public function getKey(): string
    {
        return 'tables';
    }

    public function getDefaultCompareFields(): array
    {
        return [
            'engine',       // 存储引擎（MergeTree / Dictionary / View 等）
            'comment',      // 表注释
        ];
    }

    public function fetchData(ConnectionAdapterInterface $adapter, string $database): array
    {
        $fields = implode(', ', array_merge(
            ['database', 'name AS table'],
            $this->getDefaultCompareFields()
        ));
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
