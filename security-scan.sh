#!/bin/bash
# ============================================================
# CmdCode 安全扫描 - 每小时执行（systemd service 调用）
# 功能：内存Top10 + 后门/木马/挖矿检测
# 结果写入 /root/logs/security-report-YYYYMMddHH.log
# ============================================================

REPORT="/root/logs/security-report-$(date +%Y%m%d%H).log"
SUSPICIOUS=0

{
    echo "========================================"
    echo "安全扫描报告 - $(date '+%Y-%m-%d %H:%M:%S')"
    echo "========================================"

    # 【1】内存 Top 10 进程
    echo ""
    echo "【1】内存占用 Top 10 进程"
    echo "----------------------------------------"
    ps aux --sort=-%mem | awk 'NR<=11 {printf "%-10s %-8s %-6s %-10s %s\n", $1, $2, $3, $4, $11}'

    # 【2】挖矿/后门进程
    echo ""
    echo "【2】可疑进程扫描"
    echo "----------------------------------------"
    DANGER=$(ps aux | grep -Ei "xmrig|python.*miner|cryptonight|stratum+tcp|minerd|bitcoin|cgminer|ethminer|nanominer|serpent" | grep -v grep)
    if [ -n "$DANGER" ]; then
        echo "[!!] 发现挖矿/可疑进程:"
        echo "$DANGER"
        SUSPICIOUS=1
    else
        echo "[OK] 未发现挖矿进程"
    fi

    # 【3】CPU >80% 异常（排除工具进程）
    echo ""
    echo "【3】CPU >80% 异常进程"
    echo "----------------------------------------"
    HIGH_CPU=$(ps aux --sort=-%cpu | awk 'NR>1 && $3>80 && $11!="ps" && $11!="grep" && $11!="awk" && $11!="tail" && $11!="head" && $11!="sort" && $11!="cut" && $11!="sed" && $11!="bash" && $11!="sh" && $11!="systemctl" && $11!="sudo" && $11!="cat" && $11!="find" {printf "PID=%s USER=%s CPU=%.1f%% CMD=%s\n", $2, $1, $3, $11}')
    if [ -n "$HIGH_CPU" ]; then
        echo "$HIGH_CPU"
        SUSPICIOUS=1
    else
        echo "[OK] 无异常高CPU进程"
    fi

    # 【4】异常端口监听
    echo ""
    echo "【4】异常端口监听检测"
    echo "----------------------------------------"
    # 常见后门端口 + 非标准端口
    # 排除已知服务端口：22/80/443/8080/8081/8090/7681(ttyd)/3010(backend)
    ABNORMAL=$(ss -tlnp 2>/dev/null | grep -vE ":22 |:80 |:443 |:8080 |:8081 |:8090 |:3000 |:3010 |:5173 |:3306 |:6379|:53 |:7681 |:9000 |:8888 |:8082 |:8099 " | grep LISTEN)
    if [ -n "$ABNORMAL" ]; then
        echo "[!!] 非标准端口监听:"
        echo "$ABNORMAL"
        SUSPICIOUS=1
    else
        echo "[OK] 端口监听正常"
    fi

    # 【5】可疑文件
    echo ""
    echo "【5】可疑文件检测"
    echo "----------------------------------------"
    DANGER_FILES=$(find /tmp /var/tmp /run /dev/shm -type f \
        \( -name "*.so" -o -name "*xmrig*" -o -name "*cryptonight*" -o -name "*kworker*" \) \
        ! -path "*/hermes-snap*" ! -path "*/lftp_*" ! -path "*/deepseek*" \
        ! -path "*/start_cpolar*" ! -path "*/backfill*" ! -path "*/i18n*" \
        ! -path "*/ghostty*" ! -path "*/clean_hk*" ! -path "*/.bun-webgpu*" \
        ! -path "*/.58abff74b9b7abcd*" \
        ! -path "*/.3dabf79bfef05ef8*" \
        ! -path "*/.5ff33f*" \
        2>/dev/null | head -10)
    if [ -n "$DANGER_FILES" ]; then
        echo "[!!] 可疑文件:"
        echo "$DANGER_FILES"
        SUSPICIOUS=1
    else
        echo "[OK] 无可疑文件"
    fi

    # 【6】SSH authorized_keys
    echo ""
    echo "【6】SSH authorized_keys 检测"
    echo "----------------------------------------"
    for user in $(cut -d: -f1 /etc/passwd); do
        key=$(cat /home/$user/.ssh/authorized_keys 2>/dev/null)
        [ -n "$key" ] && echo "[$user] $key"
    done

    # 【7】僵尸进程
    echo ""
    echo "【7】僵尸进程检测"
    echo "----------------------------------------"
    ZOMBIE=$(ps aux | awk '$8=="Z" {print}')
    if [ -n "$ZOMBIE" ]; then
        echo "[!!] 僵尸进程:"
        echo "$ZOMBIE"
        SUSPICIOUS=1
    else
        echo "[OK] 无僵尸进程"
    fi

    echo ""
    echo "========================================"
    if [ $SUSPICIOUS -eq 1 ]; then
        echo "[!!] 发现可疑活动，请立即检查！"
    else
        echo "[OK] 未发现明显后门/木马/挖矿迹象"
    fi
    echo "========================================"

} > "$REPORT" 2>&1

# 写入 flag 文件供 Hermes Cron Job 检测
if [ $SUSPICIOUS -eq 1 ]; then
    echo "SUSPICIOUS" > /root/logs/security-alert.flag
else
    echo "CLEAN" > /root/logs/security-alert.flag
fi

echo "扫描完成 - $(date '+%Y-%m-%d %H:%M:%S') - SUSPICIOUS=$SUSPICIOUS"
