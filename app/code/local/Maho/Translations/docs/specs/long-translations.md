# 功能规格：超长文案翻译

> 状态：已交付@0.3.0
> 关联 PRD：FR-13
> 关联 ADR：ADR-0008
> 模块：`Maho_Translations`
> 分支：`feature/long-translations`

## 1. 背景与目标

`core_translate` 的 `string` 和 `translate` 都是 `VARCHAR(255)`。第一期把超过 255 字符的源文案标记为 `is_supported = 0`，编辑页只读，CSV 导入拒绝，运行时不支持长翻译。

本功能增加长文案存储和运行时合并，让超过 255 字符的界面文案也能编辑、导入导出、被 AI 生成草稿，并在前台和后台生效。

## 2. 需求

### 2.1 范围内

- 超过 255 字符的源文案可以编辑翻译。
- 超过 255 字符的翻译可以保存。
- 长文案按 locale 和 store 保存，支持全局覆盖和 store 覆盖。
- 后台网格、编辑页、CSV 导入导出和 AI 草稿都支持长文案。
- 运行时翻译器加载长文案，保存或清除后刷新翻译缓存。
- 网格状态正常计算，增加 `Long` 筛选。
- 长表和 `core_translate` 互斥，长表优先。

### 2.2 范围外

- 超过 `TEXT` 上限 65535 字符的文案。
- 内联编辑，属于第三期 FR-11。
- 商品、分类、CMS 内容翻译，属于 FR-12。
- 审计、回滚和修改历史，属于 FR-14。
- 主题 `translate.csv` 和 `jstranslator.xml` 的长文案管理。
- 公开 REST、GraphQL 或 MCP 接口。

### 2.3 FR 映射

| FR | 本 spec 章节 |
|---|---|
| FR-13 | 2 需求、3 设计、5 验收标准 |

## 3. 设计

### 3.1 依赖与版本

- 模块版本从 `0.2.0` 升到 `0.3.0`。
- 依赖已交付的 FR-01 到 FR-10。
- 重写 `core_resource/translate` 资源模型，不替换 `core/translate` 单例，不重写 `Mage_Core_Model_Translate`。
- 新增长文案表，不修改 `core_translate` 表结构。

### 3.2 数据模型

新增表 `translation_long`：

| 字段 | 类型 | 说明 |
|---|---|---|
| `long_id` | INTEGER UNSIGNED PK AUTO | 主键 |
| `source_hash` | VARCHAR(32) | `md5(source_text)` |
| `source_text` | TEXT | 源文案快照 |
| `translation` | TEXT | 长翻译 |
| `locale` | VARCHAR(20) | 目标 locale |
| `store_id` | SMALLINT UNSIGNED | 0 为全局，其他为 store 覆盖 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

唯一键：`source_hash, locale, store_id`。普通索引：`locale, store_id`。不建到 `translation_source` 的外键，扫描重建 source 表时保留长翻译。

### 3.3 存储路由

在 `Maho_Translations_Model_Catalog` 增加统一写入方法，`saveTranslation()`、`clearTranslation()` 和 CSV 导入共用：

- 计算 `$needsLong = mb_strlen($sourceText) > 255 || mb_strlen($translation) > 255`。
- 翻译为空或等于源文案：删除同 scope 的长表行和 core 行。
- `$needsLong`：删除同 scope 的 core 行，upsert 长表行。
- 否则：删除同 scope 的长表行，写 `core_translate`。
- 所有路径校验 locale、store 和占位符；长度只受 TEXT 上限约束。
- 缓存刷新由调用方在批量结束后统一执行，避免 CSV 每行刷新。

### 3.4 运行时合并

新增 `Maho_Translations_Model_Resource_Translate`，继承 `Mage_Core_Model_Resource_Translate`，在模块配置里重写 `core_resource/translate` 资源：

- `getTranslationArray($storeId, $locale)` 先取核心结果，再合并 `translation_long` 中 store 0 和当前 store 的记录，store 覆盖最后写入。
- `getTranslationArrayByStrings($strings, $storeId)` 对指定源文案做同样合并。
- 长表记录排在 core 之后，长表优先。
- 核心翻译缓存会包含长文案；保存或清除长文案后清理 `Mage_Core_Model_Translate::CACHE_TAG`。
- 不修改 `Mage_Core_Model_Translate` 的加载顺序和缓存机制。

### 3.5 网格与状态

- `Maho_Translations_Model_Resource_Source_Collection` 增加长表 store/global 两个 left join。
- 生效翻译优先级：
  `长表 store > 长表 global > core store > core global > pack > source`。
- `translation_status` 不再产生 `unsupported`；长文案按 `missing`、`translated`、`conflict` 正常计算。
- `getStatusOptions()` 把 `unsupported` 替换为 `long`，筛选条件为 `source_length > 255`。
- 长度列继续显示实际字符数。
- 网格源文案超过 255 字符时仍完整保存在 TEXT 中，展示使用 longtext renderer 截断。

### 3.6 编辑与保存

- 编辑页去掉 `is_supported` 导致的只读和保存阻断。
- 保存时不再拒绝超过 255 字符的源文案或翻译。
- `Catalog::getEffectiveTranslation()`、`getStoreOverride()` 和 `getGlobalOverride()` 优先读长表。
- 清除覆盖同时删除长表和 core 行。
- 占位符校验、locale/store 校验和缓存刷新保持不变。

