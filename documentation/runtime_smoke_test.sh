#!/usr/bin/env bash
# Runtime smoke checks for chat endpoints and permission-scoped tool access.
#
# Default mode: guest/runtime checks only.
# Admin mutation checks are opt-in and best-effort, intended to support
# manual validation workflows in environments where scripted login is stable.

set -euo pipefail

BASE_URL="${BASE_URL:-http://katalysis-epra-theme.test}"
API_BASE="$BASE_URL/ccm/system/katalysis_neuron_ai"
COOKIE_FILE="${COOKIE_FILE:-/tmp/katalysis_neuron_ai_smoke.cookies}"

ADMIN_USER="${ADMIN_USER:-}"
ADMIN_PASS="${ADMIN_PASS:-}"
REQUIRE_ADMIN="${REQUIRE_ADMIN:-0}"
RUN_GUEST_CHECKS="${RUN_GUEST_CHECKS:-1}"
RUN_ADMIN_MUTATION_CHECKS="${RUN_ADMIN_MUTATION_CHECKS:-0}"
KEEP_TMP="${KEEP_TMP:-0}"

TMP_DIR="$(mktemp -d /tmp/katalysis-neuron-smoke.XXXXXX)"
cleanup() {
    if [[ "$KEEP_TMP" == "1" ]]; then
        echo "INFO: Keeping temp directory: $TMP_DIR"
    else
        rm -rf "$TMP_DIR"
    fi
}
trap cleanup EXIT

pass_count=0
fail_count=0

pass() {
    echo "PASS: $1"
    pass_count=$((pass_count + 1))
}

fail() {
    echo "FAIL: $1"
    fail_count=$((fail_count + 1))
}

log() {
    echo "INFO: $1"
}

json_field() {
    local file="$1"
    local field="$2"
    php -r '
        $d = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($d)) {
            exit(2);
        }
        $v = $d[$argv[2]] ?? null;
        if (is_bool($v)) {
            echo $v ? "true" : "false";
        } elseif (is_scalar($v) || $v === null) {
            echo (string)$v;
        } else {
            echo json_encode($v);
        }
    ' "$file" "$field" 2>/dev/null || true
}

http_json() {
    local method="$1"
    local url="$2"
    local body_file="$3"
    local payload="${4:-}"

    if [[ -n "$payload" ]]; then
        curl -sS -c "$COOKIE_FILE" -b "$COOKIE_FILE" -X "$method" \
            -H "Content-Type: application/json" \
            -d "$payload" \
            -o "$body_file" \
            -w "%{http_code}" \
            "$url"
    else
        curl -sS -c "$COOKIE_FILE" -b "$COOKIE_FILE" -X "$method" \
            -o "$body_file" \
            -w "%{http_code}" \
            "$url"
    fi
}

post_chat_message() {
    local message="$1"
    local out_file="$2"
    local payload

    payload="$(php -r 'echo json_encode(["message" => $argv[1]], JSON_UNESCAPED_SLASHES);' "$message")"
    http_json "POST" "$API_BASE/chat/send_message" "$out_file" "$payload" >/dev/null
}

extract_chat_response_text() {
    local file="$1"
    php -r '
        $d = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($d)) {
            exit(1);
        }
        echo (string)($d["response"] ?? "");
    ' "$file" 2>/dev/null || true
}

extract_inner_json_field() {
    local file="$1"
    local field="$2"
    php -r '
        $outer = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($outer)) {
            exit(1);
        }
        $response = (string)($outer["response"] ?? "");
        if ($response === "") {
            exit(1);
        }
        if (preg_match("/\{[\s\S]*\}/", $response, $m)) {
            $inner = json_decode($m[0], true);
            if (is_array($inner)) {
                $v = $inner[$argv[2]] ?? null;
                if (is_bool($v)) {
                    echo $v ? "true" : "false";
                } elseif (is_scalar($v) || $v === null) {
                    echo (string)$v;
                } else {
                    echo json_encode($v);
                }
            }
        }
    ' "$file" "$field" 2>/dev/null || true
}

extract_login_error_message() {
    local file="$1"
    php -r '
        $html = file_get_contents($argv[1]);
        if (!$html) {
            exit(1);
        }
        if (preg_match("/<div[^>]*alert-danger[^>]*>(.*?)<\/div>/is", $html, $m)) {
            $msg = trim(preg_replace("/\s+/", " ", strip_tags($m[1])));
            echo $msg;
        }
    ' "$file" 2>/dev/null || true
}

