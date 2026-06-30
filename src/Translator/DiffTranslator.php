<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Translator;

/**
 * Diff 结果翻译器
 *
 * 将 schema-compare 组件的 diff 结果翻译为中文（或其他语言）
 * 支持分组结构（diffs_by_table/index/projection）和平铺结构
 */
class DiffTranslator
{
    /** @var array 属性名翻译映射 [维度 => [英文 => 中文]] */
    protected array $fieldLabels = [];

    /** @var array 差异类型映射 [英文Key => 中文标签] */
    protected array $diffTypeMap = [
        'only_in_baseline' => '基准有线上无',
        'only_in_live'     => '线上有基准无',
        'field_changed'    => '属性变化',
        'changed'          => '属性变化',
    ];

    /** @var string 分组结构的 key 列表 */
    protected array $groupKeys = [
        'diffs_by_table',
        'diffs_by_index',
        'diffs_by_projection',
    ];

    /**
     * @param array $fieldLabels 属性名翻译映射 [维度 => [英文 => 中文]]
     * @param array|null $diffTypeMap 自定义差异类型映射，null 使用默认中文
     */
    public function __construct(array $fieldLabels = [], ?array $diffTypeMap = null)
    {
        $this->fieldLabels = $fieldLabels;
        if ($diffTypeMap !== null) {
            $this->diffTypeMap = $diffTypeMap;
        }
    }

    /**
     * 翻译 diff 结果
     *
     * @param  array  $rawDiff 原始 diff 数据
     * @param  string $dimLabel 维度中文名（如 '字段'、'索引'）
     * @return array  翻译后的结果
     */
    public function translate(array $rawDiff, string $dimLabel): array
    {
        if (empty($rawDiff)) {
            return [
                'has_diff' => false,
                'summary'  => [],
                '明细'     => [],
            ];
        }

        $summary = $rawDiff['summary'] ?? [];

        return [
            '是否有不同' => $rawDiff['has_diff'] ?? false,
            '对比维度'   => $dimLabel,
            '总结'       => [
                ($this->diffTypeMap['only_in_baseline'] ?? 'only_in_baseline') => $summary['only_in_baseline'] ?? 0,
                ($this->diffTypeMap['only_in_live'] ?? 'only_in_live')         => $summary['only_in_live']     ?? 0,
                ($this->diffTypeMap['field_changed'] ?? 'field_changed')        => $summary['field_changed']    ?? 0,
            ],
            '明细'       => $this->translateDetails($rawDiff, $dimLabel),
        ];
    }

    /**
     * 翻译明细数据（自动识别分组/平铺结构）
     */
    public function translateDetails(array $rawDiff, string $dimLabel): array
    {
        // 尝试按分组遍历
        foreach ($this->groupKeys as $groupKey) {
            if (!empty($rawDiff[$groupKey])) {
                return $this->translateGroupedDiff($rawDiff[$groupKey], $dimLabel);
            }
        }

        // 平铺结构
        return $this->translateFlatDiff($rawDiff, $dimLabel);
    }

    /**
     * 翻译分组结构（如 diffs_by_table）
     *
     * @param  array  $groupedData 分组数据
     * @param  string $dimLabel    维度标签
     * @return array  翻译后的分组数据
     */
    public function translateGroupedDiff(array $groupedData, string $dimLabel): array
    {
        $result  = [];
        $labels  = $this->fieldLabels[$dimLabel] ?? [];

        foreach ($groupedData as $key => $item) {
            $translated = [];
            foreach ($this->diffTypeMap as $en => $zh) {
                if (!empty($item[$en])) {
                    $translated[$zh] = ($en === 'field_changed' || $en === 'changed')
                        ? $this->translateFieldChanges($item[$en], $labels)
                        : $item[$en];
                }
            }
            if (!empty($translated)) {
                $result[$key] = $translated;
            }
        }

        return $result;
    }

    /**
     * 翻译平铺结构
     */
    public function translateFlatDiff(array $rawDiff, string $dimLabel): array
    {
        $result = [];
        $labels = $this->fieldLabels[$dimLabel] ?? [];

        foreach ($this->diffTypeMap as $en => $zh) {
            if (!empty($rawDiff[$en])) {
                $result[$zh] = ($en === 'field_changed' || $en === 'changed')
                    ? $this->translateFieldChanges($rawDiff[$en], $labels)
                    : $rawDiff[$en];
            }
        }

        return $result;
    }

    /**
     * 翻译字段变化详情
     *
     * @param  array $changes 字段变化数据 ['field_name' => ['attr' => ['old', 'new'], ...]]
     * @param  array $labels  属性名映射 ['attr' => '中文名', ...]
     * @return array  翻译后的数据
     */
    public function translateFieldChanges(array $changes, array $labels): array
    {
        $result = [];
        foreach ($changes as $name => $diffs) {
            $translated = [];
            foreach ($diffs as $attr => $values) {
                $translated[$labels[$attr] ?? $attr] = $values;
            }
            $result[$name] = $translated;
        }
        return $result;
    }

    // ----------------------------------------------------------------
    // Getter / Setter
    // ----------------------------------------------------------------

    /**
     * 获取属性名翻译映射
     */
    public function getFieldLabels(): array
    {
        return $this->fieldLabels;
    }

    /**
     * 设置属性名翻译映射
     */
    public function setFieldLabels(array $fieldLabels): self
    {
        $this->fieldLabels = $fieldLabels;
        return $this;
    }

    /**
     * 获取差异类型映射
     */
    public function getDiffTypeMap(): array
    {
        return $this->diffTypeMap;
    }

    /**
     * 设置差异类型映射（用于多语言支持）
     */
    public function setDiffTypeMap(array $diffTypeMap): self
    {
        $this->diffTypeMap = $diffTypeMap;
        return $this;
    }

    /**
     * 添加单个维度的属性映射
     */
    public function addFieldLabels(string $dimension, array $labels): self
    {
        $this->fieldLabels[$dimension] = $labels;
        return $this;
    }
}
