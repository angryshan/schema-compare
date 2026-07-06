<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Generator\Ck;

use TxAdmin\SchemaCompare\Generator\AbstractSqlGenerator;

/**
 * ClickHouse SQL 生成器
 *
 * SQL 方向：导出的 SQL 在线上库执行，让线上对齐基准。
 * CK 的分区键/排序键变更通常需要重建表，因此以 warn 提示为主。
 */
class CkSqlGenerator extends AbstractSqlGenerator
{
    public function generateIndexSql(array $indexDiff): array
    {
        $result = [
            'create' => [],
            'drop' => [],
            'warn' => [],
        ];

        // only_in_baseline: 基准有表、线上无 -> 线上需 CREATE TABLE
        if (!empty($indexDiff['only_in_baseline'])) {
            foreach ($indexDiff['only_in_baseline'] as $tableName) {
                $result['warn'][] = "-- [CK] 表 `{$tableName}` 仅存在于基准库（线上缺失），线上需 CREATE TABLE";
            }
        }
        // only_in_live: 线上有表、基准无 -> 线上需 DROP TABLE
        if (!empty($indexDiff['only_in_live'])) {
            foreach ($indexDiff['only_in_live'] as $tableName) {
                $result['warn'][] = "-- [CK] 表 `{$tableName}` 仅存在于线上库（基准缺失），线上需 DROP TABLE";
            }
        }
        if (!empty($indexDiff['changed'])) {
            foreach ($indexDiff['changed'] as $tableName => $changes) {
                foreach ($changes as $attr => $val) {
                    // 线上对齐基准：原值=线上值，目标值=基准值
                    $old = $val['live'] ?? '';
                    $new = $val['baseline'] ?? '';
                    $result['warn'][] = "-- [CK] 表 `{$tableName}` 的 {$attr} 需由线上值 '{$old}' 同步为基准值 '{$new}' (分区键/排序键变更需重建表)";
                }
            }
        }

        return $result;
    }

    protected function generatePreciseIndexSql(array $indexDiff, array $liveIndexes, array $baselineIndexes = [], array $missingTables = []): array
    {
        // 整表缺失的表由 columns 维度统一处理，indexes 维度跳过避免重复 warn
        if (!empty($missingTables)) {
            $indexDiff = $this->filterMissingTablesFromCkIndexDiff($indexDiff, $missingTables);
        }

        return $this->generateIndexSql($indexDiff);
    }

    /**
     * 从 CK indexes 维度的平铺 diff 中移除整表缺失的表
     *
     * CK indexes 结构为平铺：only_in_baseline / only_in_live / changed
     */
    protected function filterMissingTablesFromCkIndexDiff(array $indexDiff, array $missingTables): array
    {
        if (!empty($indexDiff['only_in_baseline'])) {
            $indexDiff['only_in_baseline'] = array_values(array_filter(
                $indexDiff['only_in_baseline'],
                fn ($t) => !isset($missingTables[$t])
            ));
        }
        if (!empty($indexDiff['only_in_live'])) {
            $indexDiff['only_in_live'] = array_values(array_filter(
                $indexDiff['only_in_live'],
                fn ($t) => !isset($missingTables[$t])
            ));
        }
        if (!empty($indexDiff['changed'])) {
            foreach ($missingTables as $table => $_) {
                unset($indexDiff['changed'][$table]);
            }
        }

        return $indexDiff;
    }

    /**
     * 用行数据生成完整的 ADD COLUMN 语句（线上对齐基准时传入基准行）
     */
    protected function buildAddColumnSql(string $table, array $row): string
    {
        $name = $row['name'] ?? '';
        $type = $row['type'] ?? 'String';

        $default = '';
        $defaultKind = $row['default_kind'] ?? '';
        $defaultExpr = $row['default_expression'] ?? '';

        if ($defaultKind === 'DEFAULT' && $defaultExpr !== '') {
            $default = " DEFAULT {$defaultExpr}";
        } elseif ($defaultKind === 'MATERIALIZED') {
            $default = " MATERIALIZED {$defaultExpr}";
        } elseif ($defaultKind === 'ALIAS') {
            $default = " ALIAS {$defaultExpr}";
        }

        $codec = !empty($row['compression_codec'])
            ? " CODEC({$row['compression_codec']})"
            : '';
        $comment = !empty($row['comment'])
            ? " COMMENT '" . addslashes($row['comment']) . "'"
            : '';

        return "ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$type}{$default}{$codec}{$comment};";
    }

