<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Processor;

/**
 * Diff 结果处理器管道（post-diff）
 *
 * 用于处理 diff() 返回的差异结果
 * 典型处理器：MissingTableSimplifier
 */
class DiffPipeline
{
    /** @var array 处理器列表 */
    protected array $processors = [];

    /**
     * 添加处理器
     *
     * @param object $processor 处理器实例（需实现 process(array $diff): array）
     * @return self
     */
    public function add(object $processor): self
    {
        $this->processors[] = $processor;
        return $this;
    }

    /**
     * 处理 diff 结果
     *
     * @param array $diffResult diff 结果 ['has_diff' => true, 'details' => [...]]
     * @return array 处理后的 diff 结果
     */
    public function process(array $diffResult): array
    {
        foreach ($this->processors as $processor) {
            if (method_exists($processor, 'process')) {
                $diffResult = $processor->process($diffResult);
            }
        }
        return $diffResult;
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
