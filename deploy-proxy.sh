#!/bin/bash
# deploy-proxy.sh — 部署 proxy.php 到线上
# 自动从 proxy.php 读取真实 token，无需硬编码
set -e
SRC="/cmdcode-solo/proxy.php"
TOKEN=$(grep -oP "define\('ACCESS_TOKEN', '\K[0-9a-f]+(?=')" "$SRC")

if [ -z "$TOKEN" ] || [ "$TOKEN" = "__YOUR_PROXY_ACCESS_TOKEN__" ]; then
  echo "❌ 错误：本地 proxy.php 是脱敏版，无法部署！"
  echo "   请先从线上同步生产版到本地：bash /root/.hermes/skills/devops/production-file-sync-local/SKILL.md"
  exit 1
fi

echo "=== 部署到香港站 ==="
lftp -u host0012314959,Xusu8800033 host0012314959.xincache1.cn \
  -e "set net:timeout 90; set ftp:passive-mode on; set xfer:clobber on; \
  put $SRC -o /www/cmdcode-minimax-toolset/proxy.php; quit" && echo "✅ 香港站"

echo "=== 部署到全球站 ==="
lftp -u host9309191354,Xusu8800033 host9309191354.xincache1.cn \
  -e "set net:timeout 90; set ftp:passive-mode on; set ftp:charset GBK; set xfer:clobber on; \
  put $SRC -o /www/proxy.php; quit" && echo "✅ 全球站"

echo "=== 验证 ==="
sleep 2
curl -s "https://cmdcode.cn/cmdcode-minimax-toolset/proxy.php" \
  -H 'Content-Type: application/json' \
  -H 'Origin: https://cmdcode.cn' \
  -d "{\"_token\":\"$TOKEN\",\"_action\":\"quota\"}" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('quotaMB:', d.get('quotaMB'))"
echo "✅ 部署完成"
