<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Processor;

/**
 * 缺失表简化处理器
 *
 * 如果整张表缺失，简化差异报告，只保留表级差异
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

        // 收集缺失的表（从 columns 维度判断，因为所有表都有字段）
        $columnsDetail = $diffResult['details']['columns'] ?? [];
        $missingTables = $this->detectMissingTables($columnsDetail);

        if (empty($missingTables)) {
            return $diffResult;
        }

        // 简化各维度的差异报告
        foreach ($diffResult['details'] as $dimension => &$detail) {
            if (empty($detail['diffs_by_table'])) {
                continue;
            }

            foreach ($detail['diffs_by_table'] as $table => &$tableDiffs) {
                if (!isset($missingTables[$table])) {
                    continue;
                }

                // 表级缺失：清空所有字段级差异，只保留表级标记
                // missing_side: 'live' 表示线上缺表（基准有线上无）
                // missing_side: 'baseline' 表示基准缺表（线上有基准无）
                $missingSide = $missingTables[$table];
                $tableDiffs = [
                    'table_missing' => true,
                    'missing_side' => $missingSide,
                    'only_in_baseline' => [],
                    'only_in_live' => [],
                    'field_changed' => [],
                ];
            }
            unset($tableDiffs);
        }
        unset($detail);

        return $diffResult;
    }

    /**
     * 检测缺失的表
     *
     * @return array ['表名' => 'live'|'baseline', ...]
     *               'live' 表示线上缺表（基准有线上无）
     *               'baseline' 表示基准缺表（线上有基准无）
     */
    protected function detectMissingTables(array $columnsDetail): array
    {
        $missingTables = [];

        foreach ($columnsDetail['diffs_by_table'] ?? [] as $table => $diffs) {
            $onlyInBaseline = $diffs['only_in_baseline'] ?? [];
            $onlyInLive = $diffs['only_in_live'] ?? [];
            $fieldChanged = $diffs['field_changed'] ?? [];

            // 整表只在基准：基准有字段，线上无字段，且无字段变更
            // => 线上缺表，missing_side = 'live'
            if (!empty($onlyInBaseline) && empty($onlyInLive) && empty($fieldChanged)) {
                $allFieldsInBaseline = $this->allKeysBelongToTable($onlyInBaseline, $table);
                if ($allFieldsInBaseline) {
                    $missingTables[$table] = 'live';
                }
            }
            // 整表只在线上：线上有字段，基准无字段，且无字段变更
            // => 基准缺表，missing_side = 'baseline'
            elseif (!empty($onlyInLive) && empty($onlyInBaseline) && empty($fieldChanged)) {
                $allFieldsInLive = $this->allKeysBelongToTable($onlyInLive, $table);
                if ($allFieldsInLive) {
                    $missingTables[$table] = 'baseline';
                }
            }
        }

        return $missingTables;
    }

    /**
     * 检查所有 key 是否都属于指定表
     */
    protected function allKeysBelongToTable(array $keys, string $table): bool
    {
        foreach ($keys as $key) {
            if (strpos($key, $table . '.') !== 0) {
                return false;
            }
        }
        return true;
    }
}
