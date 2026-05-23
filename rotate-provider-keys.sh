#!/bin/bash
# /root/scripts/rotate-provider-keys.sh
# 每日 7:00 自动轮换大模型 API Key（双重保险机制）
# 由 Hermes Cron 触发，同时轮换 OpenCode Go 链和 MiniMax 起点
#
# 轮换逻辑：
#   opencode-go 链：opencode-go → opencode-go1 → opencode-go2 → opencode-go3 → opencode-go4
#   minimax 起点：key[0]→key[1]→key[2]→key[0]...

PROXY_URL="https://cmdcode.cn/cmdcode-minimax-toolset/proxy.php"
TOKEN="__YOUR_PROXY_ACCESS_TOKEN__"

# 轮换所有 provider 组
RESULT=$(curl -s "$PROXY_URL" \
    -H 'Content-Type: application/json' \
    -H 'Origin: https://cmdcode.cn' \
    -d "{\"_token\":\"$TOKEN\",\"_path\":\"/rotate_provider\",\"group\":\"all\"}")

echo "$(date '+%Y-%m-%d %H:%M:%S') 轮换结果: $RESULT"

# 验证轮换后状态
STATUS=$(curl -s "$PROXY_URL" \
    -H 'Content-Type: application/json' \
    -H 'Origin: https://cmdcode.cn' \
    -d "{\"_token\":\"$TOKEN\",\"_path\":\"/rotation_status\"}")

echo "$(date '+%Y-%m-%d %H:%M:%S') 轮换后状态: $STATUS"
