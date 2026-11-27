# 架构文档：Maho 后台翻译管理模块

> 状态：已交付@0.3.0（FR-13 超长文案）
> 模块：`Maho_Translations`
> 关联 PRD：`docs/PRD.md`
> 关联 spec：`docs/specs/translations-admin.md`、`docs/specs/ai-translation.md`

## 1. 定位与边界

本模块是 Maho 的后台翻译管理界面，不是新的翻译运行时。运行时仍然由 `Mage_Core_Model_Translate` 负责：

1. 加载模块 CSV 语言包。
2. 加载主题 `translate.csv`。
3. 加载 `core_translate` 数据库覆盖。
4. 按 locale、area、store、theme 缓存结果。

模块做五件事：

- 扫描并索引源文案，回答“有哪些文案、在哪里用、缺不缺翻译”。
- 提供后台网格和编辑表单。
- 把人工编辑结果写入 `core_translate`，然后清理 `translate` 缓存。
- 提供 CSV 导入导出和占位符校验。
- 通过 `Maho_Ai` 生成翻译草稿，人工审核通过后才写入 `core_translate`。

模块不替换 `Mage_Core_Model_Translate`，不改变运行时优先级，不改 vendor 语言包文件，不改 Maho 核心表结构。

## 2. 模块结构

目录结构：

```text
app/code/local/Maho/Translations/
├── .claude/rules/                  # 模块级 SDD 规则
├── Block/Adminhtml/                # 网格、编辑表单、导入导出表单
├── Helper/Data.php                 # 模块文案与通用小工具
├── Model/
│   ├── Scanner.php                 # 源码和 CSV 扫描
│   ├── Catalog.php                 # 网格查询与生效翻译解析
│   ├── Csv.php                     # CSV 导入导出
│   ├── Draft.php                   # AI 草稿模型
│   ├── Ai/Prompt.php               # AI 提示词
│   ├── Ai/Callback.php             # Maho_Ai 任务回调
│   ├── Cron.php                    # AI 草稿对账 cron
│   ├── Validator/Placeholder.php   # 占位符校验
│   └── Resource/                   # 索引表和草稿资源模型、集合
├── Service/AiDraft.php             # AI 草稿生成和审核服务
├── controllers/Adminhtml/TranslationsController.php
├── docs/                           # PRD、架构、ADR、spec
├── etc/
│   ├── config.xml                  # 模块、模型、翻译文件、布局
│   └── adminhtml.xml               # 菜单和 ACL
├── sql/schema.php                  # 声明式索引表
├── CHANGELOG.md
└── README.md

app/etc/modules/Maho_Translations.xml
app/design/adminhtml/default/default/layout/maho_translations.xml
app/design/adminhtml/default/default/template/translations/*.phtml
app/locale/en_US/Maho_Translations.csv
tests/Backend/Unit/Translations/*
tests/Backend/Integration/Translations/*
```

说明：

- 代码放在 `app/code/local`，因为这是本地模块，不准备发布到 Packagist。
- 后台路由用 `#[Maho\Config\Route('/admin/translations/...')]`，和 `Maho_Giftcard`、`Maho_Ai` 的写法一致。新增或修改路由后需要执行 `composer dump-autoload`。POST 表单使用 `_nosecret` 生成不带 secret key 的 action，靠 Maho 后台表单密钥校验；GET 页面继续带 secret key。
- 后台界面复用 Maho 的 `Mage_Adminhtml_Block_Widget_Grid`、`Mage_Adminhtml_Block_Widget_Form` 和 `Maho\Data\Form`，不引入新的前端框架。
- 自定义 JS 只做选择器自动提交等轻量交互，使用原生 API 和 `mahoFetch()`，不使用 Prototype 或 jQuery。

## 3. 数据模型

所有表使用声明式 `sql/schema.php` 定义，表名不带 vendor 前缀。实际表名由 Maho 统一加 `table_prefix`。

### 3.1 `translation_source`

一条记录代表一个源文案。相同文案在多个模块出现时只保留一条，位置放在 `translation_location`。

