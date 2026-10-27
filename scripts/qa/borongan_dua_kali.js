// Satu tim borongan, dua pekerjaan berbeda dengan jeda lama di proyek yang sama (satu lunas, satu baru).
const {C,ok,done}=require('./lib.js');
(async()=>{const k=new C(),l=new C();await k.login('akun1','AkuntanTest123');await l.login('lap1','LapanganTest123');
 const P='proj_a';
 const cat=(await k.r('workers.php','POST',{project_id:P,nama:'Tim Cat',borongan:true})).j.id;
 // ---- Borongan 1 (September): lantai 1 = 5.000.000, kasbon 1.500.000, dibayar lunas
 await k.r('payroll_adjustments.php','POST',{project_id:P,tgl:'2026-09-04',jenis:'tambah',kategori:'tambahan',label:'Borongan cat lantai 1',worker_id:cat,jumlah:5000000});
 await k.r('kasbon.php','POST',{project_id:P,tgl:'2026-09-05',worker_id:cat,jumlah:1500000,ket:'kasbon tim cat'});
 let x=await k.r('payroll_periods.php','POST',{action:'ajukan',project_id:P,tgl:'2026-09-04',pj_nama:'Akuntan'});
 x=await k.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:'2026-09-04'});ok('borongan 1: disetujui, kasbon 1.500.000 terpotong',x.s===200&&x.j.potong_kasbon===1500000,JSON.stringify(x.j));
 x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:P,tgl:'2026-09-04',tgl_bayar:'2026-09-10',metode:'transfer',semua:true});ok('borongan 1: dibayar 3.500.000 dan lunas',x.s===200&&x.j.dibayar===3500000&&x.j.lunas===true,JSON.stringify(x.j));
 // ---- Tim selesai: nonaktif
 x=await k.r('workers.php','POST',{action:'set_aktif',id:cat,aktif:false});ok('tim ditandai tidak aktif setelah selesai',x.s===200&&x.j.aktif===0);
 x=await k.r('payroll.php?project_id='+P+'&tgl_from=2026-09-01&tgl_to=2026-09-30');const b1=x.j.rows.find(r=>r.worker_id===cat);ok('riwayat borongan 1 tetap terlihat walau tim nonaktif (diterima 3.500.000, lunas)',b1&&b1.net===3500000&&b1.status_bayar==='lunas',JSON.stringify(b1&&[b1.net,b1.status_bayar]));
 x=await k.r('workers.php?project_id='+P+'&all=1');ok('tim masih ada di daftar (nonaktif)',(x.j||[]).some(w=>w.id===cat&&w.aktif===0));
 // ---- Borongan 2 (Oktober): tim dipakai lagi, pekerjaan baru
 x=await k.r('workers.php','POST',{action:'set_aktif',id:cat,aktif:true});ok('tim dipakai lagi (aktif kembali)',x.s===200&&x.j.aktif===1);
 x=await k.r('payroll.php?project_id='+P+'&tgl_from=2026-10-23&tgl_to=2026-10-29');ok('minggu baru bersih: tidak membawa tagihan borongan 1',!(x.j.rows||[]).some(r=>r.worker_id===cat&&r.sisa>0),JSON.stringify((x.j.rows||[]).map(r=>[r.nama,r.sisa])));
 await k.r('payroll_adjustments.php','POST',{project_id:P,tgl:'2026-10-26',jenis:'tambah',kategori:'tambahan',label:'Borongan cat lantai 2',worker_id:cat,jumlah:6000000});
 x=await k.r('kasbon.php');const sal=JSON.stringify(x.j);
 x=await k.r('payroll_periods.php','POST',{action:'ajukan',project_id:P,tgl:'2026-10-26',pj_nama:'Akuntan'});ok('borongan 2: diajukan pusat',x.s===200,JSON.stringify(x.j));
 x=await k.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:'2026-10-26'});ok('borongan 2: disetujui; kasbon lama sudah lunas, tidak terpotong lagi',x.s===200&&x.j.potong_kasbon===0,JSON.stringify(x.j));
 // termin lintas waktu pada borongan 2
 x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:P,tgl:'2026-10-26',tgl_bayar:'2026-10-26',metode:'tunai',items:[{worker_id:cat,jumlah:2000000}]});ok('borongan 2: termin 1 = 2.000.000 (belum lunas)',x.s===200&&x.j.lunas===false,JSON.stringify(x.j));
 x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:P,tgl:'2026-10-26',tgl_bayar:'2026-10-27',metode:'transfer',items:[{worker_id:cat,jumlah:4000000}]});ok('borongan 2: termin 2 = 4.000.000 pada tanggal lain, lunas',x.s===200&&x.j.lunas===true,JSON.stringify(x.j));
 // rekap gabungan dua pekerjaan
 x=await k.r('payroll.php?project_id='+P+'&tgl_from=2026-09-01&tgl_to=2026-10-31');
 const pj=(x.j.periods||[]).filter(p=>p.adjustments.some(a=>a.worker_id===cat)).map(p=>p.adjustments.filter(a=>a.worker_id===cat&&a.kategori==='tambahan').map(a=>a.label+'='+a.jumlah).join(',')+' ['+p.status+']');
 ok('rekap rentang September–Oktober memuat kedua pekerjaan dengan label berbeda',pj.length===2&&/lantai 1/.test(pj.join())&&/lantai 2/.test(pj.join()),pj.join(' | '));
 x=await k.r('payroll.php?project_id='+P+'&tgl_from=2026-09-01&tgl_to=2026-10-31');const tot=x.j.rows.find(r=>r.worker_id===cat);ok('total gabungan: diterima 9.500.000 (3.500.000 + 6.000.000), dibayar 9.500.000, sisa 0',tot&&tot.net===9500000&&tot.dibayar===9500000&&tot.sisa===0,JSON.stringify(tot&&[tot.net,tot.dibayar,tot.sisa]));
 done('borongan dua kali');})();
