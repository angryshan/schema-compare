<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Strategy\Mysql;

use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Strategy\AbstractStrategy;

/**
 * MySQL Columns 对比策略
 *
 * 基准和实时数据均为 information_schema.COLUMNS 行数组
 * diff key: "{table}.{name}"
 *
 * 默认排除分表（按月表 _yymm / 哈希分表 _000 等），只对比基础表
 */
class MysqlColumnsStrategy extends AbstractStrategy
{
    /**
     * 是否排除分表（表名以 _数字 结尾的表）
     * @var bool
     */
    public bool $excludeSplitTables = true;
    public function getKey(): string
    {
        return 'columns';
    }

    public function getDefaultCompareFields(): array
    {
        return [
            'type',                 // 列类型（含长度，如 varchar(255)、decimal(18,2)）
            'is_nullable',          // 是否可空（YES / NO）
            'column_default',       // 默认值
            'extra',                // 额外属性（auto_increment、on update CURRENT_TIMESTAMP 等）
            'character_set_name',   // 字符集
            'collation_name',       // 排序规则
            'comment',              // 列注释
        ];
    }

    public function fetchData(ConnectionAdapterInterface $adapter, string $database): array
    {
        $sql = "
            SELECT
                TABLE_SCHEMA AS `database`,
                TABLE_NAME AS `table`,
                COLUMN_NAME AS `name`,
                COLUMN_TYPE AS `type`,
                IS_NULLABLE AS `is_nullable`,
                COLUMN_DEFAULT AS `column_default`,
                EXTRA AS `extra`,
                CHARACTER_SET_NAME AS `character_set_name`,
                COLLATION_NAME AS `collation_name`,
                COLUMN_COMMENT AS `comment`
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = '{$database}'
            {$this->splitTableFilter()}
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ";
        return $adapter->query($sql);
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
