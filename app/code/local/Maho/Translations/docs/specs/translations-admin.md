# 功能规格：后台翻译管理

> 状态：已交付@0.1.1（0.1.1 为扫描器内部重构）
> 关联 PRD：FR-01 到 FR-09
> 关联 ADR：ADR-0001、ADR-0002、ADR-0003、ADR-0004、ADR-0005
> 超长文案只读行为已由 FR-13 取代，见 `docs/specs/long-translations.md`。
> 模块：`Maho_Translations`
> 分支：`feature/translations-admin`

## 1. 背景与目标

Maho 没有后台翻译管理界面，界面文案分散在语言包 CSV 和 `core_translate` 中。管理员无法看到某条文案来自哪里、是否缺失、是否未使用，也不能安全地按 store 覆盖翻译。

本功能给后台增加一个界面文案管理入口，覆盖扫描、浏览、编辑、CSV 往返和占位符校验。运行时翻译仍由 Maho 核心负责。

## 2. 需求

### 2.1 范围内

- 扫描已安装语言包和 PHP/PHTML/XML 源码，建立文案索引。
- 按 locale、store、状态、模块、area、是否使用和文本筛选文案。
- 查看生效翻译和来源（store 覆盖、全局覆盖、语言包、源文案）。
- 编辑、保存、清除翻译覆盖。
- 保存和导入前校验占位符。
- 导出全部生效翻译、缺失文案或仅数据库覆盖。
- 导入两列 CSV，整批校验后事务写入。
- System 菜单和 ACL 权限。
- `translations:scan` CLI。

### 2.2 范围外

- 商品、分类、CMS 内容翻译。
- AI 辅助翻译见 `docs/specs/ai-translation.md`。
- 前台/后台内联编辑。第一期不做，ADR-0006 将其推迟到第三期。
- 主题 `translate.csv` 编辑。
- `jstranslator.xml` 文案管理。
- 超长文案（超过 255 字符）编辑。
- 审计日志、回滚、翻译记忆、术语表。
- 公开 API。

### 2.3 FR 映射

| FR | 本 spec 章节 |
|---|---|
| FR-01 | 3.3 扫描 |
| FR-02 | 3.4 网格 |
| FR-03 | 3.5 编辑 |
| FR-04 | 3.6 占位符校验 |
| FR-05 | 3.7 CSV 导出 |
| FR-06 | 3.8 CSV 导入 |
| FR-07 | 3.4 状态与筛选 |
| FR-08 | 3.9 菜单与权限 |
| FR-09 | 3.10 CLI |

## 3. 设计

### 3.1 模块与路由

- 模块名：`Maho_Translations`
- 代码路径：`app/code/local/Maho/Translations`
- 模块声明：`app/etc/modules/Maho_Translations.xml`
- 依赖：`Mage_Core`、`Mage_Adminhtml`
- 版本：`0.1.1`

后台路由使用 `#[Maho\Config\Route]`：

| 方法 | 路径 | Action |
|---|---|---|
| GET | `/admin/translations/index` | `indexAction` |
| GET | `/admin/translations/grid` | `gridAction` |
| GET | `/admin/translations/edit` | `editAction` |
| POST | `/admin/translations/save` | `saveAction` |
| POST | `/admin/translations/delete` | `deleteAction` |
| POST | `/admin/translations/scan` | `scanAction` |
| GET/POST | `/admin/translations/import` | `importAction` |
| GET | `/admin/translations/export` | `exportAction` |

控制器：`Maho_Translations_Adminhtml_TranslationsController`，继承 `Mage_Adminhtml_Controller_Action`。

### 3.2 数据模型

使用 ADR-0002 的索引表，详细字段见 `docs/ARCHITECTURE.md` 第 3 节：

- `translation_source`
- `translation_location`
- `translation_pack`
- `translation_scan`

翻译数据仍写 `core_translate`，不新建翻译表。

### 3.3 扫描

#### 3.3.1 入口和锁

- 后台 `scanAction` 和 CLI `translations:scan` 调用同一个 `Maho_Translations_Model_Scanner::scan()`。
- 扫描前检查 `translation_scan` 是否有 10 分钟内的 `running` 记录。有则拒绝，提示“扫描正在进行”。
- 扫描结束后把状态改为 `complete` 或 `failed`。

