# 功能规格：AI 辅助翻译草稿

> 状态：已交付@0.2.0
> 关联 PRD：FR-10
> 关联 ADR：ADR-0007
> 模块：`Maho_Translations`
> 分支：`feature/ai-translation-drafts`

## 1. 背景与目标

第一期已交付界面文案扫描、网格、编辑、CSV 导入导出和占位符校验。翻译人员仍需逐条手工翻译缺失文案。

本功能在现有翻译管理界面上增加 AI 草稿：AI 只生成候选翻译并写入独立草稿表，人工审核通过后才写入 `core_translate`。运行时翻译仍由 Maho 核心负责，AI 结果不会自动生效。

## 2. 需求

### 2.1 范围内

- 对当前 locale + store 下的缺失文案批量生成 AI 草稿。
- 对人工勾选的已有翻译生成备选草稿，审核通过前原翻译不变。
- 1 到 3 条在后台请求内同步生成，超过 3 条提交 `Maho_Ai` 异步队列。
- 翻译网格增加 AI Draft 状态列和 Review 操作。
- 编辑页增加 AI Draft tab，可查看、修改、通过或拒绝草稿。
- 网格支持批量通过和批量拒绝。
- 通过草稿时复用现有占位符、长度和 locale/store 校验，并清理翻译缓存。
- 新增 AI 生成 ACL，只读用户看不到 AI 操作。
- 使用 `Maho_Ai` 当前目标 store 的默认 provider 和 model。

### 2.2 范围外

- AI 直接写入 `core_translate` 或自动通过草稿。
- 扫描后自动生成草稿。
- 定时批量生成或 CLI 生成。
- 商品、分类、CMS 内容翻译，属于 FR-12。
- 内联编辑，属于第三期 FR-11。
- 超长文案支持已由 FR-13 交付。
- 完整审计、回滚和草稿历史，属于第三期 FR-14。
- 模块独立的 provider、API key、术语表或翻译记忆配置。
- 公开 REST、GraphQL 或 MCP 接口。

### 2.3 FR 映射

| FR | 本 spec 章节 |
|---|---|
| FR-10 | 2 需求、3 设计、5 验收标准 |

## 3. 设计

### 3.1 依赖与版本

- 模块版本从 `0.1.1` 升到 `0.2.0`。
- 新增模块依赖 `Maho_Ai`，使用 `Mage::helper('ai')` 和 `Maho_Ai_Model_Task` 队列。
- 不复制 provider 配置，不保存 API key。
- AI 配置使用目标 store 的 `ai/general/*` 设置；store 0 使用默认 scope。

### 3.2 路由与权限

新增后台路由：

| 方法 | 路径 | Action | ACL |
|---|---|---|---|
| POST | `/admin/translations/aiGenerate` | `aiGenerateAction` | `system/translations/ai` |
| POST | `/admin/translations/aiDraftSave` | `aiDraftSaveAction` | `system/translations/save` |
| POST | `/admin/translations/aiApprove` | `aiApproveAction` | `system/translations/save` |
| POST | `/admin/translations/aiReject` | `aiRejectAction` | `system/translations/save` |

- 所有 POST 依赖 Maho 表单密钥。
- `aiGenerate` 可以接受 `mode=missing` 或 `mode=selected`。
- `aiApprove`、`aiReject` 接受单个 `draft_id` 或 `draft_ids[]`。
- 只读用户不显示 AI 操作，直接请求返回 403。

### 3.3 数据模型

新增表 `translation_draft`：

