#!/bin/sh
set -eu
source_file="$1"
target_file="${source_file%.flv}.mp4"
temporary_file="${target_file}.processing"
trap 'rm -f -- "$temporary_file"' EXIT
ffmpeg -nostdin -y -i "$source_file" -c copy -movflags +faststart -f mp4 "$temporary_file"
mv -- "$temporary_file" "$target_file"
