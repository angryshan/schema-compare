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
    /**
     * 不支持任何 ALTER COLUMN 操作的引擎（ADD/DROP/MODIFY 全部不支持）
     */
    protected const NO_ALTER_COLUMN_ENGINES = [
        'Dictionary', 'View', 'MaterializedView', 'LiveView',
        'Set', 'Join', 'Null', 'URL', 'File',
        'Kafka', 'RabbitMQ', 'S3', 'HDFS',
        'PostgreSQL', 'MySQL', 'MongoDB', 'ODBC', 'JDBC',
        'ExternalDistributed', 'Distributed',
    ];

    /**
     * 仅支持 ADD COLUMN 的引擎（不支持 DROP/MODIFY）
     */
    protected const ADD_ONLY_ALTER_ENGINES = [
        'Log', 'TinyLog', 'StripeLog',
    ];

    /** @var array<string, string> 表名 => 引擎名，从 indexes 维度的实时数据中提取 */
    protected array $tableEngines = [];

    /**
     * 生成 CK 索引/分区键/排序键差异的 SQL
     *
     * CK indexes 维度现在使用 diffs_by_table 结构（与 MySQL 统一）
     * 每个表的差异包含：only_in_baseline, only_in_live, field_changed
     */
    public function generateIndexSql(array $indexDiff): array
    {
        $result = [
            'create' => [],
            'drop' => [],
            'warn' => [],
        ];

        // 处理 diffs_by_table 结构（新的统一结构）
        foreach ($indexDiff['diffs_by_table'] ?? [] as $table => $diff) {
            // 整表缺失：跳过（由 columns 维度处理）
            if (!empty($diff['table_missing'])) {
                continue;
            }

            // only_in_baseline: 基准有、线上无 -> 需要创建索引/分区键等
            if (!empty($diff['only_in_baseline'])) {
                foreach ($diff['only_in_baseline'] as $key) {
                    $result['warn'][] = "-- [CK] 表 `{$table}` 的索引/键 `{$key}` 仅存在于基准库，需 CREATE INDEX 或重建表";
                }
            }

            // only_in_live: 线上有、基准无 -> 需要删除
            if (!empty($diff['only_in_live'])) {
                foreach ($diff['only_in_live'] as $key) {
                    $result['warn'][] = "-- [CK] 表 `{$table}` 的索引/键 `{$key}` 仅存在于线上库，需 DROP INDEX 或重建表";
                }
            }

            // field_changed: 属性变化 -> 需要重建表
            if (!empty($diff['field_changed'])) {
                foreach ($diff['field_changed'] as $key => $changes) {
                    foreach ($changes as $attr => $val) {
                        $old = $val['live'] ?? '';
                        $new = $val['baseline'] ?? '';
                        $result['warn'][] = "-- [CK] 表 `{$table}` 的 `{$key}` 的 {$attr} 需由 '{$old}' 同步为 '{$new}' (需重建表)";
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 重写：先从 indexes 维度的实时数据中提取引擎信息，供后续 SQL 生成判断
     * 并添加跳数索引（skipping_indexes）的 SQL 生成
     */
    public function generatePreciseSql(array $diffResult, array $liveData, array $baselineData = []): array
    {
        $this->tableEngines = [];
        foreach ($liveData['indexes'] ?? [] as $row) {
            $table = $row['table'] ?? '';
            $engine = $row['engine'] ?? '';
            if ($table !== '' && $engine !== '') {
                $this->tableEngines[$table] = $engine;
            }
        }

        // 调用父类方法生成基础 SQL
        $sqls = parent::generatePreciseSql($diffResult, $liveData, $baselineData);

        // 生成跳数索引 SQL（CK 特有）
        $details = $diffResult['details'] ?? [];
        if (!empty($details['skipping_indexes'])) {
            $sqls['skipping_indexes'] = $this->generateSkippingIndexSql($details['skipping_indexes']);
        }

        return $sqls;
    }

    protected function generatePreciseIndexSql(array $indexDiff, array $liveIndexes, array $baselineIndexes = [], array $missingTables = []): array
    {
        // 整表缺失的表由 columns 维度统一处理，indexes 维度在 generateIndexSql 中通过 table_missing 标记跳过
        return $this->generateIndexSql($indexDiff);
    }

    /**
     * 判断指定表是否支持某种 ALTER COLUMN 操作
     *
     * @param string $table 表名
     * @param string $operation 操作类型：'add' | 'drop' | 'modify'
     * @return bool true=支持或引擎未知（默认放行），false=不支持
     */
    protected function isAlterColumnSupported(string $table, string $operation): bool
    {
        $engine = $this->tableEngines[$table] ?? '';
        if ($engine === '') {
            return true; // 引擎未知（如 generateAll 路径无实时数据），默认放行
        }

        if (in_array($engine, self::NO_ALTER_COLUMN_ENGINES, true)) {
            return false;
        }

        // Log 系列引擎仅支持 ADD，不支持 DROP/MODIFY
        if ($operation !== 'add' && in_array($engine, self::ADD_ONLY_ALTER_ENGINES, true)) {
            return false;
        }

        return true;
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

        $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$type}{$default}{$codec}{$comment};";

        if (!$this->isAlterColumnSupported($table, 'add')) {
            $engine = $this->tableEngines[$table] ?? '';
            return "-- TODO: 表 `{$table}` 引擎为 {$engine}，不支持 ADD COLUMN，需人工处理\n-- {$sql}";
        }

        return $sql;
    }

    protected function buildAddColumnPlaceholderSql(string $table, string $fieldName): string
    {
        return "-- TODO: ALTER TABLE `{$table}` ADD COLUMN `{$fieldName}` ... (需补充完整定义)";
    }

    protected function buildDropColumnSql(string $table, string $columnName): string
    {
        $sql = "ALTER TABLE `{$table}` DROP COLUMN IF EXISTS `{$columnName}`;";

        if (!$this->isAlterColumnSupported($table, 'drop')) {
            $engine = $this->tableEngines[$table] ?? '';
            return "-- TODO: 表 `{$table}` 引擎为 {$engine}，不支持 DROP COLUMN，需人工处理\n-- {$sql}";
        }

        return $sql;
    }

    protected function buildModifyColumnSql(string $table, string $fieldName, array $changes): array
    {
        // 引擎不支持 MODIFY COLUMN 时，统一打 TODO
        if (!$this->isAlterColumnSupported($table, 'modify')) {
            $engine = $this->tableEngines[$table] ?? '';
            $detail = [];
            foreach ($changes as $attr => $values) {
                $oldVal = $values['live'] ?? '';
                $newVal = $values['baseline'] ?? '';
                $detail[] = "{$attr}: {$oldVal} -> {$newVal}";
            }
            return [
                "-- TODO: 表 `{$table}` 引擎为 {$engine}，不支持 MODIFY COLUMN，需人工处理",
                '-- ' . implode(', ', $detail),
            ];
        }

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
                    // default_expression 是实际的默认值表达式（如 '0', 'now()' 等）
                    if ($newVal !== '') {
                        $sqls[] = "ALTER TABLE `{$table}` ALTER COLUMN `{$fieldName}` DEFAULT {$newVal}; -- 原: {$oldVal}";
                    } else {
                        $sqls[] = "ALTER TABLE `{$table}` ALTER COLUMN `{$fieldName}` REMOVE DEFAULT; -- 原: {$oldVal}";
                    }
                    break;
                case 'default_kind':
                    // default_kind 是类型标识（如 'DEFAULT', 'MATERIALIZED', 'ALIAS'），不直接生成 SQL
                    // 实际的默认值变更由 default_expression 处理
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
            // 类型变更需要先 DROP 再 ADD，任一不支持就打 TODO
            if (!$this->isAlterColumnSupported($table, 'drop') || !$this->isAlterColumnSupported($table, 'add')) {
                $engine = $this->tableEngines[$table] ?? '';
                return [
                    "-- TODO: 表 `{$table}` 引擎为 {$engine}，不支持 ALTER COLUMN（先删后加），需人工处理",
                    '-- ' . $this->buildAddColumnSql($table, $targetRow),
                ];
            }

            return [
                '-- CK 类型变更需要先删后加',
                $this->buildDropColumnSql($table, $fieldName),
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

    /**
     * 生成跳数索引（Data Skipping Indexes）差异的 SQL
     *
     * CK 支持在线添加/删除跳数索引：
     * - ADD INDEX: ALTER TABLE t ADD INDEX idx_name TYPE minmax GRANULARITY 4
     * - DROP INDEX: ALTER TABLE t DROP INDEX idx_name
     *
     * @param array $skippingIndexDiff 跳数索引差异结果
     * @return array ['add' => [], 'drop' => [], 'warn' => []]
     */
    public function generateSkippingIndexSql(array $skippingIndexDiff): array
    {
        $result = [
            'add' => [],
            'drop' => [],
            'warn' => [],
        ];

        foreach ($skippingIndexDiff['diffs_by_table'] ?? [] as $table => $diff) {
            // 整表缺失：跳过（由 columns 维度处理）
            if (!empty($diff['table_missing'])) {
                continue;
            }

            // only_in_baseline: 基准有、线上无 -> 需要创建索引
            if (!empty($diff['only_in_baseline'])) {
                foreach ($diff['only_in_baseline'] as $key) {
                    // key 格式: "table.index_name"
                    $parts = explode('.', $key, 2);
                    $indexName = $parts[1] ?? $key;
                    $result['warn'][] = "-- TODO: 表 `{$table}` 的跳数索引 `{$indexName}` 仅存在于基准库，需 ADD INDEX（需要完整定义）";
                }
            }

            // only_in_live: 线上有、基准无 -> 需要删除索引
            if (!empty($diff['only_in_live'])) {
                foreach ($diff['only_in_live'] as $key) {
                    $parts = explode('.', $key, 2);
                    $indexName = $parts[1] ?? $key;
                    $result['drop'][] = "ALTER TABLE `{$table}` DROP INDEX IF EXISTS `{$indexName}`;";
                }
            }

            // field_changed: 属性变化 -> 需要先删后加
            if (!empty($diff['field_changed'])) {
                foreach ($diff['field_changed'] as $key => $changes) {
                    $parts = explode('.', $key, 2);
                    $indexName = $parts[1] ?? $key;
                    $changeDetails = [];
                    foreach ($changes as $attr => $val) {
                        $old = $val['live'] ?? '';
                        $new = $val['baseline'] ?? '';
                        $changeDetails[] = "{$attr}: {$old} -> {$new}";
                    }
                    $result['warn'][] = "-- TODO: 表 `{$table}` 的跳数索引 `{$indexName}` 属性变更需重建（" . implode(', ', $changeDetails) . "）";
                }
            }
        }

        return $result;
    }
}
