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
            WHERE TABLE_SCHEMA = {$this->quoteDatabase($database)}
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
            $diffs = $this->collectFieldDiffs($bRow, $lRow);
            if (!empty($diffs)) {
                $changed[$key] = $diffs;
            }
        }

        foreach ($liveMap as $key => $lRow) {
            if (!isset($baselineMap[$key])) {
                $onlyInLive[] = $key;
            }
        }

        // 按 "table.index_name" 归组
        $diffsByIndex = [];
        foreach ($onlyInBaseline as $key) {
            $parts = explode('.', $key, 3);
            $indexKey = $parts[0] . '.' . $parts[1];
            $diffsByIndex[$indexKey]['only_in_baseline'][] = $key;
        }
        foreach ($onlyInLive as $key) {
            $parts = explode('.', $key, 3);
            $indexKey = $parts[0] . '.' . $parts[1];
            $diffsByIndex[$indexKey]['only_in_live'][] = $key;
        }
        foreach ($changed as $key => $diffs) {
            $parts = explode('.', $key, 3);
            $indexKey = $parts[0] . '.' . $parts[1];
            $diffsByIndex[$indexKey]['field_changed'][$key] = $diffs;
        }

        $hasDiff = !empty($onlyInBaseline) || !empty($onlyInLive) || !empty($changed);

        return [
            'has_diff' => $hasDiff,
            'summary' => [
                'only_in_baseline' => count($onlyInBaseline),
                'only_in_live' => count($onlyInLive),
                'field_changed' => count($changed),
            ],
            'diffs_by_index' => $diffsByIndex,
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
