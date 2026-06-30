<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Driver;

use TxAdmin\SchemaCompare\AbstractSchemaDriver;
use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Contracts\SchemaStrategyInterface;
use TxAdmin\SchemaCompare\Strategy\Mysql\MysqlColumnsStrategy;
use TxAdmin\SchemaCompare\Strategy\Mysql\MysqlIndexesStrategy;
use TxAdmin\SchemaCompare\Strategy\Mysql\MysqlTableStrategy;

/**
 * MySQL 结构对比驱动
 *
 * 默认使用 Columns / Indexes / Table 三个策略
 * 可通过构造函数第三个参数覆盖，实现自定义策略组合
 */
class MysqlSchemaDriver extends AbstractSchemaDriver
{
    /**
     * @param ConnectionAdapterInterface $adapter
     * @param string                     $type       默认 'mysql'
     * @param SchemaStrategyInterface[]  $strategies 为空时使用 defaultStrategies()
     */
    public function __construct(
        ConnectionAdapterInterface $adapter,
        string $type = 'mysql',
        array $strategies = []
    ) {
        parent::__construct($adapter, $type, $strategies);
    }

    protected function defaultStrategies(): array
    {
        return [
            new MysqlColumnsStrategy(),
            new MysqlIndexesStrategy(),
            new MysqlTableStrategy(),
        ];
    }

    /**
     * 统一设置所有策略的分表过滤开关
     *
     * @param bool $exclude true=排除分表（默认），false=包含分表
     * @return $this
     */
    public function setExcludeSplitTables(bool $exclude): self
    {
        foreach ($this->strategies as $strategy) {
            if (property_exists($strategy, 'excludeSplitTables')) {
                $strategy->excludeSplitTables = $exclude;
            }
        }
        return $this;
    }
}
