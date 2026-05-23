#!/bin/bash
# /root/scripts/long-task-cron-worker.sh
# 系统 crontab 触发（非 Hermes Cron），每15秒错峰运行
# 通用长任务 Worker — 处理音乐/视频等长时间运行的 MiniMax API 任务
# 从香港站拉取 pending 任务，在本机调 MiniMax，再写回结果

PROXY_URL="https://cmdcode.cn/cmdcode-minimax-toolset/proxy.php"
TOKEN="__YOUR_PROXY_ACCESS_TOKEN__"
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

# ── 本地 429 冷却状态文件（与 PHP 端 cooldown 互补）──
WORKER_COOLDOWN_FILE="/tmp/worker_key_cooldown.json"

# 检查 key 是否在冷却期内
key_in_cooldown() {
    local key="$1"
    local now
    now=$(date +%s)
    if [ -f "$WORKER_COOLDOWN_FILE" ]; then
        local expires
        expires=$(python3 -c "import json,os; d=json.load(open('$WORKER_COOLDOWN_FILE')) if os.path.exists('$WORKER_COOLDOWN_FILE') else {}; print(d.get('$key',0))" 2>/dev/null)
        [ -n "$expires" ] && [ "$expires" -gt "$now" ] && return 0
    fi
    return 1
}

# 标记 key 进入 30 秒冷却
mark_key_cooldown() {
    local key="$1"
    local seconds="${2:-30}"
    local now
    now=$(date +%s)
    local expires=$((now + seconds))
    python3 -c "
import json, os
f='$WORKER_COOLDOWN_FILE'
d=json.load(open(f)) if os.path.exists(f) else {}
d['$key']=$expires
json.dump(d, open(f,'w'))
" 2>/dev/null
}

# ── Provider 配置刷新函数（每次任务处理前调用，确保捕获最新轮换状态）──
refresh_provider_keys() {
    local PROVIDER_JSON
    PROVIDER_JSON=$(curl -s "$PROXY_URL" \
        -H 'Content-Type: application/json' \
        -H 'Origin: https://cmdcode.cn' \
        -d "{\"_token\":\"$TOKEN\",\"_path\":\"/music_get_provider\"}")

    # 解析 base_url 和 keys
    if echo "$PROVIDER_JSON" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('base_url',''))" 2>/dev/null | grep -q '^https\?'; then
        BASE_URL=$(echo "$PROVIDER_JSON" | python3 -c "import sys,json; print(json.load(sys.stdin).get('base_url',''))")
        KEYS=$(echo "$PROVIDER_JSON" | python3 -c "import sys,json; d=json.load(sys.stdin); print(' '.join([k for k in d.get('keys',[]) if k]))")
    else
        echo "$(date '+%Y-%m-%d %H:%M:%S') ❌ /music_get_provider 不可用"
        return 1
    fi

    IFS=' ' read -ra KEY_ARRAY <<< "$KEYS"
    if [ ${#KEY_ARRAY[@]} -eq 0 ]; then
        return 1
    fi
    return 0
}

# 初始加载 provider 配置
refresh_provider_keys || exit 1

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

    # 刷新 provider 配置（确保捕获每15秒内轮换后的最新 Key 顺序）
    refresh_provider_keys || return 1

    for KEY in "${KEY_ARRAY[@]}"; do
        [ -z "$KEY" ] && continue

        # 检查本地 429 冷却期
        if key_in_cooldown "$KEY"; then
            echo "$(date '+%Y-%m-%d %H:%M:%S') [$PREFIX] key in cooldown (429 backoff), skipping"
            continue
        fi

        RESULT=$(curl -s -w "\n%{http_code}" "$API_URL" \
            -H 'Content-Type: application/json' \
            -H "Authorization: Bearer $KEY" \
            -d "$PARAMS" \
            --max-time "$TIMEOUT")
        HTTP_CODE=$(echo "$RESULT" | tail -1)
        BODY=$(echo "$RESULT" | sed '$d')

        # 000 = 网络断连, 429 = 限流 → 均标记冷却并换下一 Key
        if [ "$HTTP_CODE" = "000" ]; then
            echo "$(date '+%Y-%m-%d %H:%M:%S') [$PREFIX] curl failed (HTTP 000, network error) on a key, trying next"
            mark_key_cooldown "$KEY" 30
            continue
        fi
        if [ "$HTTP_CODE" = "429" ]; then
            echo "$(date '+%Y-%m-%d %H:%M:%S') [$PREFIX] key rate limited (HTTP 429), cooldown 30s"
            mark_key_cooldown "$KEY" 30
            continue
        fi
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
        -d "{\"_token\":\"$TOKEN\",\"_path\":\"$WRITE_ENDPOINT\",\"task_id\":\"$FIRST_ID\",\"result\":$(echo "$RESULT_BODY" | python3 -c 'import sys,json; print(json.dumps(json.loads(sys.stdin.read())))')}" > /dev/null

    echo "$(date '+%Y-%m-%d %H:%M:%S') [$PREFIX] task=$FIRST_ID http=$HTTP_CODE"
    return 0
}

