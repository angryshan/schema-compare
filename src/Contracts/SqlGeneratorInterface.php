<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Contracts;

/**
 * SQL 生成器接口
 *
 * 根据 diff 结果生成 ALTER / CREATE / DROP 等 SQL 语句
 */
interface SqlGeneratorInterface
{
    /**
     * 根据完整 diff 结果生成所有 SQL
     *
     * @param array $diffResult 驱动 diff() 返回的完整结果
     * @return array<string, array>
     */
    public function generateAll(array $diffResult): array;

    /**
     * 生成字段变更 SQL
     *
     * @param array $columnDiff columns 维度的 diff 数据
     * @return array<string, string[]>
     */
    public function generateColumnSql(array $columnDiff): array;

    /**
     * 生成索引变更 SQL
     *
     * @param array $indexDiff indexes 维度的 diff 数据
     * @return array<string, string[]>
     */
    public function generateIndexSql(array $indexDiff): array;

    /**
     * 生成表属性变更 SQL
     *
     * @param array $tableDiff tables 维度的 diff 数据
     * @return array<string, string[]>
     */
    public function generateTableSql(array $tableDiff): array;

    /**
     * 根据带 live 完整数据的 diff 生成精确 SQL
     *
     * @param array $diffResult diff 结果
     * @param array $liveData 线上实时数据（fetchStructure 返回格式）
     * @return array<string, array>
     */
    public function generatePreciseSql(array $diffResult, array $liveData): array;

    /**
     * 将所有 SQL 合并为一个字符串
     */
    public function combineSql(array $sqlGroups, string $separator = "\n\n"): string;
}
