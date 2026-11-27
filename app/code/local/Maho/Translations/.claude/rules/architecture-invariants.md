# 架构不变量

这些边界不能在没有新 ADR 的情况下打破。

- 不重写 `Mage_Core_Model_Translate`，不替换 `core/translate` 单例。允许重写 `core_resource/translate` 资源模型来合并长文案（ADR-0008）。
- 翻译覆盖写入 `core_translate` 或 `translation_long`；超过 255 字符的源文案或翻译不得写入 `core_translate` 的 255 字段。CSV 只用于导入和导出。
- 不修改 Maho 核心表结构，不改 vendor 语言包文件。
- 索引表只通过 `sql/schema.php` 定义，不写 MySQL 专用 install 脚本。
- 查询必须兼容 MySQL、MariaDB、PostgreSQL 和 SQLite。
- 不使用 Prototype、jQuery、Zend_*、Varien_* 新代码。旧别名只用于兼容，不主动使用。
- 后台 JS 使用原生 API 和 `mahoFetch()`。
- 保存、清除、导入成功后必须清理 `Mage_Core_Model_Translate::CACHE_TAG`。
- 占位符校验失败必须阻止保存和导入。
- 超过 255 字符的文案使用 `translation_long`，受 TEXT 65535 上限约束。
- 未使用文案只提示，不提供删除按钮。
- 所有后台 action 必须有 ACL 检查，所有写操作依赖 Maho 表单密钥。
- 输出必须转义，SQL 必须参数化，上传文件不能写入可执行目录。
- 第二期 FR-10 引入 AI 辅助翻译，但 AI 只写 `translation_draft`，人工审核通过后才写 `core_translate`；内联编辑、内容翻译和公开 API 仍不在第二期。
