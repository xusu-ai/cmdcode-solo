# Cmdcode Solo

> One Page, Full Hermes-Style Agent.

**Cmdcode Solo** —— 一个纯 HTML 单页 AI 智能体，在浏览器中就能运行。无需安装、无需跳转，一个页面完成对话、指令执行与任务自动化。

如赫耳墨斯般迅捷，一页即达。

👉 **即刻访问：** [CmdCode.cn/ui.html](https://cmdcode.cn/ui.html) — 访客免登录，直接使用

---

## ✨ 核心特性

- **🧠 类 Hermes Agent 体验** —— 支持多轮对话、工具调用、上下文记忆与自主任务执行。
- **📄 真正的单页应用** —— 所有功能集成于一个 HTML 文件，无路由、无复杂构建工具链。
- **💾 文件沙箱与网盘** —— 内置文件管理器，支持上传/下载/预览，**访客**与登录用户均有专属存储空间。
- **🖼️ 访客共享盘** —— 无需注册，免费生图，左上角访客共享盘可看到所有人生成的图片。即开即用。
- **🔐 安全鉴权** —— 支持登录/注册，令牌由服务端下发，前端不存储任何敏感凭证。
- **⚡ 流式响应** —— 实时展示 AI 思考过程与工具调用步骤。
- **🎵 多模态支持** —— 文生图、图生图、TTS 语音合成、音乐生成、视频生成，全部在对话中完成。
- **📱 移动端适配** —— 响应式设计，支持 iOS/Android 安全区域，可作为 PWA 使用。
- **🪶 零框架依赖** —— 纯原生 JavaScript (ES6)、CSS3 与 HTML5，无任何第三方库。

---

## 🖼️ 界面预览

| 对话交互 | 文件管理 | 移动端适配 |
|:---:|:---:|:---:|
| 聊天界面 + AI 流式回复 | 文件浏览器 + 共享盘 | 响应式布局，支持安全区域 |

*(截图可后续补充)*

---

## 🛠️ 技术架构

| 层级 | 技术选型 | 说明 |
|---|---|---|
| **前端** | 原生 HTML/CSS/JS | 无框架，单文件 1343 行实现完整应用 |
| **通信** | `fetch` + `AbortController` | 支持请求取消与流式读取 |
| **后端代理** | PHP (`proxy.php`) | 转发 API 请求，隐藏真实 Token，17 个 Action |
| **AI 模型** | MiniMax / DeepSeek / OpenCode Go | 多供应商轮换，支持思考模式与工具调用 |
| **文件存储** | 服务端文件系统 | 按用户隔离，100MB 配额，访客共享盘 |
| **异步任务** | Cron + Shell 脚本 | 音乐/视频等长时间任务后台处理 |

---

## 📁 项目结构

```
cmdcode-solo/
├── ui.html                        # 主应用页面（完整单页，80KB）
├── proxy.php                      # API 代理与鉴权（37KB，17个 Action）
├── config.enc.php                 # 加密配置（AES-256-CBC）
├── htaccess-example               # Apache/LiteSpeed 安全规则
├── long-task-cron-worker.sh       # 异步任务 Worker（音乐/视频）
├── cron.d-long-task-worker        # Cron 调度配置（每15秒）
├── long-task-worker-check.sh      # Worker 健康检查
├── v.php                          # 源代码阅读器
├── source.html                    # 开源首页元数据
└── README.md
```

### 各文件说明

| 文件 | 大小 | 说明 |
|------|------|------|
| **ui.html** | 80 KB | 前端聊天界面，零框架依赖，内置 AI Agent 对话、文件管理器、图片查看器、音视频播放、用户认证等全部功能 |
| **proxy.php** | 38 KB | 多供应商 API 代理，解决浏览器 CORS 问题。支持 MiniMax（三密钥轮换容灾）和 OpenCode Go，含用户注册登录、文件系统、分享链接、Web 抓取等 |
| **config.enc.php** | 2 KB | AES-256-CBC 加密配置，密钥永不落盘 |
| **htaccess-example** | 351 B | Apache/LiteSpeed 安全规则：禁止直接访问 `.enc.php`、保护 `.htaccess` 自身 |
| **long-task-cron-worker.sh** | 4.5 KB | 系统 crontab 触发的异步任务 Worker，处理 MiniMax 音乐/视频等长时间运行的 API 任务 |
| **cron.d-long-task-worker** | 411 B | Cron 调度配置，每 15 秒错峰触发 Worker |
| **long-task-worker-check.sh** | 1.7 KB | Worker 心跳检测，卡死自动清理锁文件 |
| **v.php** | 4.7 KB | 纯源代码阅读器，白名单安全读取 source/ 目录下的文件 |
| **source.html** | 7 KB | 开源首页，展示所有文件的元数据与下载链接 |

---

## 🚀 快速开始

### 环境要求

- 任意支持 PHP 的 Web 服务器（Nginx / Apache / LiteSpeed）
- HTTPS 证书（用于安全传输令牌）
- MiniMax / DeepSeek / OpenCode Go API Key（任一即可）

### 部署

```bash
# 克隆仓库
git clone https://gitee.com/xusuai/cmdcode-solo.git

# 将文件放置于 Web 服务器目录
cp -r cmdcode-solo/* /var/www/html/

# 配置 proxy.php 中的 ACCESS_TOKEN 和 API Key
# 修改 ui.html 中的 PROXY_URL 为你自己的代理地址
```

### 访问

打开浏览器访问 `https://your-domain/ui.html` 即可开始使用。

| 模式 | 说明 |
|------|------|
| **👤 访客模式** | 无需登录注册，对话与文件自动保存至共享目录。可查看他人生成的图片 |
| **🔑 登录模式** | 注册后获得 100MB 专属个人网盘空间，对话记录持久化保存 |

---

## 💬 开发理念

Cmdcode Solo 的设计哲学是 **极简、单页、零依赖**。它不依赖任何前端框架或构建工具，只使用浏览器原生能力，便实现了：

- 完整的 AI Agent 对话系统（含工具调用）
- 文件上传、下载、预览管理
- 用户认证与配额管理
- 上下文压缩与长对话记忆
- 请求取消与流式响应
- 文生图 / 图生图 / TTS / 音乐 / 视频多模态
- iOS/Android 安全区域适配

整个应用的核心代码（HTML + CSS + JS）约 80KB / 1343 行，适合学习原生 Web 开发或作为轻量级 AI 应用基座。

---

## 🔗 相关链接

- **在线体验：** [https://cmdcode.cn/ui.html](https://cmdcode.cn/ui.html)
- **开源首页：** [https://cmdcode.cn/source/](https://cmdcode.cn/source/)
- **香港主站：** [https://cmdcode.cn](https://cmdcode.cn)
- **全球站点：** [https://www.qqcmd.com](https://www.qqcmd.com)

---

## 📄 开源协议

本项目采用 MIT 协议开源，欢迎提交 Issue 与 Pull Request。
