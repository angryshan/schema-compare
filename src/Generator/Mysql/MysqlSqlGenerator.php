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
        // 用于去重：记录已处理的索引
        $processedCreates = [];
        $processedDrops = [];

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

                // 去重 key
                $dedupKey = $table . '.' . $idxName;

                // only_in_live: 线上有、基准无 -> 线上 DROP INDEX
                if (!empty($diff['only_in_live'])) {
                    if (!isset($processedDrops[$dedupKey])) {
                        $result['drop'][] = $this->buildDropIndexSql($table, $idxName);
                        $processedDrops[$dedupKey] = true;
                    }
                }

                // only_in_baseline: 基准有、线上无 -> 线上 CREATE INDEX（用基准定义）
                if (!empty($diff['only_in_baseline'])) {
                    if (!isset($processedCreates[$dedupKey])) {
                        $baselineRows = $baselineMap[$indexKey] ?? null;
                        $result['create'][] = $baselineRows
                            ? $this->buildCreateCompositeIndex($table, $idxName, $baselineRows)
                            : "-- TODO: CREATE INDEX `{$idxName}` ON `{$table}`";
                        $processedCreates[$dedupKey] = true;
                    }
                    continue; // 已有 only_in_baseline，跳过 field_changed 处理
                }

                // field_changed: 线上 DROP 旧索引 + 用基准定义 CREATE 新索引
                if (!empty($diff['field_changed'])) {
                    if (!isset($processedDrops[$dedupKey])) {
                        $result['drop'][] = $this->buildDropIndexSql($table, $idxName);
                        $processedDrops[$dedupKey] = true;
                    }
                    if (!isset($processedCreates[$dedupKey])) {
                        $baselineRows = $baselineMap[$indexKey] ?? null;
                        $result['create'][] = $baselineRows
                            ? $this->buildCreateCompositeIndex($table, $idxName, $baselineRows)
                            : "-- TODO: CREATE INDEX `{$idxName}` ON `{$table}` (索引属性变化)";
                        $processedCreates[$dedupKey] = true;
                    }
                }
            }

            return $result;
        }

        // MySQL indexes 维度统一使用 diffs_by_index 结构（key=table.index_name）
        // diffs_by_table 分支为旧兼容代码，已删除

        return $result;
    }

    /**
     * 用行数据生成完整的 ADD COLUMN 语句（线上对齐基准时传入基准行）
     */
    protected function buildAddColumnSql(string $table, array $row): string
    {
        $name = $row['name'] ?? $row['column_name'] ?? '';
        $colDef = $this->buildColumnDefinition($row);
        $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$colDef};";

        // auto_increment 列在 MySQL 中必须定义为键（PRIMARY KEY 或 UNIQUE KEY）
        // 由于目标表可能已有主键，自动追加会导致执行失败，此处仅加 TODO 提醒人工确认
        $extra = $row['extra'] ?? '';
        if (stripos($extra, 'auto_increment') !== false) {
            $sql = '-- ' . $sql;
            $sql .= " -- TODO: 该列为 auto_increment，需定义为主键或唯一键，请确认目标表是否已有主键后再执行";
        }

        return $sql;
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
                case 'ordinal_position':
                    $sqls[] = "-- ALTER TABLE `{$table}` MODIFY COLUMN `{$fieldName}` ... (位置调整: {$oldVal} -> {$newVal})";
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

        // 检测是否包含位置变更
        $hasPositionChange = isset($changes['ordinal_position']);

        // 有完整基准行定义时，生成完整 MODIFY COLUMN（覆盖 type/comment/default/nullable 等所有变更）
        $sql = $this->buildModifyColumnFromRowSql($table, $targetRow);

        // 如果有位置变更，添加 TODO 注释提醒
        if ($hasPositionChange) {
            $oldPos = $changes['ordinal_position']['live'] ?? '?';
            $newPos = $changes['ordinal_position']['baseline'] ?? '?';
            $sql .= " -- TODO: 字段位置需要调整 ({$oldPos} -> {$newPos})，请使用 AFTER/FIRST 子句手动指定位置";
        }

        return [$sql];
    }

    /**
     * 精确路径：字段变更 SQL（MySQL 特殊处理，支持字段位置调整）n     *
     * 为包含位置变更的字段自动生成 AFTER/FIRST 子句
     */
    protected function generatePreciseColumnSql(array $columnDiff, array $liveColumns, array $baselineColumns = []): array
    {
        $result = ['add' => [], 'drop' => [], 'modify' => []];

        $baselineMap = [];
        // 按表分组，用于确定字段位置
        $baselineByTable = [];
        foreach ($baselineColumns as $row) {
            $table = $row['table'] ?? '';
            $name = $row['name'] ?? '';
            $key = $table . '.' . $name;
            $baselineMap[$key] = $row;

            if (!isset($baselineByTable[$table])) {
                $baselineByTable[$table] = [];
            }
            $baselineByTable[$table][] = $row;
        }

        // 为每个表构建位置映射：ordinal_position => field_name
        $positionMapByTable = [];
        foreach ($baselineByTable as $table => $rows) {
            // 按 ordinal_position 排序
            usort($rows, function ($a, $b) {
                return (int) ($a['ordinal_position'] ?? 0) - (int) ($b['ordinal_position'] ?? 0);
            });

            $positionMapByTable[$table] = [];
            foreach ($rows as $idx => $row) {
                $pos = (int) ($row['ordinal_position'] ?? ($idx + 1));
                $positionMapByTable[$table][$pos] = $row['name'] ?? '';
            }
        }

        foreach ($columnDiff['diffs_by_table'] ?? [] as $table => $diff) {
            // 整表缺失：生成 CREATE TABLE / DROP TABLE，不生成逐字段语句
            if (!empty($diff['table_missing'])) {
                if ($diff['missing_side'] === 'live') {
                    $result['add'][] = $this->buildCreateTablePlaceholderSql($table);
                } else {
                    $result['drop'][] = $this->buildDropTableSql($table);
                }
                continue;
            }

            // only_in_baseline: 基准有、线上无 -> 线上 ADD COLUMN
            if (!empty($diff['only_in_baseline'])) {
                foreach ($diff['only_in_baseline'] as $fieldKey) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $baselineRow = $baselineMap[$table . '.' . $fieldName] ?? null;
                    if ($baselineRow) {
                        // 确定前一个字段，用于生成 AFTER/FIRST
                        $prevColumn = $this->getPreviousColumn($table, $fieldName, $positionMapByTable);
                        $result['add'][] = $this->buildAddColumnWithPositionSql($table, $baselineRow, $prevColumn);
                    } else {
                        $result['add'][] = $this->buildAddColumnPlaceholderSql($table, $fieldName);
                    }
                }
            }

            // only_in_live: 线上有、基准无 -> 线上 DROP COLUMN
            if (!empty($diff['only_in_live'])) {
                foreach ($diff['only_in_live'] as $fieldKey) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $result['drop'][] = $this->buildDropColumnSql($table, $fieldName);
                }
            }

            // field_changed: 线上 MODIFY 到基准值
            if (!empty($diff['field_changed'])) {
                foreach ($diff['field_changed'] as $fieldKey => $changes) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $baselineRow = $baselineMap[$table . '.' . $fieldName] ?? null;

                    // 检测是否包含位置变更
                    $hasPositionChange = isset($changes['ordinal_position']);
                    $prevColumn = null;
                    if ($hasPositionChange) {
                        $prevColumn = $this->getPreviousColumn($table, $fieldName, $positionMapByTable);
                    }

                    $result['modify'] = array_merge(
                        $result['modify'],
                        $this->buildPreciseModifySqlWithPosition($table, $fieldName, $changes, $baselineRow, $prevColumn)
                    );
                }
            }
        }

        return $result;
    }

    /**
     * 获取指定字段的前一个字段名
     *
     * @param string $table 表名
     * @param string $fieldName 当前字段名
     * @param array $positionMapByTable 位置映射 [table => [position => field_name]]
     * @return string|null null 表示 FIRST，空字符串表示未知
     */
    protected function getPreviousColumn(string $table, string $fieldName, array $positionMapByTable): ?string
    {
        if (!isset($positionMapByTable[$table])) {
            return '';
        }

        $positions = $positionMapByTable[$table];
        $currentPos = null;

        // 找到当前字段的位置
        foreach ($positions as $pos => $name) {
            if ($name === $fieldName) {
                $currentPos = $pos;
                break;
            }
        }

        if ($currentPos === null) {
            return '';
        }

        // 如果是第一个字段，返回 null 表示 FIRST
        $minPos = min(array_keys($positions));
        if ($currentPos === $minPos) {
            return null;
        }

        // 找到前一个位置
        $prevPos = null;
        foreach (array_keys($positions) as $pos) {
            if ($pos < $currentPos) {
                $prevPos = $pos;
            } else {
                break;
            }
        }

        return $prevPos !== null ? ($positions[$prevPos] ?? '') : '';
    }

    /**
     * 生成带位置子句的 ADD COLUMN SQL
     *
     * @param string|null $prevColumn 前一个字段名，null 表示 FIRST
     */
    protected function buildAddColumnWithPositionSql(string $table, array $row, ?string $prevColumn): string
    {
        $name = $row['name'] ?? $row['column_name'] ?? '';
        $colDef = $this->buildColumnDefinition($row);

        // 位置子句
        $positionClause = '';
        if ($prevColumn === null) {
            $positionClause = ' FIRST';
        } elseif ($prevColumn !== '') {
            $positionClause = " AFTER `{$prevColumn}`";
        }

        $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$colDef}{$positionClause};";

        // auto_increment 列处理
        $extra = $row['extra'] ?? '';
        if (stripos($extra, 'auto_increment') !== false) {
            $sql = '-- ' . $sql;
            $sql .= " -- TODO: 该列为 auto_increment，需定义为主键或唯一键，请确认目标表是否已有主键后再执行";
        }

        return $sql;
    }

    /**
     * 带位置信息的精确 MODIFY SQL 生成
     *
     * @param string|null $prevColumn 前一个字段名，null 表示 FIRST
     * @return string[]
     */
    protected function buildPreciseModifySqlWithPosition(string $table, string $fieldName, array $changes, ?array $targetRow, ?string $prevColumn): array
    {
        if (!$targetRow) {
            return $this->buildModifyColumnSql($table, $fieldName, $changes);
        }

        $sql = $this->buildModifyColumnFromRowSql($table, $targetRow, $prevColumn);

        // 如果有位置变更，添加注释
        if (isset($changes['ordinal_position'])) {
            $oldPos = $changes['ordinal_position']['live'] ?? '?';
            $newPos = $changes['ordinal_position']['baseline'] ?? '?';
            $sql .= " -- 位置: {$oldPos} -> {$newPos}";
        }

        return [$sql];
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
     *
     * @param array|null $prevColumn 前一个字段名（用于生成 AFTER 子句），null 表示 FIRST
     */
    protected function buildModifyColumnFromRowSql(string $table, array $row, ?string $prevColumn = null): string
    {
        $name = $row['name'] ?? $row['column_name'] ?? '';
        $colDef = $this->buildColumnDefinition($row);

        // 位置子句：FIRST 或 AFTER `prev_column`
        $positionClause = '';
        if (isset($row['ordinal_position'])) {
            if ($prevColumn === null) {
                $positionClause = ' FIRST';
            } elseif ($prevColumn !== '') {
                $positionClause = " AFTER `{$prevColumn}`";
            }
        }

        $sql = "ALTER TABLE `{$table}` MODIFY COLUMN `{$name}` {$colDef}{$positionClause};";

        // auto_increment 列在 MySQL 中必须定义为键（PRIMARY KEY 或 UNIQUE KEY）
        // 由于目标表可能已有主键，自动追加会导致执行失败，此处仅加 TODO 提醒人工确认
        $extra = $row['extra'] ?? '';
        if (stripos($extra, 'auto_increment') !== false) {
            $sql = '-- ' . $sql;
            $sql .= " -- TODO: 该列为 auto_increment，需定义为主键或唯一键，请确认目标表是否已有主键后再执行";
        }

        return $sql;
    }

    /**
     * 从行数据构建 MySQL 列定义片段（不含列名和 ALTER 语句外壳）
     *
     * MySQL 列定义语法顺序：
     *   type [CHARACTER SET ...] [COLLATE ...] [NOT NULL] [DEFAULT ...] [extra] [COMMENT ...]
     *
     * @param array $row information_schema.COLUMNS 行数据
     * @return string 列定义片段，如 "int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键'"
     */
    protected function buildColumnDefinition(array $row): string
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

        return "{$type}{$charset}{$collation}{$nullable}{$default}{$extra}{$comment}";
    }

    protected function buildDropIndexSql(string $table, string $indexName): string
    {
        return "ALTER TABLE `{$table}` DROP INDEX `{$indexName}`;";
    }
}
