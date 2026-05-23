#!/bin/bash
# ===== 对外端口流量统计 =====
# 端口说明:
#   8082 - SSH (仅天翼云 IP 白名单)
#   8099 - Node.js 服务 (IP 白名单)
#   8888 - Tinyproxy GitHub 代理 (IP+BasicAuth 双重)

echo "═══════════════════════════════════════════"
echo "  对外端口流量统计"
echo "  时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo "═══════════════════════════════════════════"

for port in 8082 8099 8888; do
  # 端口名映射
  case $port in
    8082) name="SSH";;
    8099) name="Node.js服务";;
    8888) name="Tinyproxy代理";;
    *) name="未知";;
  esac

  # 读取计数器（第一条匹配）
  in_line=$(iptables -nvxL INPUT | grep "dpt:$port" | head -1)
  out_line=$(iptables -nvxL OUTPUT | grep "spt:$port" | head -1)

  in_pkts=$(echo "$in_line" | awk '{print $1}')
  in_bytes=$(echo "$in_line" | awk '{print $2}')
  out_pkts=$(echo "$out_line" | awk '{print $1}')
  out_bytes=$(echo "$out_line" | awk '{print $2}')

  # 防止空值
  in_bytes=${in_bytes:-0}
  in_pkts=${in_pkts:-0}
  out_bytes=${out_bytes:-0}
  out_pkts=${out_pkts:-0}

  total=$((in_bytes + out_bytes))

  # 流量数字转可读格式
  in_hr=$(numfmt --to=iec $in_bytes 2>/dev/null || echo "${in_bytes}B")
  out_hr=$(numfmt --to=iec $out_bytes 2>/dev/null || echo "${out_bytes}B")
  total_hr=$(numfmt --to=iec $total 2>/dev/null || echo "${total}B")

  printf "  %-16s 入站: %10s (%5s pkts)  出站: %10s (%5s pkts)  合计: %10s\n" \
    "$name ($port)" "$in_hr" "$in_pkts" "$out_hr" "$out_pkts" "$total_hr"
done

# tinyproxy 连接统计
echo ""
proxy_total=$(grep -c 'Established connection' /var/log/tinyproxy/tinyproxy.log 2>/dev/null || echo 0)
echo "  Tinyproxy 历史总连接数: $proxy_total"

# vnstat 网卡统计
echo ""
echo "═══════════════════════════════════════════"
echo "  服务器全网卡流量 (vnstat)"
echo "═══════════════════════════════════════════"
vnstat -i eth0 --short 2>/dev/null || echo "  vnstat 数据收集中（首次需5-10分钟）..."
