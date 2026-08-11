<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Processor;

/**
 * 缺失表简化处理器
 *
 * 如果整张表缺失，简化差异报告，只保留表级差异。
 * 所有维度统一使用分组结构：
 *   - diffs_by_table       （tables / columns / indexes 维度）：key=表名
 *   - diffs_by_projection  （projections 维度）：key=表名.投影名
 */
class MissingTableSimplifier
{
    /**
     * 处理 diff 结果，简化表级缺失的报告
     *
     * @param array $diffResult diff 结果 ['has_diff' => true, 'details' => [...]]
     * @return array 简化后的 diff 结果
     */
    public function process(array $diffResult): array
    {
        if (empty($diffResult['details'])) {
            return $diffResult;
        }

        // 收集缺失的表（从 tables 维度判断）
        $missingTables = $this->collectMissingTables($diffResult['details']);

        if (empty($missingTables)) {
            return $diffResult;
        }

        // 简化各维度的差异报告
        foreach ($diffResult['details'] as $dimension => &$detail) {
            // 1. diffs_by_table 结构（tables / columns / CK indexes 维度）：直接按表名分组
            if (!empty($detail['diffs_by_table'])) {
                // tables 维度：缺表是正常差异，只标记 table_missing 用于前端识别，保留原计数
                // 其他维度：清空缺表的字段级差异，排除缺表计数
                $isTablesDim = ($dimension === 'tables');
                $this->simplifyDiffsByTable($detail['diffs_by_table'], $missingTables, $isTablesDim);
                $detail['summary'] = $this->recalcSubKeySummary($detail['diffs_by_table'], !$isTablesDim);
            }

            // 2. diffs_by_index 结构（MySQL indexes 维度）：按 "表名.索引名" 分组
            if (!empty($detail['diffs_by_index'])) {
                $detail['diffs_by_index'] = $this->simplifySubKeyGrouped($detail['diffs_by_index'], $missingTables);
                // indexes 维度排除缺表计数
                $detail['summary'] = $this->recalcSubKeySummary($detail['diffs_by_index'], true);
            }

            // 3. diffs_by_projection 结构（projections 维度）：按 "表名.投影名" 分组
            if (!empty($detail['diffs_by_projection'])) {
                $detail['diffs_by_projection'] = $this->simplifySubKeyGrouped($detail['diffs_by_projection'], $missingTables);
                // projections 维度排除缺表计数
                $detail['summary'] = $this->recalcSubKeySummary($detail['diffs_by_projection'], true);
            }
        }
        unset($detail);

        return $diffResult;
    }

    /**
     * 简化 diffs_by_table 结构：直接按表名匹配，替换为 table_missing 标记
     *
     * @param bool $preserveCounts 是否保留计数（tables 维度用 true，其他维度用 false）
     */
    protected function simplifyDiffsByTable(array &$diffsByTable, array $missingTables, bool $preserveCounts = false): void
    {
        foreach ($diffsByTable as $table => &$tableDiffs) {
            if (!isset($missingTables[$table])) {
                continue;
            }

            // tables 维度：保留原计数，只加标记用于前端识别
            if ($preserveCounts) {
                $tableDiffs['table_missing'] = true;
                $tableDiffs['missing_side'] = $missingTables[$table];
                continue;
            }

            // 其他维度：清空所有字段级差异，只保留表级标记
            // missing_side: 'live' 表示线上缺表（基准有线上无）
            // missing_side: 'baseline' 表示基准缺表（线上有基准无）
            $tableDiffs = [
                'table_missing' => true,
                'missing_side' => $missingTables[$table],
                'only_in_baseline' => [],
                'only_in_live' => [],
                'field_changed' => [],
            ];
        }
        unset($tableDiffs);
    }