| 列 | 类型 | 说明 |
|---|---|---|
| `source_id` | INTEGER unsigned autoincrement | 主键 |
| `source_hash` | CHAR(32) | `md5(source_text)`，唯一索引 |
| `source_crc` | BIGINT | PHP `crc32(source_text)`，用于对齐 `core_translate` 唯一索引 |
| `source_text` | TEXT | 源文案原文，不做 trim |
| `source_length` | INTEGER unsigned | `mb_strlen(source_text, 'UTF-8')` |
| `is_used` | SMALLINT unsigned | 源码扫描是否找到使用位置 |
| `is_supported` | SMALLINT unsigned | 是否可直接写入 `core_translate`；1 到 255 字符为 1，长文案走 `translation_long` |
| `modules` | TEXT | 去重后的模块名，逗号分隔，用于展示和筛选 |
| `areas` | VARCHAR(128) | 去重后的 area，逗号分隔 |
| `locations_count` | INTEGER unsigned | 位置数量 |
| `first_seen_at` | DATETIME_IMMUTABLE | 首次扫描时间 |
| `last_seen_at` | DATETIME_IMMUTABLE | 最近扫描时间 |

索引：

- 唯一索引 `source_hash`。
- 普通索引 `source_crc`。
- 普通索引 `is_used`、`is_supported`。

### 3.2 `translation_location`

一条记录代表源文案在某个文件里的一个出现位置。

| 列 | 类型 | 说明 |
|---|---|---|
| `location_id` | INTEGER unsigned autoincrement | 主键 |
| `source_id` | INTEGER unsigned | 外键到 `translation_source.source_id`，级联删除 |
| `module` | VARCHAR(128) | 模块名，例如 `Mage_Core`；无法判断时为空 |
| `area` | VARCHAR(16) | `frontend`、`adminhtml`、`install` 或空 |
| `file` | VARCHAR(255) | 相对 Maho 根目录的路径 |
| `line` | INTEGER unsigned nullable | PHP/PHTML/XML 行号 |
| `path` | VARCHAR(255) nullable | XML 节点路径，例如 `system/section/group/field/label` |

唯一索引：`source_id, module, area, file, line`。普通索引：`source_id`、`module`、`area`。

### 3.3 `translation_pack`

一条记录代表某个 locale 的语言包翻译。为了网格查询简单，每个 `locale + source_hash` 只保留一条规范翻译；同一文案在多个模块 CSV 中有不同翻译时，保留排序后的第一条，并记录冲突数量和备选翻译。网格把这类文案标记为 `conflict`，编辑页列出备选值。

| 列 | 类型 | 说明 |
|---|---|---|
| `pack_id` | INTEGER unsigned autoincrement | 主键 |
| `locale` | VARCHAR(20) | 语言代码 |
| `source_hash` | CHAR(32) | 源文案哈希 |
| `source_text` | TEXT | 源文案，方便导出时不必再关联 |
| `translation` | TEXT nullable | 语言包翻译 |
| `module` | VARCHAR(128) | 来源模块 CSV 名，例如 `Mage_Core` |
| `file` | VARCHAR(255) | 来源 CSV 相对路径 |
| `conflict_count` | INTEGER unsigned | 同一 locale + 源文案的冲突数量 |
| `alternatives` | TEXT nullable | 备选翻译，每行 `Module: translation` |

唯一索引：`locale, source_hash`。普通索引：`locale`。

### 3.4 `translation_scan`

记录最近扫描的状态和统计。每次扫描插入一条，扫描完成后清理旧记录，只保留最近 20 条。

| 列 | 类型 | 说明 |
|---|---|---|
| `scan_id` | INTEGER unsigned autoincrement | 主键 |
| `status` | VARCHAR(16) | `running`、`complete`、`failed` |
| `started_at` | DATETIME_IMMUTABLE | 开始时间 |
| `finished_at` | DATETIME_IMMUTABLE nullable | 完成时间 |
| `source_count` | INTEGER unsigned | 源文案数量 |
| `location_count` | INTEGER unsigned | 位置数量 |
| `pack_count` | INTEGER unsigned | 语言包翻译数量 |
| `skipped_count` | INTEGER unsigned | 跳过的动态或无法解析文案数量 |
| `message` | TEXT nullable | 错误或提示信息 |

