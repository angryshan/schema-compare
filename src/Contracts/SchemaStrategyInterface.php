<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Contracts;

/**
 * 结构对比策略接口
 *
 * 每个实现类负责一个对比维度（columns / indexes / projections / ...）
 */
interface SchemaStrategyInterface
{
    /**
     * 数据 key，用于 fetchStructure() 输出和 JSON 基准文件的字段名
     * 如 'columns' / 'indexes' / 'projections'
     */
    public function getKey(): string;

    /**
     * 默认对比字段，构造函数未传 compareFields 时使用
     */
    public function getDefaultCompareFields(): array;

    /**
     * 查询目标数据库的该维度数据
     *
     * @param ConnectionAdapterInterface $adapter DB 连接适配器（由驱动传入，策略无状态）
     * @param string $database 目标数据库名
     * @return array
     */
    public function fetchData(ConnectionAdapterInterface $adapter, string $database): array;

    /**
     * 对比基准与实时数据，返回差异报告
     *
     * @param array $baseline fetchData() 格式的基准数据
     * @param array $live fetchData() 格式的实时数据
     * @return array{has_diff: bool, summary: array, ...}
     */
    public function diff(array $baseline, array $live): array;
}
