# 接口文档：Maho 后台翻译管理模块

> 状态：已交付@0.3.0
> 关联 PRD：`docs/PRD.md`
> 关联架构：`docs/ARCHITECTURE.md`

## 1. 对外接口范围

第一期和第二期 FR-10 没有公开 REST、GraphQL 或 MCP 接口。所有入口都是 Maho 后台路由和 CLI 命令，只对已登录且有 ACL 权限的后台用户开放。

## 2. 后台路由

| 路由 | 方法 | 用途 | ACL |
|---|---|---|---|
| `/admin/translations/index` | GET | 翻译网格 | `system/translations` |
| `/admin/translations/grid` | GET | 网格 AJAX 刷新 | `system/translations` |
| `/admin/translations/edit` | GET | 编辑翻译 | `system/translations` |
| `/admin/translations/save` | POST | 保存翻译 | `system/translations/save` |
| `/admin/translations/delete` | POST | 清除覆盖 | `system/translations/save` |
| `/admin/translations/scan` | POST | 运行扫描 | `system/translations/scan` |
| `/admin/translations/import` | GET/POST | 导入 CSV | `system/translations/import` |
| `/admin/translations/export` | GET | 导出表单；带 `download=1` 时下载 CSV | `system/translations/export` |
| `/admin/translations/aiGenerate` | POST | 生成 AI 草稿 | `system/translations/ai` |
| `/admin/translations/aiDraftSave` | POST | 保存 AI 草稿文本 | `system/translations/save` |
| `/admin/translations/aiApprove` | POST | 通过 AI 草稿 | `system/translations/save` |
| `/admin/translations/aiReject` | POST | 拒绝 AI 草稿 | `system/translations/save` |

所有 POST 请求使用 Maho 后台自动表单密钥校验。控制器只从 `getParam()` 读取参数。

## 3. 请求参数

### 3.1 网格和编辑

| 参数 | 类型 | 说明 |
|---|---|---|
| `locale` | string | 语言代码，例如 `en_US` |
| `store` | int | store ID，`0` 表示 All Store Views |
| `source_id` | int | `translation_source.source_id` |
| `status` | string | `missing`、`translated`、`conflict`、`unused`、`long` |
| `module` | string | 模块名筛选 |
| `area` | string | `frontend`、`adminhtml`、`install` |
| `q` | string | 源文案或翻译搜索 |

### 3.2 AI 草稿

| 参数 | 类型 | 说明 |
|---|---|---|
| `mode=missing` | string | 对当前筛选下的全部缺失文案生成草稿 |
| `mode=selected` | string | 对 `source_ids[]` 生成草稿，包含已有翻译 |
| `draft_id` | int | 单条审核时使用 |
| `source_ids[]` | int[] | 批量通过或拒绝时使用 |
| `ai_draft_translation` | string | 保存草稿时使用，最大 65535 字符 |

生成限制：

- 单次最多 100 条。
- 1 到 3 条同步生成，超过 3 条要求 `ai/queue` 已启用。
- AI provider、model 和 API key 使用 `System > Configuration > AI` 的目标 store 配置。

### 3.3 导出

| 参数 | 类型 | 说明 |
|---|---|---|
| `locale` | string | 必填 |
| `store` | int | 默认 `0` |
| `scope` | string | `effective`、`missing`、`overrides`；前两者只导出可编辑的源文案 |
| `download` | int | 表单页不传；下载时传 `1` |

### 3.4 导入

| 参数 | 类型 | 说明 |
|---|---|---|
| `locale` | string | 必填，必须是已安装或有数据库记录的 locale |
| `store` | int | 默认 `0`，必须是有效 store |
| `file` | file | 两列 CSV，实际大小最大 10 MB，MIME 为 CSV/纯文本 |

## 4. CSV 格式

- UTF-8 编码，支持 BOM。
- 两列，第一列源文案，第二列翻译。
- 使用逗号分隔，双引号转义。
- 没有表头。若第一行是 `source,translation` 或 `Source,Translation`，自动跳过。
- 缺失文案导出的第二列为空。
- 同一文件内源文案不得重复。
- 源文案和翻译可以使用长文案，受 `TEXT` 上限 65535 字符约束。
- 以 `=`, `+`, `-`, `@`, tab 或 CR 开头的单元格在导出时加 `'` 前缀，导入时还原。

示例：

```csv
"Home page","Pagina iniziale"
"Add to Cart","Aggiungi al carrello"
```

## 5. CLI

### 5.1 `translations:scan`

```bash
./maho translations:scan
```

输出：

```text
Scanned 5882 files.
Sources: 8717
Locations: 16985
Pack translations: 8690
Skipped dynamic strings: 151
Long strings: 27
```

退出码：

- `0`：扫描完成。
- `1`：扫描失败或已有扫描在运行。

`--details` 会额外输出跳过文件、XML 解析失败、语言包冲突和耗时。第一期没有 `--locale` 定向扫描。第二期再评估 `translations:export` 和 `translations:import` CLI。

## 6. 错误响应

后台页面使用 Maho 的 session 消息：

- 成功：`addSuccess()`。
- 错误：`addError()`，错误信息包含具体行号或占位符差异。

AJAX 网格请求返回标准网格 HTML。非 AJAX 写操作保存成功后重定向到网格或编辑页。

## 7. 版本与兼容

- 第一期、第二期 FR-10 和 FR-13 支持 Maho 27.x 和 PHP 8.5。
- FR-10 需要 `Maho_Ai`；异步批量生成需要启用 `ai/queue` 并运行 cron。
- FR-13 使用 `translation_long` 保存长文案，升级后需要 `migrate` 和 `cache:flush`。
- 数据库表由 `sql/schema.php` 管理，升级通过 `./maho migrate`。
- 删除模块时，索引表、`translation_draft` 和 `translation_long` 可以删除；`core_translate` 里的覆盖记录需要用户自行决定是否保留。