login_admin_if_configured() {
    if [[ -z "$ADMIN_USER" || -z "$ADMIN_PASS" ]]; then
        if [[ "$REQUIRE_ADMIN" == "1" ]]; then
            fail "REQUIRE_ADMIN=1 but ADMIN_USER/ADMIN_PASS were not provided"
        else
            log "ADMIN_USER/ADMIN_PASS not provided; skipping authenticated mutation checks."
        fi
        return 1
    fi

    local login_page="$TMP_DIR/login_page.html"
    local post_result="$TMP_DIR/login_post.html"

    # Use a fresh cookie jar for login to avoid stale/multiple session cookies
    # from prior guest checks (chat/new regenerates session IDs).
    rm -f "$COOKIE_FILE"
    : > "$COOKIE_FILE"

    curl -sS -c "$COOKIE_FILE" -b "$COOKIE_FILE" "$BASE_URL/login" -o "$login_page"

    local ccm_token
    ccm_token="$(php -r '
        $h = file_get_contents($argv[1]);
        if (!$h) {
            exit(1);
        }
        $needle = "name=" . chr(34) . "ccm_token" . chr(34) . " value=" . chr(34);
        $p = strpos($h, $needle);
        if ($p !== false) {
            $s = substr($h, $p + strlen($needle));
            $q = strpos($s, chr(34));
            if ($q !== false) {
                echo substr($s, 0, $q);
            }
        }
    ' "$login_page" 2>/dev/null || true)"

    if [[ -z "$ccm_token" || ! "$ccm_token" =~ ^[0-9]+:[a-f0-9]+$ ]]; then
        fail "Unable to parse a valid ccm_token from login page"
        return 1
    fi

    local final_url
    final_url="$(curl -sS -L -A "Mozilla/5.0" -e "$BASE_URL/login" -c "$COOKIE_FILE" -b "$COOKIE_FILE" \
        -o "$post_result" \
        -w "%{url_effective}" \
        -X POST "$BASE_URL/login/authenticate/concrete" \
        --data-urlencode "uName=$ADMIN_USER" \
        --data-urlencode "uPassword=$ADMIN_PASS" \
        --data-urlencode "ccm_token=$ccm_token")"

    if [[ "$final_url" == *"/login"* ]]; then
        local login_error
        login_error="$(extract_login_error_message "$post_result")"
        if [[ -n "$login_error" ]]; then
            fail "Admin login failed: $login_error"
        else
            fail "Admin login failed (still on login route)"
        fi
        return 1
    fi

    local dashboard_status
    dashboard_status="$(curl -sS -c "$COOKIE_FILE" -b "$COOKIE_FILE" -o /dev/null -w "%{http_code}" "$BASE_URL/dashboard")"
    if [[ "$dashboard_status" == "200" ]]; then
        pass "Admin login succeeded"
        return 0
    fi

    fail "Admin login uncertain (dashboard returned HTTP $dashboard_status)"
    return 1
}

run_guest_checks() {
    log "Running guest checks against $BASE_URL"
    rm -f "$COOKIE_FILE"
    : > "$COOKIE_FILE"

    local list_body="$TMP_DIR/chat_list.json"
    local load_body="$TMP_DIR/chat_load.json"
    local new_body="$TMP_DIR/chat_new.json"
    local send_body="$TMP_DIR/chat_send.json"
    local status

    status="$(http_json "GET" "$API_BASE/chat/list" "$list_body")"
    if [[ "$status" == "200" && "$(json_field "$list_body" "success")" == "true" ]]; then
        pass "GET /chat/list returns success JSON"
    else
        fail "GET /chat/list unexpected response (HTTP $status)"
    fi

    status="$(http_json "GET" "$API_BASE/chat/load?id=99999999" "$load_body")"
    if [[ "$status" == "404" && "$(json_field "$load_body" "success")" == "false" ]]; then
        pass "GET /chat/load?id=unknown returns 404 JSON error"
    else
        fail "GET /chat/load?id=unknown unexpected response (HTTP $status)"
    fi

    status="$(http_json "POST" "$API_BASE/chat/new" "$new_body")"
    if [[ "$status" == "200" && "$(json_field "$new_body" "success")" == "true" ]]; then
        pass "POST /chat/new returns success JSON"
    else
        fail "POST /chat/new unexpected response (HTTP $status)"
    fi

    post_chat_message "Runtime smoke health check." "$send_body"
    if [[ "$(json_field "$send_body" "success")" == "true" ]]; then
        local response_text
        response_text="$(extract_chat_response_text "$send_body")"
        if [[ -n "$response_text" ]]; then
            pass "POST /chat/send_message returns assistant content"
        else
            fail "POST /chat/send_message returned empty response"
        fi
    else
        fail "POST /chat/send_message did not return success=true"
    fi

    local tool1="$TMP_DIR/tool_list_page_types.json"
    local tool2="$TMP_DIR/tool_list_pages.json"
    local msg1="Use list_page_types and return first five handles only."
    local msg2="Use list_pages with limit 3 and return page ids and paths only."

    post_chat_message "$msg1" "$tool1"
    if [[ "$(json_field "$tool1" "success")" == "true" ]]; then
        pass "Tool path smoke: list_page_types executed"
    else
        fail "Tool path smoke: list_page_types request failed"
    fi

    post_chat_message "$msg2" "$tool2"
    if [[ "$(json_field "$tool2" "success")" == "true" ]]; then
        pass "Tool path smoke: list_pages executed"
    else
        fail "Tool path smoke: list_pages request failed"
    fi
}

