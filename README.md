# Cmdcode Solo

> One Page, Full Hermes-Style Agent.

**Cmdcode Solo** — A pure HTML single-page AI agent that runs directly in the browser. No installation required, no navigation needed — complete conversations, command execution, and task automation all on one page.

Swift as Hermes, delivered in a single page.

👉 **Try it now:** [CmdCode.cn/ui.html](https://cmdcode.cn/ui.html) — Guest access, no login required

---

## ✨ Core Features

- **🧠 Hermes Agent-like Experience** — Multi-turn conversation, tool calling, context memory, and autonomous task execution.
- **📄 True Single-Page Application** — All features integrated into a single HTML file, no routing or complex build toolchain.
- **💾 File Sandbox & Cloud Drive** — Built-in file manager with upload/download/preview, dedicated storage for both **guests** and logged-in users.
- **🖼️ Guest Shared Drive** — No registration needed, generate images for free. The guest shared drive in the top-left corner displays images created by everyone. Ready to use out of the box.
- **🔐 Secure Authentication** — Login/registration supported, tokens issued server-side, no sensitive credentials stored on the frontend.
- **⚡ Streaming Responses** — Real-time display of AI thought process and tool invocation steps.
- **🎵 Multimodal Support** — Text-to-image, image-to-image, TTS voice synthesis, music generation, video generation — all within the conversation.
- **📱 Mobile Optimized** — Responsive design with iOS/Android safe area support, usable as a PWA.
- **🪶 Zero Framework Dependencies** — Pure vanilla JavaScript (ES6), CSS3, and HTML5, no third-party libraries.

---

## 🖼️ Interface Preview

| Conversation | File Management | Mobile Adaptation |
|:---:|:---:|:---:|
| Chat interface + AI streaming replies | File browser + shared drive | Responsive layout with safe area support |

*(Screenshots to be added)*

---

## 🛠️ Technical Architecture

| Layer | Technology | Description |
|---|---|---|
| **Frontend** | Vanilla HTML/CSS/JS | No frameworks, single file 1343 lines implements full application |
| **Communication** | `fetch` + `AbortController` | Supports request cancellation and streaming reads |
| **Backend Proxy** | PHP (`proxy.php`) | Forwards API requests, hides real tokens, 17 Actions |
| **AI Models** | MiniMax / DeepSeek / OpenCode Go | Multi-provider rotation, supports thinking mode and tool calling |
| **File Storage** | Server filesystem | Per-user isolation, 100MB quota, guest shared drive |
| **Async Tasks** | Cron + Shell scripts | Background processing for long-running tasks like music/video |

---

## 📁 Project Structure

```
cmdcode-solo/
├── ui.html                        # Main application page (complete single page, 80KB)
├── proxy.php                      # API proxy & authentication (37KB, 17 Actions)
├── config.enc.php                 # Encrypted configuration (AES-256-CBC)
├── htaccess-example               # Apache/LiteSpeed security rules
├── long-task-cron-worker.sh       # Async task worker (music/video)
├── cron.d-long-task-worker        # Cron schedule config (every 15 seconds)
├── long-task-worker-check.sh      # Worker health check
├── v.php                          # Source code viewer
├── source.html                    # Open source homepage metadata
└── README.md
```

### File Descriptions

| File | Size | Description |
|------|------|-------------|
| **ui.html** | 80 KB | Frontend chat interface, zero framework dependencies. Built-in AI Agent conversation, file manager, image viewer, audio/video playback, user authentication, and more |
| **proxy.php** | 38 KB | Multi-provider API proxy, solves browser CORS issues. Supports MiniMax (triple-key rotation failover) and OpenCode Go, includes user registration/login, file system, share links, web scraping, etc. |
| **config.enc.php** | 2 KB | AES-256-CBC encrypted configuration, keys never written to disk |
| **htaccess-example** | 351 B | Apache/LiteSpeed security rules: blocks direct access to `.enc.php`, protects `.htaccess` itself |
| **long-task-cron-worker.sh** | 4.5 KB | Cron-triggered async task worker, processes long-running MiniMax music/video API tasks |
| **cron.d-long-task-worker** | 411 B | Cron schedule config, staggered every 15 seconds |
| **long-task-worker-check.sh** | 1.7 KB | Worker heartbeat check, auto-clears lock file on hang |
| **v.php** | 4.7 KB | Plain source code viewer, whitelist-secured reading of files under source/ directory |
| **source.html** | 7 KB | Open source homepage, displays metadata and download links for all files |

---

## 🚀 Quick Start

### Requirements

- Any PHP-capable web server (Nginx / Apache / LiteSpeed)
- HTTPS certificate (for secure token transmission)
- MiniMax / DeepSeek / OpenCode Go API Key (any one will do)

### Deployment

```bash
# Clone the repository
git clone https://gitee.com/xusuai/cmdcode-solo.git

# Place files in your web server directory
cp -r cmdcode-solo/* /var/www/html/

# Configure ACCESS_TOKEN and API Key in proxy.php
# Change PROXY_URL in ui.html to your own proxy address
```

### Usage

Open your browser and visit `https://your-domain/ui.html` to start using.

| Mode | Description |
|------|-------------|
| **👤 Guest Mode** | No login required, conversations and files auto-saved to shared directory. View images generated by other users |
| **🔑 Login Mode** | Register to get 100MB dedicated personal cloud storage, persistent conversation history |

---

## 💬 Development Philosophy

Cmdcode Solo's design philosophy is **minimalist, single-page, zero-dependency**. It relies on no frontend frameworks or build tools, using only native browser capabilities, yet achieves:

- Full AI Agent conversation system (with tool calling)
- File upload, download, preview, and management
- User authentication and quota management
- Context compression and long conversation memory
- Request cancellation and streaming responses
- Text-to-image / image-to-image / TTS / music / video multimodal
- iOS/Android safe area adaptation

The core application code (HTML + CSS + JS) is approximately 80KB / 1343 lines, suitable for learning vanilla web development or as a lightweight AI application base.

---

## 🔗 Related Links

- **Live Demo:** [https://cmdcode.cn/ui.html](https://cmdcode.cn/ui.html)
- **Open Source Homepage:** [https://cmdcode.cn/source/](https://cmdcode.cn/source/)
- **Hong Kong Main Site:** [https://cmdcode.cn](https://cmdcode.cn)
- **Global Site:** [https://www.qqcmd.com](https://www.qqcmd.com)

---

## 📄 License

This project is open source under the MIT License. Issues and Pull Requests are welcome.