### 3.5 `translation_draft`

AI 草稿表。每个 `source_hash + locale + store_id` 只保留一条当前草稿，重新生成会覆盖当前记录，不保留历史。

| 列 | 类型 | 说明 |
|---|---|---|
| `draft_id` | INTEGER unsigned autoincrement | 主键 |
| `task_id` | INTEGER unsigned nullable | 最近一次异步任务 ID |
| `source_hash` | VARCHAR(32) | 源文案哈希 |
| `source_text` | TEXT | 生成时的源文案快照 |
| `locale` | VARCHAR(20) | 目标 locale |
| `store_id` | SMALLINT unsigned | 0 为全局，其他为 store 覆盖 |
| `base_translation` | TEXT nullable | 生成时的当前生效翻译 |
| `draft_translation` | TEXT nullable | AI 返回或人工修改后的草稿 |
| `status` | VARCHAR(16) | `pending`、`approved`、`rejected`、`failed` |
| `platform` | VARCHAR(50) nullable | 实际 provider |
| `model` | VARCHAR(100) nullable | 实际 model |
| `error_message` | TEXT nullable | 校验或 provider 错误 |
| `requested_by` | INTEGER unsigned nullable | 发起生成的后台用户 |
| `reviewed_by` | INTEGER unsigned nullable | 通过或拒绝的后台用户 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |
| `reviewed_at` | DATETIME nullable | 审核时间 |

唯一索引：`source_hash, locale, store_id`。普通索引：`status`、`locale + store_id + status`、`task_id`。

不建到 `translation_source` 的外键。扫描会重建 source 表，草稿必须跨扫描保留。`source_text` 是快照，源文案后续变化不会改写已有草稿。

### 3.6 `core_translate`

不新建翻译表，继续使用 Maho 现有的 `core_translate`：

- `store_id = 0` 表示全局覆盖。
- `store_id > 0` 表示指定 store 覆盖。
- `locale` 指定语言。
- `string` 是源文案，`translate` 是翻译。
- `crc_string` 是 `crc32(string)`。
- 唯一索引是 `store_id, locale, crc_string, string`。

限制：`string` 和 `translate` 都是 `VARCHAR(255)`。超过 255 字符的源文案或翻译写入 `translation_long`，不写 `core_translate`；后台、CSV 和 AI 草稿都支持。

### 3.7 `translation_long`

保存超过 255 字符的源文案或翻译。长表与 `core_translate` 互斥，同一 `source_hash + locale + store_id` 最多一条记录。

| 列 | 类型 | 说明 |
|---|---|---|
| `long_id` | INTEGER unsigned autoincrement | 主键 |
| `source_hash` | VARCHAR(32) | 源文案哈希 |
| `source_text` | TEXT | 源文案快照 |
| `translation` | TEXT | 长翻译 |
| `locale` | VARCHAR(20) | 目标 locale |
| `store_id` | SMALLINT unsigned | 0 为全局，其他为 store 覆盖 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

唯一索引：`source_hash, locale, store_id`。普通索引：`locale, store_id`。不建到 `translation_source` 的外键，扫描重建 source 表时保留长翻译。

## 4. 扫描流程

### 4.1 入口

- 后台 `adminhtml/translations/scan`。
- CLI `translations:scan`。
- 两者调用同一个 `Maho_Translations_Model_Scanner`。

### 4.2 锁

扫描开始前检查 `translation_scan` 是否有 `status = running` 且开始时间在 10 分钟内。若有，拒绝并提示“扫描正在进行”。超过 10 分钟视为僵死，允许接管。

### 4.3 文件发现

只扫描已安装 Maho 包里的以下目录：

- `app/code/{core,community,local}/`
- `app/design/{frontend,adminhtml,install}/`
- `app/locale/`

通过 `Maho::getInstalledPackages()` 和 `Maho::globPackages()` 获取包路径，避免扫描 `vendor` 下的普通 PHP 库、`node_modules`、`var`、`public/media`。