| 字段 | 类型 | 说明 |
|---|---|---|
| `draft_id` | INTEGER UNSIGNED PK AUTO | 草稿 ID |
| `task_id` | INTEGER UNSIGNED NULL | 最近一次异步任务 ID |
| `source_hash` | VARCHAR(32) | 源文案 md5，关联索引 |
| `source_text` | TEXT | 生成时的源文案快照 |
| `locale` | VARCHAR(20) | 目标 locale |
| `store_id` | SMALLINT UNSIGNED | 0 为全局，其他为 store 覆盖 |
| `base_translation` | TEXT NULL | 生成时的当前生效翻译 |
| `draft_translation` | TEXT NULL | AI 返回或人工修改后的草稿 |
| `status` | VARCHAR(16) | `pending`、`approved`、`rejected`、`failed` |
| `platform` | VARCHAR(50) NULL | 实际 provider |
| `model` | VARCHAR(100) NULL | 实际 model |
| `error_message` | TEXT NULL | 校验或 provider 错误 |
| `requested_by` | INTEGER UNSIGNED NULL | 发起生成的后台用户 |
| `reviewed_by` | INTEGER UNSIGNED NULL | 通过或拒绝的后台用户 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |
| `reviewed_at` | DATETIME NULL | 审核时间 |

索引：

- 唯一键 `source_hash + locale + store_id`，保证每个 scope 只有一条当前草稿。
- 普通索引 `status`、`locale + store_id + status`、`task_id`。

说明：

- 不建到 `translation_source` 的外键。扫描会重建 source 表，草稿必须跨扫描保留。
- `source_text` 是快照，源文案后续变化不会改写已有草稿。
- 源文案从索引消失后，草稿仍在表中，但网格不再显示，留待后续清理。
- 重新生成覆盖同 scope 的当前草稿，不保留历史。完整审计由 FR-14 负责。

### 3.4 状态机

| 状态 | 含义 | 可执行操作 |
|---|---|---|
| `pending` | 已排队，或 AI 已返回但待审核 | 编辑、通过、拒绝、重新生成 |
| `approved` | 已通过并写入 `core_translate` | 重新生成 |
| `rejected` | 已拒绝，未修改 `core_translate` | 重新生成 |
| `failed` | provider、队列或回调失败 | 重新生成 |

规则：

- 新建或重新生成时，状态回到 `pending`，清空 `draft_translation`、`error_message`、`task_id`、`reviewed_by`、`reviewed_at`，更新 `base_translation`。
- `pending` 且 `draft_translation` 为空的记录表示异步任务尚未返回。
- 占位符或长度校验失败不改变状态，保留 `pending`，写入 `error_message`，通过操作被阻止。
- 通过和拒绝只允许 `pending` 草稿；其他状态返回可读错误。

### 3.5 生成流程

入口：

- 网格 mass action `Generate AI drafts`：处理勾选行，缺失和已有翻译都可生成。
- 网格工具栏 `Generate missing`：处理当前 locale、store 和筛选下的全部缺失文案。
- 单次最多 100 条。超过上限时拒绝并提示收窄筛选。

预检：

- `Maho_Ai` 已启用。
- 目标 store 的默认 chat platform 和 model 已配置。
- 超过 3 条时 `ai/queue/enabled` 必须开启。
- locale 和 store 必须有效。
- 源文案必须存在；长文案由 FR-13 路由到 `translation_long`。

每条文案：

1. 读取当前生效翻译作为 `base_translation`。
2. Upsert 草稿，状态 `pending`，`draft_translation` 为空。
3. 构建提示词：目标语言、store、模块、area、源文案、占位符列表、当前翻译（如果存在）。
4. 同步或异步调用 AI。
5. AI 返回后写入 `draft_translation`，校验占位符和长度。
6. 校验不通过时保留 `pending` 并写 `error_message`；返回空内容时标记 `failed`。

### 3.6 同步与异步

同步：

- 1 到 3 条在当前后台请求中逐条调用 `Mage::helper('ai')->invoke()`。
- 单条失败不影响其他条；汇总成功和失败数量。
- 记录 provider/model（如果无法从 helper 获取，则记录配置值或留空）。

异步：

- 超过 3 条时每条提交一个 `Maho_Ai` completion 任务。
- 任务参数：`consumer=translations`、`action=draft`、`store_id` 目标 store、`context[draft_id]`、`callback_class=Maho_Translations_Model_Ai_Callback`、`callback_method=onComplete`。提交成功后把 `task_id` 写回草稿。
- 回调调用 `Maho_Translations_Service_AiDraft::applyTaskResponse()`，校验 `task_id` 后幂等写入草稿。
- 任务失败时 `Maho_Ai` 不会触发回调，因此增加 `translations_ai_reconcile` cron，每 5 分钟对账最近 7 天的 `ai_task`：
  - `complete` 但草稿仍无文本时，应用任务 `response`。
  - `failed` 或 `cancelled` 且无可用响应时，草稿标 `failed` 并写入任务错误。
  - 处理上限 200 条，避免一次 cron 过重。
