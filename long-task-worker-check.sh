#!/bin/bash
# /root/.hermes/scripts/long-task-worker-check.sh
# 检查 long-task-cron-worker 是否正常运行
# 使用心跳文件（每15秒至少 touch 一次）+ crontab 双重验证
# 输出结果会被 Hermes cron 投递到 QQ

HEARTBEAT_FILE="/tmp/long-task-worker-heartbeat"
CRONTAB_FILE="/etc/cron.d/long-task-worker"
NOW=$(date +%s)

echo "📋 long-task-cron-worker 健康检查"
echo "---"

# ① 检查 crontab 配置文件
if [ -f "$CRONTAB_FILE" ]; then
    echo "✅ crontab 配置文件存在"
else
    echo "❌ crontab 配置文件缺失: $CRONTAB_FILE"
fi

# ② 检查 crond 服务
if systemctl is-active cron >/dev/null 2>&1; then
    echo "✅ crond 服务运行中"
else
    echo "❌ crond 服务未运行"
fi

# ③ 检查心跳文件新鲜度
if [ -f "$HEARTBEAT_FILE" ]; then
    MTIME=$(stat -c %Y "$HEARTBEAT_FILE" 2>/dev/null || stat -f %m "$HEARTBEAT_FILE" 2>/dev/null)
    if [ -n "$MTIME" ]; then
        AGE=$((NOW - MTIME))
        if [ "$AGE" -le 120 ]; then
            echo "✅ worker 活跃正常（${AGE}秒前有心跳）"
        elif [ "$AGE" -le 300 ]; then
            echo "⚠️  worker 可能异常（最后心跳 ${AGE}秒前，超过120秒阈值）"
        else
            echo "❌ worker 已停止（最后心跳 ${AGE}秒前）"
        fi
    else
        echo "⚠️  无法读取心跳文件时间戳"
    fi
else
    echo "❌ 心跳文件不存在（worker 从未运行或已被清理）"
fi

# ④ 有进程在跑也提示一下（辅助信息）
RUNNING=$(ps aux | grep '/bash /root/scripts/long-task-cron-worker.sh' | grep -v grep | wc -l)
if [ "$RUNNING" -gt 0 ]; then
    echo "📌 当前有 ${RUNNING} 个 worker 进程在运行"
fi
