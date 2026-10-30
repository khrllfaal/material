// Potongan kasbon mingguan: otomatis sebagai isian awal, Akuntan boleh menurunkan (tidak boleh menaikkan / negatif). Jam terkunci 2026-10-27.
const {C,ok,done}=require('./lib.js');
(async()=>{const a=new C(),l=new C(),k=new C();
 await a.login('adminp','AdminTest123');await l.login('lap1','LapanganTest123');await k.login('akun1','AkuntanTest123');
 const P='proj_a';
 await a.r('labor_rates.php','POST',{project_id:P,grade:'TK',tarif_harian:140000,tarif_lembur:17500});
 const w=async n=>(await a.r('workers.php','POST',{project_id:P,nama:n,grade:'TK',tim:'Tim A'})).j.id;
 const ids=[await w('Satu'),await w('Dua'),await w('Tiga')];
 const saldo=async id=>{const x=await k.r('kasbon.php?project_id='+P);const r=(x.j.saldo||x.j||[]);return x};
 for(const d of ['2026-10-23','2026-10-24','2026-10-25']) await a.r('attendance.php','POST',{project_id:P,tgl:d,entries:ids.map(i=>({worker_id:i,hari:1,lembur_jam:0}))});
 for(const i of ids) await k.r('kasbon.php','POST',{project_id:P,tgl:'2026-10-24',worker_id:i,jumlah:300000,ket:'kasbon'});
 let x=await l.r('payroll_periods.php','POST',{action:'ajukan',project_id:P,tgl:'2026-10-25',pj_nama:'Arya'});ok('diajukan',x.s===200,JSON.stringify(x.j));
 x=await k.r('payroll_periods.php?action=kasbon_plan&project_id='+P+'&tgl=2026-10-25');ok('rencana otomatis: 3 orang x 300.000',x.s===200&&x.j.items.length===3&&x.j.total===900000,JSON.stringify(x.j).slice(0,200));
 const S=(b)=>k.r('payroll_periods.php','POST',Object.assign({action:'setujui',project_id:P,tgl:'2026-10-25'},b));
 x=await S({potong:{[ids[0]]:400000}});ok('lebih dari angka otomatis ditolak (422)',x.s===422,JSON.stringify(x.j));
 x=await S({potong:{[ids[0]]:-5}});ok('negatif ditolak (422)',x.s===422,JSON.stringify(x.j));
 x=await S({potong:{[ids[0]]:'abc'}});ok('bukan angka ditolak (422)',x.s===422,JSON.stringify(x.j));
 x=await k.r('payroll_periods.php?project_id='+P+'&tgl=2026-10-25');ok('setelah penolakan periode masih diajukan (tidak setengah jadi)',x.j.status==='diajukan',x.j.status);
 x=await a.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:'2026-10-25',potong:{[ids[0]]:1}});ok('Admin Pusat tetap tidak boleh menyetujui (403)',x.s===403);
 x=await S({potong:{[ids[0]]:100000,[ids[1]]:0}});ok('disetujui dengan ubahan: total 100.000 + 0 + 300.000 = 400.000',x.s===200&&x.j.potong_kasbon===400000&&x.j.orang===2,JSON.stringify(x.j));
 x=await k.r('payroll.php?project_id='+P+'&tgl_from=2026-10-23&tgl_to=2026-10-25');if(!x.j.rows)console.log('PAYROLL',x.s,JSON.stringify(x.j).slice(0,300));const net=id=>Math.round(x.j.rows.find(r=>r.worker_id===id).net);
 ok('Satu: 420.000 - 100.000 = 320.000',net(ids[0])===320000,net(ids[0]));ok('Dua: tidak dipotong = 420.000',net(ids[1])===420000,net(ids[1]));ok('Tiga: otomatis penuh 300.000 = 120.000',net(ids[2])===120000,net(ids[2]));
 x=await k.r('kasbon.php?project_id='+P);const txt=JSON.stringify(x.j);
 // saldo sisa: pekerja Satu 200.000, Dua 300.000, Tiga 0 -> periode berikut memotong sisanya
 x=await k.r('kasbon.php');const sd=id=>(x.j.saldo.find(r=>r.worker_id===id)||{saldo:0}).saldo;
 ok('sisa kasbon tetap tercatat: Satu 200.000, Dua 300.000, Tiga lunas',sd(ids[0])===200000&&sd(ids[1])===300000&&sd(ids[2])===0,JSON.stringify(x.j.saldo.map(r=>[r.nama,r.saldo])));
 x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:P,tgl:'2026-10-25',tgl_bayar:'2026-10-27',metode:'tunai',semua:true});ok('dibayar sesuai gaji setelah potongan (320+420+120 = 860.000)',x.s===200&&x.j.dibayar===860000,JSON.stringify(x.j));
 done();})().catch(e=>{console.log('ERR',e);process.exit(1)});
