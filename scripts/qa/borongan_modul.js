// Menu Borongan (borongan.php): alur kontrak -> termin -> bayar, retensi, kasbon tim, addendum, selesai, batal, buku besar, jurnal, peran, bersamaan.
const {C}=require('./lib.js');
const R=[];const ok=(n,c,d='')=>R.push((c?'PASS':'FAIL')+' | '+n+(d?' | '+String(d).slice(0,170):''));
const eq=(a,b)=>Math.abs(a-b)<0.005;
(async()=>{const a=new C(),k=new C(),o=new C(),l=new C();await a.login('adminp','AdminTest123');await k.login('akun1','AkuntanTest123');await o.login('owner1','OwnerTest123');await l.login('lap1','LapanganTest123');
 const P='proj_a';
 const tim=(await k.r('workers.php','POST',{project_id:P,nama:'Tim Kaca',borongan:true,no_rek:'BCA 777'})).j.id;
 let x=await k.r('kasbon.php','POST',{project_id:P,tgl:'2026-10-05',worker_id:tim,jumlah:5000000,ket:'kasbon tim'});ok('kasbon tim 5.000.000 dicatat',x.s===200);
 const B=(c,b)=>c.r('borongan.php','POST',b);
 // --- kontrak
 x=await B(a,{action:'buat',worker_id:tim,nama_pekerjaan:'Pasang kaca lantai 1',tgl_mulai:'2026-10-01',nilai:100000000,retensi_persen:5});const k1=x.j.id;
 ok('Admin Pusat membuat kontrak: nomor BK/2026/10/0001, status draft',x.s===200&&x.j.no_kontrak==='BK/2026/10/0001',JSON.stringify(x.j));
 ok('Lapangan tidak boleh membuka menu Borongan (403)',(await l.r('borongan.php')).s===403);
 ok('Owner tidak boleh menulis (403)',(await B(o,{action:'buat',worker_id:tim,nama_pekerjaan:'x',tgl_mulai:'2026-10-01',nilai:1})).s===403);
 ok('tanpa login: 401',(await new C().r('borongan.php')).s===401);
 ok('nilai kontrak 0 ditolak (422)',(await B(a,{action:'buat',worker_id:tim,nama_pekerjaan:'x',tgl_mulai:'2026-10-01',nilai:0})).s===422);
 ok('retensi 31% ditolak (422)',(await B(a,{action:'buat',worker_id:tim,nama_pekerjaan:'x',tgl_mulai:'2026-10-01',nilai:1000,retensi_persen:31})).s===422);
 ok('pekerja biasa (bukan tim borongan) tidak bisa dikontrak (422)',(await B(a,{action:'buat',worker_id:(await a.r('workers.php','POST',{project_id:P,nama:'Biasa',grade:'TK'})).j.id,nama_pekerjaan:'x',tgl_mulai:'2026-10-01',nilai:1000})).s===422);
 x=await B(a,{action:'termin_buat',kontrak_id:k1,bruto:1000000});ok('termin pada kontrak draft ditolak (409)',x.s===409,x.s);
 x=await B(a,{action:'setujui',id:k1});ok('Admin Pusat tidak boleh menyetujui kontrak (403)',x.s===403);
 x=await B(k,{action:'setujui',id:k1});ok('kontrak belum diajukan: setujui ditolak (409)',x.s===409);
 x=await B(a,{action:'ajukan',id:k1});ok('Admin Pusat mengajukan kontrak',x.s===200);
 x=await B(a,{action:'ubah',id:k1,nama_pekerjaan:'x',tgl_mulai:'2026-10-01',nilai:1});ok('kontrak yang sudah diajukan tidak bisa diubah (409)',x.s===409);
 x=await B(k,{action:'setujui',id:k1});ok('Akuntan menyetujui kontrak',x.s===200&&x.j.status==='disetujui');
 // --- termin
 x=await B(a,{action:'termin_buat',kontrak_id:k1,bruto:30000000,tgl:'2026-10-10',ket:'progres 30%'});const t1=x.j.id;
 ok('termin 1: bruto 30jt => retensi 1,5jt, potong kasbon 5jt, bersih 23,5jt',x.s===200&&eq(x.j.retensi,1500000)&&eq(x.j.potong_kasbon,5000000)&&eq(x.j.bersih,23500000)&&x.j.no_termin===1,JSON.stringify(x.j));
 x=await B(a,{action:'termin_buat',kontrak_id:k1,bruto:40000000,tgl:'2026-10-20',denda:2000000,denda_ket:'terlambat 4 hari'});const t2=x.j.id;
 ok('termin 2: bruto 40jt, denda 2jt => retensi 2jt, bersih 36jt (kasbon sudah dipakai termin 1: pratinjau 5jt dihitung ulang saat disetujui)',x.s===200&&eq(x.j.retensi,2000000)&&x.j.no_termin===2,JSON.stringify(x.j));
 ok('denda tanpa alasan ditolak (422)',(await B(a,{action:'termin_buat',kontrak_id:k1,bruto:1000,denda:10})).s===422);
 x=await B(a,{action:'termin_buat',kontrak_id:k1,bruto:31000000});ok('termin 31jt melebihi sisa jatah 30jt ditolak (422)',x.s===422&&/sisa nilai kontrak/.test(x.j.error),JSON.stringify(x.j));
 x=await B(k,{action:'termin_bayar',id:t1,tgl_bayar:'2026-10-12',metode:'transfer'});ok('bayar sebelum disetujui ditolak (409)',x.s===409);
 x=await B(a,{action:'termin_setujui',id:t1});ok('Admin Pusat tidak boleh menyetujui termin (403)',x.s===403);
 x=await B(k,{action:'termin_setujui',id:t1});ok('Akuntan menyetujui termin 1',x.s===200&&eq(x.j.potong_kasbon,5000000)&&eq(x.j.bersih,23500000),JSON.stringify(x.j));
 x=await B(k,{action:'termin_bayar',id:t1,tgl_bayar:'2026-10-05',metode:'transfer'});ok('tanggal bayar sebelum tanggal termin ditolak (422)',x.s===422,x.s);
 x=await B(k,{action:'termin_bayar',id:t1,tgl_bayar:'2026-10-12',metode:'transfer'});
 ok('bayar termin 1: nomor bukti BP/2026/10/0001, dibayar 23,5jt, kasbon dipotong 5jt',x.s===200&&x.j.bukti_no==='BP/2026/10/0001'&&eq(x.j.dibayar,23500000)&&eq(x.j.potong_kasbon,5000000),JSON.stringify(x.j));
 x=await B(k,{action:'termin_bayar',id:t1,tgl_bayar:'2026-10-12',metode:'transfer'});ok('bayar dua kali ditolak (409)',x.s===409);
 let kb=await k.r('kasbon.php');const row=kb.j.kasbon.find(r=>r.ref===t1);
 ok('buku kasbon: ada baris pelunasan -5jt bertaut ke termin; saldo kasbon tim 0',row&&eq(row.jumlah,-5000000)&&!(kb.j.saldo||[]).some(s=>s.worker_id===tim&&Math.abs(s.saldo)>0.005),JSON.stringify(row)+' '+JSON.stringify(kb.j.saldo));
 // --- bayar bersamaan (termin 2)
 await B(k,{action:'termin_setujui',id:t2});
 const rs=await Promise.all([1,2,3,4,5,6].map(()=>B(k,{action:'termin_bayar',id:t2,tgl_bayar:'2026-10-22',metode:'transfer'})));
 const sukses=rs.filter(r=>r.s===200);ok('6 pembayaran bersamaan untuk termin 2: tepat satu berhasil, lainnya 409',sukses.length===1&&rs.filter(r=>r.s===409).length===5,rs.map(r=>r.s).join(','));
 ok('termin 2 dibayar 36jt dengan nomor bukti BP/2026/10/0002',eq(sukses[0].j.dibayar,36000000)&&sukses[0].j.bukti_no==='BP/2026/10/0002',JSON.stringify(sukses[0].j));
 // --- buku besar
 let bt=await k.r('borongan.php?tim='+tim);const rk=bt.j.kontrak[0].ringkas;
 ok('ringkasan kontrak: ditagih 70jt, belum ditagih 30jt, retensi ditahan 3,5jt, dibayar bersih 59,5jt, kasbon 5jt, denda 2jt',eq(rk.ditagih,70000000)&&eq(rk.belum_ditagih,30000000)&&eq(rk.retensi_ditahan,3500000)&&eq(rk.dibayar_bersih,59500000)&&eq(rk.kasbon_dipotong,5000000)&&eq(rk.denda,2000000),JSON.stringify(rk));
 ok('buku besar: saldo kewajiban 33,5jt; entri berurut waktu; saldo akhir = hutang_saldo',eq(bt.j.hutang_saldo,33500000)&&eq(bt.j.buku[bt.j.buku.length-1].saldo,33500000)&&bt.j.buku.map(e=>e.tgl).join()===[...bt.j.buku.map(e=>e.tgl)].sort().join(),JSON.stringify(bt.j.buku.map(e=>[e.tgl,e.jenis,e.saldo])));
 ok('buku kasbon tim di buku besar: saldo 0',eq(bt.j.kasbon_saldo,0));
 // --- addendum
 x=await B(a,{action:'addendum_buat',kontrak_id:k1,jumlah:-31000000,alasan:'kurang volume'});ok('addendum -31jt melebihi sisa belum ditagih ditolak (422)',x.s===422,x.s);
 x=await B(a,{action:'addendum_buat',kontrak_id:k1,jumlah:10000000,alasan:'tambah kerja jendela'});const ad1=x.j.id;ok('addendum +10jt diajukan',x.s===200);
 let d1=(await k.r('borongan.php?id='+k1)).j;ok('addendum belum disetujui: nilai kini masih 100jt',eq(d1.ringkas.nilai_kini,100000000));
 ok('Admin Pusat tidak boleh menyetujui addendum (403)',(await B(a,{action:'addendum_setujui',id:ad1})).s===403);
 x=await B(k,{action:'addendum_setujui',id:ad1});d1=(await k.r('borongan.php?id='+k1)).j;ok('addendum disetujui: nilai kini 110jt, belum ditagih 40jt',x.s===200&&eq(d1.ringkas.nilai_kini,110000000)&&eq(d1.ringkas.belum_ditagih,40000000),JSON.stringify(d1.ringkas));
 ok('addendum diproses dua kali ditolak (409)',(await B(k,{action:'addendum_setujui',id:ad1})).s===409);
 // --- saldo kasbon berubah sesudah disetujui
 await k.r('kasbon.php','POST',{project_id:P,tgl:'2026-10-24',worker_id:tim,jumlah:2000000,ket:'kasbon baru'});
 x=await B(a,{action:'termin_buat',kontrak_id:k1,bruto:40000000,tgl:'2026-10-25'});const t3=x.j.id;ok('termin 3 (40jt): potong kasbon 2jt dari kasbon baru, retensi 2jt, bersih 36jt',x.s===200&&eq(x.j.potong_kasbon,2000000)&&eq(x.j.bersih,36000000),JSON.stringify(x.j));
 await B(k,{action:'termin_setujui',id:t3});
 await k.r('kasbon.php','POST',{project_id:P,tgl:'2026-10-26',worker_id:tim,jumlah:1500000,jenis:'pelunasan',ket:'tim mengembalikan tunai'});
 x=await B(k,{action:'termin_bayar',id:t3,tgl_bayar:'2026-10-26',metode:'tunai'});ok('saldo kasbon turun di bawah potongan yang disetujui: bayar ditolak (409) dengan petunjuk',x.s===409&&/Saldo kasbon tim berubah/.test(x.j.error),JSON.stringify(x.j));
 x=await B(k,{action:'termin_tolak',id:t3,alasan:'hitung ulang'});ok('termin ditolak dengan alasan',x.s===200);
 ok('termin ditolak tidak mengurangi jatah (belum ditagih 40jt lagi)',eq((await k.r('borongan.php?id='+k1)).j.ringkas.belum_ditagih,40000000));
 x=await B(a,{action:'termin_buat',kontrak_id:k1,bruto:40000000,tgl:'2026-10-26'});const t4=x.j.id;ok('termin 4 dibuat ulang: potong kasbon 500.000 (saldo kini 0,5jt), bersih 37,5jt',x.s===200&&eq(x.j.potong_kasbon,500000)&&eq(x.j.bersih,37500000)&&x.j.no_termin===4,JSON.stringify(x.j));
 await B(k,{action:'termin_setujui',id:t4});x=await B(k,{action:'termin_bayar',id:t4,tgl_bayar:'2026-10-26',metode:'tunai'});ok('termin 4 dibayar tunai',x.s===200&&eq(x.j.dibayar,37500000)&&x.j.bukti_no==='BP/2026/10/0003',JSON.stringify(x.j));
 // --- selesai
 d1=(await k.r('borongan.php?id='+k1)).j;ok('semua nilai (110jt) sudah ditagih: belum ditagih 0',eq(d1.ringkas.belum_ditagih,0));
 x=await B(a,{action:'selesai',id:k1});ok('Admin Pusat tidak boleh menyelesaikan kontrak (403)',x.s===403);
 x=await B(a,{action:'termin_buat',kontrak_id:k1,jenis:'lepas_retensi',bruto:99999999});ok('pelepasan retensi melebihi retensi ditahan ditolak (422)',x.s===422,JSON.stringify(x.j));
 d1=(await k.r('borongan.php?id='+k1)).j;const ret=d1.ringkas.retensi_ditahan;ok('retensi ditahan = 5% x 110jt = 5,5jt',eq(ret,5500000),ret);
 x=await B(k,{action:'selesai',id:k1});ok('selesai tanpa sisa: berhasil, retensi masih ditahan 5,5jt',x.s===200&&eq(x.j.retensi_ditahan,5500000)&&eq(x.j.dikurangi,0),JSON.stringify(x.j));
 ok('kontrak selesai: termin baru ditolak (409)',(await B(a,{action:'termin_buat',kontrak_id:k1,bruto:1000})).s===409);
 x=await B(a,{action:'termin_buat',kontrak_id:k1,jenis:'lepas_retensi',bruto:5500000,tgl:'2026-10-27',ket:'serah terima'});const t5=x.j.id;ok('pelepasan retensi 5,5jt (pada kontrak selesai) diajukan',x.s===200&&eq(x.j.retensi,0)&&eq(x.j.bersih,5500000),JSON.stringify(x.j));
 await B(k,{action:'termin_setujui',id:t5});x=await B(k,{action:'termin_bayar',id:t5,tgl_bayar:'2026-10-27',metode:'tunai'});ok('pelepasan retensi dibayar: bukti BP/2026/10/0004',x.s===200&&x.j.bukti_no==='BP/2026/10/0004',JSON.stringify(x.j));
 bt=await k.r('borongan.php?tim='+tim);ok('setelah semua lunas: kewajiban kepada tim 0 dan retensi 0',eq(bt.j.hutang_saldo,0)&&eq(bt.j.kontrak[0].ringkas.retensi_ditahan,0)&&eq(bt.j.kontrak[0].ringkas.hutang_saldo,0),JSON.stringify([bt.j.hutang_saldo,bt.j.kontrak[0].ringkas]));
 // --- kontrak kedua untuk tim yang sama setelah jeda
 x=await B(a,{action:'buat',worker_id:tim,nama_pekerjaan:'Pasang kaca lantai 2',tgl_mulai:'2026-10-26',nilai:20000000,retensi_persen:0});const k2=x.j.id;ok('kontrak kedua tim yang sama: nomor BK/2026/10/0002',x.j.no_kontrak==='BK/2026/10/0002');
 await B(a,{action:'ajukan',id:k2});await B(k,{action:'setujui',id:k2});
 x=await B(a,{action:'termin_buat',kontrak_id:k2,bruto:8000000,tgl:'2026-10-27'});const u1=x.j.id;ok('kontrak 2 termin 1 mulai dari nomor 1 (terpisah dari kontrak 1)',x.j.no_termin===1&&eq(x.j.retensi,0)&&eq(x.j.bersih,8000000),JSON.stringify(x.j));
 await B(k,{action:'termin_setujui',id:u1});await B(k,{action:'termin_bayar',id:u1,tgl_bayar:'2026-10-27',metode:'transfer'});
 bt=await k.r('borongan.php?tim='+tim);ok('buku besar gabungan 2 kontrak: kewajiban 12jt (20jt - 8jt)',bt.j.kontrak.length===2&&eq(bt.j.hutang_saldo,12000000),bt.j.hutang_saldo);
 // --- selesai dengan pengurangan nilai
 x=await B(k,{action:'selesai',id:k2});ok('selesai dengan sisa 12jt tanpa kurangi nilai ditolak (409)',x.s===409&&/belum ditagih seluruhnya/.test(x.j.error),JSON.stringify(x.j));
 x=await B(k,{action:'selesai',id:k2,kurangi_nilai:true});ok('selesai dengan mengurangi nilai 12jt',x.s===200&&eq(x.j.dikurangi,12000000),JSON.stringify(x.j));
 bt=await k.r('borongan.php?tim='+tim);ok('setelah penutupan: kewajiban 0',eq(bt.j.hutang_saldo,0)&&bt.j.kontrak.find(c=>c.id===k2).ringkas.nilai_kini===8000000,JSON.stringify(bt.j.kontrak.find(c=>c.id===k2).ringkas));
 // --- batal
 x=await B(a,{action:'buat',worker_id:tim,nama_pekerjaan:'Rencana batal',tgl_mulai:'2026-10-27',nilai:5000000});const k3=x.j.id;
 x=await B(a,{action:'batal',id:k3});ok('Admin Pusat membatalkan kontrak draft',x.s===200&&x.j.status==='batal');
 ok('kontrak yang sudah punya pembayaran tidak bisa dibatalkan (409)',(await B(k,{action:'batal',id:k1,alasan:'x'})).s===409||(await B(k,{action:'batal',id:k1,alasan:'x'})).s===409);
 x=await B(a,{action:'buat',worker_id:tim,nama_pekerjaan:'Rencana 2',tgl_mulai:'2026-10-27',nilai:5000000});const k4=x.j.id;await B(a,{action:'ajukan',id:k4});
 ok('Admin Pusat tidak boleh membatalkan kontrak yang sudah diajukan (403)',(await B(a,{action:'batal',id:k4,alasan:'x'})).s===403);
 ok('batal tanpa alasan (kontrak diajukan) ditolak (422)',(await B(k,{action:'batal',id:k4})).s===422);
 x=await B(k,{action:'kembalikan',id:k4,alasan:'nilai salah'});ok('Akuntan mengembalikan kontrak ke draft',x.s===200&&x.j.status==='draft');
 x=await B(a,{action:'ubah',id:k4,nama_pekerjaan:'Rencana 2 (revisi)',tgl_mulai:'2026-10-27',nilai:6000000,retensi_persen:2.5});ok('draft bisa diubah setelah dikembalikan',x.s===200);
 // --- daftar, jurnal, bukti
 x=await k.r('borongan.php');const ts=x.j.tim.find(t=>t.worker_id===tim);
 ok('daftar: ringkasan per tim memuat tim dengan kewajiban 0 dan kasbon 0; kontrak berjalan 0',x.s===200&&ts&&eq(ts.hutang_saldo,0)&&eq(ts.kasbon_saldo,0)&&ts.kontrak_berjalan===0,JSON.stringify(ts));
 x=await k.r('borongan.php?action=jurnal&from=2026-10-01&to=2026-10-27');let D=0,Kr=0,seimbang=true;const akun={};
 for(const e of x.j){let d=0,c=0;for(const [ak,dd,cc] of e.lines){d+=dd;c+=cc;akun[ak]=(akun[ak]||0)+dd-cc}if(Math.abs(d-c)>0.005)seimbang=false}
 ok('jurnal borongan: setiap peristiwa seimbang; hutang borongan & hutang retensi nol; piutang kasbon berkurang 7,5jt (5jt + 2jt + 0,5jt)',x.s===200&&seimbang&&eq(akun['Hutang Borongan']||0,0)&&eq(akun['Hutang Retensi']||0,0)&&eq(akun['Piutang Kasbon'],-5500000),JSON.stringify(akun));
 const bx=await k.r('borongan.php?action=bukti&bukti_no='+encodeURIComponent('BP/2026/10/0001'));
 ok('data bukti termin: nomor, tim, kontrak, bruto 30jt, retensi 1,5jt, kasbon 5jt, bersih 23,5jt',bx.s===200&&bx.j.tim_nama==='Tim Kaca'&&bx.j.no_kontrak==='BK/2026/10/0001'&&eq(bx.j.bruto,30000000)&&eq(bx.j.retensi,1500000)&&eq(bx.j.potong_kasbon,5000000)&&eq(bx.j.bersih,23500000),JSON.stringify(bx.j));
 ok('Owner boleh membaca; Lapangan tidak boleh melihat bukti borongan (403)',(await o.r('borongan.php')).s===200&&(await l.r('borongan.php?action=bukti&bukti_no=BP%2F2026%2F10%2F0001')).s===403);
 // perlindungan catatan otomatis
 const kb2=await k.r('kasbon.php');const auto=kb2.j.kasbon.find(r=>r.ref===t1);
 x=await k.r('kasbon.php?id='+auto.id,'DELETE');ok('potongan kasbon otomatis dari termin tidak bisa dihapus lewat buku kasbon (409)',x.s===409&&/otomatis/.test(x.j.error),JSON.stringify(x.j));
 x=await a.r('workers.php?id='+tim,'DELETE');ok('tim yang punya kontrak tidak bisa dihapus (409)',x.s===409&&/kontrak borongan/.test(x.j.error),JSON.stringify(x.j));
 // nomor bukti gaji dan borongan satu deret
 const W=(await a.r('workers.php','POST',{project_id:'proj_b',nama:'Pekerja Harian',grade:'TK'})).j.id;await a.r('labor_rates.php','POST',{project_id:'proj_b',grade:'TK',tarif_harian:100000,tarif_lembur:10000});
 await a.r('attendance.php','POST',{project_id:'proj_b',tgl:'2026-10-20',entries:[{worker_id:W,hari:1,lembur_jam:0}]});await k.r('payroll_periods.php','POST',{action:'ajukan',project_id:'proj_b',tgl:'2026-10-20',pj_nama:'PJ'});await k.r('payroll_periods.php','POST',{action:'setujui',project_id:'proj_b',tgl:'2026-10-20'});
 x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:'proj_b',tgl:'2026-10-20',tgl_bayar:'2026-10-27',metode:'tunai',semua:true});
 ok('bayar gaji mingguan sesudah borongan: nomor bukti melanjutkan deret yang sama (BP/2026/10/0006), tidak ada nomor ganda',x.j.bukti_no==='BP/2026/10/0006',JSON.stringify(x.j));
 // jatah termin dilindungi saat banyak permintaan bersamaan
 x=await B(a,{action:'buat',worker_id:tim,nama_pekerjaan:'Uji jatah',tgl_mulai:'2026-10-27',nilai:10000000,retensi_persen:0});const k5=x.j.id;await B(a,{action:'ajukan',id:k5});await B(k,{action:'setujui',id:k5});
 const rs2=await Promise.all([1,2,3,4,5].map(()=>B(a,{action:'termin_buat',kontrak_id:k5,bruto:6000000,tgl:'2026-10-27'})));
 ok('5 permintaan termin 6jt bersamaan pada kontrak 10jt: tepat satu diterima, empat ditolak (422)',rs2.filter(r=>r.s===200).length===1&&rs2.filter(r=>r.s===422).length===4,rs2.map(r=>r.s).join(','));
 const rs3=await Promise.all([1,2,3].map(()=>B(a,{action:'addendum_buat',kontrak_id:k5,jumlah:-4000000,alasan:'uji'})));
 ok('addendum -4jt (sisa jatah 4jt) 3 kali bersamaan: setiap permintaan diperiksa terhadap jatah saat dibuat; persetujuan tetap diperiksa ulang',rs3.every(r=>r.s===200||r.s===422));
 const ids=rs3.filter(r=>r.s===200).map(r=>r.j.id);const ap=await Promise.all(ids.map(id=>B(k,{action:'addendum_setujui',id})));
 ok('persetujuan addendum bersamaan: hanya satu yang lolos (sisa jatah 4jt), sisanya ditolak (422)',ap.filter(r=>r.s===200).length===1&&ap.filter(r=>r.s===422).length===ids.length-1,ap.map(r=>r.s).join(','));
 const fin=(await k.r('borongan.php?id='+k5)).j.ringkas;ok('kontrak uji jatah: nilai kini 6jt, belum ditagih 0 (tidak pernah negatif)',eq(fin.nilai_kini,6000000)&&eq(fin.belum_ditagih,0),JSON.stringify(fin));
 console.log(R.join('\n'));console.log('== borongan modul: PASS '+R.filter(r=>r.startsWith('PASS')).length+' / FAIL '+R.filter(r=>r.startsWith('FAIL')).length);})();