#### 3.3.2 文件范围

只扫描：

- `app/code/{core,community,local}/**/*.{php,phtml}`
- `app/design/{frontend,adminhtml,install}/**/*.{php,phtml,xml}`
- `app/code/**/etc/*.xml`
- `app/locale/*/*.csv`

不扫描：

- `vendor/` 下的普通 PHP 库。
- `node_modules/`、`.git/`、`var/`、`public/media/`。
- 超过 2 MB 的单文件。
- 二进制文件。

#### 3.3.3 PHP/PHTML 解析

匹配：

```php
__('Text')
__("Text")
$this->__('Text')
```

使用 `token_get_all()` 解析，而不是正则。只有 `__(` 的第一个参数是单一字符串字面量，且后面紧跟 `,` 或 `)` 时才索引。注释忽略；变量参数、双引号插值、字符串拼接和 heredoc 计为动态文案，不进入索引。

#### 3.3.4 XML 解析

- 使用 `DOMDocument` 和 `DOMXPath`，`libxml_use_internal_errors(true)`。
- 找到所有带 `translate` 属性的节点。
- 对 `translate` 属性里的每个子节点名，取该子节点文本。
- 记录节点路径，例如 `system/section/group/field/label`。
- 根据节点路径里的 `adminhtml`、`frontend`、`install` 段修正 area。
- 格式错误计入 `skipped_count`，不中断扫描。

#### 3.3.5 CSV 解析

- 读取 `app/locale/<locale>/*.csv`，先去掉 UTF-8 BOM。
- 文件名去掉 `.csv` 作为模块名。
- 第一列源文案，第二列翻译。
- 空源文案跳过。
- 同一 locale + 源文案重复时保留第一条，记录冲突数量和备选翻译。

#### 3.3.6 索引写入

- 在事务里清空三张索引表。
- `translation_source` 按 `source_hash` 去重。
- `translation_location` 按 `(source_id, module, area, file, line)` 去重。
- `translation_pack` 按 `(locale, source_hash)` 去重。
- 批量插入，每批 500 条。
- 扫描完成后保留最近 20 条 `translation_scan` 记录。

#### 3.3.7 统计

扫描结果至少包含：

- 扫描文件数。
- 源文案数。
- 位置数。
- 语言包翻译数。
- 动态文案跳过数。
- 超长文案数。
- 耗时。

### 3.4 网格

#### 3.4.1 选择器

页面顶部提供：

- Locale：可编辑下拉，默认 `en_US`。
- Store：下拉，默认 `0`（All Store Views）。
- 状态：All、Missing、Translated、Unused、Unsupported。
- 模块：可编辑下拉。
- Area：All、Frontend、Adminhtml、Install。
- 搜索：源文案或翻译包含的文本。

选择器提交 GET 到 `*/*/index`。

#### 3.4.2 列

| 列 | 内容 |
|---|---|
| Source | 源文案，长文本截断显示，完整内容放 `title` |
| Translation | 生效翻译，长文本截断 |
| Status | Missing / Translated / Conflict / Unsupported 徽标 |
| Used | Yes / No；No 时显示“可能未使用” |
| Module | 模块名，多个用逗号分隔 |
| Area | area，多个用逗号分隔 |
| Conflicts | `translation_pack.conflict_count` |
| Length | 源文案字符数 |
| Action | Edit |

#### 3.4.3 状态定义

- `Unsupported`：`is_supported = 0`。
- `Missing`：有支持编辑，生效翻译为空或等于源文案。
- `Translated`：有支持编辑，生效翻译不等于源文案。
- `Conflict`：语言包存在多个不同翻译，`translation_pack.conflict_count > 0`。
- `Unused`：`is_used = 0`，独立筛选维度，可以和 Missing/Translated 重叠。

#### 3.4.4 生效翻译

```text
store 覆盖 > 全局覆盖 > 语言包翻译 > 源文案
```

#### 3.4.5 空状态

没有扫描记录或索引为空时，网格显示提示：

> 尚未扫描翻译源。请先运行扫描。

并提供“Scan now”按钮。

### 3.5 编辑

#### 3.5.1 页面布局与表单字段

