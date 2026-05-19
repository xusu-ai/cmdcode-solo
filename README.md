# CmdCode Solo

> 单页 · 零安装 · 零依赖 · 全记忆系统 AI Agent

一个极简的、可自托管的 AI 编程助手，完全在浏览器中运行，具有持久化长期记忆系统——无需构建工具、无需框架、无需外部服务。仅需一个 HTML 文件和一个 PHP 代理即可部署在任何服务器上。

👉 **在线演示:** [CmdCode.cn/ui.html](https://cmdcode.cn/ui.html) — 访客模式无需登录

---

## ✨ 核心功能

- **🧠 单页应用** — 所有前端逻辑在单个 `ui.html` 中，无 npm、无 webpack、无框架。
- **📄 零安装、零外部依赖** — 无需 Docker、Node.js、Python venv。仅需 PHP 7.4+ 和 MySQL 5.7+（可在最便宜的虚拟主机上运行）。
- **💾 完整记忆系统（L1+L2+L3）** — 基于 TencentDB Agent Memory 架构的本地化实现：
  - **L1 原子记忆** — 从对话中自动提取事实、决策、偏好和凭据，使用 AES-256-CBC 加密存储。
  - **L2 场景抽象** — 按项目或主题隔离记忆，防止跨任务混淆。
  - **L3 用户画像** — 提炼长期稳定的用户特征，让 AI 真正"了解你"。
- **🔐 凭据加密记忆** — 密码、API Key 等不会被丢弃——它们在磁盘上加密，检索时在内存中解密，使用后立即清除。
- **🖼️ 访客共享云盘** — 无需注册，所有用户生成的图片自动出现在共享云盘中，开箱即用。
- **👤 多用户物理隔离** — 每个用户拥有专用文件夹（`/user_data/{id}/Memory/`），受 100MB 配额限制。
- **⚡ 流式响应** — 实时展示 AI 思考过程和工具调用步骤。
- **🎵 多模态支持** — 文生图、图生图、语音合成、音乐生成、视频生成——全部在对话中完成。
- **📱 移动端适配** — 响应式设计，支持 iOS/Android 安全区域，可作为 PWA 使用。
- **🪶 零框架依赖** — 纯原生 JavaScript（ES6）、CSS3、HTML5，无第三方库。

---

## 🤖 快速开始 — AI Agent 一键部署（推荐）

这是最快的部署方式。**只需一句话，AI 帮你搞定一切：**

将仓库地址交给 AI Agent（如 **Hermes Agent**、**Claude Code**、**OpenClaw** 等），说：

> **"请帮我将这个项目部署到我的 Web 服务器和本地环境，让我可以直接访问 ui.html。"**

```text
# Gitee（推荐国内用户）
https://gitee.com/xusuai/cmdcode-solo.git

# GitHub（推荐海外用户）
https://github.com/xusu-ai/cmdcode-solo.git
```

AI Agent 会自动处理所有步骤：

| 步骤 | 描述 |
|:----|:-----|
| ✅ 克隆代码 | 从仓库拉取最新代码 |
| ✅ 配置 API Key | 你提供 LLM API Key，AI 写入加密配置 |
| ✅ 配置服务器 | 你提供 FTP/服务器凭证，AI 上传文件至 Web 目录 |
| ✅ 配置数据库 | 你提供 MySQL 凭证，AI 自动创建数据表 |
| ✅ 配置定时任务 | AI 添加异步 Worker 定时任务 |
| ✅ 目录权限 | AI 创建 `/user_data` 并设置正确权限 |
| ✅ 安全加固 | AI 配置 `.htaccess` 安全规则 |
| 🎉 **完成！** | 访问 `https://你的域名/ui.html` 开始使用 |

> **你只需做 3 件事：** ① 批准操作 ② 提交 API Key ③ 提交服务器凭证
>
> 其他一切由 AI Agent 自动完成。省时省力。

---

## 🛠️ 手动部署

### 环境要求

- PHP 7.4+（需 openssl、curl、pdo_mysql 扩展）
- MySQL 5.7+ 或 MariaDB 10.2+
- 任意 Web 服务器（Apache / Nginx / LiteSpeed）
- Cron 守护进程（用于异步记忆处理）

### 部署步骤

1. 将仓库克隆到你的 Web 服务器目录。
2. 编辑 `config.enc.php`，设置强随机密码短语，并将 API Key 写入加密存储。
3. 修改 `proxy.php` 中的 `ACCESS_TOKEN` 和域名白名单。
4. 导入所需数据表（自动创建，或手动执行 `CREATE TABLE IF NOT EXISTS ...`）。
5. 配置 Crontab：
   ```cron
   * * * * * sleep 0; /path/to/long-task-cron-worker.sh
   * * * * * sleep 15; /path/to/long-task-cron-worker.sh
   * * * * * sleep 30; /path/to/long-task-cron-worker.sh
   * * * * * sleep 45; /path/to/long-task-cron-worker.sh
   ```
6. 确保 `/user_data` 目录 Web 服务器可写。
7. 访问 `https://你的域名/ui.html` 开始使用。

### 使用模式

| 模式 | 描述 |
|:----|:-----|
| **👤 访客模式** | 无需登录，对话和文件自动保存到共享目录，可查看其他用户生成的图片 |
| **🔑 登录模式** | 注册获 100MB 专属个人云盘，对话历史持久化 |

---

## 🧠 记忆系统架构

### 记忆生命周期

1. **自动提取** — 对话上下文压缩时，前端无阻塞地入队任务，将对话发送到后端。
2. **异步处理** — Cron Worker 拉取任务，调用 LLM 提取原子事实，用 AES-256-CBC 加密，追加到 `L1_facts.jsonl`。
3. **智能检索** — 每条用户消息前，前端检索相关记忆（全文匹配 + 时间衰减 + RRF 热度融合）并注入系统提示词。
4. **画像更新** — 每 30 条新记忆触发一次用户画像提炼，保持 L3 画像实时更新。

### 隐私保护

- 记忆内容使用用户专属密钥（主密钥 + 用户 ID 通过 HMAC 派生）加密。
- 密文在检索时解密到内存，仅将所需明文注入 LLM 上下文，使用后立即释放。
- 凭据类记忆在索引表中被掩码处理，防止日志泄露。

---

## 📂 项目结构

```
cmdcode-solo/
├── ui.html                        # 完整前端 Agent，含记忆检索（~91KB）
├── proxy.php                      # API 代理 + 记忆系统核心（~78KB）
├── config.enc.php                 # AES-256-CBC 加密配置文件
├── long-task-cron-worker.sh       # 通用异步 Worker（~16KB）
├── cron.d-long-task-worker        # Crontab 配置（每15秒错峰运行）
├── long-task-worker-check.sh      # Worker 健康检查
├── htaccess-example               # LiteSpeed 安全规则
├── v.php                          # 源代码查看器
├── source.html                    # 开源首页元数据
├── README.md                      # 中文说明
└── README_EN.md                   # English README
```

### 文件详情

| 文件 | 大小 | 描述 |
|:----|:----|:------|
| **ui.html** | ~91 KB | 前端聊天界面，零框架依赖。内置 AI Agent 对话、文件管理器、图片查看器、音视频播放、用户认证、多模态工具（文生图/视频/音乐/语音）、3 Key 限流轮换、会话级配额耗尽保护 |
| **proxy.php** | ~78 KB | 多供应商 API 代理，解决浏览器 CORS。支持 MiniMax（三密钥轮换容灾）和 OpenCode Go，含用户认证、文件系统、记忆系统、远程 Bash、分享链接、网页抓取、完整视频/音乐异步管道 |
| **config.enc.php** | ~2.5 KB | AES-256-CBC 加密配置，密钥永不落盘 |
| **long-task-cron-worker.sh** | ~16 KB | Cron 触发的异步任务 Worker，处理 MiniMax 长时音乐/视频生成及 LLM 记忆提取，绕过 PHP-FPM 30s 超时限制，含 3 Key 轮换和 429 检测 |
| **htaccess-example** | 351 B | LiteSpeed 安全规则：阻止直接访问 `.enc.php`、保护 `.htaccess` 自身 |

**记忆相关 DB 表**: `memory_tasks`（任务队列）、`memory_index`（全文索引）

**用户记忆目录**: `/user_data/{user_id}/Memory/` — L1 加密事实（JSONL）、L2 场景、L3 画像

---

## 🔄 3 Key 轮换架构

所有 MiniMax API 调用均实现三密钥自动轮换：

| 层 | 实现 |
|:---|:-----|
| **通用处理器** | `proxy.php` 底部回退处理器（第1732行）: `foreach ($api_keys as $idx => $key)` → 429 自动换 Key → 全部耗尽返回 `proxy_all_keys_exhausted` |
| **音乐异步** | cron worker `for TRY_KEY in "${KEY_ARRAY[@]}"` → 429/000 跳过 → 本机 curl 无 PHP 30s 限制 |
| **视频异步** | cron worker 同上模式，提交→轮询→获取 URL，逐 Key 尝试 |
| **前端保护** | 6 个会话级标志（`_videoQuotaExhausted`/`_musicQuotaExhausted`/`_imageQuotaExhausted`/`_ttsQuotaExhausted`/`_visionQuotaExhausted`/`_webSearchQuotaExhausted`），首次 429 后零 API 消耗阻断 AI 重试 |

---

## 📊 性能与成本

- **存储**: 重度用户每年产生约 28MB 数据（100MB 配额可用 3 年以上）。
- **Token 节省**: 上下文卸载 + 记忆注入减少每轮对话约 30% Token 消耗。
- **响应延迟**: 记忆检索在 20ms 内完成，不影响对话响应速度。

---

## 🔒 安全建议

- 保护 `config.enc.php`（附带的 .htaccess 示例可阻止直接访问）。
- 定期备份 `/user_data` 目录和数据库。
- 使用 HTTPS 加密传输。
- 保持 PHP 和 Web 服务器安全更新。

---

## 💬 设计理念

CmdCode Solo 遵循 **极简、单页、零依赖** 的哲学。不依赖任何前端框架或构建工具，仅使用原生浏览器能力，却实现了：

- 完整的 AI Agent 对话系统（工具调用）
- 文件上传、下载、预览、管理
- 用户认证和配额管理
- 上下文压缩和长对话记忆
- 请求取消和流式响应
- 文生图 / 图生图 / TTS / 音乐 / 视频多模态
- iOS/Android 安全区域适配

核心应用代码（HTML + CSS + JS）约 91KB，适合学习原生 Web 开发或作为轻量级 AI 应用基座。

---

## 🔗 相关链接

- **在线演示:** [https://cmdcode.cn/ui.html](https://cmdcode.cn/ui.html)
- **开源首页:** [https://cmdcode.cn/source/](https://cmdcode.cn/source/)
- **香港主站:** [https://cmdcode.cn](https://cmdcode.cn)
- **全球站:** [https://www.qqcmd.com](https://www.qqcmd.com)

---

## 🙏 致谢

- 记忆系统架构灵感来自 [TencentDB Agent Memory](https://cloud.tencent.com/product/tacl)
- 异步 Worker 模式基于 Hermes 系列 AI 助手的最佳实践

## 📄 许可证

MIT License — 自由使用、修改和分发。欢迎提交 Issue 和 Pull Request。
