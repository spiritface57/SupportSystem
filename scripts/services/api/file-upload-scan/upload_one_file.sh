#!/bin/bash

API="http://localhost:8000/api/upload"
FILE="$1"
CHUNK_SIZE=1048576

if [ ! -f "$FILE" ]; then
  echo "File not found: $FILE"
  exit 1
fi

FILENAME=$(basename "$FILE")
TOTAL_BYTES=$(stat -c%s "$FILE" 2>/dev/null || stat -f%z "$FILE")

echo "Uploading $FILENAME ($TOTAL_BYTES bytes)"
START=$(date +%s%3N)
# 1️⃣ INIT
INIT_RESPONSE=$(curl -s -X POST "$API/init" \
  -H "Content-Type: application/json" \
  -d "{
    \"filename\": \"$FILENAME\",
    \"total_bytes\": $TOTAL_BYTES,
    \"chunk_bytes\": $CHUNK_SIZE
  }")

# استخراج upload_id بدون jq
UPLOAD_ID=$(echo "$INIT_RESPONSE" | sed -n 's/.*"upload_id"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')

if [ -z "$UPLOAD_ID" ]; then
  echo "Init failed. Response:"
  echo "$INIT_RESPONSE"
  exit 1
fi

echo "upload_id = $UPLOAD_ID"

# 2️⃣ SPLIT FILE
TMP_DIR=$(mktemp -d)
split -b $CHUNK_SIZE -d "$FILE" "$TMP_DIR/chunk_"

INDEX=0
for CHUNK in "$TMP_DIR"/chunk_*; do
  curl -s -X POST "$API/chunk" \
    -F "upload_id=$UPLOAD_ID" \
    -F "index=$INDEX" \
    -F "chunk=@$CHUNK" >/dev/null

  ((INDEX++))
done

rm -rf "$TMP_DIR"

# 3️⃣ FINALIZE
FINAL_RESPONSE=$(curl -s -X POST "$API/finalize" \
  -H "Content-Type: application/json" \
  -d "{
    \"upload_id\": \"$UPLOAD_ID\",
    \"filename\": \"$FILENAME\",
    \"total_bytes\": $TOTAL_BYTES
  }")
END=$(date +%s%3N)

echo "Finalize response:"
echo "$FINAL_RESPONSE"

LATENCY=$((END - START))
echo "finalize_latency_ms=$LATENCY"