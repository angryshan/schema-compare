<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Strategy;

use TxAdmin\SchemaCompare\Contracts\SchemaStrategyInterface;

/**
 * 策略基类
 *
 * 管理 compareFields，提供 buildMap / escapeIdentifier 等辅助方法
 * 具体查询和 diff 逻辑由子类实现
 */
abstract class AbstractStrategy implements SchemaStrategyInterface
{
    protected array $compareFields;

    /**
     * 是否开启宽松比较
     * true = 忽略空格、换行、制表符差异
     * false = 严格逐字符比较
     * @var bool
     */
    public bool $looseComparison = true;

    /**
     * @param null|array $compareFields 为 null 时使用 getDefaultCompareFields()
     */
    public function __construct(?array $compareFields = null)
    {
        $this->compareFields = $compareFields ?? $this->getDefaultCompareFields();
    }

    // ----------------------------------------------------------------
    // 辅助：构建 key => row 的查找 map
    // ----------------------------------------------------------------

    /**
     * @param array $rows 行数组
     * @param callable $keyFn fn(array $row): string  生成 map key
     * @return array
     */
    protected function buildMap(array $rows, callable $keyFn): array
    {
        $map = [];
        foreach ($rows as $row) {
            $key = $keyFn($row);

            if (isset($map[$key])) {
                trigger_error(
                    "buildMap 检测到重复 key: '{$key}'，后者将覆盖前者",
                    E_USER_NOTICE
                );
            }
            $map[$key] = $row;
        }
        return $map;
    }

    // ----------------------------------------------------------------
    // 辅助：安全转义
    // ----------------------------------------------------------------

    /**
     * 转义 SQL 标识符（数据库名/表名/列名），防止注入
     *
     * 规则：
     *   - 过滤掉反引号、分号、单双引号、注释符号等危险字符
     *   - 只保留字母、数字、下划线、美元符号
     *
     * @param string $identifier 待转义的标识符
     * @return string 安全的标识符
     */
    protected function escapeIdentifier(string $identifier): string
    {
        // 只允许合法标识符字符：字母、数字、下划线、连字符、美元符号
        $safe = preg_replace('/[^a-zA-Z0-9_$-]/', '', $identifier);
        if ($safe !== $identifier) {
            trigger_error(
                "escapeIdentifier 检测到非法字符并已过滤: '{$identifier}' -> '{$safe}'",
                E_USER_NOTICE
            );
        }
        return $safe;
    }

    /**
     * 转义字符串字面量（WHERE / TABLE_SCHEMA 等值比较场景）
     *
     * MySQL 和 ClickHouse 均适用：
     *   - MySQL:  WHERE TABLE_SCHEMA = 'xm2_center'
     *   - CK:     WHERE database = 'xm2_center'
     *
     * 规则：
     *   - 先通过 escapeIdentifier 过滤危险字符（只保留字母/数字/_/-/$）
     *   - 再用单引号包裹，内部单引号转义为两个单引号
     */
    protected function quoteStringLiteral(string $value): string
    {
        $safe = $this->escapeIdentifier($value);

        return "'" . str_replace("'", "''", $safe) . "'";
    }

    // ----------------------------------------------------------------
    // 辅助：字段值比较（统一 NULL / 空串处理）
    // ----------------------------------------------------------------

    /**
     * 归一化字段值用于比较
     *
     *   - information_schema 中 NULL 和空串语义不同，但 (string) null => ''
     *   - 如果一侧返回 NULL、另一侧返回 ''，直接强转后都是 ''，差异被吞掉
     *   - 此方法将原始 null 统一转为空串，保持行为一致性
     *   - 如果需要区分 NULL 和空串，子类可覆写此方法
     *
     * @param mixed $value 原始字段值
     * @return string 归一化后的字符串
     */
    protected function normalizeValue($value): string
    {
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }

    /**
     * 比较两个字段值是否不同（已归一化）
     *
     * @param array $bRow 基准行数据
     * @param array $lRow 线上行数据
     * @param string $field 字段名
     * @return null|array null 表示无差异，['baseline' => ..., 'live' => ...] 表示有差异
     */
    protected function compareField(array $bRow, array $lRow, string $field): ?array
    {
        $bVal = $this->normalizeValue($bRow[$field] ?? null);
        $lVal = $this->normalizeValue($lRow[$field] ?? null);

        // 宽松比较：忽略空格、换行、制表符差异
        if ($this->looseComparison) {
            $bVal = $this->normalizeForCompare($bVal);
            $lVal = $this->normalizeForCompare($lVal);
        }

        if ($bVal !== $lVal) {
            return ['baseline' => $bVal, 'live' => $lVal];
        }

        return null;
    }

