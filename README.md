# CmdCode Solo

CmdCode WebUI 开源源代码 —— 一个单页、零依赖的 AI 智能体前端，在浏览器中即可运行。

## 项目结构

```
cmdcode-solo/
├── ui.html                    # 主应用页面（完整单页 80KB）
├── proxy.php                  # API 代理与鉴权（37KB，17个Action）
├── config.enc.php             # 加密配置（AES-256-CBC）
├── htaccess-example           # 安全规则示例（Apache/LiteSpeed）
├── long-task-cron-worker.sh   # 异步任务 Worker（音乐/视频）
├── cron.d-long-task-worker    # Cron 调度配置（每15秒）
├── long-task-worker-check.sh  # Worker 健康检查脚本
├── v.php                      # 源代码阅读器
├── source.html                # 开源首页
└── README.md
```

## 核心功能

| 功能 | 说明 |
|------|------|
| 🧠 AI Agent 对话 | Hermes 风格的智能体，支持工具调用、流式响应 |
| 📁 文件管理器 | 上传/下载/预览，用户隔离存储，配额管理 |
| 🖼️ 图片查看器 | 键盘翻页、触屏滑动，多图浏览 |
| 🎵 音频/视频播放 | 内嵌播放器，支持 mp3/wav/mp4 等格式 |
| 🔐 用户认证 | 登录/注册，Token 鉴权，guest 访客模式 |
| 🌐 多供应商 API | MiniMax、OpenCode Go 等 AI 模型代理 |
| 📱 移动端适配 | 响应式设计，支持 iOS/Android 安全区域 |
| 📤 原生分享 | navigator.share 三层策略（文件→URL→下载） |

## 技术架构

| 层级 | 技术 | 说明 |
|------|------|------|
| 前端 | 原生 HTML/CSS/JS | 零框架依赖，单文件 1343 行 |
| 后端代理 | PHP（proxy.php） | 路由分发，Token 鉴权，CORS 绕过 |
| AI 模型 | MiniMax / OpenCode Go 等 | 多供应商轮换，流式输出 |
| 文件存储 | 服务端文件系统 | 按用户隔离，100MB 配额 |
| 异步任务 | Cron + Shell 脚本 | 音乐/视频等长时间任务 |

## 部署

### 环境要求
- PHP 7.4+ Web 服务器（Nginx / Apache / LiteSpeed）
- HTTPS 证书（安全传输 Token）
- MiniMax / OpenCode Go API Key

### 快速开始

```bash
# 克隆仓库
git clone https://gitee.com/xusuai/cmdcode-solo.git

# 部署到 Web 服务器
cp -r cmdcode-solo/* /var/www/html/

# 编辑 proxy.php，配置 ACCESS_TOKEN 和 API Key
# 编辑 ui.html，修改 PROXY_URL 为你的代理地址
```

## 开源协议

MIT License
