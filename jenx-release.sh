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

[ $# -eq 1 ] || die "Usage: $0 <git-ref>"

REF="$1"

cd "$FOUND_DIR" || die "Cannot cd to $FOUND_DIR"

echo "=== Jen-X Release ==="
echo "Application : $FOUND_DIR"
echo "Requested   : $REF"
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
# 3. Resolve requested ref to an exact commit
# ------------------------------------------------------------

COMMIT="$(git rev-parse "$REF^{commit}" 2>/dev/null)" \
    || die "Cannot resolve git ref: $REF"

echo "Resolved    : $COMMIT"

# ------------------------------------------------------------
# 4. Checkout exact commit
# ------------------------------------------------------------

echo "Checking out $COMMIT..."

git checkout --detach "$COMMIT" \
    || die "git checkout failed"

# ------------------------------------------------------------
# 5. Install exactly what package-lock specifies
# ------------------------------------------------------------

echo "Installing dependencies..."

npm ci || die "npm ci failed"

# ------------------------------------------------------------
# 6. Run release gate
# ------------------------------------------------------------

echo
echo "Running release checks..."

npm run release:check \
    || die "release:check failed. Running service was NOT restarted."

# ------------------------------------------------------------
# 7. Restart application
# ------------------------------------------------------------

echo
echo "Restarting $SERVICE..."

sudo -n systemctl restart "$SERVICE" \
    || die "Failed to restart $SERVICE"

# ------------------------------------------------------------
# 8. Wait for service
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
# 9. HTTP health check
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
# 10. REPORT DEPLOYED COMMIT 
# ------------------------------------------------------------

echo
echo "Recording release state..."

RELEASED_AT="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

mkdir -p "$STATE_DIR" || die "Cannot create state directory."

TMP_FILE="${CURRENT_FILE}.tmp"

cat > "$TMP_FILE" <<EOF
{
  "commit": "$COMMIT",
  "releasedAt": "$RELEASED_AT",
  "status": "success",
  "health": $HTTP_STATUS
}
EOF

mv "$TMP_FILE" "$CURRENT_FILE" \
    || die "Cannot update current release state."

printf '%s\n' \
    "{\"commit\":\"$COMMIT\",\"releasedAt\":\"$RELEASED_AT\",\"status\":\"success\",\"health\":$HTTP_STATUS}" \
    >> "$HISTORY_FILE" \
    || die "Cannot update release history."

echo "Release state recorded."


echo
echo "========================================"
echo " RELEASE SUCCESSFUL"
echo "========================================"
echo "Commit : $COMMIT"
echo "Service: $SERVICE"
echo "Health : HTTP $HTTP_STATUS"
echo

