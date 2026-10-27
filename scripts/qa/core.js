const {C,ok,done}=require('./lib.js');
(async()=>{const a=new C(),l=new C(),k=new C(),o=new C();
 await a.login('adminp','AdminTest123');await l.login('lap1','LapanganTest123');await k.login('akun1','AkuntanTest123');await o.login('owner1','OwnerTest123');
 const P='proj_a';
 await a.r('labor_rates.php','POST',{project_id:P,grade:'KTK',tarif_harian:175000,tarif_lembur:21000});await a.r('labor_rates.php','POST',{project_id:P,grade:'TK',tarif_harian:140000,tarif_lembur:17500});
 const kep=(await l.r('workers.php','POST',{project_id:P,nama:'Kepala',grade:'KTK',tim:'Tim A'})).j.id,tuk=(await l.r('workers.php','POST',{project_id:P,nama:'Tukang',grade:'TK',tim:'Tim A'})).j.id;
 let x=await l.r('workers.php?project_id='+P);ok('lapangan melihat daftar pekerja TANPA tarif/rekening',x.s===200&&x.j.length===2&&x.j.every(w=>!('tarif_harian' in w)&&!('no_rek' in w)));
 x=await l.r('payroll.php');ok('lapangan tidak boleh membuka rekap gaji (403)',x.s===403);
 x=await l.r('workers.php','POST',{id:kep,project_id:P,nama:'Kepala X',grade:'KTK'});ok('lapangan tidak boleh mengubah pekerja yang sudah ada (403)',x.s===403,JSON.stringify(x.j));
 // gaji minggu lalu (16-22): absen 20,21,22
 const day=async(d,e)=>l.r('attendance.php','POST',{project_id:P,tgl:d,entries:e});
 await day('2026-10-20',[{worker_id:kep,hari:1,lembur_jam:0},{worker_id:tuk,hari:1,lembur_jam:0}]);
 await day('2026-10-21',[{worker_id:kep,hari:1,lembur_jam:2},{worker_id:tuk,hari:0.5,lembur_jam:0}]);
 await day('2026-10-22',[{worker_id:kep,hari:1,lembur_jam:0},{worker_id:tuk,hari:1,lembur_jam:0}]);
 x=await k.r('payroll.php?project_id='+P+'&tgl_from=2026-10-16&tgl_to=2026-10-22');const rk=x.j.rows.find(r=>r.worker_id===kep),rt=x.j.rows.find(r=>r.worker_id===tuk);
 ok('hitungan: Kepala 3 hari x 175.000 + lembur 2 jam x 21.000 = 567.000',rk&&Math.round(rk.net)===567000,rk&&rk.net);ok('hitungan: Tukang 2,5 hari x 140.000 = 350.000',rt&&Math.round(rt.net)===350000,rt&&rt.net);
 x=await l.r('payroll_periods.php','POST',{action:'ajukan',project_id:P,tgl:'2026-10-22',pj_nama:'Arya'});ok('ajukan hari Selasa untuk minggu lalu (tidak dipotong)',x.s===200,JSON.stringify(x.j));
 x=await l.r('payroll_periods.php?project_id='+P+'&tgl=2026-10-22');ok('periode tetap Jumat 16 - Kamis 22',x.j.tgl_mulai==='2026-10-16'&&x.j.tgl_akhir==='2026-10-22',x.j.tgl_mulai+'..'+x.j.tgl_akhir);
 x=await day('2026-10-21',[{worker_id:kep,hari:0.5,lembur_jam:0}]);ok('lapangan tidak bisa mengubah absen yang sudah diajukan (409)',x.s===409,JSON.stringify(x.j));
 x=await a.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:'2026-10-22'});ok('Admin Pusat tidak boleh menyetujui (403)',x.s===403);
 x=await o.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:'2026-10-22'});ok('Owner tidak boleh menyetujui (403)',x.s===403);
 x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:P,tgl:'2026-10-22',semua:true});ok('belum disetujui: bayar ditolak (409)',x.s===409);
 x=await k.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:'2026-10-22'});ok('Akuntan menyetujui',x.s===200,JSON.stringify(x.j).slice(0,100));
 x=await a.r('payroll_periods.php','POST',{action:'bayar',project_id:P,tgl:'2026-10-22',semua:true});ok('Admin Pusat tidak boleh membayar (403)',x.s===403);
 const pay=await Promise.all([1,2,3,4].map(()=>k.r('payroll_periods.php','POST',{action:'bayar',project_id:P,tgl:'2026-10-22',tgl_bayar:'2026-10-27',metode:'transfer',semua:true})));
 ok('4 pembayaran bersamaan: hanya satu yang tercatat (total 917.000)',pay.filter(r=>r.s===200).length===1&&pay.find(r=>r.s===200).j.dibayar===917000,JSON.stringify(pay.map(r=>r.s)));
 x=await a.r('attendance.php','POST',{project_id:P,tgl:'2026-10-21',entries:[{worker_id:kep,hari:0.5,lembur_jam:0}]});ok('periode yang sudah dibayar terkunci (409)',x.s===409);
 // kasbon otomatis (minggu ini 23-25)
 for(const d of ['2026-10-23','2026-10-24','2026-10-25']) await day(d,[{worker_id:tuk,hari:1,lembur_jam:0}]);
 x=await k.r('kasbon.php','POST',{project_id:P,tgl:'2026-10-24',worker_id:tuk,jumlah:100000,ket:'kasbon'});ok('kasbon 100.000 atas nama Tukang',x.s===200);
 x=await l.r('payroll_periods.php','POST',{action:'ajukan',project_id:P,tgl:'2026-10-25',pj_nama:'Arya'});ok('ajukan hari Selasa sebelum Kamis (proyek berhenti): periode dipotong di hari kerja terakhir',x.s===200);
 x=await l.r('payroll_periods.php?project_id='+P+'&tgl=2026-10-25');ok('periode 23-25 Okt',x.j.tgl_mulai==='2026-10-23'&&x.j.tgl_akhir==='2026-10-25',x.j.tgl_mulai+'..'+x.j.tgl_akhir);
 x=await l.r('payroll_periods.php?project_id='+P+'&tgl=2026-10-27');ok('hari sisa membentuk periode susulan 26-29 Okt (draft)',x.j.tgl_mulai==='2026-10-26'&&x.j.tgl_akhir==='2026-10-29'&&x.j.status==='draft',x.j.tgl_mulai+'..'+x.j.tgl_akhir+' '+x.j.status);
 x=await k.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:'2026-10-25'});ok('setujui memotong kasbon otomatis 100.000',x.s===200&&x.j.potong_kasbon===100000,JSON.stringify(x.j));
 x=await k.r('payroll.php?project_id='+P+'&tgl_from=2026-10-23&tgl_to=2026-10-25');const r2=x.j.rows.find(r=>r.worker_id===tuk);ok('Tukang: 3 hari 420.000 - kasbon 100.000 = 320.000',r2&&Math.round(r2.net)===320000,r2&&r2.net);
 x=await k.r('kasbon.php');const saldo=(Array.isArray(x.j)?x.j:(x.j.saldo||[])).find&&null;
 // keamanan
 x=await o.r('attendance.php','POST',{project_id:P,tgl:'2026-10-27',entries:[{worker_id:kep,hari:1,lembur_jam:0}]});ok('Owner tidak boleh menulis absen (403)',x.s===403);
 const raw=await fetch('http://127.0.0.1:8899/backend/api/attendance.php',{method:'POST',headers:{'Content-Type':'application/json',Origin:'https://evil.example',Cookie:l.c},body:'{}'});ok('permintaan dari situs luar ditolak (403)',raw.status===403,raw.status);
 const raw2=await fetch('http://127.0.0.1:8899/backend/api/attendance.php',{method:'POST',headers:{'Content-Type':'text/plain',Cookie:l.c},body:'{}'});ok('content-type text/plain ditolak (415)',raw2.status===415,raw2.status);
 x=await a.r("workers.php?project_id="+encodeURIComponent("proj_a' OR '1'='1"));ok('percobaan injeksi SQL tidak menyebabkan error server',x.s<500,x.s);
 x=await l.r('attendance.php','POST',{project_id:P,tgl:'2026-10-10',entries:[{worker_id:kep,hari:1,lembur_jam:0}]});ok('lapangan tidak bisa mengisi absen > 7 hari lalu (403)',x.s===403,JSON.stringify(x.j));
 // hapus proyek
 x=await a.r('projects.php?id='+P,'DELETE');ok('hapus proyek yang punya data uang diminta konfirmasi (409)',x.s===409&&x.j.butuh_konfirmasi===true,JSON.stringify(x.j).slice(0,120));
 x=await a.r('projects.php?id='+P+'&konfirmasi=salah','DELETE');ok('nama konfirmasi salah ditolak',x.s===409||x.s===422,x.s);
 x=await a.r('projects.php?id='+P+'&konfirmasi='+encodeURIComponent('Proyek A'),'DELETE');ok('nama proyek benar: proyek terhapus',x.s===200,JSON.stringify(x.j));
 done('inti');})();
