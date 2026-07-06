<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Generator\Mysql;

use TxAdmin\SchemaCompare\Generator\AbstractSqlGenerator;

/**
 * MySQL SQL 生成器
 *
 * SQL 方向：导出的 SQL 在线上库执行，让线上对齐基准。
 */
class MysqlSqlGenerator extends AbstractSqlGenerator
{
    public function generateIndexSql(array $indexDiff): array
    {
        $result = [
            'create' => [],
            'drop' => [],
        ];

        foreach ($indexDiff['diffs_by_index'] ?? [] as $indexKey => $diff) {
            [$table, $idxName] = $this->parseTableDotIndex($indexKey);

            // only_in_baseline: 基准有、线上无 -> 线上 CREATE INDEX（占位）
            if (!empty($diff['only_in_baseline'])) {
                $result['create'][] = "-- TODO: CREATE INDEX `{$idxName}` ON `{$table}` (...) (需补充完整定义)";
            }
            // only_in_live: 线上有、基准无 -> 线上 DROP INDEX
            if (!empty($diff['only_in_live'])) {
                $result['drop'][] = $this->buildDropIndexSql($table, $idxName);
            }
            if (!empty($diff['field_changed'])) {
                $result['drop'][] = $this->buildDropIndexSql($table, $idxName);
                $result['create'][] = "-- TODO: CREATE INDEX `{$idxName}` ON `{$table}` (...) (索引属性变化，建议使用 generatePreciseSql)";
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function buildCreateCompositeIndex(string $table, string $idxName, array $rows): string
    {
        usort($rows, function ($a, $b) {
            return (int) ($a['seq_in_index'] ?? 0) - (int) ($b['seq_in_index'] ?? 0);
        });

        $columns = [];
        foreach ($rows as $row) {
            $col = '`' . ($row['column_name'] ?? '') . '`';
            if (!empty($row['sub_part']) && $row['sub_part'] !== null) {
                $col .= '(' . (int) $row['sub_part'] . ')';
            }
            if (isset($row['collation']) && $row['collation'] === 'D') {
                $col .= ' DESC';
            }
            $columns[] = $col;
        }

        $first = reset($rows);
        $unique = $this->isUniqueIndex($first['non_unique'] ?? null) ? 'UNIQUE ' : '';

        return "CREATE {$unique}INDEX `{$idxName}` ON `{$table}` (" . implode(', ', $columns) . ');';
    }

    protected function generatePreciseIndexSql(array $indexDiff, array $liveIndexes, array $baselineIndexes = [], array $missingTables = []): array
    {
        $result = ['create' => [], 'drop' => []];

        $baselineMap = [];
        foreach ($baselineIndexes as $row) {
            $key = ($row['table'] ?? '') . '.' . ($row['index_name'] ?? $row['name'] ?? '');
            if (!isset($baselineMap[$key])) {
                $baselineMap[$key] = [];
            }
            $baselineMap[$key][] = $row;
        }

        $diffsByIndex = $indexDiff['diffs_by_index'] ?? [];
        if (!empty($diffsByIndex)) {
            foreach ($diffsByIndex as $indexKey => $diff) {
                [$table, $idxName] = $this->parseTableDotIndex($indexKey);

                // 整表缺失：表级 DDL 由 columns 维度生成，跳过索引级
                if (isset($missingTables[$table])) {
                    continue;
                }

                // only_in_live: 线上有、基准无 -> 线上 DROP INDEX
                if (!empty($diff['only_in_live'])) {
                    $result['drop'][] = $this->buildDropIndexSql($table, $idxName);
                }
                // only_in_baseline: 基准有、线上无 -> 线上 CREATE INDEX（用基准定义）
                if (!empty($diff['only_in_baseline'])) {
                    $baselineRows = $baselineMap[$indexKey] ?? null;
                    $result['create'][] = $baselineRows
                        ? $this->buildCreateCompositeIndex($table, $idxName, $baselineRows)
                        : "-- TODO: CREATE INDEX `{$idxName}` ON `{$table}`";
                }
                // field_changed: 线上 DROP 旧索引 + 用基准定义 CREATE 新索引
                if (!empty($diff['field_changed'])) {
                    $result['drop'][] = $this->buildDropIndexSql($table, $idxName);
                    $baselineRows = $baselineMap[$indexKey] ?? null;
                    $result['create'][] = $baselineRows
                        ? $this->buildCreateCompositeIndex($table, $idxName, $baselineRows)
                        : "-- TODO: CREATE INDEX `{$idxName}` ON `{$table}` (索引属性变化)";
                }
            }

            return $result;
        }

        foreach ($indexDiff['diffs_by_table'] ?? [] as $table => $diff) {
            // 整表缺失：表级 DDL 由 columns 维度生成，跳过索引级
            if (isset($missingTables[$table])) {
                continue;
            }

            // only_in_live: 线上有、基准无 -> 线上 DROP INDEX
            if (!empty($diff['only_in_live'])) {
                foreach ($diff['only_in_live'] as $idxInfo) {
                    $idxName = is_array($idxInfo) ? ($idxInfo['index_name'] ?? $idxInfo[0]) : $idxInfo;
                    $result['drop'][] = $this->buildDropIndexSql($table, (string) $idxName);
                }
            }

            // only_in_baseline: 基准有、线上无 -> 线上 CREATE INDEX（用基准定义）
            if (!empty($diff['only_in_baseline'])) {
                foreach ($diff['only_in_baseline'] as $idxInfo) {
                    $idxName = is_array($idxInfo) ? ($idxInfo['index_name'] ?? $idxInfo[0]) : $idxInfo;
                    $baselineRows = $baselineMap[$table . '.' . $idxName] ?? null;
                    $result['create'][] = $baselineRows
                        ? $this->buildCreateCompositeIndex($table, (string) $idxName, $baselineRows)
                        : "-- TODO: CREATE INDEX `{$idxName}` ON `{$table}`";
                }
            }
        }

        return $result;
    }

    /**
     * 用行数据生成完整的 ADD COLUMN 语句（线上对齐基准时传入基准行）
     */
    protected function buildAddColumnSql(string $table, array $row): string
    {
        $type = $row['type'] ?? '';
        $nullable = ($row['is_nullable'] ?? 'YES') === 'YES' ? '' : ' NOT NULL';
        
        // 处理默认值：column_default 为 null 表示没有默认值，空字符串 '' 表示默认值为空字符串
        $default = '';
        if ($row['column_default'] !== null) {
            if ($row['column_default'] === 'NULL') {
                $default = ' DEFAULT NULL';
            } else {
                $default = " DEFAULT '" . addslashes($row['column_default']) . "'";
            }
        }
        
        $comment = !empty($row['comment'])
            ? " COMMENT '" . addslashes($row['comment']) . "'"
            : '';
        $extra = !empty($row['extra']) ? ' ' . $row['extra'] : '';
        $charset = !empty($row['character_set_name'])
            ? " CHARACTER SET {$row['character_set_name']}"
            : '';
        $collation = !empty($row['collation_name'])
            ? " COLLATE {$row['collation_name']}"
            : '';
        $name = $row['name'] ?? $row['column_name'] ?? '';

        return "ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$type}{$nullable}{$default}{$extra}{$charset}{$collation}{$comment};";
    }

    protected function buildAddColumnPlaceholderSql(string $table, string $fieldName): string
    {
        return "-- TODO: ALTER TABLE `{$table}` ADD COLUMN `{$fieldName}` ... (需补充完整定义)";
    }

    protected function buildDropColumnSql(string $table, string $columnName): string
    {
        return "ALTER TABLE `{$table}` DROP COLUMN `{$columnName}`;";
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
                    $sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `{$fieldName}` {$newVal}; -- 原: {$oldVal}";
                    break;
                case 'is_nullable':
                    $action = ($newVal === 'YES') ? '可空' : '非空';
                    $sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `{$fieldName}` ... ({$action}); -- 原: {$oldVal}";
                    break;
                case 'comment':
                    $sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `{$fieldName}` ... COMMENT '{$newVal}'; -- 原: {$oldVal}";
                    break;
                case 'column_default':
                    // 新值为空字符串 '' 表示基准没有默认值
                    if ($newVal === '') {
                        $sqls[] = "ALTER TABLE `{$table}` ALTER COLUMN `{$fieldName}` DROP DEFAULT; -- 原: '{$oldVal}'";
                    } else {
                        $newDef = 'SET DEFAULT ' . (strtolower($newVal) === 'null' ? 'NULL' : "'{$newVal}'");
                        $sqls[] = "ALTER TABLE `{$table}` ALTER COLUMN `{$fieldName}` {$newDef}; -- 原: '{$oldVal}'";
                    }
                    break;
                default:
                    $sqls[] = "-- ALTER TABLE `{$table}` MODIFY `{$fieldName}` {$attr}: {$oldVal} -> {$newVal}";
            }
        }

        return $sqls;
    }

    /**
     * 精确路径：有基准行时生成完整 MODIFY COLUMN（将线上同步为基准定义）
     *
     * @param array|null $targetRow 基准行数据，用于生成完整列定义
     */
    protected function buildPreciseModifySql(string $table, string $fieldName, array $changes, ?array $targetRow): array
    {
        if (!$targetRow) {
            return $this->buildModifyColumnSql($table, $fieldName, $changes);
        }

        // 有完整基准行定义时，生成完整 MODIFY COLUMN（覆盖 type/comment/default/nullable 等所有变更）
        return [$this->buildModifyColumnFromRowSql($table, $targetRow)];
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
                return ["ALTER TABLE `{$table}` ENGINE={$newVal}; -- 原: {$oldVal}"];
            case 'table_comment':
            case 'comment':
                return ["ALTER TABLE `{$table}` COMMENT='" . addslashes($newVal) . "'; -- 原: {$oldVal}"];
            case 'table_collation':
                return ["ALTER TABLE `{$table}` COLLATE={$newVal}; -- 原: {$oldVal}"];
            case 'auto_increment':
                return ["ALTER TABLE `{$table}` AUTO_INCREMENT={$newVal}; -- 原: {$oldVal}"];
            default:
                return ["-- ALTER TABLE `{$table}` SET {$attr}={$newVal} (原: {$oldVal})"];
        }
    }