跳过：

- `.git`、`node_modules`、`vendor` 下的非 Maho 包。
- 二进制文件、超过 2 MB 的单文件。
- `var/`、`public/media/`、`public/js/` 下的第三方压缩 JS。

### 4.4 解析规则

PHP/PHTML：

- 正则匹配 `__('...')` 和 `__("...")`，包含 `$this->__('...')`。
- 记录文件、行号、模块、area。
- 变量、拼接、动态字符串不解析，计入 `skipped_count`。

XML：

- 用 `SimpleXMLElement` 解析，忽略格式错误并计入 `skipped_count`。
- 查找所有带 `translate` 属性的节点。
- 对属性里列出的每个子节点名，取子节点文本作为源文案。
- 记录文件、行号、节点路径、模块、area。

CSV：

- 用 `Maho\File\Csv` 读取 `app/locale/<locale>/*.csv`。
- 第一列是源文案，第二列是翻译。
- 文件名（去掉 `.csv`）作为模块名，例如 `Mage_Core.csv` 对应 `Mage_Core`。
- 空源文案跳过。
- 同一 locale 和源文案出现多次时，保留第一条有效翻译，记录冲突数量。

### 4.5 模块和 area 判断

- `app/code/{pool}/{Vendor}/{Module}/...` 的模块名为 `Vendor_Module`。
- `app/code/core/Mage/Core/...` 的模块名是 `Mage_Core`。
- `app/design/adminhtml/...` 的 area 是 `adminhtml`。
- `app/design/install/...` 的 area 是 `install`。
- 其他 `app/design/frontend/...` 的 area 是 `frontend`。
- `app/code/*/*/Adminhtml/...` 的 area 是 `adminhtml`。
- `app/code/*/*/Install/...` 的 area 是 `install`。
- 其他代码 area 是 `frontend`。
- 无法判断模块时，模块名为空，area 仍按路径判断。

### 4.6 写入

1. 读取旧的 `source_hash => first_seen_at`，然后在一个事务里删除 `translation_location`、`translation_source`、`translation_pack`。
2. 批量插入 `translation_source`，每批 500 条。
3. 批量插入 `translation_location`，每批 500 条。
4. 批量插入 `translation_pack`，每批 500 条。
5. 更新 `translation_scan` 为完成，写入统计。
6. 保留已有源文案的 `first_seen_at`，更新 `last_seen_at`。
7. 清理 20 条之前的旧扫描记录。

读取方在事务提交前看到旧索引，提交后看到新索引。SQLite 写入期间会短暂锁库，扫描时间应控制在可接受范围。

## 5. 生效翻译解析

网格和编辑页使用以下优先级：

```text
长表 store 覆盖（store_id = 当前 store）
  > 长表全局覆盖（store_id = 0）
  > core_translate store 覆盖（store_id = 当前 store）
  > core_translate 全局覆盖（store_id = 0）
  > 语言包翻译（translation_pack）
  > 源文案

如果语言包存在多个不同翻译，状态为 `conflict`，网格显示规范值和冲突数，编辑页列出备选值。
```

主题 `translate.csv` 在运行时优先级高于模块 CSV、低于数据库覆盖，但第一期不索引主题翻译。若主题里存在覆盖，网格可能把文案显示为缺失，这是已知限制，第二期处理。

JavaScript 文案来自 `jstranslator.xml`，不走 CSV 语言包，第一期不纳入扫描和编辑。

## 6. 后台请求流

### 6.1 网格

`adminhtml/translations/index`：

1. 读取 `locale` 和 `store` 参数。
2. 默认 locale 为 `en_US`，默认 store 为 `0`（All Store Views）。
3. 网格集合查询 `translation_source`，左连接 `translation_long`、`translation_pack`、`core_translate` 和 `translation_draft`。
4. 计算生效翻译和状态（`missing`、`translated`、`conflict`）。
5. 渲染网格、AI Draft 列、mass action 和筛选器。

### 6.2 编辑

`adminhtml/translations/edit`：

