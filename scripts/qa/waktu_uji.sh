#!/bin/bash
# Jalankan database + server PHP + uji dengan jam dikunci ke 27 Okt 2026 (tanggal yang dipakai uji berbasis tanggal tetap).
# Pemakaian:  scripts/qa/waktu_uji.sh mulai   |   scripts/qa/waktu_uji.sh node core.js   |   scripts/qa/waktu_uji.sh berhenti
# Perlu paket "faketime" (apt-get install faketime).
FAKE="${FAKE:-2026-10-27 09:00:00}"
cd "$(dirname "$0")/../.."
case "$1" in
  mulai)
    mysqladmin -uroot shutdown >/dev/null 2>&1; sleep 2
    (nohup faketime -f "$FAKE" mysqld_safe >/tmp/mysqld.log 2>&1 &); sleep 8
    ./scripts/qa/up_env.sh >/dev/null 2>&1
    for pid in $(ps -eo pid,args | awk '/php -S 127.0.0.1:8899/ && !/awk/{print $1}'); do kill "$pid"; done; sleep 1
    (PHP_CLI_SERVER_WORKERS=8 nohup faketime -f "$FAKE" php -S 127.0.0.1:8899 -t "$PWD" >/tmp/php.log 2>&1 &); sleep 1
    echo "siap (jam server: $FAKE)";;
  berhenti)
    for pid in $(ps -eo pid,args | awk '/php -S 127.0.0.1:8899/ && !/awk/{print $1}'); do kill "$pid"; done
    mysql -uroot -e "DROP DATABASE IF EXISTS material; DROP USER IF EXISTS 'mt'@'127.0.0.1'"; rm -f backend/api/config.php; echo "bersih";;
  *) cd scripts/qa; NODE_PATH=${NODE_PATH:-/opt/node22/lib/node_modules} faketime -f "$FAKE" "$@";;
esac