run_admin_mutation_checks() {
    log "Admin mutation checks are opt-in and environment-dependent."

    if ! login_admin_if_configured; then
        if [[ "$REQUIRE_ADMIN" == "1" ]]; then
            return 1
        fi
        log "Skipping admin mutation checks because authenticated login could not be confirmed."
        return 0
    fi

    log "Running authenticated mutation checks"

    local stamp
    stamp="$(date +%Y%m%d%H%M%S)"
    local title="Neuron Runtime Smoke $stamp"
    local slug="neuron-runtime-smoke-$stamp"

    local create_file="$TMP_DIR/tool_create_page.json"
    local update_file="$TMP_DIR/tool_update_page.json"
    local delete_file="$TMP_DIR/tool_delete_page.json"

    post_chat_message "Use create_page with parentPageID=1, pageTypeHandle=page, name=$title, slug=$slug. Return strict JSON only with keys ok,page_id,path,error." "$create_file"
    local create_response
    create_response="$(extract_chat_response_text "$create_file")"

    local page_id
    printf "%s" "$create_response" > "$TMP_DIR/create_response.txt"
    page_id="$(php -r '
        $txt = (string)file_get_contents($argv[1]);
        if (preg_match("/\"page_id\"\s*:\s*(\d+)/", $txt, $m)) {
            echo $m[1];
        }
    ' "$TMP_DIR/create_response.txt" 2>/dev/null || true)"

    if [[ -z "$page_id" ]]; then
        fail "create_page did not return a parseable page_id"
        log "create_page response: $create_response"
        return 0
    fi

    if [[ "$(extract_inner_json_field "$create_file" "ok")" != "true" ]]; then
        fail "create_page inner response did not report ok=true"
        log "create_page response: $create_response"
        return 0
    fi

    pass "create_page returned page_id=$page_id"

    post_chat_message "Use update_page with pageID=$page_id, name=$title Updated, description=Runtime smoke update. Return strict JSON only with keys ok,page_id,error." "$update_file"
    if [[ "$(json_field "$update_file" "success")" == "true" && "$(extract_inner_json_field "$update_file" "ok")" == "true" ]]; then
        pass "update_page request completed"
    else
        fail "update_page failed (outer success or inner ok was false)"
        log "update_page response: $(extract_chat_response_text "$update_file")"
    fi

    post_chat_message "Use delete_page with pageID=$page_id. Return strict JSON only with keys ok,page_id,deleted,error." "$delete_file"
    if [[ "$(json_field "$delete_file" "success")" == "true" && "$(extract_inner_json_field "$delete_file" "ok")" == "true" ]]; then
        pass "delete_page request completed"
    else
        fail "delete_page failed (outer success or inner ok was false)"
        log "delete_page response: $(extract_chat_response_text "$delete_file")"
    fi
}

print_summary() {
    echo
    echo "Summary: $pass_count passed, $fail_count failed"
    if [[ "$fail_count" -gt 0 ]]; then
        exit 1
    fi
}

if [[ "$RUN_GUEST_CHECKS" == "1" ]]; then
    run_guest_checks
else
    log "Skipping guest checks (RUN_GUEST_CHECKS=$RUN_GUEST_CHECKS)"
fi

if [[ "$RUN_ADMIN_MUTATION_CHECKS" == "1" ]]; then
    run_admin_mutation_checks
else
    log "Skipping admin mutation checks (RUN_ADMIN_MUTATION_CHECKS=$RUN_ADMIN_MUTATION_CHECKS)"
fi

print_summary
