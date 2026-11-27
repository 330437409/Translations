# 模块级 SDD 规则

本目录是 `Maho_Translations` 模块的防漂移规则。它们只作用于本模块，不改变 Maho 上游仓库的规则。

权威顺序：

1. Maho 仓库根目录的 `AGENTS.md`，尤其是代码风格、后台主题、测试和安全约定。
2. 本目录的规则。
3. `docs/PRD.md`、`docs/ARCHITECTURE.md`、`docs/adr/`、`docs/specs/`。

冲突时先停下来问用户，不静默选择一边。
