#!/bin/bash
# 每小时记录所有端口流量日志
LOGFILE=/var/log/port-traffic.log
TS=$(date '+%Y-%m-%d %H:%M:%S')
ENTRY="$TS"

for port in 8082 8099 8888; do
  in_bytes=$(iptables -nvxL INPUT | grep "dpt:$port" | head -1 | awk '{print $2}')
  in_pkts=$(iptables -nvxL INPUT | grep "dpt:$port" | head -1 | awk '{print $1}')
  out_bytes=$(iptables -nvxL OUTPUT | grep "spt:$port" | head -1 | awk '{print $2}')
  out_pkts=$(iptables -nvxL OUTPUT | grep "spt:$port" | head -1 | awk '{print $1}')
  in_bytes=${in_bytes:-0}; in_pkts=${in_pkts:-0}; out_bytes=${out_bytes:-0}; out_pkts=${out_pkts:-0}
  ENTRY="$ENTRY P$port:IN${in_bytes}B/${in_pkts}p OUT${out_bytes}B/${out_pkts}p"
done

echo "$ENTRY" >> $LOGFILE