    /**
     * 用行数据生成完整的 MODIFY COLUMN 语句（将线上列定义同步为基准列定义）
     */
    protected function buildModifyColumnFromRowSql(string $table, array $row): string
    {
        $type = $row['type'] ?? '';
        $nullable = ($row['is_nullable'] ?? 'YES') === 'YES' ? '' : ' NOT NULL';
        
        // 处理默认值：column_default 为 null 表示没有默认值，空字符串 '' 表示默认值为空字符串
        $default = '';
        if ($row['column_default'] !== null) {
            if ($row['column_default'] === 'NULL') {
                $default = ' DEFAULT NULL';
            } else {
                $default = " DEFAULT '" . addslashes($row['column_default']) . "'";
            }
        }
        
        $comment = !empty($row['comment'])
            ? " COMMENT '" . addslashes($row['comment']) . "'"
            : '';
        $extra = !empty($row['extra']) ? ' ' . $row['extra'] : '';
        $charset = !empty($row['character_set_name'])
            ? " CHARACTER SET {$row['character_set_name']}"
            : '';
        $collation = !empty($row['collation_name'])
            ? " COLLATE {$row['collation_name']}"
            : '';
        $name = $row['name'] ?? $row['column_name'] ?? '';

        return "ALTER TABLE `{$table}` MODIFY COLUMN `{$name}` {$type}{$nullable}{$default}{$extra}{$charset}{$collation}{$comment};";
    }

    protected function buildDropIndexSql(string $table, string $indexName): string
    {
        return "ALTER TABLE `{$table}` DROP INDEX `{$indexName}`;";
    }
}
