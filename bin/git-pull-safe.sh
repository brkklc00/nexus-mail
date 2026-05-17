#!/usr/bin/env bash
# Root veya farklı kullanıcı ile çalışırken "dubious ownership" hatasını
# GLOBAL git ayarı değiştirmeden çözer. Hiçbir dosya silmez; yalnızca git pull.
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
exec git -c "safe.directory=${ROOT}" pull "$@"
