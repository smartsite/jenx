#!/bin/bash
set -u

STATE_DIR="/var/www/xsmart.site/jenx/state"
CURRENT_FILE="$STATE_DIR/current.json"
HISTORY_FILE="$STATE_DIR/releases.log"

FOUND_DIR="/var/www/xsmart.site/foundd"
SERVICE="foundd"
HEALTH_URL="http://127.0.0.1:5000/api/health"


die() {
    echo
    echo "ERROR: $*"
    exit 1
}


[ $# -eq 1 ] || die "Usage: $0 <git-ref> | rollback"

COMMAND="$1"

if [ "$COMMAND" = "rollback" ]; then
    :
else
    REF="$COMMAND"
fi


cd "$FOUND_DIR" || die "Cannot cd to $FOUND_DIR"

echo "=== Jen-X Release ==="
echo "Application : $FOUND_DIR"
echo "Requested   : $COMMAND"
echo

if [ "$COMMAND" = "rollback" ]; then
    echo "Operation   : ROLLBACK"
else
    echo "Operation   : RELEASE"
fi

echo


# ------------------------------------------------------------
# 1. Working tree must be clean
# ------------------------------------------------------------

echo "Checking working tree..."

if [ -n "$(git status --porcelain)" ]; then
    die "Working tree is not clean. Refusing to deploy."
fi


# ------------------------------------------------------------
# 2. Fetch GitHub
# ------------------------------------------------------------

echo "Fetching origin..."

git fetch origin || die "git fetch failed"


# ------------------------------------------------------------
# 3. Resolve rollback target if required
# ------------------------------------------------------------

if [ "$COMMAND" = "rollback" ]; then

    [ -f "$HISTORY_FILE" ] \
        || die "No release history exists."

    [ -f "$CURRENT_FILE" ] \
        || die "No current release state exists."

    CURRENT_COMMIT="$(grep -o '"commit":"[^"]*"' "$CURRENT_FILE" \
        | head -n 1 \
        | cut -d'"' -f4)"

    [ -n "$CURRENT_COMMIT" ] \
        || die "Cannot determine current release commit."

    REF="$(
        tac "$HISTORY_FILE" |
        while IFS= read -r line; do

            status="$(printf '%s\n' "$line" |
                grep -o '"status":"[^"]*"' |
                cut -d'"' -f4)"

            commit="$(printf '%s\n' "$line" |
                grep -o '"commit":"[^"]*"' |
                cut -d'"' -f4)"

            if [ "$status" = "success" ] &&
               [ -n "$commit" ] &&
               [ "$commit" != "$CURRENT_COMMIT" ]; then

                printf '%s\n' "$commit"
                break
            fi

        done
    )"

    [ -n "$REF" ] \
        || die "No previous successful release found."

    echo "Rollback target: $REF"
fi


# ------------------------------------------------------------
# 4. Resolve requested ref to an exact commit
# ------------------------------------------------------------

echo "Resolved ref : $REF"

COMMIT="$(git rev-parse "$REF^{commit}" 2>/dev/null)" \
    || die "Cannot resolve git ref: $REF"

echo "Resolved     : $COMMIT"


# ------------------------------------------------------------
# 5. Checkout exact commit
# ------------------------------------------------------------

echo "Checking out $COMMIT..."

git checkout --detach "$COMMIT" \
    || die "git checkout failed"


# ------------------------------------------------------------
# 6. Install exactly what package-lock specifies
# ------------------------------------------------------------

echo "Installing dependencies..."

npm ci || die "npm ci failed"


# ------------------------------------------------------------
# 7. Run release gate
# ------------------------------------------------------------

echo
echo "Running release checks..."

npm run release:check \
    || die "release:check failed. Running service was NOT restarted."


# ------------------------------------------------------------
# 8. Restart application
# ------------------------------------------------------------

echo
echo "Restarting $SERVICE..."

sudo -n systemctl restart "$SERVICE.service" \
    || die "Failed to restart $SERVICE.service"


# ------------------------------------------------------------
# 9. Wait for service
# ------------------------------------------------------------

echo "Waiting for service..."

for i in {1..30}; do

    if systemctl is-active --quiet "$SERVICE"; then
        echo "Service is active."
        break
    fi

    if [ "$i" -eq 30 ]; then
        die "Service failed to become active."
    fi

    sleep 1

done


# ------------------------------------------------------------
# 10. HTTP health check
# ------------------------------------------------------------

echo "Checking application health..."

HTTP_STATUS="000"

for i in {1..30}; do

    HTTP_STATUS="$(curl -sS -o /dev/null -w '%{http_code}' \
        --max-time 2 \
        "$HEALTH_URL" 2>/dev/null || true)"

    if [ "$HTTP_STATUS" = "200" ]; then
        echo "Health check passed."
        break
    fi

    if [ "$i" -eq 30 ]; then
        echo "Health check returned HTTP $HTTP_STATUS"
        die "Application health check failed."
    fi

    sleep 1

done


# ------------------------------------------------------------
# 11. Record release state
# ------------------------------------------------------------

echo
echo "Recording release state..."

RELEASED_AT="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

mkdir -p "$STATE_DIR" || die "Cannot create state directory."

chgrp www-data "$STATE_DIR" \
    || die "Cannot set state directory group."

chmod 750 "$STATE_DIR" \
    || die "Cannot set state directory permissions."

TMP_FILE="${CURRENT_FILE}.tmp"

if [ "$COMMAND" = "rollback" ]; then
    OPERATION="rollback"
else
    OPERATION="release"
fi


cat > "$TMP_FILE" <<EOF
{
  "commit": "$COMMIT",
  "releasedAt": "$RELEASED_AT",
  "status": "success",
  "operation": "$OPERATION",
  "health": $HTTP_STATUS
}
EOF

mv "$TMP_FILE" "$CURRENT_FILE" \
    || die "Cannot update current release state."

chmod 644 "$CURRENT_FILE" \
    || die "Cannot set current state file permissions."

printf '%s\n' \
    "{\"commit\":\"$COMMIT\",\"releasedAt\":\"$RELEASED_AT\",\"status\":\"success\",\"operation\":\"$OPERATION\",\"health\":$HTTP_STATUS}" \
    >> "$HISTORY_FILE" \
    || die "Cannot update release history."

chmod 644 "$HISTORY_FILE" \
    || die "Cannot set release history permissions."


echo "Release state recorded."


# ------------------------------------------------------------
# 12. Report success
# ------------------------------------------------------------

echo
echo "========================================"
echo " RELEASE SUCCESSFUL"
echo "========================================"
echo "Commit : $COMMIT"
echo "Service: $SERVICE"
echo "Health : HTTP $HTTP_STATUS"
echo
