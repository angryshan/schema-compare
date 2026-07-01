<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Generator\Ck;

use TxAdmin\SchemaCompare\Generator\AbstractSqlGenerator;

/**
 * ClickHouse SQL 生成器
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

        if (!empty($indexDiff['only_in_baseline'])) {
            foreach ($indexDiff['only_in_baseline'] as $tableName) {
                $result['warn'][] = "-- [CK] 表 `{$tableName}` 仅存在于基准库，请检查是否需要 DROP TABLE";
            }
        }
        if (!empty($indexDiff['only_in_live'])) {
            foreach ($indexDiff['only_in_live'] as $tableName) {
                $result['warn'][] = "-- [CK] 表 `{$tableName}` 仅存在于线上库，请检查是否需要 CREATE TABLE";
            }
        }
        if (!empty($indexDiff['changed'])) {
            foreach ($indexDiff['changed'] as $tableName => $changes) {
                foreach ($changes as $attr => $val) {
                    $old = $val['baseline'] ?? '';
                    $new = $val['live'] ?? '';
                    $result['warn'][] = "-- [CK] 表 `{$tableName}` 的 {$attr} 发生变化: {$old} -> {$new} (分区键/排序键变更需重建表)";
                }
            }
        }

        return $result;
    }

    protected function generatePreciseIndexSql(array $indexDiff, array $liveIndexes): array
    {
        return $this->generateIndexSql($indexDiff);
    }

    protected function buildAddColumnSql(string $table, array $liveRow): string
    {
        $name = $liveRow['name'] ?? '';
        $type = $liveRow['type'] ?? 'String';

        $default = '';
        $defaultKind = $liveRow['default_kind'] ?? '';
        $defaultExpr = $liveRow['default_expression'] ?? '';

        if ($defaultKind === 'DEFAULT' && $defaultExpr !== '') {
            $default = " DEFAULT {$defaultExpr}";
        } elseif ($defaultKind === 'MATERIALIZED') {
            $default = " MATERIALIZED {$defaultExpr}";
        } elseif ($defaultKind === 'ALIAS') {
            $default = " ALIAS {$defaultExpr}";
        }

        $codec = !empty($liveRow['compression_codec'])
            ? " CODEC({$liveRow['compression_codec']})"
            : '';
        $comment = !empty($liveRow['comment'])
            ? " COMMENT '" . addslashes($liveRow['comment']) . "'"
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
            $oldVal = $values['baseline'] ?? '';
            $newVal = $values['live'] ?? '';

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

    protected function buildPreciseModifySql(string $table, string $fieldName, array $changes, ?array $liveRow): array
    {
        if (!$liveRow) {
            return $this->buildModifyColumnSql($table, $fieldName, $changes);
        }

        if (isset($changes['type'])) {
            return [
                '-- CK 类型变更需要先删后加',
                "ALTER TABLE `{$table}` DROP COLUMN IF EXISTS `{$fieldName}`;",
                $this->buildAddColumnSql($table, $liveRow),
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
        return "-- TODO: CREATE TABLE `{$tableName}` (...) (需补充完整建表语句)";
    }

    protected function buildAlterTableSql(string $table, string $attr, array $change): array
    {
        $oldVal = $change['baseline'] ?? '';
        $newVal = $change['live'] ?? '';

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
                return ["ALTER TABLE `{$table}` MODIFY COMMENT '{$newVal}'; -- 原: {$oldVal}"];
            default:
                return ["-- ALTER TABLE `{$table}` SET {$attr}={$newVal} (原: {$oldVal}) [CK 可能不支持]"];
        }
    }
}
