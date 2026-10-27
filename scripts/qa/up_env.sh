#!/bin/bash
# Lingkungan uji LOKAL sementara (MariaDB/MySQL + php -S). Kata sandi database dibuat acak tiap dijalankan dan
# ditulis ke backend/api/config.php (sudah di .gitignore). Jangan dipakai di server sungguhan.
set -e
cd "$(dirname "$0")/../.."
# Kata sandi acak dibuat sekali; dipakai ulang bila config.php sudah ada (supaya server yang sedang berjalan tidak kehilangan akses)
PW=$(grep -o "'db_pass'=>'[^']*'" backend/api/config.php 2>/dev/null | head -1 | sed "s/.*=>'//; s/'$//")
[ -n "$PW" ] || PW=$(head -c 12 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')
mysql -uroot -e "DROP DATABASE IF EXISTS material; CREATE DATABASE material CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS 'mt'@'127.0.0.1' IDENTIFIED BY '$PW'; ALTER USER 'mt'@'127.0.0.1' IDENTIFIED BY '$PW'; GRANT ALL ON material.* TO 'mt'@'127.0.0.1';"
mysql -uroot material < backend/mysql/database_lengkap.sql
cat > backend/api/config.php <<EOC
<?php
return ['db_host'=>'127.0.0.1','db_name'=>'material','db_user'=>'mt','db_pass'=>'$PW','cors_origins'=>[],'backup_dir'=>'/tmp/bk','backup_retention_days'=>14];
EOC
for u in "adminp AdminTest123 Admin admin" "lap1 LapanganTest123 Lap lapangan" "lap2 LapanganTest123 Lap2 lapangan" "lap3 LapanganTest123 Lap3 lapangan" "akun1 AkuntanTest123 Akuntan akuntan" "owner1 OwnerTest123 Owner owner"; do set -- $u; php backend/api/bin/create_user.php $1 $2 "$3" $4 >/dev/null 2>&1; done
mysql -uroot material -e "insert into projects(id,nama) values('proj_a','Proyek A'),('proj_b','Proyek B'); insert into user_projects(user_id,project_id) select id,'proj_a' from users where username in ('lap1','lap3'); insert into user_projects(user_id,project_id) select id,'proj_b' from users where username in ('lap2','lap3'); update users set failed_attempts=0,locked_until=null"
ps -eo args | grep -q "[p]hp -S 127.0.0.1:8899" || (nohup php -S 127.0.0.1:8899 -t "$PWD" >/tmp/php.log 2>&1 &)
sleep 1
echo "Siap: http://127.0.0.1:8899/frontend/index.html  (setelah selesai: rm backend/api/config.php, DROP DATABASE material)"