### 3.7 CSV

- 导出 `effective`、`missing`、`overrides` 都包含长文案。
- `missing` 判断使用新的生效翻译 SQL，包含长表。
- 导入不再因 255 字符拒绝源文案或翻译。
- 导入逐行调用统一存储路由；整批事务，失败时全部回滚。
- 重复导入仍然幂等。
- `overrides` 导出合并长表和 core，长表优先。

### 3.8 AI 草稿

- AI 生成不再跳过 `is_supported = 0` 的源文案。
- AI 草稿校验不再应用 255 字符上限，只应用占位符和空值校验。
- 通过草稿时调用统一存储路由，长翻译写入 `translation_long`。
- AI 草稿表 `draft_translation` 已是 TEXT，不需要改表。
- provider 上下文限制导致的长文案失败会标记草稿 `failed`，不影响其他草稿。

### 3.9 缓存与并发

- 保存、清除、CSV 导入成功后清理翻译缓存。
- 长表写入和 core 删除在同一个事务里完成。
- 同一 scope 并发保存采用最后写入生效。
- 升级后必须执行 `./maho migrate` 和 `./maho cache:flush`，否则旧缓存可能不含长文案。

### 3.10 安全

- 长文案按纯文本处理，输出转义。
- 占位符校验仍然阻止缺失或多出的 printf、模板指令和 HTML 标签。
- SQL 参数化，不拼接用户输入。
- 所有写操作继续校验 ACL 和后台表单密钥。

## 4. 任务拆分

- [x] PRD 更新 FR-13 描述、状态和 AC-12。
- [x] 新增 ADR-0008，取代 ADR-0004 的只读决策。
- [x] 新增 `translation_long` schema、模型、资源模型和集合。
- [x] 新增统一存储路由，改造 Catalog、controller 和 Transfer。
- [x] 新增 `core_resource/translate` 资源重写和长文案合并。
- [x] 改造网格集合、状态和 Long 筛选。
- [x] 改造编辑页、清除和缓存刷新。
- [x] 改造 CSV 导入导出和 overrides。
- [x] 改造 AI 草稿生成和审批。
- [x] 新增单元测试、集成测试和运行时测试。
- [x] 更新 ARCHITECTURE、API、README、CHANGELOG 和模块规则。
- [x] 在 SQLite、MySQL、PostgreSQL 和真实 storefront 验证。

## 5. 验收标准

- 超过 255 字符的源文案可以保存翻译，长表出现记录，`core_translate` 没有同 scope 记录。
- 超过 255 字符的翻译可以保存，短源文案也能写长表，原 core 记录被清理。
- 保存短翻译时清理同 scope 的长表记录。
- 清除覆盖同时删除长表和 core 记录，回退到语言包或源文案。
- store 覆盖优先于全局覆盖，长表优先于 core。
- 运行时 `getTranslationArray()` 和 `getTranslationArrayByStrings()` 返回长翻译。
- 前台或后台实际文案使用长翻译，保存/清除后立即变化。
- CSV 导出包含长文案，导入后长表记录正确，重复导入幂等。
- AI 草稿可以生成和通过长文案，不因 255 字符被跳过或拒绝。
- 网格状态正常，`Long` 筛选只显示 `source_length > 255` 的源文案。
- 短文案原有行为、占位符校验、ACL 和缓存测试保持通过。
- SQLite、MySQL 8.0、PostgreSQL 18.7 测试通过；真实 storefront 手工验证。

## 6. 风险与待定

- 重写 `core_resource/translate` 资源会影响全局翻译加载，必须用集成测试锁住短文案行为。
- 旧翻译缓存可能不含长文案，升级后必须执行 migrate 和 cache flush。
- `TEXT` 上限 65535；更大的文案不在本期范围。
- 长文案表和 `core_translate` 的互斥依赖统一存储路由，绕过模块直接写 core 仍可能产生不一致，文档需要注明。
- AI provider 的上下文长度可能小于 65535，超长输入可能单条失败。

## 7. 实现与验证

| 项目 | 证据 |
|---|---|
| Schema | `translation_long` 已在 `sql/schema.php` 定义，SQLite、MySQL 8.0、PostgreSQL 18.7 执行 `./maho migrate` 成功 |
| 存储路由 | 长文案与 core 互斥，长表优先；保存短翻译清理长表，保存长翻译清理 core |
| 运行时 | `core_resource/translate` 资源重写生效，`getTranslationArray()` 和 `getTranslationArrayByStrings()` 返回长翻译 |
| 测试合计 | 82 passed，339 assertions，SQLite、MySQL 8.0、PostgreSQL 18.7 均通过 |
| Lint | 模块范围 php-cs-fixer、PHPStan、Rector 通过 |
| 后台手工验证 | 699 字符源文案保存、Long 筛选、Clear Override、翻译缓存刷新通过 |
| 运行时手工验证 | 长翻译在核心翻译器返回，清除后恢复源文案 |
| AI 手工验证 | DeepSeek generic provider 生成 699 字符草稿，人工修改后通过，长表写入成功 |
