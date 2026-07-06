<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Exporter;

/**
 * Schema 对比结果 SQL 导出器
 *
 * 将差异 SQL 分组格式化为可下载的 .sql 文件内容
 */
class SqlExporter
{
    /**
     * SQL 分组中文标签映射
     */
    protected array $groupLabels = [
        'columns' => '字段变更',
        'indexes' => '索引变更',
        'tables'  => '表属性变更',
        'create'  => '新增',
        'drop'    => '删除',
        'modify'  => '修改',
        'alter'   => 'ALTER',
        'warn'    => '提示（需人工确认）',
    ];

    /**
     * 导出 SQL 内容（直接输出到浏览器下载）
     *
     * @param array  $sqlData  SQL 数据 ['连接名' => ['columns' => [...], ...]]
     * @param string $type     数据库类型标识，如 'mysql' | 'ck'
     * @param string $filename 下载文件名（不含扩展名）
     */
    public function download(array $sqlData, string $type, string $filename = ''): void
    {
        if (empty($sqlData)) {
            echo '无差异 SQL 可导出';
            return;
        }

        $filename = $filename ?: date('YmdHis') . "-{$type}-schema-diff";
        $sqlContent = $this->format($sqlData, $type);

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.sql"');
        header('Content-Length: ' . strlen($sqlContent));
        echo $sqlContent;
    }

    /**
     * 格式化 SQL 内容为字符串
     *
     * @param array  $sqlData SQL 数据
     * @param string $type    数据库类型标识
     */
    public function format(array $sqlData, string $type): string
    {
        $lines = [];
        $lines[] = "-- ============================================================";
        $lines[] = "-- Schema Compare SQL Export";
        $lines[] = "-- 类型: {$type} | 时间: " . date('Y-m-d H:i:s');
        $lines[] = "-- ============================================================\n";

        $totalStatements = 0;

        foreach ($sqlData as $connName => $sqlGroups) {
            if (empty($sqlGroups) || !is_array($sqlGroups)) {
                continue;
            }

            $lines[] = "-- ============================================================";
            $lines[] = "-- 连接: {$connName}";
            $lines[] = "-- ============================================================\n";
            $lines[] = $this->formatSqlGroups($sqlGroups);
            $lines[] = "";

            $totalStatements += $this->countSqlStatements($sqlGroups);
        }

        $lines[] = "\n-- 共 {$totalStatements} 条语句（跨 " . count($sqlData) . " 个连接）";

        return implode("\n", $lines);
    }

    /**
     * 格式化 SQL 分组
     */
    protected function formatSqlGroups(array $sqlGroups): string
    {
        $lines = [];

        foreach ($sqlGroups as $group => $items) {
            if (empty($items) || !is_array($items)) {
                continue;
            }

            $label = $this->groupLabels[$group] ?? $group;
            $count = $this->countItems($items);
            $lines[] = "-- ------------------------------";
            $lines[] = "-- 【{$label}】(" . $count . " 条)";
            $lines[] = "-- ------------------------------";

            if ($this->isAssocArray($items)) {
                // 嵌套分组（如 columns => [add => [...], drop => [...]]）
                foreach ($items as $action => $actionItems) {
                    $actionLabel = $this->groupLabels[$action] ?? $action;
                    if (empty($actionItems)) {
                        continue;
                    }
                    $lines[] = "\n-- >> {$actionLabel}";
                    foreach ($actionItems as $sql) {
                        $lines[] = $sql;
                    }
                }
            } else {
                // 平铺数组
                foreach ($items as $sql) {
                    $lines[] = $sql;
                }
            }
            $lines[] = "";
        }

        return implode("\n", $lines);
    }

    /**
     * 统计 SQL 语句数量
     */
    protected function countSqlStatements(array $sqlGroups): int
    {
        $count = 0;
        foreach ($sqlGroups as $items) {
            if (!is_array($items)) {
                continue;
            }
            if ($this->isAssocArray($items)) {
                foreach ($items as $subItems) {
                    if (is_array($subItems)) {
                        $count += count($subItems);
                    }
                }
            } else {
                $count += count($items);
            }
        }
        return $count;
    }

    /**
     * 统计 items 数量（支持嵌套结构）
     */
    protected function countItems(array $items): int
    {
        if ($this->isAssocArray($items)) {
            // 嵌套分组，递归计数
            $count = 0;
            foreach ($items as $subItems) {
                if (is_array($subItems)) {
                    $count += count($subItems);
                }
            }
            return $count;
        }
        // 平铺数组
        return count($items);
    }

    /**
     * 检查数组是否为关联数组（非连续数字索引）
     */
    protected function isAssocArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
