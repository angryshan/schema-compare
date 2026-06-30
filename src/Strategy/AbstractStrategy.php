<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Strategy;

use TxAdmin\SchemaCompare\Contracts\SchemaStrategyInterface;

/**
 * 策略基类
 *
 * 管理 compareFields，提供 buildMap 辅助方法
 * 具体查询和 diff 逻辑由子类实现
 */
abstract class AbstractStrategy implements SchemaStrategyInterface
{
    protected array $compareFields;

    /**
     * @param null|array $compareFields 为 null 时使用 getDefaultCompareFields()
     */
    public function __construct(?array $compareFields = null)
    {
        $this->compareFields = $compareFields ?? $this->getDefaultCompareFields();
    }

    // ----------------------------------------------------------------
    // 辅助：构建 key => row 的查找 map
    // ----------------------------------------------------------------

    /**
     * @param array $rows 行数组
     * @param callable $keyFn fn(array $row): string  生成 map key
     * @return array
     */
    protected function buildMap(array $rows, callable $keyFn): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$keyFn($row)] = $row;
        }
        return $map;
    }
}
