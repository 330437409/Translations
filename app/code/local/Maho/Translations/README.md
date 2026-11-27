# Maho_Translations

Maho 后台界面文案管理模块。它扫描语言包和源码，提供翻译网格、编辑、缺失/未使用分析、CSV 导入导出、占位符校验和 AI 翻译草稿。翻译覆盖写入 `core_translate`，运行时仍由 Maho 核心翻译器加载。

## 状态

当前版本 0.3.0，作为功能基线。FR-12 内容翻译、FR-11 内联编辑、FR-14 审计回滚及翻译记忆/术语表暂停，不排期；只做维护和修复。

## 文档

- `docs/PRD.md`：需求、FR、验收标准。
- `docs/ARCHITECTURE.md`：模块结构、数据模型、扫描和运行时边界。
- `docs/API.md`：后台路由、参数、CSV 格式和 CLI。
- `docs/adr/`：架构决策记录。
- `docs/specs/translations-admin.md`：第一期功能规格。
- `docs/specs/ai-translation.md`：FR-10 AI 辅助翻译规格。
- `.claude/rules/`：模块级防漂移规则。

## 语言包

模块自带简体中文语言包 `app/locale/zh_CN/Maho_Translations.csv`，后台界面语言切换为 `zh_CN` 后生效。

## 安装

模块位于 `app/code/local/Maho/Translations`，并附带 `app/etc/modules/Maho_Translations.xml`。安装步骤：

1. 执行 `composer dump-autoload`，让类映射和路由属性生效。
2. 执行 `./maho migrate`，创建索引表、AI 草稿表和长文案表。
3. 执行 `./maho cache:flush`。
4. 在 System > Configuration > AI 配置 provider、model 和 API key。
5. 使用异步批量生成时，启用 `ai/queue` 并确保 cron 正常运行。
6. 登录后台，进入 System > Translations，运行扫描。

## 使用

```bash
./maho translations:scan --details
```

后台入口：

```text
System > Translations
```

页面右上角提供 Scan、Import、Export 和 Generate missing。Export 会打开表单，可以选择 locale、store 和导出范围（effective、missing、overrides）。

AI 草稿：

- 勾选网格行后，用 mass action 生成 AI drafts；已有翻译也可以生成备选草稿。
- 点击 `Generate missing` 对当前 locale、store 和筛选下的全部缺失文案生成草稿。
- 1 到 3 条同步生成，超过 3 条进入 `Maho_Ai` 异步队列。
- 在编辑页 `AI Draft` tab 修改、保存、通过或拒绝草稿。
- 通过后才写入 `core_translate`；拒绝不改变现有翻译。

长文案：

- 超过 255 字符的源文案或翻译写入 `translation_long`，不写 `core_translate`。
- 保存短翻译时自动清理同 scope 的长记录，反之亦然。
- 网格状态按正常 missing/translated/conflict 计算，`Long` 筛选只显示超过 255 字符的源文案。
- 运行时通过 `core_resource/translate` 资源合并长翻译；保存或清除后清理翻译缓存。
- 上限为 `TEXT` 的 65535 字符。

## 限制

- 只管理界面文案，不管理商品、分类、CMS 内容。
- 翻译覆盖写入 `core_translate` 或 `translation_long`，不直接改语言包 CSV。
- 超过 255 字符的文案写入 `translation_long`，上限 65535；长表和 `core_translate` 互斥。
- 主题 `translate.csv` 和 `jstranslator.xml` 不在第一期范围。
- 内联编辑、内容翻译和审计回滚已由 ADR-0009 暂停，不排期。
- AI 只生成草稿，不自动通过，不直接修改 `core_translate`。
- 每个 `source_hash + locale + store` 只保留一条当前草稿，不保留历史；审计回滚 FR-14 已暂停。
- 异步批量生成要求 `ai/queue` 和 cron 正常；队列未启用时只允许 1 到 3 条同步生成。
- 同一源文案在多个模块 CSV 中有不同翻译时，网格标记为 Conflict 并显示冲突数，编辑页列出备选值；按模块展开暂停，不排期。

## 许可

代码遵循 Maho 仓库的 OSL-3.0/AFL-3.0 许可。模块文档中的第三方参考仅用于功能研究，不复制不兼容许可的代码。
