<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Driver;

use TxAdmin\SchemaCompare\AbstractSchemaDriver;
use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Contracts\SchemaStrategyInterface;
use TxAdmin\SchemaCompare\Strategy\Ck\CkColumnsStrategy;
use TxAdmin\SchemaCompare\Strategy\Ck\CkIndexesStrategy;
use TxAdmin\SchemaCompare\Strategy\Ck\CkProjectionsStrategy;

/**
 * ClickHouse 结构对比驱动
 *
 * 默认使用 Columns / Indexes / Projections 三个策略
 * 可通过构造函数第三个参数覆盖，实现自定义策略组合
 */
class CkSchemaDriver extends AbstractSchemaDriver
{
    /**
     * @param ConnectionAdapterInterface $adapter
     * @param string $type 默认 'clickhouse'
     * @param SchemaStrategyInterface[] $strategies 为空时使用 defaultStrategies()
     */
    public function __construct(
        ConnectionAdapterInterface $adapter,
        string $type = 'clickhouse',
        array $strategies = []
    ) {
        parent::__construct($adapter, $type, $strategies);
    }

    protected function defaultStrategies(): array
    {
        return [
            new CkColumnsStrategy(),
            new CkIndexesStrategy(),
            new CkProjectionsStrategy(),
        ];
    }
}
