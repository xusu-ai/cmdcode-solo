#!/bin/bash
# CmdCode WebUI Frontend Health Check & Auto-Restart
# Check port 8081 every minute

if ! curl -s -o /dev/null -w "%{http_code}" http://localhost:8081/ | grep -q "200"; then
    echo "[$(date)] Frontend 8081 down, restarting..." | tee -a /root/logs/cmdcode-webui.log
    pkill -f "python3 -m http.server 8081" 2>/dev/null
    cd /root/cmdcode-mini/webui && python3 -m http.server 8081 >> /root/logs/cmdcode-webui-frontend.log 2>&1 &
fi