    protected function buildAddColumnPlaceholderSql(string $table, string $fieldName): string
    {
        return "-- TODO: ALTER TABLE `{$table}` ADD COLUMN `{$fieldName}` ... (需补充完整定义)";
    }

    protected function buildDropColumnSql(string $table, string $columnName): string
    {
        return "ALTER TABLE `{$table}` DROP COLUMN IF EXISTS `{$columnName}`;";
    }

    protected function buildModifyColumnSql(string $table, string $fieldName, array $changes): array
    {
        $sqls = [];

        foreach ($changes as $attr => $values) {
            // 线上对齐基准：目标值=基准值，原值=线上值
            $oldVal = $values['live'] ?? '';
            $newVal = $values['baseline'] ?? '';

            switch ($attr) {
                case 'type':
                    $sqls[] = '-- CK 不支持直接修改类型，需手动操作:';
                    $sqls[] = "-- 1. ALTER TABLE `{$table}` DROP COLUMN `{$fieldName}`;";
                    $sqls[] = "-- 2. ALTER TABLE `{$table}` ADD COLUMN `{$fieldName}` {$newVal}; -- 原: {$oldVal}";
                    break;
                case 'comment':
                    $sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `{$fieldName}` COMMENT '{$newVal}'; -- 原: {$oldVal}";
                    break;
                case 'default_expression':
                case 'default_kind':
                    if ($newVal !== '') {
                        $sqls[] = "ALTER TABLE `{$table}` ALTER COLUMN `{$fieldName}` DEFAULT {$newVal}; -- 原: {$oldVal}";
                    } else {
                        $sqls[] = "ALTER TABLE `{$table}` ALTER COLUMN `{$fieldName}` REMOVE DEFAULT; -- 原: {$oldVal}";
                    }
                    break;
                case 'compression_codec':
                    $sqls[] = "-- CK 不支持在线修改压缩编码: {$fieldName} {$oldVal} -> {$newVal}";
                    break;
                default:
                    $sqls[] = "-- ALTER TABLE `{$table}` MODIFY COLUMN `{$fieldName}` {$attr}: {$oldVal} -> {$newVal}";
            }
        }

        return $sqls;
    }

    /**
     * 精确路径：有基准行时，CK 类型变更需先删后加（用基准定义重建）
     *
     * @param array|null $targetRow 基准行数据
     */
    protected function buildPreciseModifySql(string $table, string $fieldName, array $changes, ?array $targetRow): array
    {
        if (!$targetRow) {
            return $this->buildModifyColumnSql($table, $fieldName, $changes);
        }

        if (isset($changes['type'])) {
            return [
                '-- CK 类型变更需要先删后加',
                "ALTER TABLE `{$table}` DROP COLUMN IF EXISTS `{$fieldName}`;",
                $this->buildAddColumnSql($table, $targetRow),
            ];
        }

        return $this->buildModifyColumnSql($table, $fieldName, $changes);
    }

    protected function buildDropTableSql(string $table): string
    {
        return "DROP TABLE IF EXISTS `{$table}`;";
    }

    protected function buildCreateTablePlaceholderSql(string $tableName): string
    {
        return "-- TODO: CREATE TABLE `{$tableName}` (...) (整表缺失，需人工确认完整建表语句)";
    }

    protected function buildAlterTableSql(string $table, string $attr, array $change): array
    {
        // 线上对齐基准：目标值=基准值，原值=线上值
        $oldVal = $change['live'] ?? '';
        $newVal = $change['baseline'] ?? '';

        switch ($attr) {
            case 'engine':
                return [
                    '-- CK 不支持在线修改表引擎，需手动操作:',
                    "-- 1. 创建新表 (ENGINE={$newVal})",
                    '-- 2. INSERT INTO new_table SELECT * FROM old_table;',
                    '-- 3. DROP TABLE old_table;',
                    "-- 4. RENAME TABLE new_table TO old_table; -- 原: {$oldVal}",
                ];
            case 'comment':
            case 'table_comment':
                return ["ALTER TABLE `{$table}` MODIFY COMMENT='{$newVal}'; -- 原: {$oldVal}"];
            default:
                return ["-- ALTER TABLE `{$table}` SET {$attr}={$newVal} (原: {$oldVal}) [CK 可能不支持]"];
        }
    }
}