# ─── 视频任务处理（MiniMax Hailuo 异步 API：提交→轮询→获取下载URL）───
process_video_task() {
    local PENDING_ENDPOINT="/video_pending"
    local READ_ENDPOINT="/video_read_params"
    local WRITE_ENDPOINT="/video_write_result"

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

    PROMPT=$(echo "$PARAMS_JSON" | python3 -c "
import sys,json
d=json.load(sys.stdin)
if 'error' in d: sys.exit(1)
print(json.dumps(d.get('params',{})))
" 2>/dev/null) || return 1

    # Step 1-3: 逐 Key 尝试（提交→轮询→获取URL），一把 Key 失败后换下一把
    SUBMIT_URL="${BASE_URL}/video_generation"
    SUBMIT_TASK_ID=""
    FILE_ID=""
    VIDEO_URL=""
    USED_KEY=""

    # 刷新 provider 配置（确保捕获最新轮换状态）
    refresh_provider_keys || return 1

    for TRY_KEY in "${KEY_ARRAY[@]}"; do
        [ -z "$TRY_KEY" ] && continue

        # 检查本地 429 冷却期
        if key_in_cooldown "$TRY_KEY"; then
            echo "$(date '+%Y-%m-%d %H:%M:%S') [video] key in cooldown (429 backoff), skipping"
            continue
        fi

        # Step 1: 提交视频生成任务到 MiniMax → 获取 taskId，检测 429/000 立即跳下一 Key
        CLEAN_PROMPT=$(echo "$PROMPT" | python3 -c "
import sys, json
d = json.load(sys.stdin)
# 只保留 MiniMax video API 支持的字段
clean = {}
for key in ['model', 'prompt', 'first_frame_image', 'last_frame_image', 'subject_reference']:
    if key in d:
        clean[key] = d[key]
print(json.dumps(clean))
" 2>/dev/null)

        [ -z "$CLEAN_PROMPT" ] && CLEAN_PROMPT="$PROMPT"

        SUBMIT_RESULT=$(curl -s -w "\n%{http_code}" "$SUBMIT_URL" \
            -H 'Content-Type: application/json' \
            -H "Authorization: Bearer $TRY_KEY" \
            -d "$(echo "$CLEAN_PROMPT" | python3 -c 'import sys,json; print(json.dumps(json.loads(sys.stdin.read())))')" \
            --max-time 60 2>/dev/null)
        SUBMIT_HTTP=$(echo "$SUBMIT_RESULT" | tail -1)
        SUBMIT_BODY=$(echo "$SUBMIT_RESULT" | sed '$d')
        # 429(限流)或000(网络断连) → 标记冷却并换下一 Key
        if [ "$SUBMIT_HTTP" = "000" ]; then
            echo "$(date '+%Y-%m-%d %H:%M:%S') [video] submit curl failed (HTTP 000, network error), cooldown 30s"
            mark_key_cooldown "$TRY_KEY" 30
            continue
        fi
        if [ "$SUBMIT_HTTP" = "429" ]; then
            echo "$(date '+%Y-%m-%d %H:%M:%S') [video] submit rate limited (HTTP 429), cooldown 30s"
            mark_key_cooldown "$TRY_KEY" 30
            continue
        fi
        [ -z "$SUBMIT_BODY" ] && continue

        SUBMIT_TASK_ID=$(echo "$SUBMIT_BODY" | python3 -c "import sys,json; print(json.load(sys.stdin).get('task_id','') or json.load(sys.stdin).get('taskId',''))" 2>/dev/null)
        [ -z "$SUBMIT_TASK_ID" ] && continue

        # Step 2: 轮询 MiniMax 直到视频生成完成（最多等 120s）
        QUERY_URL="${BASE_URL}/query/video_generation"
        POLL_START=$(date +%s)
        POLL_MAX=120
        VIDEO_STATUS=""
        POLL_RESULT_BODY=""

        while true; do
            NOW=$(date +%s)
            ELAPSED=$((NOW - POLL_START))
            [ $ELAPSED -gt $POLL_MAX ] && break

            sleep 5

            POLL_RESULT_BODY=$(curl -s "${QUERY_URL}?task_id=${SUBMIT_TASK_ID}" \
                -H 'Content-Type: application/json' \
                -H "Authorization: Bearer $TRY_KEY" \
                --max-time 15 2>/dev/null)

            [ -z "$POLL_RESULT_BODY" ] && continue

            VIDEO_STATUS=$(echo "$POLL_RESULT_BODY" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null)
            FILE_ID=$(echo "$POLL_RESULT_BODY" | python3 -c "import sys,json; print(json.load(sys.stdin).get('file_id',''))" 2>/dev/null)

            [ "$VIDEO_STATUS" = "Success" ] && break
            [ "$VIDEO_STATUS" = "Failed" ] && break
        done

        if [ "$VIDEO_STATUS" = "Success" ] && [ -n "$FILE_ID" ]; then
            # 成功：前端可通过 file_id 自行获取下载 URL（files/retrieve）
            # 写入 poll 结果（含 file_id），前端 handler 会处理 URL 获取
            USED_KEY="$TRY_KEY"
            curl -s "$PROXY_URL" \
                -H 'Content-Type: application/json' \
                -d "{\"_token\":\"$TOKEN\",\"_path\":\"$WRITE_ENDPOINT\",\"task_id\":\"$FIRST_ID\",\"result\":$(echo "$POLL_RESULT_BODY" | python3 -c 'import sys,json; print(json.dumps(json.loads(sys.stdin.read())))')}" > /dev/null
            echo "$(date '+%Y-%m-%d %H:%M:%S') [video] task=$FIRST_ID status=Success file_id=$FILE_ID"
            return 0
        fi

        # 这把 Key 失败（配额不足或生成失败），换下一把
        echo "$(date '+%Y-%m-%d %H:%M:%S') [video] task=$FIRST_ID key_tried status=$VIDEO_STATUS file_id=$FILE_ID"
        SUBMIT_TASK_ID=""
        FILE_ID=""
    done

    # 所有 Key 都失败 → 写错误结果（含配额重置时间）
    # 所有3个 Key 的 MiniMax Token Plan 每日配额均已用尽
    # 重置时间: 2026-05-19T00:00:00+08:00 (每日 16:00 UTC)
    curl -s "$PROXY_URL" \
        -H 'Content-Type: application/json' \
        -d "{\"_token\":\"$TOKEN\",\"_path\":\"$WRITE_ENDPOINT\",\"task_id\":\"$FIRST_ID\",\"result\":{\"error\":\"all_keys_exhausted\",\"message\":\"video quota exhausted, retry at midnight\",\"status\":\"$VIDEO_STATUS\"}}" > /dev/null
    echo "$(date '+%Y-%m-%d %H:%M:%S') [video] task=$FIRST_ID all_keys_exhausted status=$VIDEO_STATUS"
    return 1
}

# 主流程：优先处理音乐，再处理视频（一次只处理一个任务）
process_task "music"  "/music_pending"     "/music_read_params"     "/music_write_result"     "/music_generation" && exit 0
process_video_task && exit 0

# === 记忆任务处理（本地调 LLM，绕过 PHP 30s 超时） ===
process_memory_tasks() {
    PENDING_JSON=$(curl -s "$PROXY_URL" \
        -H 'Content-Type: application/json' \
        -d "{\"_token\":\"$TOKEN\",\"_path\":\"/memory_pending\"}" 2>/dev/null)
    
    PENDING_COUNT=$(echo "$PENDING_JSON" | python3 -c "import sys,json; print(json.load(sys.stdin).get('count',0))" 2>/dev/null)
    [ "$PENDING_COUNT" -eq 0 ] 2>/dev/null && return 1
    
    # 获取 OpenCode LLM provider 配置
    LLM_KEYS=""
    LLM_BASE_URL=""
    LLM_PROVIDER_JSON=$(curl -s "$PROXY_URL" \
        -H 'Content-Type: application/json' \
        -d "{\"_token\":\"$TOKEN\",\"_path\":\"/memory_get_provider\"}" 2>/dev/null)
    
    LLM_BASE_URL=$(echo "$LLM_PROVIDER_JSON" | python3 -c "import sys,json; print(json.load(sys.stdin).get('base_url',''))" 2>/dev/null)
    LLM_KEYS=$(echo "$LLM_PROVIDER_JSON" | python3 -c "import sys,json; d=json.load(sys.stdin); print(' '.join([k for k in d.get('keys',[]) if k]))" 2>/dev/null)
    
    if [ -z "$LLM_BASE_URL" ] || [ -z "$LLM_KEYS" ]; then
        echo "$(date '+%Y-%m-%d %H:%M:%S') [memory] no_llm_provider"
        return 1
    fi
    
    IFS=' ' read -ra LLM_KEY_ARRAY <<< "$LLM_KEYS"
    
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
        
        # 1. 读取任务参数
        PARAMS_JSON=$(curl -s "$PROXY_URL" \
            -H 'Content-Type: application/json' \
            -d "{\"_token\":\"$TOKEN\",\"_path\":\"/memory_read_params\",\"task_id\":$TID}" 2>/dev/null)
        
        USER_ID=$(echo "$PARAMS_JSON" | python3 -c "import sys,json; print(json.load(sys.stdin).get('user_id',''))" 2>/dev/null)
        PAYLOAD=$(echo "$PARAMS_JSON" | python3 -c "
import sys,json
d=json.load(sys.stdin)
if 'error' in d: sys.exit(1)
print(json.dumps(d.get('payload',{})))
" 2>/dev/null) || { echo "$(date '+%Y-%m-%d %H:%M:%S') [memory] task=$TID read_params_failed"; sleep 3; continue; }
        
        # 2. 提取 messages（只取前5条）
        MESSAGES_JSON=$(echo "$PAYLOAD" | python3 -c "
import sys,json
d=json.load(sys.stdin)
msgs = d.get('messages',[])
sys.stdout.buffer.write(json.dumps(msgs[:5], ensure_ascii=False).encode('utf-8'))
" 2>/dev/null)
        
        [ -z "$MESSAGES_JSON" ] && { echo "$(date '+%Y-%m-%d %H:%M:%S') [memory] task=$TID no_messages"; sleep 3; continue; }
        
        # 3. 构造提取 prompt（和 PHP 端一致，通过 stdin 传 JSON 避免 bash 吃掉引号）
        PROMPT=$(echo "$MESSAGES_JSON" | python3 -c "
import sys, json
msgs = json.loads(sys.stdin.read().strip())
prompt = '从以下对话中提取原子级事实（即客观的、独立的知识点）。'
prompt += '请以 JSON 格式输出，key 为 facts，值为对象数组，每个对象包含：'
prompt += 'fact(字符串), category(字符串, 可选: credential/decision/constraint/preference/event/knowledge/contact), importance(1-10整数)。'
prompt += '\\n\\n对话内容:\\n' + json.dumps(msgs, ensure_ascii=False)
sys.stdout.buffer.write(prompt.encode('utf-8'))
" 2>/dev/null)
        
        [ -z "$PROMPT" ] && { echo "$(date '+%Y-%m-%d %H:%M:%S') [memory] task=$TID prompt_failed"; sleep 3; continue; }
        
        # 4. 本地调用 OpenCode API（无 PHP-FPM 30s 超时限制！）
        LLM_RESULT=""
        LLM_HTTP=0
        for KEY in "${LLM_KEY_ARRAY[@]}"; do
            [ -z "$KEY" ] && continue

            # 检查本地 429 冷却期
            if key_in_cooldown "$KEY"; then
                echo "$(date '+%Y-%m-%d %H:%M:%S') [memory] opencode key in cooldown (429 backoff), skipping"
                continue
            fi

            LLM_RESP=$(echo "$PROMPT" | python3 -c "import sys, json; p = sys.stdin.read(); print(json.dumps({'model': 'deepseek-v4-flash', 'messages': [{'role': 'user', 'content': p}], 'temperature': 0.3, 'max_tokens': 100000}))" | curl -s -w "\n%{http_code}" "$LLM_BASE_URL/chat/completions" -H 'Content-Type: application/json' -H "Authorization: Bearer $KEY" -d @- --max-time $TIMEOUT)
            LLM_HTTP=$(echo "$LLM_RESP" | tail -1)
            LLM_BODY=$(echo "$LLM_RESP" | sed '$d')
            if [ "$LLM_HTTP" = "000" ]; then
                echo "$(date '+%Y-%m-%d %H:%M:%S') [memory] opencode curl failed (HTTP 000), cooldown 30s"
                mark_key_cooldown "$KEY" 30
                continue
            fi
            if [ "$LLM_HTTP" = "429" ]; then
                echo "$(date '+%Y-%m-%d %H:%M:%S') [memory] opencode rate limited (HTTP 429), cooldown 30s"
                mark_key_cooldown "$KEY" 30
                continue
            fi
            [ "$LLM_HTTP" -ge 400 ] && continue
            LLM_RESULT="$LLM_BODY"
            break
        done
        
        if [ -z "$LLM_RESULT" ]; then
            # 全部失败 — 打回 pending 让下次重试
            curl -s "$PROXY_URL" \
                -H 'Content-Type: application/json' \
                -d "{\"_token\":\"$TOKEN\",\"_path\":\"/memory_write_result\",\"task_id\":$TID,\"user_id\":\"$USER_ID\",\"facts\":[],\"scene_id\":\"scene_default\"}" > /dev/null
            echo "$(date '+%Y-%m-%d %H:%M:%S') [memory] task=$TID llm_all_failed"
            sleep 3
            continue
        fi
        
        # 5. 解析 LLM 回复，提取 facts JSON
        FACTS_JSON=$(echo "$LLM_RESULT" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    raw = d.get('choices',[{}])[0].get('message',{}).get('content','')
    # 尝试解析 content 中的 JSON
    content = raw.strip()
    # 去除可能的 markdown 代码块
    if content.startswith('\`\`\`json'):
        content = content[7:]
    if content.startswith('\`\`\`'):
        content = content[3:]
    if content.endswith('\`\`\`'):
        content = content[:-3]
    content = content.strip()
    facts_data = json.loads(content)
    if isinstance(facts_data, dict) and 'facts' in facts_data:
        print(json.dumps(facts_data['facts']))
    elif isinstance(facts_data, list):
        print(json.dumps(facts_data))
    else:
        print('[]')
except Exception as e:
    print('[]')
" 2>/dev/null)
        
        [ -z "$FACTS_JSON" ] && FACTS_JSON='[]'
        
        # 6. 回写结果到香港站
        SCENE_ID=$(echo "$PAYLOAD" | python3 -c "import sys,json; print(json.load(sys.stdin).get('scene_id','scene_default'))" 2>/dev/null)
        [ -z "$SCENE_ID" ] && SCENE_ID='scene_default'
        
        WRITE_RESULT=$(curl -s "$PROXY_URL" \
            -H 'Content-Type: application/json' \
            -d "{\"_token\":\"$TOKEN\",\"_path\":\"/memory_write_result\",\"task_id\":$TID,\"user_id\":\"$USER_ID\",\"facts\":$FACTS_JSON,\"scene_id\":\"$SCENE_ID\"}" 2>/dev/null)
        
        STATUS=$(echo "$WRITE_RESULT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('status','unknown'))" 2>/dev/null)
        COUNT=$(echo "$WRITE_RESULT" | python3 -c "import sys,json; print(json.dumps(json.load(sys.stdin).get('facts_stored',0)))" 2>/dev/null)
        echo "$(date '+%Y-%m-%d %H:%M:%S') [memory] task=$TID status=$STATUS facts=$COUNT"
        
        sleep 3
    done
    return 0
}

process_memory_tasks
exit 0
