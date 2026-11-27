# ADR-0008：超长文案使用独立表并通过 core/translate 资源合并

## 状态

已接受。

## 背景

`core_translate` 的 `string` 和 `translate` 都是 `VARCHAR(255)`。ADR-0004 让超过 255 字符的文案在第一期只读，但那会让长文案永远无法通过后台管理。

FR-13 要求长源文案和长翻译可编辑、可导入导出、可被 AI 生成草稿，并在运行时生效。不能修改 `core_translate` 表结构，也不能重写 `Mage_Core_Model_Translate` 或替换 `core/translate` 单例。

## 决策

新增 `translation_long` 表保存超过 255 字符的源文案或翻译。长表与 `core_translate` 互斥，长表优先；保存和清除时由统一存储路由同步清理另一张表。

运行时新增 `Maho_Translations_Model_Resource_Translate`，重写 `core_resource/translate` 资源模型。它在 `getTranslationArray()` 和 `getTranslationArrayByStrings()` 里合并长表记录，使核心翻译缓存包含长文案。`Mage_Core_Model_Translate` 本身不修改。

长文案同时纳入后台编辑、CSV 导入导出、网格状态和 AI 草稿。

## 理由

- 不改 `core_translate` 表结构，保持与 Maho 核心兼容。
- 不替换 `core/translate` 单例，避免改变核心翻译器的加载顺序和缓存机制。
- 重写资源模型可以在核心翻译缓存阶段合并长表，避免每个请求额外查询或事件补丁。
- 长表和 core 互斥，避免两条记录争夺同一 scope。
- 统一存储路由让后台、CSV 和 AI 草稿共享同一套校验和缓存规则。

## 后果

- 模块新增 `translation_long` 表和 `core_resource/translate` 资源重写。
- 升级后必须执行 `./maho migrate` 和 `./maho cache:flush`。
- 长表和 core 的互斥依赖模块统一存储路由；绕过模块直接写 core 可能产生不一致。
- TEXT 上限为 65535，更大的文案不在本期范围。
- 旧 ADR-0004 的“超长文案只读”决策被本 ADR 取代。

## 备选方案

- **在 controller_action_predispatch 观察者里合并**：不改核心资源，但每个请求多一次查询，核心翻译缓存不含长文案，不采用。
- **重写 `Mage_Core_Model_Translate`**：直接替换核心翻译器，风险过高，违反架构不变量，不采用。
- **只做 CSV 和运行时**：后台不能编辑长文案，不满足 FR-13，不采用。
- **使用 MEDIUMTEXT**：支持更大文案，但和当前 source 索引的 TEXT 上限不一致，本期不需要。
