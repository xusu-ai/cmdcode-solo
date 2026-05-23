#!/bin/bash
# CmdCode WebUI Backend Health Check & Auto-Restart
# Check port 3010 every minute

if ! curl -s http://localhost:3010/api/health 2>/dev/null | grep -q "ok"; then
    echo "[$(date)] Backend 3010 down, restarting..." | tee -a /root/logs/cmdcode-webui.log
    pkill -f "qemu-x86_64.*web.ts" 2>/dev/null
    cd /root/cmdcode-mini && qemu-x86_64-static /opt/bun/bun-linux-x64/bun run src/web.ts >> /root/logs/cmdcode-webui-backend.log 2>&1 &
fi
