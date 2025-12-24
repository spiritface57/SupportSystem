#!/bin/bash

set -e

FILE1="$1"
FILE2="$2"

if [ ! -f "$FILE1" ] || [ ! -f "$FILE2" ]; then
  echo "Usage: $0 <file1> <file2>"
  exit 1
fi

./upload_one_file.sh "$FILE1" &
PID1=$!

./upload_one_file.sh "$FILE2" &
PID2=$!

wait $PID1
wait $PID2

echo "Both uploads finished."