编辑页与 Maho 其他编辑页一致，使用左侧 tabs、右侧表单的两列结构：

- `Translation`：源文案、当前翻译、生效翻译和占位符提示。
- `Context`：全局覆盖、语言包翻译和语言包冲突。
- `Usage`：引用该源文案的模块、area、文件和行号。

切换 tab 不丢失已输入内容；保存按钮始终提交同一个 `edit_form`。

**Translation tab**

- Source：只读，源文案。
- Locale：隐藏。
- Store：隐藏，保存时使用当前 scope。
- Translation：可编辑 textarea，允许为空表示清除。
- Current effective translation：只读，当前生效翻译。
- Placeholders：只读，列出源文案中的占位符。

**Context tab**

- Global override：只读，全局数据库翻译。
- Pack translation：只读，语言包翻译。
- Language pack conflicts：有冲突时只读，列出备选翻译。

**Usage tab**

- Used in：只读，模块、area、文件路径和行号。

#### 3.5.2 保存

- 校验表单密钥和 ACL。
- 校验 locale 存在、store 存在、源文案在索引里。
- 校验源文案 `is_supported = 1`。
- 校验翻译长度不超过 255。
- 校验占位符。
- 如果翻译为空或等于源文案：
  - 删除当前 store 的 `core_translate` 记录。
  - 如果是全局编辑（`store_id = 0`），删除全局记录。
- 否则调用 `Mage_Core_Model_Resource_Translate_String::saveTranslate()`：
  - 参数：源文案、翻译、locale、store ID。
- 清理 `translate` 缓存。
- 成功消息：“Translation saved.”
- 失败时保留表单数据并显示错误。

#### 3.5.3 清除覆盖

- 编辑页提供“Clear override”按钮。
- 删除当前 locale + store 的 `core_translate` 记录。
- 清理缓存。
- 成功后返回网格，显示“Override cleared.”

#### 3.5.4 并发

- 最后写入生效。
- 第一期不做乐观锁。
- `core_translate` 唯一索引防止重复记录。

### 3.6 占位符校验

#### 3.6.1 printf

提取：

- `%s`、`%d`、`%u`、`%f`、`%c`、`%x`、`%o`、`%b`、`%e`、`%g`、`%%`。
- 位置参数：`%1$s`、`%2$d`。
- 修饰符：`%1$02d`、`%-10s`。

比较完整标记的多重集合。源文案有 `%1$s` 和 `%2$s`，翻译必须同时有这两个标记。

#### 3.6.2 模板指令

提取 `{{...}}` 片段，trim 后比较多重集合。例如：

- `{{var customer.name}}`
- `{{block type="core/template"}}`

指令内部空格归一化后再比较，避免无关空格导致误报。

#### 3.6.3 HTML 标签

提取标签名，忽略属性：

- `<p>` 和 `</p>` 都记为 `p`。
- `<br/>` 和 `<br>` 都记为 `br`。
- 比较标签名多重集合。

#### 3.6.4 失败信息

示例：

```text
Placeholder mismatch: missing %s; extra %d
```

CSV 导入时信息带行号：

```text
Row 12: Placeholder mismatch: missing {{var sku}}
```

### 3.7 CSV 导出

#### 3.7.1 入口

`adminhtml/translations/export` 在没有 `download` 参数时渲染导出表单，选择 locale、store、scope。带 `download=1` 时下载 CSV。

#### 3.7.2 范围

- `effective`：所有可编辑源文案的生效翻译，超长源文案不导出。
- `missing`：可编辑、生效翻译为空或等于源文案的文案，第二列为空。
- `overrides`：只导出 `core_translate` 中当前 locale + store 的记录。

#### 3.7.3 格式

- UTF-8，无 BOM。
- 两列，逗号分隔，双引号转义。
- 无表头。
- 行顺序按源文案升序。
- 文件名：`translations_<locale>_store<store>_<scope>_<Ymd_His>.csv`。
- 以 `=`, `+`, `-`, `@`, tab 或 CR 开头的单元格加 `'` 前缀；导入时还原。

#### 3.7.4 实现

- 控制器设置 `Content-Type: text/csv; charset=UTF-8` 和 `Content-Disposition: attachment`。
- 使用 `php://temp` 生成 CSV，再写入响应 body。
- 不写临时文件。

