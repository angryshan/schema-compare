<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Adapters;

use Hyperf\DB\DB;
use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Exceptions\SchemaCompareException;

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
        $result = DB::connection($this->poolName)->query($sql);

        // 防御：查询失败时框架可能返回 false 或非数组
        if (!is_array($result)) {
            throw new SchemaCompareException(
                "SQL 查询返回非数组结果 (pool: {$this->poolName})，请检查连接或语法: " . substr($sql, 0, 100)
            );
        }

        return $result;
    }
}
