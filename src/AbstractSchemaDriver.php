<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare;

use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Contracts\SchemaStrategyInterface;
use TxAdmin\SchemaCompare\Exceptions\InvalidBaselineException;

/**
 * 驱动基类
 *
 * 负责：
 *   1. 保管 adapter + strategies 集合
 *   2. fetchStructure()  — 聚合所有策略的查询结果
 *   3. diff()            — 聚合所有策略的 diff 结果
 *   4. compareFromJson() / compareFromArray() — 便捷入口
 *   5. exportJson()      — 序列化结构为 JSON 字符串
 *
 * 不涉及任何文件 IO、SVN 操作（由应用层负责）
 */
abstract class AbstractSchemaDriver
{
    protected ConnectionAdapterInterface $adapter;

    protected string $type;

    /** @var SchemaStrategyInterface[] */
    protected array $strategies = [];

    /**
     * @param ConnectionAdapterInterface  $adapter    DB 连接适配器
     * @param string                      $type       驱动标识，如 'clickhouse' / 'mysql'
     * @param SchemaStrategyInterface[]   $strategies 策略集合；为空时调用 defaultStrategies()
     */
    public function __construct(
        ConnectionAdapterInterface $adapter,
        string $type,
        array $strategies = []
    ) {
        $this->adapter    = $adapter;
        $this->type       = $type;
        $this->strategies = empty($strategies) ? $this->defaultStrategies() : $strategies;
    }

    // ----------------------------------------------------------------
    // 子类实现
    // ----------------------------------------------------------------

    /**
     * 当外部未传 strategies 时，使用的默认策略集合
     * @return SchemaStrategyInterface[]
     */
    abstract protected function defaultStrategies(): array;

    // ----------------------------------------------------------------
    // 公共接口
    // ----------------------------------------------------------------

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * 返回当前启用的策略集合，可供应用层读取
     * @return SchemaStrategyInterface[]
     */
    public function getStrategies(): array
    {
        return $this->strategies;
    }

    /**
     * 查询目标库所有策略的结构数据
     *
     * @param  string $database 目标库名
     * @return array  ['columns' => [...], 'indexes' => [...], ...]
     */
    public function fetchStructure(string $database): array
    {
        $result = [];
        foreach ($this->strategies as $strategy) {
            $result[$strategy->getKey()] = $strategy->fetchData($this->adapter, $database);
        }
        return $result;
    }

    /**
     * 将 fetchStructure() 结果序列化为 JSON
     */
    public function exportJson(string $database): string
    {
        $data = $this->fetchStructure($database);
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 比较 JSON 基准 vs 实时数据
     *
     * @param  string $jsonBaseline fetchStructure() 导出的 JSON 字符串
     * @param  string $database     实时查询的目标库名
     * @return array  diff 报告
     * @throws InvalidBaselineException
     */
    public function compareFromJson(string $jsonBaseline, string $database): array
    {
        $baseline = json_decode($jsonBaseline, true);
        if (!is_array($baseline)) {
            throw new InvalidBaselineException('JSON 基准解码失败：' . json_last_error_msg());
        }
        return $this->compareFromArray($baseline, $database);
    }

    /**
     * 比较数组基准 vs 实时数据
     *
     * @param  array  $baseline fetchStructure() 格式的基准数据
     * @param  string $database 实时查询的目标库名
     * @return array  diff 报告
     */
    public function compareFromArray(array $baseline, string $database): array
    {
        $live = $this->fetchStructure($database);
        return $this->diff($baseline, $live);
    }

    /**
     * 聚合所有策略的 diff 结果
     *
     * @param  array $baseline fetchStructure() 格式的基准数据
     * @param  array $live     fetchStructure() 格式的实时数据
     * @return array{has_diff: bool, details: array}
     */
    public function diff(array $baseline, array $live): array
    {
        $hasDiff = false;
        $details = [];

        foreach ($this->strategies as $strategy) {
            $key          = $strategy->getKey();
            $baselineData = $baseline[$key] ?? [];
            $liveData     = $live[$key] ?? [];

            $result = $strategy->diff($baselineData, $liveData);

            if ($result['has_diff']) {
                $hasDiff = true;
            }
            $details[$key] = $result;
        }

        return [
            'has_diff' => $hasDiff,
            'details'  => $details,
        ];
    }
}
