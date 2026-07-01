<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Generator;

use TxAdmin\SchemaCompare\Contracts\SqlGeneratorInterface;

/**
 * SQL 生成器基类
 *
 * 编排 diff 遍历与结果合并，具体 SQL 语法由子类实现
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
            if (!empty($diff['only_in_live'])) {
                foreach ($diff['only_in_live'] as $fieldKey) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $result['add'][] = $this->buildAddColumnPlaceholderSql($table, $fieldName);
                }
            }

            if (!empty($diff['only_in_baseline'])) {
                foreach ($diff['only_in_baseline'] as $fieldKey) {
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

    public function generateTableSql(array $tableDiff): array
    {
        $result = [
            'alter' => [],
            'create' => [],
            'drop' => [],
        ];

        $diffsByTable = $tableDiff['diffs_by_table'] ?? [];
        if (!empty($diffsByTable)) {
            foreach ($diffsByTable as $table => $diff) {
                $this->appendTableDiffSql($result, $table, $diff);
            }

            return $result;
        }

        foreach ($tableDiff['only_in_live'] ?? [] as $tableName) {
            $result['create'][] = $this->buildCreateTablePlaceholderSql((string) $tableName);
        }
        foreach ($tableDiff['only_in_baseline'] ?? [] as $tableName) {
            $t = is_array($tableName) ? ($tableName[0] ?? $tableName) : $tableName;
            $result['drop'][] = $this->buildDropTableSql((string) $t);
        }
        foreach ($tableDiff['changed'] ?? [] as $tableName => $attrs) {
            if (!is_array($attrs)) {
                continue;
            }
            foreach ($attrs as $attr => $change) {
                if (!is_array($change)) {
                    continue;
                }
                $result['alter'] = array_merge(
                    $result['alter'],
                    $this->buildAlterTableSql((string) $tableName, (string) $attr, $change)
                );
            }
        }

        return $result;
    }

    public function generatePreciseSql(array $diffResult, array $liveData): array
    {
        $details = $diffResult['details'] ?? [];
        $sqls = [];

        if (!empty($details['columns']) && !empty($liveData['columns'])) {
            $sqls['columns'] = $this->generatePreciseColumnSql($details['columns'], $liveData['columns']);
        }
        if (!empty($details['indexes']) && !empty($liveData['indexes'])) {
            $sqls['indexes'] = $this->generatePreciseIndexSql($details['indexes'], $liveData['indexes']);
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

    abstract protected function generatePreciseIndexSql(array $indexDiff, array $liveIndexes): array;

    abstract protected function buildAddColumnSql(string $table, array $liveRow): string;

    abstract protected function buildAddColumnPlaceholderSql(string $table, string $fieldName): string;

    abstract protected function buildDropColumnSql(string $table, string $columnName): string;

    /**
     * @return string[]
     */
    abstract protected function buildModifyColumnSql(string $table, string $fieldName, array $changes): array;

    /**
     * @return string[]
     */
    abstract protected function buildPreciseModifySql(string $table, string $fieldName, array $changes, ?array $liveRow): array;

    abstract protected function buildDropTableSql(string $table): string;

    abstract protected function buildCreateTablePlaceholderSql(string $tableName): string;

    /**
     * @return string[]
     */
    abstract protected function buildAlterTableSql(string $table, string $attr, array $change): array;

    // ----------------------------------------------------------------
    // 共享精确字段 SQL
    // ----------------------------------------------------------------

    protected function generatePreciseColumnSql(array $columnDiff, array $liveColumns): array
    {
        $result = ['add' => [], 'drop' => [], 'modify' => []];

        $liveMap = [];
        foreach ($liveColumns as $row) {
            $key = ($row['table'] ?? '') . '.' . ($row['name'] ?? '');
            $liveMap[$key] = $row;
        }

        foreach ($columnDiff['diffs_by_table'] ?? [] as $table => $diff) {
            if (!empty($diff['only_in_live'])) {
                foreach ($diff['only_in_live'] as $fieldKey) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $liveRow = $liveMap[$table . '.' . $fieldName] ?? null;
                    $result['add'][] = $liveRow
                        ? $this->buildAddColumnSql($table, $liveRow)
                        : "-- TODO: ALTER TABLE `{$table}` ADD COLUMN `{$fieldName}`";
                }
            }

            if (!empty($diff['only_in_baseline'])) {
                foreach ($diff['only_in_baseline'] as $fieldKey) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $result['drop'][] = $this->buildDropColumnSql($table, $fieldName);
                }
            }

            if (!empty($diff['field_changed'])) {
                foreach ($diff['field_changed'] as $fieldKey => $changes) {
                    $fieldName = $this->extractFieldName($fieldKey);
                    $liveRow = $liveMap[$table . '.' . $fieldName] ?? null;
                    $result['modify'] = array_merge(
                        $result['modify'],
                        $this->buildPreciseModifySql($table, $fieldName, $changes, $liveRow)
                    );
                }
            }
        }

        return $result;
    }

    protected function appendTableDiffSql(array &$result, string $table, array $diff): void
    {
        if (!empty($diff['only_in_live'])) {
            foreach ($diff['only_in_live'] as $tableName) {
                $result['create'][] = $this->buildCreateTablePlaceholderSql((string) $tableName);
            }
        }

        if (!empty($diff['only_in_baseline'])) {
            foreach ($diff['only_in_baseline'] as $tableName) {
                $t = is_array($tableName) ? ($tableName[0] ?? $tableName) : $tableName;
                $result['drop'][] = $this->buildDropTableSql((string) $t);
            }
        }

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
