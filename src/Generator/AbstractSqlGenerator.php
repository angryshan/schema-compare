<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Generator;

use TxAdmin\SchemaCompare\Contracts\SqlGeneratorInterface;

/**
 * SQL 生成器基类
 *
 * 编排 diff 遍历与结果合并，具体 SQL 语法由子类实现
 *
 * SQL 方向约定：导出的 SQL 在「线上库」执行，目标是「让线上对齐基准」。
 *   - only_in_baseline（基准有、线上无）-> 线上 ADD COLUMN / CREATE TABLE / CREATE INDEX
 *   - only_in_live    （线上有、基准无）-> 线上 DROP COLUMN / DROP TABLE / DROP INDEX
 *   - field_changed                     -> 线上 MODIFY / ALTER 到基准值
 */
abstract class AbstractSqlGenerator implements SqlGeneratorInterface
{
    /** @var array<string, string> 表名映射 [别名 => 真实表名] */
    protected array $tableAliasMap = [];

    public function generateAll(array $diffResult): array
    {
        $details = $diffResult['details'] ?? [];
        $sqls = [];

        if (!empty($details['columns'])) {
            $sqls['columns'] = $this->generateColumnSql($details['columns']);
        }
        if (!empty($details['indexes'])) {
            $sqls['indexes'] = $this->generateIndexSql($details['indexes']);
        }
        if (!empty($details['tables'])) {
            $sqls['tables'] = $this->generateTableSql($details['tables']);
        }

        return $sqls;
    }

    public function generateColumnSql(array $columnDiff): array
    {
        $result = [
            'add' => [],
            'drop' => [],
            'modify' => [],
        ];

        foreach ($columnDiff['diffs_by_table'] ?? [] as $table => $diff) {
            // 整表缺失：生成 CREATE TABLE / DROP TABLE，不生成逐字段语句
            if (!empty($diff['table_missing'])) {
                if ($diff['missing_side'] === 'live') {
                    // 线上缺表，基准有表 -> 线上 CREATE TABLE（占位）
                    $result['add'][] = $this->buildCreateTablePlaceholderSql($table);
                } else {
                    // 基准缺表，线上有表 -> 线上 DROP TABLE
                    $result['drop'][] = $this->buildDropTableSql($table);
                }
                continue;
            }

            // only_in_baseline: 基准有、线上无 -> 线上 ADD COLUMN（占位）
            if (!empty($diff['only_in_baseline'])) {
                foreach ($diff['only_in_baseline'] as $fieldKey) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $result['add'][] = $this->buildAddColumnPlaceholderSql($table, $fieldName);
                }
            }

            // only_in_live: 线上有、基准无 -> 线上 DROP COLUMN
            if (!empty($diff['only_in_live'])) {
                foreach ($diff['only_in_live'] as $fieldKey) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $result['drop'][] = $this->buildDropColumnSql($table, $fieldName);
                }
            }