### 3.8 CSV 导入

#### 3.8.1 上传校验

- locale 必须已安装或有数据库记录，store 必须是有效 store。
- 只接受 `.csv`。
- 实际文件大小最大 10 MB。
- MIME 必须是 `text/csv`、`text/plain`、`application/csv` 或 `application/vnd.ms-excel`。
- 读取前 3 个字节检查 UTF-8 BOM。
- 解析失败时显示行号。

#### 3.8.2 行校验

每一行：

- 至少两列。
- 源文案非空。
- 源文案和翻译长度不超过 255。
- 源文案在索引里存在且 `is_supported = 1`。
- 占位符匹配。
- 文件内源文案不重复。
- 翻译等于源文案时视为“清除覆盖”，不是错误。

任意一行失败：

- 收集所有错误。
- 不写入任何数据。
- 显示错误列表，最多显示前 50 条，其余计数。

#### 3.8.3 写入

全部通过后：

1. 开始事务。
2. 逐行保存：
   - 翻译为空或等于源文案：删除当前 store 记录。
   - 否则调用 `saveTranslate()`。
3. 提交。
4. 清理 `translate` 缓存。
5. 统计新增、更新、删除、未变化。
6. 显示结果。

异常时回滚，显示错误。

#### 3.8.4 幂等

同一文件重复导入：

- 翻译相同的行不重复写入。
- 统计里记为“未变化”。
- 不产生重复记录。

### 3.9 菜单与权限

#### 3.9.1 菜单

在 `adminhtml.xml` 的 `system` 菜单下增加：

```xml
<translations translate="title" module="translations">
    <title>Translations</title>
    <sort_order>25</sort_order>
    <action>adminhtml/translations/index</action>
</translations>
```

#### 3.9.2 ACL

```text
admin/system/translations
├── view
├── save
├── scan
├── import
└── export
```

控制器 `ADMIN_RESOURCE = 'system/translations'`，并设置 `ACTION_RESOURCES`：

- `index`、`grid`、`edit`：`system/translations`
- `save`、`delete`：`system/translations/save`
- `scan`：`system/translations/scan`
- `import`：`system/translations/import`
- `export`：`system/translations/export`

#### 3.9.3 菜单高亮

所有 action 设置 `_setActiveMenu('system/translations')`。

### 3.10 CLI

命令：

```bash
./maho translations:scan
```

选项：

- `--details`：输出扫描的文件数、耗时、跳过文件、XML 解析失败和语言包冲突。

第一期没有 `--locale` 定向扫描。

输出统计，退出码 `0` 或 `1`。

### 3.11 缓存

保存、清除、导入成功后：

```php
Mage::app()->cleanCache([Mage_Core_Model_Translate::CACHE_TAG]);
```

扫描不清理翻译缓存。

### 3.12 跨数据库与安全

- 使用 `Maho\Db\Select` 和 `quoteInto()`，不拼接用户输入。
- 批量插入使用 `insertMultiple()`。
- 事务使用适配器 API。
- 所有输出转义，包括编辑页标题里的源文案。
- CSV 导出对公式前缀做转义，导入时还原。
- 上传文件只读，不落盘到可执行目录。
- 扫描路径只从已安装包的 `app/code` 和 `app/design` 发现，不接受用户输入路径。

## 4. 任务拆分

### 4.1 文档与脚手架

- [x] 创建模块目录和 `app/etc/modules/Maho_Translations.xml`
- [x] 创建 `etc/config.xml`、`etc/adminhtml.xml`
- [x] 创建 `sql/schema.php`
- [x] 创建模块 `README.md`、`CHANGELOG.md`
- [x] 创建 `app/locale/en_US/Maho_Translations.csv`

### 4.2 扫描

- [x] `Model/Scanner.php` 文件发现
- [x] PHP/PHTML 解析和行号
- [x] XML 解析和节点路径
- [x] CSV 解析和模块名
- [x] 模块和 area 判断
- [x] 索引批量写入和事务
- [x] 扫描锁和统计
- [x] `translations:scan` CLI

### 4.3 网格与编辑

