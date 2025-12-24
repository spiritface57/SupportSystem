#!/bin/bash

set -u
set -o pipefail

API_BASE="http://localhost:8000/api/upload"
CHUNK_SIZE=1048576
FOLDER="${1:-./files}"

if [ ! -d "$FOLDER" ]; then
  echo "❌ Folder not found: $FOLDER"
  exit 1
fi

echo "📂 Uploading files from: $FOLDER"
echo "------------------------------------"

for FILE in "$FOLDER"/*; do
  [ -f "$FILE" ] || continue

  FILENAME=$(basename "$FILE")
  TOTAL_BYTES=$(stat -c%s "$FILE" 2>/dev/null || stat -f%z "$FILE")

  echo ""
  echo "➡️  File: $FILENAME ($TOTAL_BYTES bytes)"

  # 1️⃣ INIT
  INIT_RESPONSE=$(curl -s -X POST "$API_BASE/init" \
    -H "Content-Type: application/json" \
    -d @- <<EOF
{
  "filename": "$FILENAME",
  "total_bytes": $TOTAL_BYTES,
  "chunk_bytes": $CHUNK_SIZE
}
EOF
)

  UPLOAD_ID=$(echo "$INIT_RESPONSE" | sed -n 's/.*"upload_id"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')

  if [ -z "$UPLOAD_ID" ]; then
    echo "❌ Init failed. Response:"
    echo "$INIT_RESPONSE"
    echo "↪ skipping file"
    continue
  fi

  echo "   upload_id = $UPLOAD_ID"

  # 2️⃣ SPLIT FILE INTO CHUNKS
  TMP_DIR=$(mktemp -d)
  split -b $CHUNK_SIZE -d "$FILE" "$TMP_DIR/chunk_"

  INDEX=0
  for CHUNK in "$TMP_DIR"/chunk_*; do
    curl -s -X POST "$API_BASE/chunk" \
      -F "upload_id=$UPLOAD_ID" \
      -F "index=$INDEX" \
      -F "chunk=@$CHUNK" >/dev/null || {
        echo "❌ chunk upload failed (index=$INDEX)"
        break
      }
    ((INDEX++))
  done

  rm -rf "$TMP_DIR"
  echo "   chunks uploaded: $INDEX"

  # 3️⃣ FINALIZE
  FINAL_RESPONSE=$(curl -s -X POST "$API_BASE/finalize" \
    -H "Content-Type: application/json" \
    -d @- <<EOF
{
  "upload_id": "$UPLOAD_ID",
  "filename": "$FILENAME",
  "total_bytes": $TOTAL_BYTES
}
EOF
)

  echo "   finalize response:"
  echo "   $FINAL_RESPONSE"
done

echo ""
echo "✅ Folder upload finished."
