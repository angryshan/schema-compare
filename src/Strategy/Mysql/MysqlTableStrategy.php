<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Strategy\Mysql;

use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Strategy\AbstractStrategy;

/**
 * MySQL Table 属性对比策略
 *
 * 基准和实时数据均为 information_schema.TABLES 行数组
 * diff key: "{table}"
 *
 * 注意：AUTO_INCREMENT 查出来存档但不纳入对比（会随数据增长变化产生噪音）
 * 默认排除分表（按月表 _yymm / 哈希分表 _000 等），只对比基础表
 */
class MysqlTableStrategy extends AbstractStrategy
{
    /**
     * 是否排除分表（表名以 _数字 结尾的表）
     * @var bool
     */
    public bool $excludeSplitTables = true;

    public function getKey(): string
    {
        return 'tables';
    }

    public function getDefaultCompareFields(): array
    {
        return [
            'engine',           // 存储引擎（InnoDB / MyISAM / MEMORY）
            'table_collation',  // 表排序规则（如 utf8mb4_general_ci）
            'table_comment',    // 表注释
        ];
    }

    public function fetchData(ConnectionAdapterInterface $adapter, string $database): array
    {
        $sql = "
            SELECT
                TABLE_SCHEMA AS `database`,
                TABLE_NAME AS `table`,
                ENGINE AS `engine`,
                TABLE_COLLATION AS `table_collation`,
                TABLE_COMMENT AS `table_comment`,
                AUTO_INCREMENT AS `auto_increment`
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = {$this->quoteDatabase($database)} AND TABLE_TYPE = 'BASE TABLE'
            {$this->splitTableFilter()}
            ORDER BY TABLE_NAME
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
