#!/usr/bin/env python3
"""Membuat SQL data besar untuk uji beban: 80 proyek, 60 akun lapangan, 2.400 pekerja, ±130 ribu baris absen.
Pemakaian:  python3 scripts/qa/data_besar.py | mysql -uroot material   (setelah ./scripts/qa/up_env.sh)
Akun lapangan: lapx001..lapx060, sandi LapanganTest123 (hanya untuk database uji lokal)."""
import random, datetime, subprocess
random.seed(7)
H = subprocess.check_output(['php', '-r', 'echo password_hash("LapanganTest123", PASSWORD_BCRYPT, ["cost"=>12]);']).decode()
NP, NW = 80, 30
out = ["insert into projects(id,nama) values " + ",".join(f"('pr{p:03}','Proyek {p:03} Pembangunan Jalan dan Gedung')" for p in range(1, NP + 1)) + ";"]
users, up = [], []
for u in range(1, 61):
    uid = f"ul{u:03}"; users.append(f"('{uid}','lapx{u:03}','{H}','Lapangan {u:03}','lapangan')")
    for p in random.sample(range(1, NP + 1), 2): up.append(f"('{uid}','pr{p:03}')")
out.append("insert into users(id,username,password_hash,nama,role) values " + ",".join(users) + ";")
out.append("insert into user_projects(user_id,project_id) values " + ",".join(sorted(set(up))) + ";")
out.append("insert into labor_rates(project_id,tim,grade,tarif_harian,tarif_lembur) values " + ",".join(f"('pr{p:03}','','{g}',{h},{h//8//500*500})" for p in range(1, NP + 1) for g, h in (('KTK', 175000), ('TK', 140000), ('LD', 115000))) + ";")
wk, att, start = [], [], datetime.date(2026, 8, 28)
for p in range(1, NP + 1):
    for i in range(1, NW + 1):
        wid = f"w{p:03}_{i:02}"; g = 'KTK' if i <= 2 else ('TK' if i <= 14 else 'LD'); tim = f"Tim {chr(65 + i % 4)}"
        wk.append(f"('{wid}','pr{p:03}','Pekerja {p:03}-{i:02}','{g}','{tim}','2026-01-01')")
        h = {'KTK': 175000, 'TK': 140000, 'LD': 115000}[g]
        for d in range(60):
            if random.random() < 0.92:
                att.append(f"('{wid}','pr{p:03}','{start + datetime.timedelta(days=d)}',{random.choice([1, 1, 1, 0.5])},{random.choice([0, 0, 0, 1, 2])},'{g}','{tim}',{h},{h//8//500*500})")
out.append("insert into workers(id,project_id,nama,grade,tim,created_at) values " + ",".join(wk) + ";")
for i in range(0, len(att), 5000):
    out.append("insert into attendance(worker_id,project_id,tgl,hari,lembur_jam,grade,tim,tarif_harian,tarif_lembur) values " + ",".join(att[i:i + 5000]) + ";")
print("\n".join(out))
