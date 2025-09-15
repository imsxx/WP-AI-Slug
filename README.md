## WP-AI-Slug（整合增强版）

一款用于为文章与分类/标签等术语自动生成英文 URL Slug 的 WordPress 插件，兼容 OpenAI / DeepSeek / 自定义（OpenAI Chat Completions 兼容）端点，提供测试连通性、异步批处理、手动编辑与日志等功能。

- 主页：https://imsxx.com
- 源码：https://github.com/imsxx/wp-ai-slug
- 环境：WordPress ≥ 5.0，PHP ≥ 7.4

### 功能特性
- 新建/保存时自动生成英文 Slug（小写、连字符）；命中“固定词库”时按映射提示。
- 多服务商与自定义端点（OpenAI / DeepSeek / 兼容 OpenAI Chat Completions）。
- 设置页“测试连通性”一键验证 HTTP 200。
- 异步批处理历史内容：小批量、间隔执行，生成“提案”后在“手动编辑”统一应用。
- 手动编辑页：列表行内编辑、生成提案、接受/拒绝、页内消息反馈。
- 运行日志：记录请求异常与处理结果，便于排查。
- 多语言 UI（中/英）。

### 安装与启用
1. 将 `wp-ai-slug` 文件夹上传至 `/wp-content/plugins/`。
2. 后台“插件”启用“WP-AI-Slug”。
3. 如站点已安装其他“自动生成 slug”的同类插件，建议停用以避免重复处理。

### 快速开始
1. 设置页“基础配置”选择服务商并填入：API 基地址、端点路径、API Key、模型名。
2. 点击“测试连通性”，确认返回 HTTP 200。
3. 勾选“启用的文章类型/分类法”，决定自动生成与批处理的默认范围。
4. 可选：填写“固定词库”。每行一个映射（原词=英文 或 原词|英文），仅当标题命中“原词”时生效。
5. 新建/保存文章或术语时，若未手填 slug，将自动生成英文 slug。
6. 历史内容：到“异步批处理”执行，产出提案后去“手动编辑”统一“接受选中”。

### 配置项说明
- 服务提供商：OpenAI / DeepSeek / 自定义（兼容 OpenAI Chat Completions）。
- API 基地址：如 https://api.openai.com（不要以斜杠结尾）。
- 端点路径：如 /v1/chat/completions（与基地址拼为完整接口）。
- API Key：对应服务商密钥（保存后以星号显示）。
- 模型：如 gpt-4o-mini、deepseek-chat（确保账户有权限）。
- 启用的文章类型：勾选后，1）新建/保存该类型内容时自动生成 slug；2）文章批处理默认范围。
- 启用的分类法：勾选后，1）新建该分类法术语时自动生成 slug；2）术语批处理默认范围（适用于分类/标签等“非文章”内容）。
- 固定词库：仅在标题命中“原词”时注入映射提示，减少误替换与 token 消耗。

### 异步批处理（文章/术语）
- 文章：可选择文章类型、每批数量、是否跳过“已由 AI 生成”的对象；任务会生成提案并记录日志。
- 术语：适用于分类/标签；默认仅处理 slug 为空或等于名称标准化结果的术语，避免覆盖你已自定义的 slug。
- 游标：批处理中记录“已处理到的最后 ID”，便于中断后继续；“重置游标”可重新扫描。

### 手动编辑页
- 查看文章列表（可筛选文章类型与属性），支持行内编辑 slug、生成提案、接受/拒绝提案、批量“接受选中/拒绝选中”。
- 所有操作在页内以提示条反馈成功/失败数量。

### 常见问题（FAQ）
- Q：术语批处理处理哪些对象？
  - A：分类/标签等“非文章”内容的 slug。默认只处理“未自定义”的术语，避免覆盖。
- Q：如何按新词库重跑历史 slug？
  - A：文章批处理中取消勾选“跳过已由 AI 生成”，生成新提案后在“手动编辑”统一“接受选中”。
- Q：游标是什么？
  - A：表示批处理“上次处理到”的 ID，可继续或重置重跑。

### 目录结构（关键文件）
- wp-ai-slug/wp-ai-slug.php：插件入口，优先加载 UTF-8 版增强实现。
- includes/fixed/class-bai-slug-settings-fixed-utf8.php：设置页与内嵌的批处理/手动编辑/日志/说明。
- includes/fixed/class-bai-slug-bulk-fixed-utf8.php：异步批处理页面（UTF-8 版）。
- includes/fixed/class-bai-slug-manage-fixed-utf8.php：手动管理页面（UTF-8 版）。
- includes/class-bai-slug-helpers.php：请求封装与错误解析（提升错误可读性）。
- includes/queue/class-bai-slug-queue.php：批处理队列、提案生成、应用/拒绝。
- assets/*.js / *.css：后台脚本与样式（manage.js、bulk.js、settings.js、admin.css）。

### 许可证与致谢
- 遵循 GPLv2 or later。
- 本插件对中文环境、异步批处理、UI 文案、日志等做了优化与改良。