1. 读取 `source_id`、`locale`、`store`。
2. 加载源文案、位置、语言包翻译、全局覆盖、store 覆盖和语言包冲突。
3. 渲染左侧 `Translation`、`Context`、`Usage`、`AI Draft` tabs 与右侧表单；有冲突时在 `Context` tab 显示 `Language pack conflicts` 只读列表。
4. 保存时校验表单密钥、ACL、locale、store、长度和占位符。
5. 写入或删除 `core_translate` 记录。
6. 清理 `translate` 缓存。
7. 返回网格或继续编辑。

`AI Draft` tab 读取当前 scope 的草稿，允许人工修改、通过或拒绝。通过时重新校验并调用 `Catalog::saveTranslation()`。

### 6.3 扫描

`adminhtml/translations/scan`：

1. 校验 ACL 和表单密钥。
2. 获取扫描锁。
3. 同步执行扫描；`scan()` 将锁、收集、索引写入和失败处理拆分为独立方法。
4. 释放锁。
5. 显示统计或错误。

大店铺建议用 CLI `translations:scan`，避免后台请求超时。

### 6.4 CSV 导入

`adminhtml/translations/import`：

1. 上传文件，校验扩展名、实际文件大小（最大 10 MB）、MIME 类型和表单密钥。
2. 解析 CSV，支持 UTF-8 BOM 和 CRLF。
3. 逐行校验源文案非空、占位符匹配、文件内不重复；长文案允许导入。
4. 任意一行失败时显示错误列表，不写入任何数据。
5. 全部通过后，在事务里按 locale 和 store 写入 `core_translate`。
6. 清理 `translate` 缓存。
7. 显示新增、更新、删除、跳过数量。

### 6.5 CSV 导出

`adminhtml/translations/export`：

1. 没有 `download` 参数时渲染导出表单，选择 locale、store、scope。
2. 带 `download=1` 时读取 `locale`、`store`、`scope`。
3. `scope` 支持 `effective`、`missing`、`overrides`。
4. 查询索引和数据库记录，生成两列 CSV。
5. 对以 `=`, `+`, `-`, `@`, tab 或 CR 开头的单元格做公式前缀转义，导入时还原。
6. 以附件形式下载，文件名包含 locale、store、范围和日期。
7. 不把文件写入 `var/` 或 `media/`，避免遗留文件。

### 6.6 AI 草稿

`adminhtml/translations/aiGenerate`：

1. 校验 ACL、表单密钥、locale、store 和目标条数。
2. 检查 `Maho_Ai` 已启用、默认 platform/model 已配置。
3. 超过 3 条时检查 `ai/queue` 已启用；超过 100 条直接拒绝。
4. 对每条文案 upsert 草稿，记录 `base_translation` 和 `requested_by`。
5. 1 到 3 条同步调用 `Maho::helper('ai')->invoke()`；超过 3 条提交 `Maho_Ai` completion 任务，把 `task_id` 写回草稿。
6. AI 返回后校验占位符和长度，写入 `draft_translation`；空响应或 provider 错误标记 `failed`。

`adminhtml/translations/aiDraftSave`、`aiApprove`、`aiReject`：

1. 校验 ACL 和表单密钥。
2. 只处理 `pending` 草稿。
3. 保存草稿时写入人工修改和校验错误。
4. 通过时调用 `Catalog::saveTranslation()` 写 `core_translate` 并清理翻译缓存。
5. 拒绝时只改草稿状态。

异步可靠性：

- `Maho_Ai` 成功时通过 `Maho_Translations_Model_Ai_Callback` 回调写入草稿。
- `Maho_Ai` 失败时不会触发回调，`translations_ai_reconcile` cron 每 5 分钟对账最近 7 天的 `ai_task`。
- 回调和对账都校验草稿 `task_id`，避免旧任务覆盖重新生成的新草稿。
- 对账只处理最近 200 条任务，避免一次 cron 过重。

### 6.7 长文案

