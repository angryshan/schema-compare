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
            'ordinal_position',     // 字段在表中的位置顺序
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
                COLUMN_COMMENT AS `comment`,
                ORDINAL_POSITION AS `ordinal_position`
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = {$this->quoteStringLiteral($database)}
            {$this->splitTableFilter()}
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ";
        return $adapter->query($sql);
    }

    /**
     * 对比字段差异
     *
     * diff key: "{table}.{name}"（表名.字段名）
     *
     * 输出结构：
     * [
     *   'has_diff' => true,
     *   'summary' => [
     *     'only_in_baseline' => 2,  // 基准有、线上无的字段数
     *     'only_in_live' => 1,      // 线上有、基准无的字段数
     *     'field_changed' => 3,     // 字段属性变化的字段数
     *   ],
     *   'diffs_by_table' => [
     *     'tx_user' => [
     *       'only_in_baseline' => ['tx_user.new_field'],
     *       'only_in_live' => ['tx_user.old_field'],
     *       'field_changed' => [
     *         'tx_user.name' => [
     *           'type' => ['baseline' => 'varchar(100)', 'live' => 'varchar(50)'],
     *           'ordinal_position' => ['baseline' => '3', 'live' => '2'],
     *         ],
     *       ],
     *     ],
     *   ],
     * ]
     *
     * @param array $baseline 基准字段数据（fetchData 结果）
     * @param array $live 线上字段数据（fetchData 结果）
     * @return array diff 结果
     */
    public function diff(array $baseline, array $live): array
    {
        $keyFn = static function (array $row): string {
            return $row['table'] . '.' . $row['name'];
        };

        // 使用基类通用 diff 实现
        return $this->doDiff($baseline, $live, $keyFn, [$this, 'extractTableFromKey']);
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
     * MySQL 字段值归一化（特殊处理 column_default）
     *
     * 对于 column_default 字段：
     *   - NULL 表示"无默认值"，返回特殊标记 '[NO_DEFAULT]'
     *   - '' 表示"默认值为空字符串"，返回 ''
     *   - 其他值正常转字符串
     *
     * 这样才能区分：
     *   - 外网：无默认值 (NULL)
     *   - 内网：默认值为空字符串 ('')
     */
    protected function normalizeValue($value): string
    {
        // 获取当前比较的字段名（通过回溯调用栈或特殊标记）
        // 由于无法直接获取字段名，我们在 compareField 中特殊处理
        return parent::normalizeValue($value);
    }

    /**
     * 比较字段值（覆写以特殊处理 column_default）
     */
    protected function compareField(array $bRow, array $lRow, string $field): ?array
    {
        // column_default 字段特殊处理：区分 NULL 和 ''
        if ($field === 'column_default') {
            $bVal = $bRow[$field] ?? null;
            $lVal = $lRow[$field] ?? null;

            // NULL 表示无默认值，用特殊标记区分
            $bStr = $bVal === null ? '[NO_DEFAULT]' : (string) $bVal;
            $lStr = $lVal === null ? '[NO_DEFAULT]' : (string) $lVal;

            if ($bStr !== $lStr) {
                return ['baseline' => $bStr, 'live' => $lStr];
            }
            return null;
        }

        return parent::compareField($bRow, $lRow, $field);
    }
}
