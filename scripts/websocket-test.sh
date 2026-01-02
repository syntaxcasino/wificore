#!/bin/bash

APP_ID="app-id"
APP_KEY="app-key"
APP_SECRET="app-secret"
HOST="localhost"
PORT=6001
CHANNEL="public-traidnet"
EVENT="LogRotationCompleted"

TIMESTAMP=$(date +%s)

# Compact payload (no newlines, no trailing spaces)
PAYLOAD='{"name":"'"$EVENT"'","channels":["'"$CHANNEL"'"],"data":"{\"router_id\":999,\"test\":true}"}'

# 1. Calculate MD5 of payload (hex, lowercase)
BODY_MD5=$(echo -n "$PAYLOAD" | openssl md5 | awk '{print $2}')

# 2. Canonical query string (sorted keys)
QUERY="auth_key=$APP_KEY&auth_timestamp=$TIMESTAMP&auth_version=1.0&body_md5=$BODY_MD5"

# 3. Build string to sign WITH ACTUAL NEWLINES
STRING_TO_SIGN=$(printf "POST\n/apps/$APP_ID/events\n$QUERY")

# 4. Sign with HMAC-SHA256 using APP_SECRET
SIGNATURE=$(printf "$STRING_TO_SIGN" | openssl dgst -sha256 -hmac "$APP_SECRET" | awk '{print $2}')

# 5. Final URL
URL="http://$HOST:$PORT/apps/$APP_ID/events?$QUERY&auth_signature=$SIGNATURE"

# 🔍 Debug info
echo "📡 Sending event '$EVENT' to channel '$CHANNEL'..."
echo "📝 Payload: $PAYLOAD"
echo "🔢 Payload length: ${#PAYLOAD}"
echo "🔑 Body MD5: $BODY_MD5"
echo "🔑 StringToSign: $(printf "$STRING_TO_SIGN" | sed 's/$/\\n/' | tr -d '\n')"  # Show with \n for readability
echo "🔑 Actual StringToSign bytes: $(printf "$STRING_TO_SIGN" | xxd)"
echo "🔑 Signature: $SIGNATURE"
echo "🌍 URL: $URL"

# 6. Send request
RESPONSE=$(curl -s -X POST "$URL" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD")

echo "💬 Response: $RESPONSE"