<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Processor;

/**
 * 结构数据处理器管道（pre-diff）
 *
 * 用于处理 fetchStructure() 返回的原始结构数据
 * 典型处理器：TableFilter
 */
class StructurePipeline
{
    /** @var array 处理器列表 */
    protected array $processors = [];

    /**
     * 添加处理器
     *
     * @param object $processor 处理器实例（需实现 process(array $structure): array）
     * @return self
     */
    public function add(object $processor): self
    {
        $this->processors[] = $processor;
        return $this;
    }

    /**
     * 处理结构数据
     *
     * @param array $structure 结构数据 ['columns' => [...], 'indexes' => [...]]
     * @return array 处理后的结构数据
     */
    public function process(array $structure): array
    {
        foreach ($this->processors as $processor) {
            if (method_exists($processor, 'process')) {
                $structure = $processor->process($structure);
            }
        }
        return $structure;
    }

    /**
     * 清空处理器
     */
    public function clear(): self
    {
        $this->processors = [];
        return $this;
    }
}
