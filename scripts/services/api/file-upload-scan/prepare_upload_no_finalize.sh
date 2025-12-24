#!/bin/bash

API="http://localhost:8000/api/upload"
FILE="$1"
CHUNK_SIZE=1048576

FILENAME=$(basename "$FILE")
TOTAL_BYTES=$(stat -c%s "$FILE" 2>/dev/null || stat -f%z "$FILE")

INIT_RESPONSE=$(curl -s -X POST "$API/init" \
  -H "Content-Type: application/json" \
  -d "{
    \"filename\": \"$FILENAME\",
    \"total_bytes\": $TOTAL_BYTES,
    \"chunk_bytes\": $CHUNK_SIZE
  }")

UPLOAD_ID=$(echo "$INIT_RESPONSE" | sed -n 's/.*"upload_id"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')

if [ -z "$UPLOAD_ID" ]; then
  echo "Init failed:"
  echo "$INIT_RESPONSE"
  exit 1
fi

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

echo "$UPLOAD_ID|$FILENAME|$TOTAL_BYTES"
