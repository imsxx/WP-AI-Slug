# WP-AI-Slug 项目说明书（整合增强版）

本项目是一款 WordPress 插件：基于大语言模型（OpenAI、DeepSeek 或兼容 OpenAI Chat Completions 的端点）自动为文章与分类术语生成英文 URL Slug，提供测试连通性、异步批处理、手动编辑与日志等增强能力。遵循 GPLv2 or later 许可。

- 官方主页：https://imsxx.com
- 仓库地址：https://github.com/imsxx/wp-ai-slug
- 运行环境：WordPress ≥ 5.0，PHP ≥ 7.4

## 代码组织

- 入口：`wp-ai-slug/wp-ai-slug.php`
  - 定义常量 `BAI_SLUG_DIR/URL`，注册自动加载，优先加载 UTF-8 增强实现。
- 后台页面（UTF-8 版）：
  - 设置页：`includes/fixed/class-bai-slug-settings-fixed-utf8.php`
  - 批处理：`includes/fixed/class-bai-slug-bulk-fixed-utf8.php`
  - 手动管理：`includes/fixed/class-bai-slug-manage-fixed-utf8.php`
- 业务逻辑：
  - 文章生成：`includes/class-bai-slug-posts.php`
  - 术语生成：`includes/class-bai-slug-terms.php`
  - 队列处理：`includes/queue/class-bai-slug-queue.php`
  - 帮助/请求：`includes/class-bai-slug-helpers.php`
  - 日志持久化：`includes/class-bai-slug-log.php`
  - i18n 字典：`includes/class-bai-slug-i18n.php`
- 前端资源：`assets/`（admin.css、settings.js、bulk.js、manage.js）

> 注意：WP 后台加载脚本的 URL 只能以 `BAI_SLUG_URL` 指向插件目录；不要在仓库根目录再放重复版的 `assets/*` 文件（会造成混淆）。

## 约定与规范

- 文件编码：统一使用 UTF-8（无 BOM）。
- 文案管理：尽量放入 `includes/class-bai-slug-i18n.php` 或通过 `wp_localize_script` 注入，避免分散硬编码。
- 安全转义：输出 HTML/属性/URL 时分别使用 `esc_html/esc_attr/esc_url`。
- 钩子与时机：
  - 文章：`wp_insert_post_data` 生成、`save_post` 跟踪手动修改。
  - 术语：`created_term` 生成。
  - 队列：`cron_schedules` 注册自定义间隔、`bai_slug_tick` 心跳执行。
- 队列清理：暂停/完成/重置时务必调用 `wp_clear_scheduled_hook('bai_slug_tick')`，避免空跑。
- 元字段：
  - `_generated_slug`：AI 生成的最终 slug。
  - `_slug_source`：来源标记（`ai` / `user-edited`）。
  - 提案用元：`_proposed_slug`/`_proposed_slug_raw`/`_proposed_meta`。

## 开发与测试

### 连通性
- 设置页 → “测试连通性”。成功返回 `OK: HTTP 200`；否则带详细错误（尽量提取 `error.message/type/code`）。

### 批处理（文章/术语）
- 文章：选择文章类型 + 每批数量 + 可选“跳过已由 AI 生成”，开始后观察进度与日志；提案在“手动编辑”页应用。
- 术语：选择分类法 + 每批数量 + “跳过已由 AI 生成的术语”；默认仅处理 slug 为空或等于名称标准化的术语，避免覆盖自定义。
- 游标：表示“处理到的最后 ID”，便于中断后继续；“重置游标”可从头重跑。
- AJAX 列表搜索同时匹配标题与 slug；传入 `bai_slug_search` 时会通过 `posts_search` / `terms_clauses` 追加 `post_name` / `terms.slug` 条件，记得用后移除钩子。

### 手动编辑
- 行内编辑标题/slug，生成提案，批量“接受选中/拒绝选中”；操作结果在页内以 notice 显示（非 alert）。
- 搜索框支持标题与 slug 关键字，便于快速定位翻译异常条目。

### 术语表导入/导出
- `admin-ajax.php?action=bai_glossary_export` 会把当前映射导出为 `source,dest` 表头的 UTF-8 CSV。
- `admin-ajax.php?action=bai_glossary_import` 接受含 `source,dest` 表头的 CSV；会跳过表头、忽略空值并在完成后重定向回“术语表”页签显示成功/失败 notice 与导入条目数。

## 编码指引（Windows/VS Code）
- VS Code：右下角选择“UTF-8”；必要时“以编码重新打开/保存→UTF-8”。
- VS Code 设置建议：`"files.encoding": "utf8"`, `"files.autoGuessEncoding": false`。
- PowerShell 写文件：使用 `Set-Content -Encoding utf8` 或 `Out-File -Encoding utf8`。

## 回归与清理建议
- 若 UTF-8 版 `*-utf8.php` 工作正常，可考虑移除旧的非 UTF-8 版以避免混淆。
- 提交前自查：
  - 是否误把前端资源写到仓库根的 `assets/`（应在插件目录 `wp-ai-slug/assets/`）。
  - 是否出现中文乱码；若有，统一转为 UTF-8 保存。

## 许可
- 本项目遵循 GPLv2 or later。