1. 保存时计算源文案和翻译长度。
2. 任一超过 255 字符时，删除同 scope 的 core 记录，写 `translation_long`。
3. 两者都不超过 255 时，删除同 scope 的长记录，写 `core_translate`。
4. 清除覆盖时两张表都删除，并清理翻译缓存。
5. 运行时由 `Maho_Translations_Model_Resource_Translate` 在 `getTranslationArray()` 和 `getTranslationArrayByStrings()` 中合并长表，长表优先。
6. 不修改 `Mage_Core_Model_Translate`，只重写 `core_resource/translate` 资源。

## 7. 占位符校验

校验器 `Maho_Translations_Model_Validator_Placeholder` 提取三类标记：

- printf：`%s`、`%d`、`%1$s`、`%1$02d`、`%%` 等。
- 模板指令：`{{var ...}}`、`{{block ...}}` 等 `{{...}}` 片段。
- HTML 标签：`<p>`、`</p>`、`<br/>` 等标签名。

校验规则：

- 源文案和翻译的 printf 标记多重集合必须一致。
- 模板指令多重集合必须一致。
- HTML 标签名多重集合必须一致。
- 源文案没有某类标记时，翻译也不能新增该类标记。
- 不比较 HTML 属性值，避免因为翻译属性文本产生误报。
- 错误信息列出缺少和多余的标记。

校验发生在保存和 CSV 导入。第一期不做“警告后继续”，校验失败直接阻止写入。

## 8. 缓存与并发

- 保存、清除、导入成功后调用 `Mage::app()->cleanCache([Mage_Core_Model_Translate::CACHE_TAG])`。
- 长文案保存和清除也清理同一个缓存标签；升级后执行 `cache:flush` 让旧缓存重新加载长表。
- 扫描只更新索引，不影响运行时翻译，不清理翻译缓存。
- 同一文案的并发编辑采用最后写入生效。`core_translate` 没有 `updated_at`，第一期不做乐观锁。
- 扫描用 `translation_scan` 行做锁，避免两个扫描同时重建索引。

## 9. 安全

- 控制器设置 `ADMIN_RESOURCE = 'system/translations'`，并按 action 检查 `save`、`scan`、`import`、`export`、`ai` 子权限。
- 所有写操作依赖 Maho 后台自动表单密钥校验，不手动跳过。
- 请求参数使用 `getParam()`，不使用 `getUserParam()`。
- 数据库查询使用 `Maho\Db\Select` 和参数绑定，不拼接用户输入。
- 网格和表单输出使用 `escapeHtml()`。
- CSV 上传校验扩展名、实际文件大小、MIME 类型和解析结果，不把上传文件写入可执行目录。
- CSV 导出对公式前缀做转义，导入时还原，降低 spreadsheet formula injection 风险。
- 源文案和翻译按纯文本处理，不把用户输入当模板执行。
- 扫描只从 Maho 已安装包的 `app/code` 和 `app/design` 目录发现文件，不接受用户输入路径；默认不跟随符号链接。

## 10. 跨数据库

- 表结构由 `sql/schema.php` 生成，使用 DBAL 类型。
- 查询使用 `Maho\Db\Select`，不使用 `GROUP_CONCAT`、`ON DUPLICATE KEY UPDATE`、`LIMIT` 以外的数据库专用语法。
- 批量插入使用 `insertMultiple()`，分批执行。
- 事务使用适配器的 `beginTransaction()`、`commit()`、`rollBack()`。
- 测试至少覆盖 SQLite，CI 覆盖 MySQL 和 PostgreSQL。

## 11. 测试策略

单元测试：

- 扫描器：PHP、PHTML、XML、CSV 解析，模块和 area 判断，行号，动态文案跳过，超长文案标记。
- 占位符校验：printf、模板指令、HTML 标签，缺少、多余、顺序变化、多字节文案。
- CSV：解析、转义、BOM、CRLF、重复行、空行、长度限制。
- 生效翻译解析：store 覆盖、全局覆盖、语言包、源文案的优先级。
- AI 草稿：提示词、同步/异步生成、草稿唯一性、通过/拒绝、占位符校验、批量部分成功、任务对账和 ACL。

集成测试：