- 回调和对账都必须幂等。草稿 `task_id` 与任务不匹配时跳过，避免旧任务覆盖重新生成的新草稿。

### 3.7 审核与通过

编辑页 AI Draft tab：

- 显示状态、provider、model、错误信息、`base_translation` 和可编辑的 `draft_translation`。
- `Save draft` 保存人工修改，重新校验；校验失败也保存文本并显示错误。
- `Approve` 只能用于 `pending` 草稿。
- `Reject` 只修改草稿状态，不写 `core_translate`。
- 按钮使用 HTML5 `formaction` 指向独立 POST 路由，不创建嵌套表单。

通过流程：

1. 读取草稿并校验状态。
2. 校验翻译非空、长度不超过 65535、占位符匹配。
3. 构造 source 数组，调用 `Maho_Translations_Model_Catalog::saveTranslation()`。
4. 写入 `core_translate`，清理 `Mage_Core_Model_Translate::CACHE_TAG`。
5. 更新草稿为 `approved`，记录 `reviewed_by`、`reviewed_at`。

拒绝流程：

- 更新草稿为 `rejected`，记录审核人。
- 不修改 `core_translate`，不清理翻译缓存。

批量审核：

- 选中多条草稿后批量通过或拒绝。
- 逐条处理，允许部分成功。
- 返回成功、跳过和失败数量；单条错误不中断整批。

### 3.8 网格与编辑页

网格：

- 新增 `AI Draft` 列，显示状态和截断后的草稿文本。
- 新增 `Review` 操作，打开编辑页 AI Draft tab。
- 保留现有 `Edit` 操作。
- 新增 mass action：`Generate AI drafts`、`Approve AI drafts`、`Reject AI drafts`。
- 新增工具栏按钮 `Generate missing`，显示当前筛选下的缺失条数并二次确认。
- 没有 AI 权限时不显示生成操作；没有保存权限时不显示通过和拒绝。

编辑页：

- 在现有 Translation、Context、Usage 后增加 `AI Draft` tab。
- 没有草稿时显示 `No AI draft for this source.`，不提供生成按钮。
- 草稿文本和按钮只在有草稿且状态为 `pending` 时可编辑。
- `approved`、`rejected`、`failed` 状态只读展示，可回到网格重新生成。

### 3.9 校验与限制

- 占位符校验复用 `Maho_Translations_Model_Validator_Placeholder`。
- 长度上限 65535；长翻译按 FR-13 路由到 `translation_long`。
- AI 返回内容按纯文本处理，不开启 HTML sanitizer；HTML 标签由占位符校验检查。
- AI 输出为空、只有空白或 provider 报错时标记 `failed`。
- 单次生成上限 100 条，单次审核上限 100 条。
- 并发生成同一 scope 时，唯一键保证只有一条草稿；后完成的写入覆盖先完成的未审核草稿。

### 3.10 失败与恢复

- AI 未启用或未配置：生成前阻断，显示可读错误。
- 队列未启用且超过 3 条：阻断并提示启用 `ai/queue` 和 cron。
- 单条 provider 失败：该草稿 `failed`，其他条继续。
- 回调失败：对账 cron 从 `ai_task.response` 重试；任务 ID 不匹配或没有可用响应时标记 `failed`。
- 草稿被并发审核：以数据库当前状态为准，非 `pending` 返回错误。
- 扫描删除源文案：草稿保留但不显示；不自动删除，避免误删未审核内容。

### 3.11 安全

- 所有路由使用 ACL 和 POST 表单密钥。
- 所有输出转义，包括 AI 返回文本和错误信息。
- AI 请求通过 `Maho_Ai` 的输入校验、输出清理和用量记录。
- 不把 API key、provider 原始响应或用户数据写入日志。
- 草稿表不保存 API key。

