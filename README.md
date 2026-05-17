# CmdCode Solo

> 单网页 · 零安装 · 零依赖 · 完整记忆系统的 AI Agent

一个极简、自托管、完全运行在浏览器中的 AI 编程助手，无需任何构建工具、框架或外部服务即可拥有长期记忆。只需一个 HTML 文件和一个 PHP 代理，即可在任意服务器上部署。

👉 **在线体验:** [CmdCode.cn/ui.html](https://cmdcode.cn/ui.html) — 访客模式，无需登录

---

## ✨ 核心特点

- **🧠 单网页应用** — 所有前端逻辑集成在单个 `ui.html` 文件中，无 npm、无 webpack、无框架。
- **📄 零安装、零外部依赖** — 不需要 Docker、不需要 Node.js、不需要 Python 虚拟环境。仅需 PHP 7.4+ 和 MySQL 5.7+（均可运行在最低配的共享主机上）。
- **💾 完整的记忆系统 (L1+L2+L3)** — 基于腾讯 TencentDB Agent Memory 架构的本地化实现：
  - **L1 原子记忆** — 自动从对话中提取事实、决策、偏好和凭证，AES-256-CBC 加密存储。
  - **L2 场景归纳** — 按项目或主题隔离记忆，防止多任务混淆。
  - **L3 用户画像** — 蒸馏出长期稳定的用户特征，让 AI 更"懂你"。
- **🔐 敏感信息加密记忆** — 密码、API Key 等凭据不会丢弃，而是加密存储，检索时内存解密，用完即焚。
- **🖼️ 访客共享盘** — 无需注册即可使用，所有用户生成的图片在左上角共享盘展示。开箱即用。
- **👤 多用户物理隔离** — 每个用户拥有独立文件夹（`/user_data/{id}/Memory/`），Linux 文件权限 0700，纳入 100MB 配额管理。
- **⚡ 流式响应** — 实时显示 AI 思考过程与工具调用步骤。
- **🎵 多模态支持** — 文生图、图生图、TTS 语音合成、音乐生成、视频生成，全部在对话中完成。
- **📱 移动端适配** — 响应式设计，支持 iOS/Android 安全区域，可作为 PWA 使用。
- **🪶 零框架依赖** — 纯原生 JavaScript (ES6)、CSS3、HTML5，无第三方库。

---

## 🤖 快速上手 — AI Agent 一键部署（推荐）

这是最快的部署方式，**只需一句话，AI 帮你全搞定**：

将以下任意一个仓库地址直接给 AI 智能体（如 **Hermes Agent**、**Claude Code**、**OpenClaw** 等），告诉它：

> **"请帮我部署这个项目到网站服务器和本地，让我可以直接访问 ui.html 使用。"**

```text
# Gitee（中国用户推荐）
https://gitee.com/xusuai/cmdcode-solo.git

# GitHub（国际用户推荐）
https://github.com/xusu-ai/cmdcode-solo.git
```

AI 智能体会自动完成以下工作：

| 步骤 | 说明 |
|------|------|
| ✅ 克隆代码 | 从仓库拉取最新代码 |
| ✅ 配置 API Key | 你提供大模型 API Key，AI 写入加密配置 |
| ✅ 配置服务器 | 你提供 FTP/服务器地址和密码，AI 上传文件到网站目录 |
| ✅ 配置数据库 | 你提供 MySQL 连接信息，AI 自动建表 |
| ✅ 配置 cron | AI 添加异步 Worker 定时任务 |
| ✅ 目录权限 | AI 创建 `/user_data` 目录并设置正确权限 |
| ✅ 安全加固 | AI 配置 `.htaccess` 安全规则 |
| 🎉 **完成！** | 直接访问 `https://你的域名/ui.html` 即可使用 |

> **你只需要做三件事：** ① 点同意授权 ② 提交 API Key ③ 提交服务器登录信息
>
> 其他一切由 AI 智能体自动完成，省时省力。

---

## 传统手动部署

### 环境要求

- PHP 7.4+（需 openssl、curl、pdo_mysql 扩展）
- MySQL 5.7+ 或 MariaDB 10.2+
- 任意 Web 服务器（Apache / Nginx / LiteSpeed）
- Cron 守护进程（用于异步记忆处理）

### 部署步骤

1. 克隆仓库到 Web 服务器目录。
2. 编辑 `config.enc.php`，设置强随机密码，并将你的 API Key 写入加密存储。
3. 修改 `proxy.php` 中的 `ACCESS_TOKEN` 和域名白名单。
4. 导入记忆系统所需的数据库表（自动创建，或手动执行 `CREATE TABLE IF NOT EXISTS ...`）。
5. 配置 Crontab：
   ```cron
   * * * * * sleep 0; /path/to/long-task-cron-worker.sh
   * * * * * sleep 15; /path/to/long-task-cron-worker.sh
   * * * * * sleep 30; /path/to/long-task-cron-worker.sh
   * * * * * sleep 45; /path/to/long-task-cron-worker.sh
   ```
6. 确保 `/user_data` 目录可被 Web 服务器写入。
7. 访问 `https://your-domain/ui.html` 即可开始使用。

### 使用方式

| 模式 | 说明 |
|------|------|
| **👤 访客模式** | 无需登录，对话和文件自动保存到共享目录，可查看其他用户生成的图片 |
| **🔑 登录模式** | 注册后获得 100MB 独享个人云存储，对话历史持久保存 |

---

## 🧠 记忆系统工作原理

### 记忆生命周期

1. **自动提取** — 当对话上下文压缩时，前端非阻塞调用入队 API，将对话发给后台。
2. **异步处理** — Cron Worker 拉取任务，调用 LLM 提取原子事实，经 AES-256-CBC 加密后追加到 `L1_facts.jsonl`。
3. **智能检索** — 用户每次发消息前，前端检索相关记忆（全文匹配 + 时间衰减 + 热度排序的 RRF 融合），注入系统提示。
4. **画像更新** — 每新增 30 条记忆，自动触发一次用户画像蒸馏，保持 L3 画像的时效性。

### 隐私保护

- 记忆内容使用用户独立密钥加密（密钥派生自主密钥 + 用户 ID 的 HMAC）。
- 检索时密文在内存中解密，LLM 上下文仅包含本次需要的明文，用完即释放。
- 凭据类记忆在索引表中脱敏显示，防止日志泄露。

---

## 📂 项目结构

```
cmdcode-solo/
├── ui.html                        # 完整前端 Agent（含记忆检索集成，~84KB）
├── proxy.php                      # API 代理 + 记忆系统核心（加密/检索/Worker 入口，~68KB）
├── config.enc.php                 # AES-256-CBC 加密配置
├── long-task-cron-worker.sh       # 通用异步 Worker（音乐/视频/记忆任务）
├── cron.d-long-task-worker        # Crontab 配置（每15秒错峰触发）
├── long-task-worker-check.sh      # Worker 健康检查
├── htaccess-example               # Apache/LiteSpeed 安全规则
├── v.php                          # 源码阅读器
├── source.html                    # 开源首页元数据
└── README.md
```

### 文件说明

| 文件 | 大小 | 说明 |
|------|------|------|
| **ui.html** | ~84 KB | 前端聊天界面，零框架依赖。内含 AI Agent 对话、文件管理器、图片查看器、音视频播放、用户认证等完整功能 |
| **proxy.php** | ~68 KB | 多供应商 API 代理，解决浏览器 CORS 问题。支持 MiniMax（三密钥轮换容灾）和 OpenCode Go，含用户注册登录、文件系统、记忆系统、远程 Bash、分享链接、Web 抓取等功能 |
| **config.enc.php** | ~2.5 KB | AES-256-CBC 加密配置，密钥永不落盘 |
| **htaccess-example** | 351 B | Apache/LiteSpeed 安全规则：禁止直接访问 `.enc.php`，保护 `.htaccess` 自身 |
| **long-task-cron-worker.sh** | ~5.8 KB | Cron 触发的异步任务 Worker，处理长耗时 MiniMax 音乐/视频/记忆提取 API 任务 |
| **cron.d-long-task-worker** | 411 B | Cron 调度配置，每 15 秒错峰执行 |
| **long-task-worker-check.sh** | ~1.7 KB | Worker 心跳检测，卡住时自动清除锁文件 |
| **v.php** | ~4.7 KB | 纯文本源码阅读器，白名单保护 source/ 目录下文件读取 |

**记忆相关数据库表**：`memory_tasks`（任务队列）、`memory_index`（全文索引）

**用户记忆目录**：`/user_data/{user_id}/Memory/` 下存储 L1 加密事实（JSONL）、L2 场景、L3 画像

---

## 📊 性能与成本

- **存储占用**：一个重度用户年增约 28 MB（在 100MB 配额内可运行 3 年以上）。
- **Token 节省**：通过上下文卸载和记忆注入，可降低每次对话约 30% 的 Token 消耗。
- **响应延迟**：记忆检索在 20ms 内完成，不影响对话实时性。

---

## 🔒 安全建议

- 保护 `config.enc.php`（已包含 .htaccess 示例禁止直接访问）。
- 定期备份 `/user_data` 目录和数据库。
- 使用 HTTPS 加密传输。
- 及时更新 PHP 和 Web 服务器安全补丁。

---

## 💬 设计理念

CmdCode Solo 的设计哲学是**极简、单页、零依赖**。不依赖任何前端框架或构建工具，仅用浏览器原生能力，却实现了：

- 完整的 AI Agent 对话系统（带工具调用）
- 文件上传、下载、预览和管理
- 用户认证与配额管理
- 上下文压缩与长对话记忆
- 请求取消与流式响应
- 文生图 / 图生图 / TTS / 音乐 / 视频多模态
- iOS/Android 安全区域适配

核心应用代码（HTML + CSS + JS）约 84KB，适合学习原生 Web 开发或作为轻量级 AI 应用底座。

---

## 🔗 相关链接

- **在线演示:** [https://cmdcode.cn/ui.html](https://cmdcode.cn/ui.html)
- **开源首页:** [https://cmdcode.cn/source/](https://cmdcode.cn/source/)
- **香港主站:** [https://cmdcode.cn](https://cmdcode.cn)
- **全球站:** [https://www.qqcmd.com](https://www.qqcmd.com)

---

## 🙏 致谢

- 记忆系统架构灵感来源于 [TencentDB Agent Memory](https://cloud.tencent.com/product/tacl)
- 异步 Worker 模式参考了 Hermes 系列 AI 助手的实践

## 📄 开源协议

MIT License — 自由使用、修改和分发。欢迎提交 Issue 和 Pull Request。
