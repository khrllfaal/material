const {C,ok,done}=require('./lib.js');
(async()=>{const a=new C(),l=new C(),l2=new C(),k=new C(),o=new C();
 await a.login('adminp','AdminTest123');await l.login('lap1','LapanganTest123');await l2.login('lap2','LapanganTest123');await k.login('akun1','AkuntanTest123');await o.login('owner1','OwnerTest123');
 const A='proj_a',Bp='proj_b',D1='2026-10-27',D0='2026-10-26';
 for(const P of [A,Bp]) await a.r('labor_rates.php','POST',{project_id:P,grade:'TK',tarif_harian:140000,tarif_lembur:17500});
 let x=await l.r('workers.php','POST',{project_id:A,nama:'Asep',grade:'TK',tim:'Tim A'});const asep=x.j.id;
 x=await l.r('workers.php','POST',{project_id:A,nama:'Budi',grade:'TK',tim:'Tim A'});const budi=x.j.id;
 // ===== J1 tim borongan (dibuat lapangan)
 x=await l.r('workers.php','POST',{project_id:A,nama:'Tim Cat',borongan:true});const cat=x.j.id;
 ok('lapangan membuat tim borongan',x.s===200&&x.j.borongan===1&&x.j.grade==='LAIN'&&x.j.tim==='Tim Cat',JSON.stringify(x.j));
 x=await k.r('workers.php?all=1&project_id='+A);const wc=(x.j||[]).find(w=>w.id===cat);
 ok('akuntan melihat borongan=1 dan tarif 0 (bukan kosong)',wc&&wc.borongan===1&&wc.tarif_harian===0&&wc.tarif_lembur===0,JSON.stringify(wc));
 x=await l.r('workers.php?project_id='+A);const wl=(x.j||[]).find(w=>w.id===cat);
 ok('lapangan melihat penanda borongan tanpa tarif/rekening',wl&&wl.borongan===1&&!('tarif_harian' in wl)&&!('no_rek' in wl),JSON.stringify(wl));
 // akuntan membuat tim borongan kedua (aluminium) dengan rekening
 x=await k.r('workers.php','POST',{project_id:A,nama:'Tim Aluminium',borongan:true,no_rek:'BCA 123'});const alu=x.j.id;
 ok('akuntan membuat tim borongan',x.s===200&&x.j.borongan===1,JSON.stringify(x.j));
 x=await k.r('workers.php','POST',{project_id:A,nama:'Tim Aluminium',borongan:true});ok('nama tim borongan ganda ditolak',x.s===409,JSON.stringify(x.j));
 // absen
 for(const d of [D0,D1]){x=await l.r('attendance.php','POST',{project_id:A,tgl:d,entries:[{worker_id:asep,hari:1,lembur_jam:0},{worker_id:budi,hari:1,lembur_jam:0},{worker_id:cat,hari:1,lembur_jam:0}]});if(x.s!==200)console.log('att',d,x.s,x.j)}
 x=await l.r('payroll_periods.php','POST',{action:'ajukan',project_id:A,tgl:D1,pj_nama:'Arya'});ok('lapangan ajukan (termasuk tim borongan)',x.s===200,JSON.stringify(x.j));
 x=await k.r('payroll.php?project_id='+A+'&tgl_from=2026-10-23&tgl_to=2026-10-29');const rc=x.j.rows.find(r=>r.worker_id===cat);
 ok('baris tim borongan: gaji harian 0, tanpa peringatan tarif, ada peringatan nominal kosong',rc&&rc.gaji===0&&rc.borongan===1&&!rc.flags.includes('tarif_kosong')&&rc.flags.includes('borongan_kosong'),JSON.stringify(rc&&rc.flags));
 ok('daftar borongan untuk pilihan Tambahan tersedia',Array.isArray(x.j.borongan)&&x.j.borongan.length===2,JSON.stringify(x.j.borongan));
 const rb=x.j.rows.find(r=>r.worker_id===asep);ok('pekerja harian tetap dihitung normal (2 hari x 140.000)',rb&&rb.gaji===280000,rb&&rb.gaji);
 // nominal borongan + kasbon tim
 x=await k.r('payroll_adjustments.php','POST',{project_id:A,tgl:D1,jenis:'tambah',kategori:'tambahan',label:'Borongan cat lt.2',worker_id:cat,jumlah:12000000});ok('akuntan mengisi nominal borongan 12.000.000',x.s===200,JSON.stringify(x.j));
 x=await k.r('kasbon.php','POST',{project_id:A,tgl:D0,worker_id:cat,jumlah:2000000,ket:'kasbon tim cat'});ok('kasbon atas nama tim borongan 2.000.000',x.s===200,JSON.stringify(x.j));
 x=await k.r('payroll_periods.php','POST',{action:'setujui',project_id:A,tgl:D1});ok('setujui berhasil tanpa blokir "tarif belum diisi"',x.s===200,JSON.stringify(x.j).slice(0,120));
 ok('kasbon otomatis memotong tim borongan',x.s===200&&x.j.potong_kasbon>=2000000,JSON.stringify(x.j));
 x=await k.r('payroll.php?project_id='+A+'&tgl_from=2026-10-23&tgl_to=2026-10-29');const rc2=x.j.rows.find(r=>r.worker_id===cat);
 ok('diterima tim borongan = 12.000.000 - 2.000.000 = 10.000.000',rc2&&rc2.net===10000000,rc2&&rc2.net);
 ok('peringatan nominal kosong hilang setelah diisi',rc2&&!rc2.flags.includes('borongan_kosong'),JSON.stringify(rc2&&rc2.flags));
 x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:A,tgl:D1,tgl_bayar:D1,metode:'transfer',items:[{worker_id:cat,jumlah:6000000}]});ok('bayar termin 1 = 6.000.000 (periode belum lunas)',x.s===200&&x.j.dibayar===6000000&&x.j.lunas===false,JSON.stringify(x.j));
 x=await k.r('payroll.php?project_id='+A+'&tgl_from=2026-10-23&tgl_to=2026-10-29');const rc3=x.j.rows.find(r=>r.worker_id===cat);ok('sisa tim borongan 4.000.000, status sebagian',rc3&&rc3.sisa===4000000&&rc3.status_bayar==='sebagian',JSON.stringify(rc3&&[rc3.sisa,rc3.status_bayar]));
 x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:A,tgl:D1,tgl_bayar:D1,metode:'transfer',semua:true});ok('bayar sisa semua sampai lunas',x.s===200&&x.j.lunas===true,JSON.stringify(x.j));
 // ===== J2 nonaktif / aktif kembali
 x=await l.r('workers.php','POST',{action:'set_aktif',id:budi,aktif:false});ok('lapangan menonaktifkan pekerja yang berhenti',x.s===200&&x.j.aktif===0,JSON.stringify(x.j));
 x=await l.r('workers.php?project_id='+A);ok('pekerja nonaktif hilang dari daftar absen',!(x.j||[]).some(w=>w.id===budi));
 x=await a.r('attendance.php?project_id='+A+'&tgl_from=2026-10-23&tgl_to=2026-10-29');ok('riwayat absen pekerja nonaktif tetap utuh',(x.j||[]).filter(r=>r.worker_id===budi).length===2,(x.j||[]).filter(r=>r.worker_id===budi).length);
 x=await l.r('workers.php','POST',{project_id:A,nama:'budi',grade:'TK',tim:'Tim A'});ok('nama sama dengan yang nonaktif: ditawarkan aktif kembali (409 + id)',x.s===409&&x.j.nonaktif_id===budi,JSON.stringify(x.j));
 x=await l.r('workers.php','POST',{action:'set_aktif',id:budi,aktif:true});ok('aktif kembali dari HP lapangan',x.s===200&&x.j.aktif===1);
 x=await l.r('workers.php?project_id='+A);ok('muncul lagi di daftar absen',(x.j||[]).some(w=>w.id===budi));
 x=await l2.r('workers.php','POST',{action:'set_aktif',id:budi,aktif:false});ok('lapangan proyek lain tidak boleh (403)',x.s===403,JSON.stringify(x.j));
 x=await o.r('workers.php','POST',{action:'set_aktif',id:budi,aktif:false});ok('owner tidak boleh (403)',x.s===403,JSON.stringify(x.j));
 // ===== J3 setengah hari di dua proyek
 x=await l2.r('workers.php?kandidat=1&project_id='+Bp);ok('lapangan proyek B tidak melihat pekerja proyek A',x.s===200&&Array.isArray(x.j)&&x.j.length===0,JSON.stringify(x.j));
 x=await a.r('workers.php?kandidat=1&project_id='+Bp);ok('admin melihat pekerja proyek lain sebagai pilihan',(x.j||[]).some(w=>w.id===asep)&&!(x.j||[]).some(w=>w.id===cat),(x.j||[]).length);
 x=await l2.r('workers.php','POST',{project_id:Bp,nama:'Asep',grade:'TK',tim:'Tim B',induk_id:asep});ok('lapangan B tidak boleh menghubungkan ke pekerja proyek di luar akses (403)',x.s===403,JSON.stringify(x.j));
 x=await a.r('workers.php','POST',{project_id:Bp,nama:'Asep',grade:'TK',tim:'Tim B',induk_id:asep});const asepB=x.j&&x.j.id;ok('admin membuat Asep di proyek B terhubung ke Asep di A',x.s===200&&asepB,JSON.stringify(x.j));
 x=await a.r('workers.php?all=1&project_id='+Bp);const wB=(x.j||[]).find(w=>w.id===asepB);ok('induk_id tersimpan',wB&&wB.induk_id===asep,JSON.stringify(wB&&wB.induk_id));
 const D2='2026-10-20';
 x=await a.r('attendance.php','POST',{project_id:A,tgl:D2,entries:[{worker_id:asep,hari:0.5,lembur_jam:0}]});ok('proyek A: Asep 1/2 hari',x.s===200&&x.j.peringatan_ganda.length===0,JSON.stringify(x.j.peringatan_ganda));
 x=await l2.r('attendance.php','POST',{project_id:Bp,tgl:D2,entries:[{worker_id:asepB,hari:0.5,lembur_jam:0}]});ok('proyek B: Asep 1/2 hari: total 1 hari, tanpa peringatan',x.s===200&&x.j.peringatan_ganda.length===0,JSON.stringify(x.j.peringatan_ganda));
 x=await l2.r('attendance.php','POST',{project_id:Bp,tgl:D2,entries:[{worker_id:asepB,hari:1,lembur_jam:0}]});ok('proyek B: Asep 1 hari: total 1,5 -> peringatan nama',x.s===200&&x.j.peringatan_ganda.includes('Asep'),JSON.stringify(x.j.peringatan_ganda));
 x=await k.r('payroll.php?tgl_from=2026-10-20&tgl_to=2026-10-20');const rowsAsep=(x.j.rows||[]).filter(r=>r.nama==='Asep');ok('rekap menandai "ganda" pada kedua baris Asep',rowsAsep.length===2&&rowsAsep.every(r=>r.flags.includes('ganda')),JSON.stringify(rowsAsep.map(r=>r.flags)));
 x=await l2.r('attendance.php','POST',{project_id:Bp,tgl:D2,entries:[{worker_id:asepB,hari:0.5,lembur_jam:0}]});
 x=await k.r('payroll.php?tgl_from=2026-10-20&tgl_to=2026-10-20');const ra=(x.j.rows||[]).filter(r=>r.nama==='Asep');ok('kembali 1/2 + 1/2: tidak ditandai ganda, gaji terbagi per proyek (70.000 + 70.000)',ra.length===2&&ra.every(r=>!r.flags.includes('ganda')&&r.gaji===70000),JSON.stringify(ra.map(r=>[r.project_nama,r.gaji,r.flags])));
 done('baru');})();
