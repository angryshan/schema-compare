<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Processor;

/**
 * 表过滤器
 *
 * 从结构数据中移除排除的表
 */
class TableFilter
{
    /** @var array 排除的表名列表 */
    protected array $excludedTables;

    /**
     * @param array $excludedTables 排除的表名列表
     */
    public function __construct(array $excludedTables = [])
    {
        $this->excludedTables = $excludedTables;
    }

    /**
     * 设置排除的表名列表
     */
    public function setExcludedTables(array $tables): self
    {
        $this->excludedTables = $tables;
        return $this;
    }

    /**
     * 过滤结构数据
     *
     * @param array $structure 结构数据 ['columns' => [...], 'indexes' => [...]]
     * @return array 过滤后的结构数据
     */
    public function process(array $structure): array
    {
        if (empty($this->excludedTables)) {
            return $structure;
        }

        $excluded = array_flip($this->excludedTables);

        foreach ($structure as $key => $items) {
            if (!is_array($items)) {
                continue;
            }
            // 按表名过滤（Strategy 统一用 'table' 别名）
            $structure[$key] = array_values(array_filter($items, function ($item) use ($excluded) {
                $tableName = $item['table'] ?? ($item['table_name'] ?? ($item['TABLE_NAME'] ?? ''));
                return $tableName === '' || !isset($excluded[$tableName]);
            }));
        }

        return $structure;
    }
}