- [x] `Model/Resource/Source` 和集合
- [x] 网格查询：pack、global、store 覆盖、生效翻译
- [x] 网格块和模板
- [x] locale/store/status/module/area/搜索筛选
- [x] 编辑页左侧 Translation / Context / Usage tabs
- [x] 编辑块、表单和模板
- [x] 保存、清除、缓存清理
- [x] tab 切换后表单字段仍随 `edit_form` 提交
- [x] 错误处理和成功消息

### 4.4 CSV

- [x] 导出 effective/missing/overrides
- [x] 导出表单和下载响应
- [x] 导出公式前缀转义和导入还原
- [x] 导入上传校验
- [x] 导入逐行校验和错误汇总
- [x] 导入事务写入和幂等统计

### 4.5 校验与权限

- [x] 占位符校验器
- [x] 保存和导入接入校验
- [x] 菜单和 ACL
- [x] 控制器 action 权限映射

### 4.6 测试与文档

- [x] 单元测试：扫描、校验、CSV、生效翻译
- [x] 集成测试：保存、清除、导入、缓存
- [x] 人工验收：后台操作和 storefront
- [x] 交叉数据库验证
- [x] 同步 PRD、ARCHITECTURE、API、CHANGELOG

## 5. 验收标准

### 5.1 自动化验收

- [x] 扫描 fixture 后，源文案、位置、模块、area、行号正确。
- [x] 重复扫描不产生重复记录。
- [x] 动态文案、注释、插值和拼接不进入索引，并计数。
- [x] 超长文案标记为不支持编辑。
- [x] 网格按 locale/store 过滤正确。
- [x] 生效翻译优先级正确。
- [x] 状态筛选正确。
- [x] 保存后 `core_translate` 正确，缓存被清理。
- [x] 清除后回退到语言包。
- [x] 占位符不匹配时保存和导入被阻止。
- [x] CSV 导出可以重新导入。
- [x] CSV 导入任意一行失败时整批不写入。
- [x] 导入重复文件幂等。

- [x] 编辑页标题对 HTML 源文案转义。
- [x] 后台导出表单可以选择 locale、store 和 scope。
- [x] 语言包冲突在网格标记为 Conflict，并在编辑页列出备选值。
- [x] 上传 MIME 和实际文件大小校验。

### 5.2 人工验收

- [x] 后台 System > Translations 可以打开。
- [x] 扫描按钮可以运行并显示统计。
- [x] 网格可以筛选、分页、排序。
- [x] 编辑一条文案后，storefront 立即变化。
- [x] 清除覆盖后，storefront 恢复。
- [x] 导出缺失文案，离线填写，再导入，网格和 storefront 更新。
- [x] 至少在一个非 SQLite 数据库完成完整流程。
- [x] ACL 拒绝未授权用户（手工 HTTP 验证）。
- [x] 表单密钥缺失时写操作被拒绝（手工 HTTP 验证）。
- [ ] 用户确认后台页面可用。

## 6. 测试计划

### 6.1 单元测试

路径：`tests/Backend/Unit/Translations/`

- `ScannerTest.php`
- `ScannerEdgeCasesTest.php`
- `PlaceholderValidatorTest.php`
- `CsvTest.php`
- `EditHeaderTest.php`
- `EditFormDraftTest.php`
- `ExportTest.php`
- `AclTest.php`

### 6.2 集成测试

路径：`tests/Backend/Integration/Translations/`

- `ScanIndexTest.php`
- `CatalogTest.php`
- `CatalogCollectionTest.php`
- `TransferTest.php`
- `ValidationTest.php`

### 6.3 测试数据

- `tests/Backend/Unit/Translations/fixtures/` 下放 PHP、PHTML、XML、CSV 样例。
- 集成测试使用测试数据库，不依赖生产数据。
- 测试结束后清理 `core_translate` 和索引表。

### 6.4 运行方式

开发期先用模块级过滤运行，不跑全量 `composer test`：

```bash
./maho-docker/maho.sh cache:flush
docker run --rm --network host -u 1000:1000 \
  -v /home/ubuntu/maho:/app -w /app maho-php:8.5 \
  ./maho translations:scan
```

Pest 运行方式：

```bash
docker run --rm --network host -u 1000:1000 -e HOME=/tmp \
  -v /home/ubuntu/maho:/app -w /app maho-php:8.5 \
  php vendor/bin/pest tests/Backend/Unit/Translations tests/Backend/Integration/Translations
```