            if (!empty($diff['field_changed'])) {
                foreach ($diff['field_changed'] as $fieldKey => $changes) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $result['modify'] = array_merge(
                        $result['modify'],
                        $this->buildModifyColumnSql($table, $fieldName, $changes)
                    );
                }
            }
        }

        return $result;
    }

    /**
     * 生成表级差异 SQL（CREATE TABLE / DROP TABLE / ALTER TABLE）
     *
     * 所有策略统一使用 diffs_by_table 结构
     */
    public function generateTableSql(array $tableDiff): array
    {
        $result = [
            'alter' => [],
            'create' => [],
            'drop' => [],
        ];

        foreach ($tableDiff['diffs_by_table'] ?? [] as $table => $diff) {
            // only_in_baseline: 基准有表、线上无 -> 线上 CREATE TABLE（占位）
            if (!empty($diff['only_in_baseline'])) {
                foreach ($diff['only_in_baseline'] as $tableName) {
                    $t = is_array($tableName) ? ($tableName[0] ?? $tableName) : $tableName;
                    $result['create'][] = $this->buildCreateTablePlaceholderSql((string) $t);
                }
            }

            // only_in_live: 线上有表、基准无 -> 线上 DROP TABLE
            if (!empty($diff['only_in_live'])) {
                foreach ($diff['only_in_live'] as $tableName) {
                    $result['drop'][] = $this->buildDropTableSql((string) $tableName);
                }
            }

            // field_changed: 表属性变更 -> ALTER TABLE
            if (!empty($diff['field_changed'])) {
                foreach ($diff['field_changed'] as $attr => $change) {
                    if (!is_array($change)) {
                        continue;
                    }
                    $result['alter'] = array_merge(
                        $result['alter'],
                        $this->buildAlterTableSql($table, (string) $attr, $change)
                    );
                }
            }
        }

        return $result;
    }

    public function generatePreciseSql(array $diffResult, array $liveData, array $baselineData = []): array
    {
        $details = $diffResult['details'] ?? [];
        $sqls = [];

        // 收集整表缺失的表名（来自 columns 维度的 table_missing 标记）
        // 这些表的表级 DDL 由 columns 维度统一生成，indexes/tables 维度跳过以避免重复
        $missingTables = $this->collectMissingTables($details['columns'] ?? []);

        if (!empty($details['columns'])) {
            $sqls['columns'] = $this->generatePreciseColumnSql(
                $details['columns'],
                $liveData['columns'] ?? [],
                $baselineData['columns'] ?? []
            );
        }
        if (!empty($details['indexes'])) {
            $sqls['indexes'] = $this->generatePreciseIndexSql(
                $details['indexes'],
                $liveData['indexes'] ?? [],
                $baselineData['indexes'] ?? [],
                $missingTables
            );
        }
        if (!empty($details['tables'])) {
            $sqls['tables'] = $this->generatePreciseTableSql($details['tables'], $missingTables);
        }

        return $sqls;
    }

    public function combineSql(array $sqlGroups, string $separator = "\n\n"): string
    {
        $allSqls = [];

        foreach ($sqlGroups as $group => $sqls) {
            if (empty($sqls)) {
                continue;
            }

            $allSqls[] = '-- ===============================';
            $allSqls[] = "-- {$group}";
            $allSqls[] = '-- ===============================';

            if ($this->isAssocArray($sqls)) {
                foreach ($sqls as $action => $actionSqls) {
                    $allSqls[] = "\n-- >> {$action}";
                    foreach ($actionSqls as $sql) {
                        $allSqls[] = $sql;
                    }
                }
            } else {
                foreach ($sqls as $sql) {
                    $allSqls[] = $sql;
                }
            }
        }

        return implode($separator, $allSqls);
    }

    public function getTableAliasMap(): array
    {
        return $this->tableAliasMap;
    }

    public function setTableAliasMap(array $tableAliasMap): self
    {
        $this->tableAliasMap = $tableAliasMap;

        return $this;
    }

    // ----------------------------------------------------------------
    // 子类实现
    // ----------------------------------------------------------------

    abstract public function generateIndexSql(array $indexDiff): array;

    /**
     * @param array $missingTables 整表缺失集合 ['表名' => 'live'|'baseline', ...]，
     *                             这些表的索引级 SQL 应跳过（表级 DDL 由 columns 维度生成）
     */
    abstract protected function generatePreciseIndexSql(array $indexDiff, array $liveIndexes, array $baselineIndexes = [], array $missingTables = []): array;

    /**
     * @param array $row 用于生成 ADD COLUMN 完整定义的行数据（线上对齐基准时传入基准行）
     */
    abstract protected function buildAddColumnSql(string $table, array $row): string;

    abstract protected function buildAddColumnPlaceholderSql(string $table, string $fieldName): string;

    abstract protected function buildDropColumnSql(string $table, string $columnName): string;

    /**
     * @return string[]
     */
    abstract protected function buildModifyColumnSql(string $table, string $fieldName, array $changes): array;

    /**
     * @param array|null $targetRow 目标行（基准行），用于生成完整 MODIFY COLUMN 语句
     * @return string[]
     */
    abstract protected function buildPreciseModifySql(string $table, string $fieldName, array $changes, ?array $targetRow): array;

    abstract protected function buildDropTableSql(string $table): string;

    abstract protected function buildCreateTablePlaceholderSql(string $tableName): string;

    /**
     * @return string[]
     */
    abstract protected function buildAlterTableSql(string $table, string $attr, array $change): array;

    // ----------------------------------------------------------------
    // 共享精确字段 SQL
    // ----------------------------------------------------------------

    /**
     * 精确路径：字段变更 SQL
     *
     * SQL 在线上执行，让线上对齐基准：
     *   - only_in_baseline（基准有、线上无）-> ADD COLUMN（用基准行定义）
     *   - only_in_live    （线上有、基准无）-> DROP COLUMN
     *   - field_changed                    -> MODIFY COLUMN 到基准定义
     *   - table_missing                    -> CREATE TABLE / DROP TABLE（整表级）
     *
     * @param array $liveColumns    线上实时字段数据（保留参数，当前方向下主要用于兼容）
     * @param array $baselineColumns 基准字段数据，用于生成 ADD / MODIFY 的完整定义
     */
    protected function generatePreciseColumnSql(array $columnDiff, array $liveColumns, array $baselineColumns = []): array
    {
        $result = ['add' => [], 'drop' => [], 'modify' => []];

        $baselineMap = [];
        foreach ($baselineColumns as $row) {
            $key = ($row['table'] ?? '') . '.' . ($row['name'] ?? '');
            $baselineMap[$key] = $row;
        }

        foreach ($columnDiff['diffs_by_table'] ?? [] as $table => $diff) {
            // 整表缺失：生成 CREATE TABLE / DROP TABLE，不生成逐字段语句
            if (!empty($diff['table_missing'])) {
                if ($diff['missing_side'] === 'live') {
                    // 线上缺表，基准有表 -> 线上 CREATE TABLE（占位）
                    $result['add'][] = $this->buildCreateTablePlaceholderSql($table);
                } else {
                    // 基准缺表，线上有表 -> 线上 DROP TABLE
                    $result['drop'][] = $this->buildDropTableSql($table);
                }
                continue;
            }

            // only_in_baseline: 基准有、线上无 -> 线上 ADD COLUMN（用基准定义）
            if (!empty($diff['only_in_baseline'])) {
                foreach ($diff['only_in_baseline'] as $fieldKey) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $baselineRow = $baselineMap[$table . '.' . $fieldName] ?? null;
                    $result['add'][] = $baselineRow
                        ? $this->buildAddColumnSql($table, $baselineRow)
                        : $this->buildAddColumnPlaceholderSql($table, $fieldName);
                }
            }

            // only_in_live: 线上有、基准无 -> 线上 DROP COLUMN
            if (!empty($diff['only_in_live'])) {
                foreach ($diff['only_in_live'] as $fieldKey) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $result['drop'][] = $this->buildDropColumnSql($table, $fieldName);
                }
            }

            // field_changed: 线上 MODIFY 到基准值（用基准行）
            if (!empty($diff['field_changed'])) {
                foreach ($diff['field_changed'] as $fieldKey => $changes) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $baselineRow = $baselineMap[$table . '.' . $fieldName] ?? null;
                    $result['modify'] = array_merge(
                        $result['modify'],
                        $this->buildPreciseModifySql($table, $fieldName, $changes, $baselineRow)
                    );
                }
            }
        }

        return $result;
    }

    /**
     * 精确路径：表属性变更 SQL（仅生成 ALTER，整表缺失由 columns 维度处理）
     *
     * @param array $tableDiff    tables 维度的 diff 数据
     * @param array $missingTables 整表缺失集合，这些表跳过（表级 DDL 由 columns 维度生成）
     */
    protected function generatePreciseTableSql(array $tableDiff, array $missingTables = []): array
    {
        $result = ['alter' => [], 'create' => [], 'drop' => []];

        foreach ($tableDiff['diffs_by_table'] ?? [] as $table => $diff) {
            // 整表缺失，表级 DDL 由 columns 维度生成
            if (isset($missingTables[$table])) {
                continue;
            }

            // 仅处理属性变更（field_changed）
            if (!empty($diff['field_changed'])) {
                foreach ($diff['field_changed'] as $attr => $change) {
                    if (!is_array($change)) {
                        continue;
                    }
                    $result['alter'] = array_merge(
                        $result['alter'],
                        $this->buildAlterTableSql($table, (string) $attr, $change)
                    );
                }
            }
        }

        return $result;
    }

    /**
     * 收集整表缺失的表名集合
     *
     * @param array $columnsDetail columns 维度的 diff 数据
     * @return array ['表名' => 'live'|'baseline', ...]
     */
    protected function collectMissingTables(array $columnsDetail): array
    {
        $missingTables = [];
        foreach ($columnsDetail['diffs_by_table'] ?? [] as $table => $diff) {
            if (!empty($diff['table_missing'])) {
                $missingTables[$table] = $diff['missing_side'] ?? 'live';
            }
        }
        return $missingTables;
    }

    protected function extractFieldName(string $key): string
    {
        $parts = explode('.', $key);

        return (string) end($parts);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseTableDotIndex(string $indexKey): array
    {
        $pos = strpos($indexKey, '.');
        if ($pos === false) {
            return ['', $indexKey];
        }

        return [
            substr($indexKey, 0, $pos),
            substr($indexKey, $pos + 1),
        ];
    }

    /**
     * @param mixed $nonUnique
     */
    protected function isUniqueIndex($nonUnique): bool
    {
        return $nonUnique === 0 || $nonUnique === '0';
    }

    protected function isAssocArray(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
