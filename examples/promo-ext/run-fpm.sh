#!/usr/bin/env bash
#
# 一键：构建 FPM 产物 → 启动 php-fpm → 启动用户态 nginx → 跑并发正确性校验 → 收尾
#
# 可用环境变量覆盖：
#   RUN_DIR FPM_PORT NGINX_PORT CONCURRENCY REQUESTS CASES SEED PHP_BIN
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPILER_DIR="$(cd "$HERE/../.." && pwd)"

RUN_DIR="${RUN_DIR:-/tmp/typephp-fpm-bench}"
FPM_PORT="${FPM_PORT:-19000}"
NGINX_PORT="${NGINX_PORT:-18080}"
CONCURRENCY="${CONCURRENCY:-1,8,32,64}"
REQUESTS="${REQUESTS:-200}"
CASES="${CASES:-32}"
SEED="${SEED:-20260930}"
PHP_BIN="${PHP_BIN:-php}"

PROJECT_YML="$HERE/project.fpm.yml"
FPM_BIN="$RUN_DIR/promo_fpm"
DOCROOT="$HERE/public"
BASE_URL="http://127.0.0.1:${NGINX_PORT}/index.php"

FPM_PID=""
NGINX_PID=""

log() { printf '\n=== %s ===\n' "$1"; }

port_in_use() {
    (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null && { exec 3<&-; return 0; } || return 1
}

wait_for_port() {
    local port="$1" name="$2"
    for _ in $(seq 1 100); do
        port_in_use "$port" && return 0
        sleep 0.1
    done
    echo "错误：${name} 未在 127.0.0.1:${port} 上监听" >&2
    return 1
}

cleanup() {
    set +e
    if [ -n "$NGINX_PID" ] && kill -0 "$NGINX_PID" 2>/dev/null; then
        kill "$NGINX_PID" 2>/dev/null
    fi
    if [ -n "$FPM_PID" ] && kill -0 "$FPM_PID" 2>/dev/null; then
        kill "$FPM_PID" 2>/dev/null
    fi
    wait 2>/dev/null
}
trap cleanup EXIT

# ---------------------------------------------------------------- 前置检查
for port in "$FPM_PORT" "$NGINX_PORT"; do
    if port_in_use "$port"; then
        echo "错误：端口 $port 已被占用，请用 FPM_PORT/NGINX_PORT 指定其他端口" >&2
        exit 1
    fi
done

mkdir -p "$RUN_DIR" \
    "$RUN_DIR/nginx" \
    "$RUN_DIR/tmp/client_body" "$RUN_DIR/tmp/proxy" \
    "$RUN_DIR/tmp/fastcgi" "$RUN_DIR/tmp/uwsgi" "$RUN_DIR/tmp/scgi"

# ---------------------------------------------------------------- 1. 构建
log "构建 php-fpm 产物（project.fpm.yml）"
if [ -x "$COMPILER_DIR/tpc" ]; then
    "$COMPILER_DIR/tpc" "$PROJECT_YML" --no-progress -j4 -o "$FPM_BIN"
else
    "$PHP_BIN" "$COMPILER_DIR/bin/tpc.php" "$PROJECT_YML" --no-progress -j4 -o "$FPM_BIN"
fi

# ---------------------------------------------------------------- 2. 渲染配置
log "渲染 FPM / nginx 配置"
sed -e "s|__RUN_DIR__|$RUN_DIR|g" -e "s|__FPM_PORT__|$FPM_PORT|g" \
    "$HERE/conf/php-fpm.conf" > "$RUN_DIR/php-fpm.conf"
sed -e "s|__RUN_DIR__|$RUN_DIR|g" \
    -e "s|__FPM_PORT__|$FPM_PORT|g" \
    -e "s|__NGINX_PORT__|$NGINX_PORT|g" \
    -e "s|__DOCROOT__|$DOCROOT|g" \
    "$HERE/conf/nginx.conf" > "$RUN_DIR/nginx.conf"

"$FPM_BIN" -n -y "$RUN_DIR/php-fpm.conf" -t >/dev/null
nginx -t -p "$RUN_DIR/nginx" -c "$RUN_DIR/nginx.conf" >/dev/null 2>&1

# ---------------------------------------------------------------- 3. 启动
log "启动 php-fpm (127.0.0.1:${FPM_PORT})"
"$FPM_BIN" -n -y "$RUN_DIR/php-fpm.conf" -F -O >"$RUN_DIR/fpm-stdout.log" 2>&1 &
FPM_PID=$!
wait_for_port "$FPM_PORT" "php-fpm"

log "启动 nginx (127.0.0.1:${NGINX_PORT})"
nginx -p "$RUN_DIR/nginx" -c "$RUN_DIR/nginx.conf" >"$RUN_DIR/nginx-stdout.log" 2>&1 &
NGINX_PID=$!
wait_for_port "$NGINX_PORT" "nginx"

printf '冒烟：'
curl -s -o /dev/null -w 'HTTP %{http_code}\n' "$BASE_URL?id=smoke&cart=%5B%5D"

# ---------------------------------------------------------------- 4. 并发校验
log "并发正确性校验"
set +e
"$PHP_BIN" "$HERE/bench/concurrency.php" "$BASE_URL" "$CONCURRENCY" "$REQUESTS" "$CASES" "$SEED"
RESULT=$?
set -e

# ---------------------------------------------------------------- 5. 收尾
log "结果"
if [ "$RESULT" -eq 0 ]; then
    echo "并发正确性校验通过"
else
    echo "并发正确性校验失败（exit ${RESULT}）"
fi
echo "FPM 日志：$RUN_DIR/fpm-error.log / $RUN_DIR/fpm-stdout.log"
exit "$RESULT"
