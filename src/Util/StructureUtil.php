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

    /**
     * 获取结构中的所有表名
     *
     * @param array $structure 结构数据
     * @return array 表名列表（去重）
     */
    public static function extractTables(array $structure): array
    {
        $tables = [];
        foreach ($structure as $dimension => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                $tableName = $item['table'] ?? ($item['table_name'] ?? ($item['TABLE_NAME'] ?? ''));
                if ($tableName !== '') {
                    $tables[] = $tableName;
                }
            }
        }
        return array_unique($tables);
    }

    /**
     * 检查结构是否为空（所有维度都为空）
     */
    public static function isEmpty(array $structure): bool
    {
        foreach ($structure as $items) {
            if (is_array($items) && !empty($items)) {
                return false;
            }
        }
        return true;
    }
}
