#!/bin/bash
# /root/scripts/long-task-cron-worker.sh
# 系统 crontab 触发（非 Hermes Cron），每15秒错峰运行
# 通用长任务 Worker — 处理音乐/视频等长时间运行的 MiniMax API 任务
# 从香港站拉取 pending 任务，在本机调 MiniMax，再写回结果

PROXY_URL="https://cmdcode.cn/cmdcode-minimax-toolset/proxy.php"
TOKEN="__YOUR_PROXY_TOKEN__"
LOCK_FILE="/tmp/long-task-worker.lock"
HEARTBEAT_FILE="/tmp/long-task-worker-heartbeat"
TIMEOUT=180
MAX_TASKS=5  # 每轮最多处理5个记忆任务

# 防重叠锁
if [ -f "$LOCK_FILE" ]; then
    LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null)
    if kill -0 "$LOCK_PID" 2>/dev/null; then
        exit 0  # 上一个还在跑，跳过
    fi
    rm -f "$LOCK_FILE"
fi
echo $$ > "$LOCK_FILE"
touch "$HEARTBEAT_FILE"
trap 'rm -f "$LOCK_FILE"' EXIT

# 获取 provider 配置
PROVIDER_JSON=$(curl -s "$PROXY_URL" \
    -H 'Content-Type: application/json' \
    -H 'Origin: https://cmdcode.cn' \
    -d "{\"_token\":\"$TOKEN\",\"_path\":\"/music_get_provider\"}")

# 解析 base_url 和 keys
if echo "$PROVIDER_JSON" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('base_url',''))" 2>/dev/null | grep -q '^https\?'; then
    BASE_URL=$(echo "$PROVIDER_JSON" | python3 -c "import sys,json; print(json.load(sys.stdin).get('base_url',''))")
    KEYS=$(echo "$PROVIDER_JSON" | python3 -c "import sys,json; d=json.load(sys.stdin); print(' '.join([k for k in d.get('keys',[]) if k]))")
else
    echo "❌ /music_get_provider 不可用"
    exit 1
fi

IFS=' ' read -ra KEY_ARRAY <<< "$KEYS"
if [ ${#KEY_ARRAY[@]} -eq 0 ]; then
    exit 1
fi

# === 任务类型判定：先检查音乐任务，再检查视频任务 ===
process_task() {
    local PREFIX="$1"       # "music" 或 "video"
    local PENDING_ENDPOINT="$2"   # "/music_pending" 或 "/video_pending"
    local READ_ENDPOINT="$3"      # "/music_read_params" 或 "/video_read_params"
    local WRITE_ENDPOINT="$4"     # "/music_write_result" 或 "/video_write_result"
    local API_PATH="$5"           # "/music_generation" 或 "/video_generation"

    PENDING_JSON=$(curl -s "$PROXY_URL" \
        -H 'Content-Type: application/json' \
        -H 'Origin: https://cmdcode.cn' \
        -d "{\"_token\":\"$TOKEN\",\"_path\":\"$PENDING_ENDPOINT\"}")

    PENDING_COUNT=$(echo "$PENDING_JSON" | python3 -c "import sys,json; print(json.load(sys.stdin).get('count',0))" 2>/dev/null)
    [ "$PENDING_COUNT" -eq 0 ] 2>/dev/null && return 1

    FIRST_ID=$(echo "$PENDING_JSON" | python3 -c "
import sys,json
d=json.load(sys.stdin)
ids = d.get('pending',[])
print(ids[0] if ids else '')
" 2>/dev/null)
    [ -z "$FIRST_ID" ] && return 1

    # 读取任务参数
    PARAMS_JSON=$(curl -s "$PROXY_URL" \
        -H 'Content-Type: application/json' \
        -H 'Origin: https://cmdcode.cn' \
        -d "{\"_token\":\"$TOKEN\",\"_path\":\"$READ_ENDPOINT\",\"task_id\":\"$FIRST_ID\"}")

    PARAMS=$(echo "$PARAMS_JSON" | python3 -c "
import sys,json
d=json.load(sys.stdin)
if 'error' in d:
    sys.exit(1)
print(json.dumps(d.get('params',{})))
" 2>/dev/null) || return 1

    # 调用 MiniMax API（本机 curl，无 PHP-FPM 超时限制！）
    API_URL="${BASE_URL}${API_PATH}"
    RESULT_BODY=""
    HTTP_CODE=0

    for KEY in "${KEY_ARRAY[@]}"; do
        [ -z "$KEY" ] && continue
        RESULT=$(curl -s -w "\n%{http_code}" "$API_URL" \
            -H 'Content-Type: application/json' \
            -H "Authorization: Bearer $KEY" \
            -d "$PARAMS" \
            --max-time $TIMEOUT)
        HTTP_CODE=$(echo "$RESULT" | tail -1)
        BODY=$(echo "$RESULT" | sed '$d')
        [ "$HTTP_CODE" = "000" ] || [ "$HTTP_CODE" = "429" ] && continue
        RESULT_BODY="$BODY"
        break
    done

    if [ -z "$RESULT_BODY" ]; then
        RESULT_BODY='{"error":"all_keys_exhausted"}'
    fi

    # 回写结果到香港站
    curl -s "$PROXY_URL" \
        -H 'Content-Type: application/json' \
        -H 'Origin: https://cmdcode.cn' \
        -d "{\"_token\":\"$TOKEN\",\"_path\":\"$WRITE_ENDPOINT\",\"task_id\":\"$FIRST_ID\",\"result\":$RESULT_BODY}" > /dev/null

    echo "$(date '+%Y-%m-%d %H:%M:%S') [$PREFIX] task=$FIRST_ID http=$HTTP_CODE"
    return 0
}

# 主流程：优先处理音乐，再处理视频（一次只处理一个任务）
process_task "music"  "/music_pending"     "/music_read_params"     "/music_write_result"     "/music_generation" && exit 0
process_task "video"  "/video_pending"     "/video_read_params"     "/video_write_result"     "/video_generation" && exit 0

# === 记忆任务处理 ===
process_memory_tasks() {
    PENDING_JSON=$(curl -s "$PROXY_URL" \
        -H 'Content-Type: application/json' \
        -d "{\"_token\":\"$TOKEN\",\"_path\":\"/memory_pending\"}" 2>/dev/null)
    
    PENDING_COUNT=$(echo "$PENDING_JSON" | python3 -c "import sys,json; print(json.load(sys.stdin).get('count',0))" 2>/dev/null)
    [ "$PENDING_COUNT" -eq 0 ] 2>/dev/null && return 1
    
    # 获取 task_id 列表
    IDS=$(echo "$PENDING_JSON" | python3 -c "
import sys,json
d=json.load(sys.stdin)
for tid in d.get('pending',[]): print(tid)
" 2>/dev/null)
    
    [ -z "$IDS" ] && return 1
    
    for TID in $IDS; do
        [ "$MAX_TASKS" -le 0 ] && break
        ((MAX_TASKS--))
        
        RESULT=$(curl -s "$PROXY_URL" \
            -H 'Content-Type: application/json' \
            -d "{\"_token\":\"$TOKEN\",\"_path\":\"/memory_process\",\"task_id\":$TID}" 2>/dev/null)
        
        STATUS=$(echo "$RESULT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('status','unknown'))" 2>/dev/null)
        echo "$(date '+%Y-%m-%d %H:%M:%S') [memory] task=$TID status=$STATUS"
        
        sleep 5
    done
    return 0
}

process_memory_tasks
exit 0
