<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Generator\Mysql;

use TxAdmin\SchemaCompare\Generator\AbstractSqlGenerator;

/**
 * MySQL SQL 生成器
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

            if (!empty($diff['only_in_baseline'])) {
                $result['drop'][] = $this->buildDropIndexSql($table, $idxName);
            }
            if (!empty($diff['only_in_live'])) {
                $result['create'][] = "-- TODO: CREATE INDEX `{$idxName}` ON `{$table}` (...) (需补充完整定义)";
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

    protected function generatePreciseIndexSql(array $indexDiff, array $liveIndexes): array
    {
        $result = ['create' => [], 'drop' => []];

        $liveMap = [];
        foreach ($liveIndexes as $row) {
            $key = ($row['table'] ?? '') . '.' . ($row['index_name'] ?? $row['name'] ?? '');
            if (!isset($liveMap[$key])) {
                $liveMap[$key] = [];
            }
            $liveMap[$key][] = $row;
        }

        $diffsByIndex = $indexDiff['diffs_by_index'] ?? [];
        if (!empty($diffsByIndex)) {
            foreach ($diffsByIndex as $indexKey => $diff) {
                [$table, $idxName] = $this->parseTableDotIndex($indexKey);

                if (!empty($diff['only_in_live'])) {
                    $liveRows = $liveMap[$indexKey] ?? null;
                    $result['create'][] = $liveRows
                        ? $this->buildCreateCompositeIndex($table, $idxName, $liveRows)
                        : "-- TODO: CREATE INDEX `{$idxName}` ON `{$table}`";
                }
                if (!empty($diff['only_in_baseline'])) {
                    $result['drop'][] = $this->buildDropIndexSql($table, $idxName);
                }
                if (!empty($diff['field_changed'])) {
                    $result['drop'][] = $this->buildDropIndexSql($table, $idxName);
                    $liveRows = $liveMap[$indexKey] ?? null;
                    $result['create'][] = $liveRows
                        ? $this->buildCreateCompositeIndex($table, $idxName, $liveRows)
                        : "-- TODO: CREATE INDEX `{$idxName}` ON `{$table}` (索引属性变化)";
                }
            }

            return $result;
        }

        foreach ($indexDiff['diffs_by_table'] ?? [] as $table => $diff) {
            if (!empty($diff['only_in_live'])) {
                foreach ($diff['only_in_live'] as $idxInfo) {
                    $idxName = is_array($idxInfo) ? ($idxInfo['index_name'] ?? $idxInfo[0]) : $idxInfo;
                    $liveRows = $liveMap[$table . '.' . $idxName] ?? null;
                    $result['create'][] = $liveRows
                        ? $this->buildCreateCompositeIndex($table, (string) $idxName, $liveRows)
                        : "-- TODO: CREATE INDEX `{$idxName}` ON `{$table}`";
                }
            }

            if (!empty($diff['only_in_baseline'])) {
                foreach ($diff['only_in_baseline'] as $idxInfo) {
                    $idxName = is_array($idxInfo) ? ($idxInfo['index_name'] ?? $idxInfo[0]) : $idxInfo;
                    $result['drop'][] = $this->buildDropIndexSql($table, (string) $idxName);
                }
            }
        }

        return $result;
    }

    protected function buildAddColumnSql(string $table, array $liveRow): string
    {
        $type = $liveRow['type'] ?? '';
        $nullable = ($liveRow['is_nullable'] ?? 'YES') === 'YES' ? '' : ' NOT NULL';
        $default = $liveRow['column_default'] !== null
            ? ' DEFAULT ' . ($liveRow['column_default'] === 'NULL' ? 'NULL' : "'" . addslashes($liveRow['column_default']) . "'")
            : '';
        $comment = !empty($liveRow['comment'])
            ? " COMMENT '" . addslashes($liveRow['comment']) . "'"
            : '';
        $extra = !empty($liveRow['extra']) ? ' ' . $liveRow['extra'] : '';
        $charset = !empty($liveRow['character_set_name'])
            ? " CHARACTER SET {$liveRow['character_set_name']}"
            : '';
        $collation = !empty($liveRow['collation_name'])
            ? " COLLATE {$liveRow['collation_name']}"
            : '';
        $name = $liveRow['name'] ?? $liveRow['column_name'] ?? '';

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
            $oldVal = $values['baseline'] ?? '';
            $newVal = $values['live'] ?? '';

            switch ($attr) {
                case 'type':
                    $sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `{$fieldName}` {$newVal}; -- 原: {$oldVal}";
                    break;
                case 'is_nullable':
                    $action = ($newVal === 'YES') ? 'DROP NOT NULL' : 'SET NOT NULL';
                    $sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `{$fieldName}` ... {$action};";
                    break;
                case 'comment':
                    $sqls[] = "ALTER TABLE `{$table}` MODIFY COLUMN `{$fieldName}` ... COMMENT '{$newVal}'; -- 原: {$oldVal}";
                    break;
                case 'column_default':
                    if ($newVal === '') {
                        $newDef = 'DROP DEFAULT';
                    } else {
                        $newDef = 'SET DEFAULT ' . (strtolower($newVal) === 'null' ? 'NULL' : "'{$newVal}'");
                    }
                    $sqls[] = "ALTER TABLE `{$table}` ALTER COLUMN `{$fieldName}` {$newDef}; -- 原: {$oldVal}";
                    break;
                default:
                    $sqls[] = "-- ALTER TABLE `{$table}` MODIFY `{$fieldName}` {$attr}: {$oldVal} -> {$newVal}";
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
            return [$this->buildModifyColumnTypeSql($table, $liveRow)];
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

    protected function buildModifyColumnTypeSql(string $table, array $liveRow): string
    {
        $type = $liveRow['type'] ?? '';
        $nullable = ($liveRow['is_nullable'] ?? 'YES') === 'YES' ? '' : ' NOT NULL';
        $default = $liveRow['column_default'] !== null
            ? ' DEFAULT ' . ($liveRow['column_default'] === 'NULL' ? 'NULL' : "'" . addslashes($liveRow['column_default']) . "'")
            : '';
        $comment = !empty($liveRow['comment'])
            ? " COMMENT '" . addslashes($liveRow['comment']) . "'"
            : '';
        $extra = !empty($liveRow['extra']) ? ' ' . $liveRow['extra'] : '';
        $charset = !empty($liveRow['character_set_name'])
            ? " CHARACTER SET {$liveRow['character_set_name']}"
            : '';
        $collation = !empty($liveRow['collation_name'])
            ? " COLLATE {$liveRow['collation_name']}"
            : '';
        $name = $liveRow['name'] ?? $liveRow['column_name'] ?? '';

        return "ALTER TABLE `{$table}` MODIFY COLUMN `{$name}` {$type}{$nullable}{$default}{$extra}{$charset}{$collation}{$comment};";
    }

    protected function buildDropIndexSql(string $table, string $indexName): string
    {
        return "ALTER TABLE `{$table}` DROP INDEX `{$indexName}`;";
    }
}
