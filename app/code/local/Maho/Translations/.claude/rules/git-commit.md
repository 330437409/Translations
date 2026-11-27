# 提交约定

本模块在 Maho 仓库内，提交遵循 Maho 根目录 `AGENTS.md`，不套用通用 SDD 的中文提交格式。

- 提交信息用英文，描述代码变化和用户可见结果。
- 不使用 `feat(...)`、`fix(...)` 这类 conventional commit 前缀。
- 不添加 AI、Claude、assistant 署名或 `Co-Authored-By`。
- 不自动 push。
- 提交前必须通过验证门，并得到用户确认。
- 一个提交只做一件事。文档、测试和实现可以放在同一个功能提交里。
- 提交信息示例：
  - `Added a translations manager to the admin`
  - `Fixed placeholder validation rejecting positional printf tokens`
  - `Documented the translations admin scope and architecture`

如果用户明确要求中文提交，按用户要求执行，但仍然不加 AI 署名、不自动 push。
