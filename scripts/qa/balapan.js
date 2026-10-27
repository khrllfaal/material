// Uji balapan (butuh php -S dengan PHP_CLI_SERVER_WORKERS>1): beberapa permintaan bersamaan tidak boleh menggandakan pembayaran/persetujuan
// atau saling menimpa absen. Diulang 8 kali pada 8 minggu berbeda.
const {C,ok,done}=require('./lib.js');
const addDays=(d,n)=>{const x=new Date(d+'T12:00:00Z');x.setUTCDate(x.getUTCDate()+n);return x.toISOString().slice(0,10)};
(async()=>{const a=new C(),k=new C();const la=await a.login('adminp','AdminTest123'),lk=await k.login('akun1','AkuntanTest123');if(la.s!==200||lk.s!==200){console.log('login gagal',la.s,lk.s,JSON.stringify(lk.j));return}
 const P='proj_a';await a.r('labor_rates.php','POST',{project_id:P,grade:'TK',tarif_harian:140000,tarif_lembur:17500});
 const w1=(await a.r('workers.php','POST',{project_id:P,nama:'Uji Satu',grade:'TK',tim:'Tim A'})).j.id,w2=(await a.r('workers.php','POST',{project_id:P,nama:'Uji Dua',grade:'TK',tim:'Tim A'})).j.id;
 const ls=[];for(let i=0;i<2;i++){const c=new C();const lr=await c.login('lap1','LapanganTest123');if(lr.s!==200){console.log('login lapangan gagal',lr.s,JSON.stringify(lr.j));return}ls.push(c)}
 let bayarOk=0,setujuiOk=0,saveOk=0,saveConf=0,bad=[];
 for(let r=0;r<8;r++){
  const fri=addDays('2026-08-07',7*r); // Jumat..Kamis minggu ke-r (semua di masa lalu)
  const d1=addDays(fri,1);
  await a.r('attendance.php','POST',{project_id:P,tgl:d1,entries:[{worker_id:w1,hari:1,lembur_jam:0},{worker_id:w2,hari:1,lembur_jam:0}]});
  let x=await k.r('payroll_periods.php','POST',{action:'ajukan',project_id:P,tgl:d1,pj_nama:'Arya'});
  // 1) 5 persetujuan bersamaan
  const s=await Promise.all([1,2,3,4,5].map(()=>k.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:d1})));
  const sOk=s.filter(q=>q.s===200).length;if(sOk===1)setujuiOk++;else bad.push('setujui minggu '+r+': '+s.map(q=>q.s));
  // 2) 6 pembayaran bersamaan
  const b=await Promise.all([1,2,3,4,5,6].map(()=>k.r('payroll_periods.php','POST',{action:'bayar',project_id:P,tgl:d1,tgl_bayar:d1,metode:'transfer',semua:true})));
  const bOk=b.filter(q=>q.s===200);if(bOk.length===1&&bOk[0].j.dibayar===280000)bayarOk++;else bad.push('bayar minggu '+r+': '+b.map(q=>q.s+':'+(q.j&&q.j.dibayar)));
  // 3) dua HP menyimpan absen sel yang sama pada hari lain minggu itu (belum ada data, ver=null) -> satu menang, satu ditolak
  const d2=addDays(fri,2);
  await a.r('attendance.php','POST',{project_id:P,tgl:d2,entries:[{worker_id:w1,hari:1,lembur_jam:0}]}).catch(()=>{});
 }
 ok('persetujuan bersamaan (5 permintaan x 8 minggu): selalu tepat satu berhasil',setujuiOk===8,setujuiOk+'/8');
 ok('pembayaran bersamaan (6 permintaan x 8 minggu): selalu tepat satu tercatat, jumlah benar 280.000',bayarOk===8,bayarOk+'/8 '+bad.filter(x=>/bayar/.test(x)).join(' | '));
 // 4) dua HP lapangan menyimpan hari yang sama, pekerja yang sama, keduanya mengira belum ada data (ver:null), minggu masih draft
 for(let r=0;r<8;r++){
  const day=addDays('2026-10-20',0), w=(await a.r('workers.php','POST',{project_id:P,nama:'Rebutan '+r,grade:'TK',tim:'Tim A'})).j.id;
  const rr=await Promise.all(ls.map((c)=>c.r('attendance.php','POST',{project_id:P,tgl:day,entries:[{worker_id:w,hari:(ls.indexOf(c)===0?1:0.5),lembur_jam:0,ver:null}]})));
  const okc=rr.filter(q=>q.s===200).length,cf=rr.filter(q=>q.s===409).length;if(okc===1&&cf===1)saveOk++;else if(okc===2)saveConf++;
 }
 ok('dua HP menyimpan sel absen yang sama bersamaan: tepat satu menang, satu ditolak (8 putaran)',saveOk===8,'sesuai '+saveOk+'/8, keduanya lolos '+saveConf);

 // 5) satu pengajuan kasbon disetujui 5 kali bersamaan: hanya satu kasbon tercatat
 let kasOk=0;
 for(let r=0;r<6;r++){
  const req=await k.r('kasbon_requests.php','POST',{project_id:P,worker_id:w1,jumlah:100000+r,ket:'balapan '+r});
  const rid=(req.j&&req.j.id)||(req.j&&req.j.ids&&req.j.ids[0]);
  if(!rid){continue}
  const ap=await Promise.all([1,2,3,4,5].map(()=>k.r('kasbon_requests.php','POST',{action:'setujui',id:rid,tgl:'2026-10-27'})));
  const n=ap.filter(q=>q.s===200).length;if(n===1)kasOk++;
 }
 ok('persetujuan kasbon bersamaan (5 permintaan x 6 pengajuan): selalu tepat satu kasbon tercatat',kasOk===6,kasOk+'/6');
 // 6) Tambahan dimasukkan saat pembayaran berjalan: tidak boleh masuk ke periode yang sudah dibayar
 let adjOk=0;
 for(let r=0;r<5;r++){
  const fri=addDays('2026-06-05',7*r),d1=addDays(fri,1);
  await a.r('attendance.php','POST',{project_id:P,tgl:d1,entries:[{worker_id:w1,hari:1,lembur_jam:0}]});
  await k.r('payroll_periods.php','POST',{action:'ajukan',project_id:P,tgl:d1,pj_nama:'Arya'});await k.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:d1});
  const rs=await Promise.all([k.r('payroll_periods.php','POST',{action:'bayar',project_id:P,tgl:d1,tgl_bayar:d1,metode:'tunai',semua:true}),k.r('payroll_adjustments.php','POST',{project_id:P,tgl:d1,jenis:'tambah',kategori:'tambahan',label:'susulan',worker_id:w1,jumlah:50000})]);
  const x=await k.r('payroll.php?project_id='+P+'&tgl_from='+fri+'&tgl_to='+addDays(fri,6));const row=x.j.rows.find(q=>q.worker_id===w1);
  // konsisten: yang dibayar = yang diterima (tidak ada tambahan yang lolos setelah periode terkunci), atau tambahan masuk sebelum bayar dan ikut terbayar
  if(row&&Math.abs(row.dibayar-row.net)<0.01&&row.dibayar>=140000)adjOk++;
 }
 ok('Tambahan bersamaan dengan pembayaran: dibayar selalu sama dengan diterima (tidak ada selisih tersembunyi), 5 putaran',adjOk===5,adjOk+'/5');
 done('balapan');})();
