<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare;

use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Contracts\SchemaStrategyInterface;
use TxAdmin\SchemaCompare\Exceptions\InvalidBaselineException;
use TxAdmin\SchemaCompare\Exceptions\SchemaCompareException;

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
     * @param ConnectionAdapterInterface $adapter DB 连接适配器
     * @param string $type 驱动标识，如 'clickhouse' / 'mysql'
     * @param SchemaStrategyInterface[] $strategies 策略集合；为空时调用 defaultStrategies()
     */
    public function __construct(
        ConnectionAdapterInterface $adapter,
        string $type,
        array $strategies = []
    ) {
        $this->adapter = $adapter;
        $this->type = $type;
        $this->strategies = empty($strategies) ? $this->defaultStrategies() : $strategies;
    }

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
     * 统一设置所有策略的宽松比较开关
     *
     * @param bool $loose true=开启宽松比较（默认，trim空白/折叠空格/统一破折号），false=严格逐字符比较
     * @return $this
     */
    public function setLooseComparison(bool $loose): self
    {
        foreach ($this->strategies as $strategy) {
            if (property_exists($strategy, 'looseComparison')) {
                $strategy->looseComparison = $loose;
            }
        }
        return $this;
    }

    /**
     * 查询目标库所有策略的结构数据
     *
     * @param string $database 目标库名
     * @return array ['columns' => [...], 'indexes' => [...], ...]
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
     *
     * @throws SchemaCompareException 当 JSON 编码失败时抛出
     */
    public function exportJson(string $database): string
    {
        $data = $this->fetchStructure($database);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new SchemaCompareException('JSON 编码失败: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * 比较 JSON 基准 vs 实时数据
     *
     * @param string $jsonBaseline fetchStructure() 导出的 JSON 字符串
     * @param string $database 实时查询的目标库名
     * @return array diff 报告
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
     * @param array $baseline fetchStructure() 格式的基准数据
     * @param string $database 实时查询的目标库名
     * @param string|null $expectedBaselineDb 可选：期望基准数据的库名，不传则自动从 baseline 提取并校验
     * @return array diff 报告
     * @throws SchemaCompareException 当库名不一致时抛出
     */
    public function compareFromArray(array $baseline, string $database, ?string $expectedBaselineDb = null): array
    {
        // 自动提取基准库名（多维度检查，不依赖第一个维度）
        $baselineDbName = $this->extractDatabaseFromBaseline($baseline);

        // 确定期望的基准库名
        $expectedDb = $expectedBaselineDb ?? $baselineDbName;

        // 校验：基准库名 vs 线上查询目标库名
        if ($expectedDb !== null && $expectedDb !== $database) {
            throw new SchemaCompareException(
                "基准数据库名 '{$expectedDb}' 与线上查询目标库 '{$database}' 不一致，可能存在跨库对比风险"
            );
        }

        $live = $this->fetchStructure($database);
        return $this->diff($baseline, $live);
    }

    /**
     * 聚合所有策略的 diff 结果
     *
     * @param array $baseline fetchStructure() 格式的基准数据
     * @param array $live fetchStructure() 格式的实时数据
     * @return array{has_diff: bool, details: array, warnings: array}
     */
    public function diff(array $baseline, array $live): array
    {
        $hasDiff = false;
        $details = [];
        $warnings = [];

        // 检测基准 JSON 中存在但当前驱动未注册的策略维度
        $currentKeys = array_map(function ($s) { return $s->getKey(); }, $this->strategies);
        $extraKeys = array_diff(array_keys($baseline), $currentKeys);
        if (!empty($extraKeys)) {
            $warnings[] = '基准数据包含当前驱动未注册的维度: ' . implode(', ', $extraKeys) . '，已跳过对比';
        }

        foreach ($this->strategies as $strategy) {
            $key = $strategy->getKey();
            $baselineData = $baseline[$key] ?? [];
            $liveData = $live[$key] ?? [];

            $result = $strategy->diff($baselineData, $liveData);

            // 防御：自定义策略可能遗漏 has_diff 键
            if (!empty($result['has_diff'])) {
                $hasDiff = true;
            }
            $details[$key] = $result;
        }

        return [
            'has_diff' => $hasDiff,
            'details' => $details,
            'warnings' => $warnings,
        ];
    }

    // ----------------------------------------------------------------
    // 辅助方法
    // ----------------------------------------------------------------

    /**
     * 从基准数据中提取数据库名（多维度检查）
     *
     * @param array $baseline fetchStructure() 格式的基准数据
     * @return string|null 提取到的库名，未找到返回 null
     */
    protected function extractDatabaseFromBaseline(array $baseline): ?string
    {
        return \TxAdmin\SchemaCompare\Util\StructureUtil::extractDatabase($baseline);
    }

    // ----------------------------------------------------------------
    // 子类实现
    // ----------------------------------------------------------------

    /**
     * 当外部未传 strategies 时，使用的默认策略集合
     * @return SchemaStrategyInterface[]
     */
    abstract protected function defaultStrategies(): array;
}
