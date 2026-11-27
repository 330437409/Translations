# 决策对齐

动手前按顺序读：

1. `docs/PRD.md`：要什么，第一期范围。
2. `docs/ARCHITECTURE.md`：怎么建，数据模型和运行时边界。
3. `docs/adr/`：已经接受的重大决策。
4. 对应的功能 spec：一期看 `docs/specs/translations-admin.md`，FR-10 看 `docs/specs/ai-translation.md`。

发现以下情况时停下来问用户，不擅自改：

- 需求与已接受 ADR 冲突。
- spec 的验收标准和 PRD 冲突。
- 实现需要改变架构不变量。
- 需要新增当前期范围外的能力。
- 发现 PRD 或 spec 本身不可测、含糊或自相矛盾。

修改决策时新增 ADR，不悄悄改旧 ADR 的结论。旧 ADR 标记“已被取代”。
