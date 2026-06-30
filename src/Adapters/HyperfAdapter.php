<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Adapters;

use Hyperf\DB\DB;
use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;

/**
 * Hyperf 适配器
 *
 * 用法：
 *   $adapter = new HyperfAdapter('clickhouse');
 *   $adapter = new HyperfAdapter('default');     // MySQL
 */
class HyperfAdapter implements ConnectionAdapterInterface
{
    private string $poolName;

    public function __construct(string $poolName = 'default')
    {
        $this->poolName = $poolName;
    }

    public function query(string $sql): array
    {
        return DB::connection($this->poolName)->query($sql);
    }
}