    /**
     * 宽松比较归一化：去除多余空白字符
     *
     * 处理内容：
     *   - 去除首尾空格
     *   - 将多个连续空白（空格、换行、制表符）替换为单个空格
     *   - 统一换行符为 \n
     *
     * @param string $value 原始值
     * @return string 归一化后的值
     */
    protected function normalizeForCompare(string $value): string
    {
        // 统一换行符为 \n
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // 将多个连续空白（空格、换行、制表符）替换为单个空格
        $value = preg_replace('/[\s]+/', ' ', $value);
        // 去除首尾空格
        return trim($value);
    }

    /**
     * 收集一行数据中所有对比字段的差异
     *
     * @return array<string, array{baseline: string, live: string}>
     */
    protected function collectFieldDiffs(array $bRow, array $lRow): array
    {
        $diffs = [];
        foreach ($this->compareFields as $field) {
            $diff = $this->compareField($bRow, $lRow, $field);
            if ($diff !== null) {
                $diffs[$field] = $diff;
            }
        }

        return $diffs;
    }

    // ----------------------------------------------------------------
    // 通用 diff 实现（子类可复用以减少重复代码）
    // ----------------------------------------------------------------

    /**
     * 通用 diff 实现：对比两组数据，返回标准化的差异结果
     *
     * 适用于大多数策略，只需提供 key 生成函数和可选的表名提取函数
     *
     * @param array    $baseline      基准数据
     * @param array    $live          线上数据
     * @param callable $keyFn         fn(array $row): string 生成唯一 key
     * @param callable|null $tableFn  fn(string $key): string 从 key 中提取表名（用于分组）
     *                                为 null 时直接使用 key 作为表名
     * @return array 标准化差异结果
     */
    protected function doDiff(
        array $baseline,
        array $live,
        callable $keyFn,
        ?callable $tableFn = null
    ): array {
        $baselineMap = $this->buildMap($baseline, $keyFn);
        $liveMap = $this->buildMap($live, $keyFn);

        $onlyInBaseline = [];
        $onlyInLive = [];
        $changed = [];

        // 对比基准数据
        foreach ($baselineMap as $key => $bRow) {
            if (!isset($liveMap[$key])) {
                $onlyInBaseline[] = $key;
                continue;
            }
            $lRow = $liveMap[$key];
            $diffs = $this->collectFieldDiffs($bRow, $lRow);
            if (!empty($diffs)) {
                $changed[$key] = $diffs;
            }
        }

        // 找出线上独有的
        foreach ($liveMap as $key => $lRow) {
            if (!isset($baselineMap[$key])) {
                $onlyInLive[] = $key;
            }
        }

        // 按表归组
        $diffsByTable = [];
        foreach ($onlyInBaseline as $key) {
            $table = $tableFn ? $tableFn($key) : $key;
            $diffsByTable[$table]['only_in_baseline'][] = $key;
        }
        foreach ($onlyInLive as $key) {
            $table = $tableFn ? $tableFn($key) : $key;
            $diffsByTable[$table]['only_in_live'][] = $key;
        }
        foreach ($changed as $key => $diffs) {
            $table = $tableFn ? $tableFn($key) : $key;
            $diffsByTable[$table]['field_changed'][$key] = $diffs;
        }

        $hasDiff = !empty($onlyInBaseline) || !empty($onlyInLive) || !empty($changed);

        return [
            'has_diff' => $hasDiff,
            'summary' => [
                'only_in_baseline' => count($onlyInBaseline),
                'only_in_live' => count($onlyInLive),
                'field_changed' => count($changed),
            ],
            'diffs_by_table' => $diffsByTable,
        ];
    }

    /**
     * 从 "表名.子键" 格式的 key 中提取表名
     *
     * @param string $key  如 "users.idx_name"
     * @return string      如 "users"
     */
    protected function extractTableFromKey(string $key): string
    {
        $pos = strpos($key, '.');
        return $pos === false ? $key : substr($key, 0, $pos);
    }

    /**
     * 从 "表名.投影名.字段名" 格式的 key 中提取投影键
     *
     * @param string $key  如 "users.proj_name.column"
     * @return string      如 "users.proj_name"
     */
    protected function extractProjectionFromKey(string $key): string
    {
        $parts = explode('.', $key, 3);
        return $parts[0] . '.' . $parts[1];
    }

    /**
     * 从 "表名.索引名.序号" 格式的 key 中提取索引键
     *
     * @param string $key  如 "users.idx_name.1"
     * @return string      如 "users.idx_name"
     */
    protected function extractIndexFromKey(string $key): string
    {
        $parts = explode('.', $key, 3);
        return $parts[0] . '.' . $parts[1];
    }
}
