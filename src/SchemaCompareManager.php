<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare;

use TxAdmin\SchemaCompare\Exceptions\DriverNotFoundException;
use TxAdmin\SchemaCompare\Exceptions\InvalidBaselineException;
use TxAdmin\SchemaCompare\Exceptions\SchemaCompareException;

/**
 * 驱动注册中心
 *
 * 职责：
 *   - 注册 / 获取 AbstractSchemaDriver 实例
 *   - 代理 exportJson / compareFromJson / compareFromArray 调用
 */
class SchemaCompareManager
{
    /** @var AbstractSchemaDriver[] key = driver.getType() */
    private array $drivers = [];

    /**
     * 注册驱动
     *
     * @param AbstractSchemaDriver $driver
     * @return self
     * @throws SchemaCompareException 同 type 重复注册时抛出
     */
    public function register(AbstractSchemaDriver $driver): self
    {
        $type = $driver->getType();
        if (isset($this->drivers[$type])) {
            throw new SchemaCompareException(
                "驱动类型 '{$type}' 已注册，不可重复覆盖（原有: " . get_class($this->drivers[$type]) . ', 新增: ' . get_class($driver) . '）'
            );
        }
        $this->drivers[$type] = $driver;
        return $this;
    }

    /**
     * 获取已注册的驱动
     *
     * @throws DriverNotFoundException
     */
    public function driver(string $type): AbstractSchemaDriver
    {
        if (!isset($this->drivers[$type])) {
            throw new DriverNotFoundException("未注册的驱动类型：{$type}");
        }
        return $this->drivers[$type];
    }

    public function hasDriver(string $type): bool
    {
        return isset($this->drivers[$type]);
    }

    /**
     * 返回所有已注册的 type 列表
     */
    public function types(): array
    {
        return array_keys($this->drivers);
    }

    // ----------------------------------------------------------------
    // 便捷代理方法
    // ----------------------------------------------------------------

    /**
     * 导出为 JSON 字符串
     *
     * @throws DriverNotFoundException
     */
    public function exportJson(string $type, string $database): string
    {
        return $this->driver($type)->exportJson($database);
    }

    /**
     * 比较 JSON 基准 vs 实时数据
     *
     * @throws DriverNotFoundException
     * @throws InvalidBaselineException
     */
    public function compareFromJson(string $type, string $jsonBaseline, string $database): array
    {
        return $this->driver($type)->compareFromJson($jsonBaseline, $database);
    }

    /**
     * 比较数组基准 vs 实时数据
     *
     * @throws DriverNotFoundException
     */
    public function compareFromArray(string $type, array $baseline, string $database): array
    {
        return $this->driver($type)->compareFromArray($baseline, $database);
    }
}