## 7. 风险与待定

| 风险 | 影响 | 缓解 |
|---|---|---|
| 扫描耗时超过后台请求限制 | 扫描失败或超时 | 提供 CLI；后台显示提示；后续可加异步队列 |
| 未使用文案误报 | 管理员误删语言包文案 | 只提示不删除；文案动态拼接时标注 |
| 主题 `translate.csv` 未索引 | 网格显示缺失但实际已翻译 | 文档标注；第二期支持 |
| 同一源文案多模块翻译不同 | 网格显示一条规范翻译 | 标记为 Conflict 并在编辑页列出备选值；按模块展示留第二期 |
| `core_translate` 255 字符限制 | 超长文案不能编辑 | 标记只读；第二期支持 |
| SQLite 扫描锁库 | 扫描期间其他请求变慢 | 控制扫描时长；大店铺用 CLI |
| 占位符严格校验误报 | 合法翻译被阻止 | 只比较标记多重集合；错误信息可读；第二期评估绕过 |
| 主题 translate.csv 未索引 | 网格可能显示缺失但实际已翻译 | 文档标注；第二期支持 |
| 后台表格旧式样式与新主题差异 | 页面观感不一致 | 复用 Maho 现有 Grid/Form；人工验收 |

待用户拍板：

1. 占位符校验是否接受“严格阻断”，还是需要“管理员强制保存”。
2. 超长文案第一期只读是否可接受。
3. CSV 导入失败时整批不写入是否可接受，还是需要“跳过错误行继续导入”。

## 8. 审核记录

| 日期 | 审核人 | 结果 | 备注 |
|---|---|---|---|
| 2026-11-22 | 用户 | 通过 | 接受占位符严格阻断、超长文案第一期只读、CSV 导入失败整批不写入 |
| 2026-11-22 | sdd-review-code | A 范围复审通过 | R-01、R-02、R-03、M-01 到 M-03 已修复并验证；整体 changes 仍被 M-04 到 M-10 阻塞 |
| 2026-11-22 | sdd-review-code | 剩余 major 复审通过 | M-04 到 M-10 和必要 minor 已修复，待最终验收 |
| 2026-11-22 | sdd-review-code | 最终复审通过 | 所有 blocking/major 关闭；`scan()` 长方法作为后续重构项 |
| 2026-11-22 | sdd-refactor-code | 重构完成 | 拆分 `scan()` 为 `beginScan()`、`writeIndex()`、`build*Rows()` 和 `markScanFailed()`，行为不变；新增 stale scan 和失败回滚测试 |


## 9. 实现与验证证据

| 项目 | 证据 |
|---|---|
| 单元测试 | `tests/Backend/Unit/Translations` 下 35 个测试通过 |
| 集成测试 | `tests/Backend/Integration/Translations` 下 47 个测试通过 |
| 测试合计 | 82 passed，339 assertions（SQLite、MySQL 8.0、PostgreSQL 18.7） |
| SQLite | 扫描、网格、保存、清除、导入、导出、占位符校验通过 |
| MySQL 8.0 | 82 个模块测试通过；后台网格正常显示 |
| PostgreSQL 18.7 | 82 个模块测试通过；后台网格和编辑页 tabs 正常显示 |
| 后台手工验证 | 登录、System > Translations、筛选、编辑页 tabs 切换、保存、清除、导入、导出表单和下载通过 |
| storefront 验证 | `Search entire store here...` 改为 `Search TEST` 后前台立即生效，清除后恢复 |
| 占位符验证 | `Hello, %s!` 保存为 `Ciao` 被阻止，错误显示 `Placeholder mismatch: missing %s` |
| 导入验证 | 合法 CSV 更新成功；占位符错误的 CSV 整批拒绝，数据库无变化 |
| ACL 验证 | 只读角色可打开页面，扫描 POST 返回 403 |
| Review 修复 | 标题 XSS、注释/插值/拼接误判、CSV BOM、模型层校验绕过、导出入口、测试隔离、导入目标、modules 溢出、空 pack、输入保留、CSV 公式注入、pack 冲突标记 |
| Lint | 模块 PHP 通过 php-cs-fixer、PHPStan、Rector |