    /**
     * 简化 diffs_by_index / diffs_by_projection 结构
     *
     * 这类结构的 key 是 "表名.索引名" 或 "表名.投影名"。
     * 对于缺表，需要：
     *   1. 移除该表的所有子条目
     *   2. 添加一条以表名为 key 的 table_missing 条目
     *   3. 保留非缺表的正常条目
     *
     * @param array $grouped   diffs_by_index 或 diffs_by_projection
     * @param array $missingTables ['表名' => 'live'|'baseline', ...]
     * @return array 简化后的分组数据
     */
    protected function simplifySubKeyGrouped(array $grouped, array $missingTables): array
    {
        $result = [];
        $simplifiedTables = [];

        foreach ($grouped as $groupKey => $diffs) {
            // 从 "表名.子键" 中提取表名
            $tableName = $this->extractTableFromGroupKey($groupKey);

            if (isset($missingTables[$tableName])) {
                // 缺表：跳过所有子条目，只记录一次 table_missing
                if (!isset($simplifiedTables[$tableName])) {
                    $result[$tableName] = [
                        'table_missing' => true,
                        'missing_side' => $missingTables[$tableName],
                        'only_in_baseline' => [],
                        'only_in_live' => [],
                        'field_changed' => [],
                    ];
                    $simplifiedTables[$tableName] = true;
                }
                continue;
            }

            // 非缺表：保留原始条目
            $result[$groupKey] = $diffs;
        }

        return $result;
    }

    /**
     * 重算子键分组维度的 summary
     *
     * @param array $grouped 简化后的分组数据
     * @param bool $excludeMissing 是否排除 table_missing 条目（tables 维度应设为 false）
     * @return array ['only_in_baseline' => int, 'only_in_live' => int, 'field_changed' => int]
     */
    protected function recalcSubKeySummary(array $grouped, bool $excludeMissing = true): array
    {
        $onlyInBaseline = 0;
        $onlyInLive = 0;
        $fieldChanged = 0;

        foreach ($grouped as $diffs) {
            $isMissing = !empty($diffs['table_missing']);

            // 排除缺表条目时跳过；保留时计为 1 条差异（整表缺失）
            if ($isMissing) {
                if ($excludeMissing) {
                    continue;
                }
                // tables 维度：缺表计入 only_in_baseline 或 only_in_live
                if ($diffs['missing_side'] === 'baseline') {
                    $onlyInLive++; // 基准缺表 = 线上有基准无
                } else {
                    $onlyInBaseline++; // 线上缺表 = 基准有线上无
                }
                continue;
            }

            $onlyInBaseline += count($diffs['only_in_baseline'] ?? []);
            $onlyInLive += count($diffs['only_in_live'] ?? []);
            $fieldChanged += count($diffs['field_changed'] ?? []);
        }

        return [
            'only_in_baseline' => $onlyInBaseline,
            'only_in_live' => $onlyInLive,
            'field_changed' => $fieldChanged,
        ];
    }

    /**
     * 从 "表名.子键" 格式的 groupKey 中提取表名
     */
    protected function extractTableFromGroupKey(string $groupKey): string
    {
        $pos = strpos($groupKey, '.');
        return $pos === false ? $groupKey : substr($groupKey, 0, $pos);
    }

    /**
     * 从 tables 维度收集缺失的表信息
     *
     * @param array $details 所有维度的差异详情
     * @return array ['表名' => 'live'|'baseline', ...]
     *               'live' 表示线上缺表（基准有线上无）
     *               'baseline' 表示基准缺表（线上有基准无）
     */
    protected function collectMissingTables(array $details): array
    {
        $missingTables = [];

        foreach ($details['tables']['diffs_by_table'] ?? [] as $table => $diffs) {
            if (!empty($diffs['only_in_baseline']) && empty($diffs['only_in_live'])) {
                // 基准有、线上无 => 线上缺表
                $missingTables[$table] = 'live';
            } elseif (!empty($diffs['only_in_live']) && empty($diffs['only_in_baseline'])) {
                // 线上有、基准无 => 基准缺表
                $missingTables[$table] = 'baseline';
            }
        }

        return $missingTables;
    }
}
