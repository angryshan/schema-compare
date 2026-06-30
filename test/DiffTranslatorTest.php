<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Test;

use PHPUnit\Framework\TestCase;
use TxAdmin\SchemaCompare\Translator\DiffTranslator;

class DiffTranslatorTest extends TestCase
{
    public function testTranslateEmptyDiff(): void
    {
        $translator = new DiffTranslator();
        $result = $translator->translate([], '字段');

        $this->assertFalse($result['has_diff']);
        $this->assertSame([], $result['summary']);
        $this->assertSame([], $result['明细']);
    }

    public function testTranslateGroupedDiff(): void
    {
        $translator = new DiffTranslator([
            '字段' => [
                'type' => '类型',
            ],
        ]);

        $rawDiff = [
            'has_diff' => true,
            'summary' => [
                'only_in_baseline' => 0,
                'only_in_live' => 0,
                'field_changed' => 1,
            ],
            'diffs_by_table' => [
                'users' => [
                    'field_changed' => [
                        'name' => [
                            'type' => ['String', 'Int32'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $translator->translate($rawDiff, '字段');

        $this->assertTrue($result['是否有不同']);
        $this->assertSame('字段', $result['对比维度']);
        $this->assertSame(1, $result['总结']['属性变化']);
        $this->assertArrayHasKey('users', $result['明细']);
        $this->assertSame(['String', 'Int32'], $result['明细']['users']['属性变化']['name']['类型']);
    }
}
