# schema-compare

## 简介

框架无关的数据库结构对比 Composer 包，支持 ClickHouse 和 MySQL，适配 ThinkPHP 6 / Hyperf。通过策略模式按维度（字段、索引、投影等）拉取结构并 diff，基准与线上结构对比结果可进一步翻译为中文输出。

## 功能

- ✨ 框架无关核心，提供 ThinkPHP / Hyperf 连接适配器
- 🚀 支持 ClickHouse（字段、索引、投影）与 MySQL（字段、索引、表）结构对比
- 🔧 策略可插拔，支持自定义对比字段与扩展新维度
- 📦 提供 `SchemaCompareManager` 多驱动注册
- 🛠️ 内置 `SqlGenerator` SQL 生成器，支持 MySQL 和 ClickHouse 语法差异处理

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
$diffResult = $driver->compareFromJson(
    file_get_contents('/path/to/baseline.json'),
    '2001_log_metadata'
);

var_dump($diffResult['has_diff']);   // bool
var_dump($diffResult['details']);    // ['columns' => [...], 'indexes' => [...], 'projections' => [...]]
// details 各字段含义见 docs/diff-details.md
```

> **`details` 字段详解**：[diff-details.md](diff-details.md)（含 columns / indexes / tables / projections 各维度结构、对比字段与示例）

### 3. Hyperf 使用

```php
<?php

use TxAdmin\SchemaCompare\Adapters\HyperfAdapter;      // PDO 方式（ClickHouse）
use TxAdmin\SchemaCompare\Adapters\HyperfORMAdapter;   // ORM 方式（MySQL）
use TxAdmin\SchemaCompare\Driver\CkSchemaDriver;
use TxAdmin\SchemaCompare\Driver\MysqlSchemaDriver;

// ClickHouse（PDO 方式）
$adapter = new HyperfAdapter('clickhouse');
$driver  = new CkSchemaDriver($adapter);

// MySQL（ORM 方式）
$adapter = new HyperfORMAdapter('default');
$driver  = new MysqlSchemaDriver($adapter);

$diffResult = $driver->compareFromJson($jsonBaseline, $database);
```

**Hyperf 适配器说明：**

| 适配器 | 组件 | 适用数据库 | 说明 |
|--------|------|------------|------|
| `HyperfAdapter` | `hyperf/db` (PDO) | ClickHouse | 轻量级 SQL 执行，与 ThinkPHP 的 PDO 方式命名一致 |
| `HyperfORMAdapter` | `hyperf/db-connection` (ORM) | MySQL | 基于 Eloquent ORM，提供连接池和模型功能 |

### 4. 跨库对比（不同库名）

```php
<?php

// 使用 diff() 方法直接对比（无库名校验）
$baseline = json_decode(file_get_contents('/path/to/baseline.json'), true);
$live = $driver->fetchStructure('target_database');
$diffResult = $driver->diff($baseline, $live);

$diffResult = $driver->compareFromArray($baseline, 'target_database');
```

### 5. 管理器（多驱动场景）

```php
<?php

use TxAdmin\SchemaCompare\Adapters\ThinkPHPAdapter;
use TxAdmin\SchemaCompare\Driver\CkSchemaDriver;
use TxAdmin\SchemaCompare\Driver\MysqlSchemaDriver;
use TxAdmin\SchemaCompare\SchemaCompareManager;

$manager = new SchemaCompareManager();
$manager->register(new CkSchemaDriver(new ThinkPHPAdapter('clickhouse')));
$manager->register(new MysqlSchemaDriver(new ThinkPHPAdapter('default')));

$diffResult = $manager->compareFromJson('clickhouse', $jsonBaseline, $database);
```

### 6. SQL 生成器（支持 MySQL 和 ClickHouse）

```php
<?php

use TxAdmin\SchemaCompare\Generator\SqlGenerator;
use TxAdmin\SchemaCompare\Generator\Mysql\MysqlSqlGenerator;
use TxAdmin\SchemaCompare\Generator\Ck\CkSqlGenerator;

// 方式1：通过字符串创建（工厂模式）
$generator = new SqlGenerator('mysql'); // 或 'clickhouse'

// 方式2：直接使用具体生成器（推荐，更灵活）
$generator = new MysqlSqlGenerator();
// $generator = new CkSqlGenerator();

// $diffResult 通过 $driver->compareFromJson() 或 $driver->diff() 获取
// 基础版：根据 diff 结果生成所有 SQL
$sqls = $generator->generateAll($diffResult);

// 精确版：传入 live 数据和基准数据，生成更精确的 SQL（含字段位置调整）
// liveData 和 baselineData 通过 $driver->fetchStructure($database) 获取
$liveData = $driver->fetchStructure('2001_log_metadata');
$baselineData = json_decode(file_get_contents('/path/to/baseline.json'), true);
$sqls = $generator->generatePreciseSql($diffResult, $liveData, $baselineData);

// 合并所有 SQL 为一个字符串
$allSql = $generator->combineSql($sqls);
```

#### 支持的操作类型：

| 操作 | MySQL | ClickHouse |
|------|-------|------------|
| 添加列 | `ALTER TABLE ... ADD COLUMN` (自动带 AFTER/FIRST) | `ALTER TABLE ... ADD COLUMN` (支持 CODEC) |
| 删除列 | `ALTER TABLE ... DROP COLUMN` | `ALTER TABLE ... DROP COLUMN IF EXISTS` |
| 修改列类型 | `ALTER TABLE ... MODIFY COLUMN` | 不支持直接修改，需先删后加 |
| 修改列位置 | `ALTER TABLE ... MODIFY COLUMN ... AFTER/FIRST` | 不支持 |
| 修改列注释 | `ALTER TABLE ... MODIFY COLUMN ... COMMENT` | `ALTER TABLE ... MODIFY COLUMN COMMENT` |
| 修改默认值 | `ALTER TABLE ... ALTER COLUMN SET DEFAULT` | `ALTER TABLE ... ALTER COLUMN DEFAULT` |
| 创建索引 | `CREATE INDEX` | `ALTER TABLE ... ADD INDEX TYPE` |
| 删除索引 | `ALTER TABLE ... DROP INDEX` | `ALTER TABLE ... DROP INDEX IF EXISTS` |
| 删除表 | `DROP TABLE IF EXISTS` | `DROP TABLE IF EXISTS` |

#### MySQL 特殊功能：

1. **字段位置自动调整**：对比 `ordinal_position`，自动生成 `AFTER` 或 `FIRST` 子句
2. **索引 SQL 去重**：同一索引不会重复生成 DROP/CREATE
3. **宽松对比模式**：支持忽略空格、连字符等格式差异（通过 `setLooseComparison(true)` 开启）

#### ClickHouse 特殊限制说明：

1. **类型修改**：CK 不支持直接修改列类型，需要先删除再添加
2. **压缩编码**：不支持在线修改，生成提示性 SQL 注释
3. **表引擎**：不支持在线修改 ENGINE，需重建表（且阿里云与本地引擎名称可能不同，已跳过对比）
4. **排序键/主键列**：不能删除属于排序键或主键的列
5. **Dictionary 引擎**：不支持 `DROP COLUMN` 等 ALTER 操作

### 代码规范

本项目遵循 PSR-12 代码规范。

## 许可证

本项目采用 [MIT](LICENSE) 许可证。
