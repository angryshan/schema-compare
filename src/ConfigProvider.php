<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare;

/**
 * Hyperf 集成入口
 *
 * 包不提供配置文件；驱动和策略完全通过构造函数注入，由应用层自行组装。
 */
class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'annotations' => [
                'scan' => [
                    'paths' => [__DIR__],
                ],
            ],
        ];
    }
}
