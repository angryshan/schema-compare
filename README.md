# schema-compare

## 简介

框架无关的数据库结构对比 Composer 包，支持 ClickHouse 和 MySQL，适配 ThinkPHP 6 / Hyperf。通过策略模式按维度（字段、索引、投影等）拉取结构并 diff，基准与线上结构对比结果可进一步翻译为中文输出。

## 功能

- ✨ 框架无关核心，提供 ThinkPHP / Hyperf 连接适配器
- 🚀 支持 ClickHouse（字段、索引、投影）与 MySQL（字段、索引、表）结构对比
- 🔧 策略可插拔，支持自定义对比字段与扩展新维度
- 📦 提供 `SchemaCompareManager` 多驱动注册与 `DiffTranslator` 差异结果翻译

## 环境要求

- PHP >= 7.4
- Hyperf >= 2.0（可选，使用 Hyperf 适配器时）
- ThinkPHP 6（可选，使用 ThinkPHP 适配器时）

## 安装

```bash
composer require tx-admin/schema-compare 
```

## 快速开始

### 1. Hyperf 集成

安装后通过 Composer `extra.hyperf` 自动注册 `ConfigProvider`，完成注解扫描，**无需发布配置文件**。驱动与策略由应用层通过构造函数注入组装。

### 2. ThinkPHP 6 使用

```php
<?php

use TxAdmin\SchemaCompare\Adapters\ThinkPHPAdapter;
use TxAdmin\SchemaCompare\Driver\CkSchemaDriver;

$adapter = new ThinkPHPAdapter('clickhouse');   // 连接池名
$driver  = new CkSchemaDriver($adapter);

// 导出基准 JSON
$json = $driver->exportJson('2001_log_metadata');
file_put_contents('/path/to/baseline.json', $json);

// 对比基准 vs 实时
$result = $driver->compareFromJson(
    file_get_contents('/path/to/baseline.json'),
    '2001_log_metadata'
);

var_dump($result['has_diff']);   // bool
var_dump($result['details']);    // ['columns' => [...], 'indexes' => [...], 'projections' => [...]]
```

### 3. Hyperf 使用

```php
<?php

use TxAdmin\SchemaCompare\Adapters\HyperfAdapter;
use TxAdmin\SchemaCompare\Driver\CkSchemaDriver;

$adapter = new HyperfAdapter('clickhouse');
$driver  = new CkSchemaDriver($adapter);

$result = $driver->compareFromJson($jsonBaseline, $database);
```

### 4. 管理器（多驱动场景）

```php
<?php

use TxAdmin\SchemaCompare\Adapters\ThinkPHPAdapter;
use TxAdmin\SchemaCompare\Driver\CkSchemaDriver;
use TxAdmin\SchemaCompare\Driver\MysqlSchemaDriver;
use TxAdmin\SchemaCompare\SchemaCompareManager;

$manager = new SchemaCompareManager();
$manager->register(new CkSchemaDriver(new ThinkPHPAdapter('clickhouse')));
$manager->register(new MysqlSchemaDriver(new ThinkPHPAdapter('default')));

$result = $manager->compareFromJson('clickhouse', $jsonBaseline, $database);
```

### 5. 差异结果翻译

```php
<?php

use TxAdmin\SchemaCompare\Translator\DiffTranslator;

$translator = new DiffTranslator([
    'columns' => [
        'type'           => '类型',
        'is_nullable'    => '可空',
        'column_default' => '默认值',
    ],
]);

$translated = $translator->translate($result['details']['columns'], '字段');
```

### 代码规范

本项目遵循 PSR-12 代码规范。

## 许可证

本项目采用 [MIT](LICENSE) 许可证。