- 扫描后索引表数量和内容正确。
- 保存翻译后 `core_translate` 正确，缓存被清理。
- 清除覆盖后回退到语言包。
- CSV 导入成功后记录正确，失败时整批不写入。
- ACL 和表单密钥。
- AI 草稿生成、审核、缓存清理和失败对账。

人工验收：

- 后台扫描、网格、编辑、导入、导出的实际操作。
- storefront 文案变化和恢复。
- 至少一个非 SQLite 数据库的完整流程。

## 12. 部署与升级

1. 复制模块代码、布局、模板和语言包文件。
2. 创建 `app/etc/modules/Maho_Translations.xml`。
3. 执行 `composer dump-autoload`，让 Composer 类映射和路由属性生效。
4. 执行 `./maho migrate`，创建索引表、AI 草稿表和长文案表。
5. 执行 `./maho cache:flush`，让翻译缓存重新加载长文案。
6. 在 System > Configuration > AI 配置 provider、model 和 API key；异步批量生成还需要启用 `ai/queue` 并运行 cron。
7. 登录后台，进入 System > Translations，运行扫描。

升级时：

- 表结构只改 `sql/schema.php`，执行 `./maho migrate`。
- 不改历史 install/upgrade 脚本。
- 模块版本在 `etc/config.xml` 中递增。
- 文档同步更新 PRD、ARCHITECTURE、API、CHANGELOG。

## 13. 已知限制

- `core_translate` 的 `string` 和 `translate` 是 `VARCHAR(255)`；长文案写入 `translation_long`，上限 65535。
- 主题 `translate.csv` 不索引，可能造成“主题已翻译但网格显示缺失”的误判。
- JavaScript 的 `jstranslator.xml` 文案不纳入管理。
- 同一源文案在多个模块 CSV 中有不同翻译时，网格只展示一条规范翻译。
- 未使用文案可能存在误报，特别是动态拼接的文案；第一期只提示，不删除。
- `core_translate` 不区分 area，同一文案在 frontend 和 adminhtml 共用一个数据库翻译。
- 长文案受 `TEXT` 上限 65535 字符约束；更大的文案不在本期范围。
- 长表和 core 的互斥依赖模块统一存储路由，绕过模块直接写 core 可能产生不一致。
- AI 草稿只保留当前一条，不保留生成历史；审计回滚留待 FR-14。
- 扫描后源文案消失时，草稿保留但不再显示，首版不自动清理。
- 异步批量生成依赖 `ai/queue` 和 cron；未启用时只允许 1 到 3 条同步生成。


## 14. 实现记录

实际实现与本文档一致，补充以下落地信息：

- 后台容器、网格、选择器、编辑表单和导入表单在 `Block/Adminhtml/`。
- 网格集合是 `Maho_Translations_Model_Resource_Source_Collection`，在 SQL 层计算 `effective_translation` 和 `translation_status`。
- 扫描器同时支持传入 fixture 根目录，便于单元测试。
- XML 解析会按节点路径里的 `adminhtml`、`frontend`、`install` 段修正 area。
- CSV 导出包含长文案，导入时按统一存储路由写入 `translation_long`。
- 导入前校验 locale 和 store 是否有效。
- 模块测试共 82 个通过，339 个断言；SQLite、MySQL 8.0 和 PostgreSQL 18.7 下均通过；后台网格、AI Draft 页面和长文案运行时正常。
- 手工验证了后台登录、网格筛选、保存、清除、导入、导出表单和下载、占位符错误提示和只读 ACL 角色。
- 手工验证了 AI 草稿列、Review 入口、AI Draft tab、通过操作和 AI 未配置时的阻断提示。
- 使用 DeepSeek generic provider 完成真实生成、人工修改、通过和 storefront 生效验证。
- 长文案通过 `translation_long` 和 `core_resource/translate` 资源合并生效；699 字符源文案的后台保存、清除、Long 筛选、运行时翻译和真实 AI 长草稿验证通过。
- Review 修复了标题 XSS、扫描器注释/插值/拼接误判、CSV BOM、模型层校验绕过、测试隔离、导入目标、modules 溢出、空 pack、输入保留、CSV 公式注入和 pack 冲突标记，并补了对应回归测试。
