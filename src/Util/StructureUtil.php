<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Util;

/**
 * 结构数据工具类
 */
class StructureUtil
{
    /**
     * 从结构数据中提取数据库名（多维度检查）
     *
     * 遍历所有维度，返回第一个找到的 `database` 字段值
     *
     * @param array $structure 结构数据 ['columns' => [...], 'indexes' => [...]]
     * @return string|null 提取到的库名，未找到返回 null
     */
    public static function extractDatabase(array $structure): ?string
    {
        foreach ($structure as $dimension => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (isset($item['database'])) {
                    return (string) $item['database'];
                }
            }
        }
        return null;
    }

}