## 4. 任务拆分

- [x] PRD 更新 FR-10 描述、状态和 AC-11。
- [x] ADR-0007 从提议中改为已接受，并更新 ADR README。
- [x] 新增 `translation_draft` schema、模型、资源模型和集合。
- [x] 新增 AI Draft service、提示词构建、同步生成和异步提交。
- [x] 新增 AI 回调模型和 `translations_ai_reconcile` cron。
- [x] 新增网格 AI Draft 列、Review 操作、三个 mass action 和 Generate missing。
- [x] 新增编辑页 AI Draft tab、保存、通过、拒绝。
- [x] 新增 ACL、路由和表单密钥校验。
- [x] 新增单元测试和集成测试。
- [x] 更新 ARCHITECTURE、API、README、CHANGELOG。
- [x] 更新模块规则，说明二期 AI 草稿的边界。
- [x] 手工验证真实 AI provider、后台审核和 storefront 生效（DeepSeek generic）。

## 5. 验收标准

- AI 未启用或未配置时，生成入口给出可读错误，不创建草稿。
- 缺失文案生成后，网格显示 `pending` 草稿，当前生效翻译不变。
- 已有翻译生成备选草稿后，审核通过前原翻译不变。
- 1 到 3 条同步生成；超过 3 条进入异步队列。
- 队列未启用时，超过 3 条被阻断；1 到 3 条仍可同步。
- 草稿可以在编辑页修改并保存。
- 通过草稿后，`core_translate` 出现或更新对应记录，翻译缓存被清理，网格生效翻译更新。
- 占位符或长度不合法时，通过被阻止，草稿保留错误信息。
- 拒绝草稿不修改 `core_translate`。
- 批量通过和拒绝按行处理，单行失败不影响其他行，并汇总结果。
- 只读 ACL 用户看不到 AI 操作，直接请求返回 403。
- 同一 `source_hash + locale + store_id` 只有一条当前草稿；重新生成覆盖旧草稿。
- 异步任务失败后，草稿最终变为 `failed`，不会永久停留在 `pending`。
- SQLite、MySQL、PostgreSQL 测试通过。
- 使用真实 AI provider 至少手工完成一次生成、修改、通过和 storefront 验证。

## 6. 风险与待定

- `Maho_Ai` 队列和 cron 是异步路径的硬依赖。未启用时只允许 1 到 3 条同步生成。
- AI 输出质量取决于 provider 和 prompt；草稿必须人工审核，不自动生效。
- 草稿按 `source_hash` 关联，扫描后源文案消失的草稿不会显示，也不会自动清理。
- 同一 scope 并发写入采用最后写入生效，不做乐观锁。
- 批量上限 100 条和同步阈值 3 条是首版常数，后续可按店铺调整。
- 真实 provider 验收需要用户提供已配置的 AI 账号和 key；测试使用 fake provider，不调用外部 API。

## 7. 实现与验证

| 项目 | 证据 |
|---|---|
| Schema | `translation_draft` 已加入 `sql/schema.php`，SQLite、MySQL 8.0、PostgreSQL 18.7 均执行 `./maho migrate` 成功 |
| 单元测试 | `AiPromptTest` 覆盖提示词、locale、模块、area、占位符和 base translation |
| 集成测试 | `AiDraftTest` 覆盖同步生成、异步排队、通过、拒绝、校验阻断、批量部分成功、任务对账和失败收敛 |
| 测试合计 | 73 passed，291 assertions，SQLite、MySQL 8.0、PostgreSQL 18.7 均通过 |
| Lint | 模块范围 php-cs-fixer、PHPStan、Rector 通过 |
| 后台手工验证 | AI Draft 列、Review 入口、AI Draft tab、保存、通过、拒绝、AI 未配置阻断均通过 |
| 真实 AI 验收 | DeepSeek generic provider 成功生成草稿；人工修改后通过，`core_translate` 写入且 storefront 立即显示新文案；验证后已清理测试覆盖 |
