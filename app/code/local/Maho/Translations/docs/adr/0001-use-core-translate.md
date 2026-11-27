# ADR-0001：使用 `core_translate` 作为唯一写入目标，不重写 `core/translate`

## 状态

已接受。

## 背景

Maho 的运行时翻译由 `Mage_Core_Model_Translate` 负责，按模块 CSV、主题 `translate.csv`、`core_translate` 的顺序加载，并按 locale、area、store、theme 缓存。最新代码还会在 store emulation 中复用已加载的翻译状态。

Magento 1.9.x 的 `acrich/translationsmanager` 通过重写 `core/translate` 来接管整个翻译流程。这个做法在 Maho 上会绕过新的缓存和状态机制，并且该模块还依赖已删除的 `Mage_Core_Model_Translate_Inline`。

## 决策

后台编辑的翻译只写入现有 `core_translate` 表，CSV 只用于导入和导出。不创建新的运行时翻译表，不重写 `Mage_Core_Model_Translate`，不替换 `core/translate` 单例。

## 理由

- 复用 Maho 已有的运行时优先级、缓存标签和 store emulation 行为。
- 升级 Maho 时不会和核心翻译器改动冲突。
- 支持按 store 覆盖，不需要自己实现一套翻译合并。
- CSV 语言包仍然是安装和分发翻译的标准方式，数据库只做覆盖层。
- 失败面小，测试可以集中在数据写入和 UI 上。

## 后果

- `core_translate` 的 `VARCHAR(255)` 限制成为第一期硬限制。
- 数据库翻译不区分 area，同一文案在 frontend 和 adminhtml 共用一个覆盖。
- 主题 `translate.csv` 和 `jstranslator.xml` 不在第一期管理范围内。
- 需要显式清理 `translate` 缓存，否则保存后页面不会立即变化。

## 备选方案

- **重写 `core/translate`**：能力最自由，但和 Maho 核心耦合，升级风险高，不采用。
- **写项目 CSV**：适合 git 部署，但只能按 locale 生效，不能按 store 覆盖，还要处理 vendor 文件优先级，不采用。
- **新建独立翻译表并在运行时合并**：需要再次重写翻译器或增加插件点，复杂度高，不采用。
