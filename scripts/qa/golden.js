// "Golden master": menjalankan skenario gaji yang lengkap lalu menyimpan SEMUA keluaran API (id acak & jam dinormalkan).
// Dipakai untuk memastikan refaktor tidak mengubah hasil hitungan satu rupiah pun.
//   node golden.js simpan  -> tulis scripts/qa/golden.json   |   node golden.js   -> bandingkan dengan golden.json
const fs=require('fs'),path=require('path');const {C}=require('./lib.js');
const OUT=path.join(__dirname,'golden.json');
const WK=new Map(); // id pekerja -> W1.. (diisi skenario); id acak lain (penyesuaian, bayar, kasbon, pengguna) -> X
const canon=v=>Array.isArray(v)?v.map(canon).sort((a,b)=>JSON.stringify(a)<JSON.stringify(b)?-1:1):(v&&typeof v==='object')?Object.fromEntries(Object.keys(v).sort().map(k=>[k,canon(v[k])])):v;
const norm=v=>JSON.parse(JSON.stringify(v).replace(/(wrk|[a-z]{2,6})_[0-9a-f]{12}|\bu[0-9a-f]{12}\b/g,m=>WK.get(m)||'X').replace(/\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d/g,'TS'));
const LOG={};let n=0;const rec=(label,x)=>{LOG[String(++n).padStart(3,'0')+' '+label]={s:x.s,j:canon(norm(x.j))}};
(async()=>{const a=new C(),l=new C(),l2=new C(),k=new C(),o=new C();
 await a.login('adminp','AdminTest123');await l.login('lap1','LapanganTest123');await l2.login('lap3','LapanganTest123');await k.login('akun1','AkuntanTest123');await o.login('owner1','OwnerTest123');
 const A='proj_a',B='proj_b';
 for(const P of [A,B]){await a.r('labor_rates.php','POST',{project_id:P,grade:'KTK',tarif_harian:175000,tarif_lembur:21000});await a.r('labor_rates.php','POST',{project_id:P,grade:'TK',tarif_harian:140000,tarif_lembur:17500});await a.r('labor_rates.php','POST',{project_id:P,grade:'LD',tarif_harian:110000,tarif_lembur:13000});}
 const mk=async(c,P,nama,grade,tim,extra={})=>(await c.r('workers.php','POST',{project_id:P,nama,grade,tim,...extra})).j.id;
// ID pekerja
 const w1=await mk(l,A,'Kepala','KTK','Tim A'),w2=await mk(l,A,'Tukang','TK','Tim A'),w3=await mk(l,A,'Helper','LD','Tim B'),w4=await mk(l,A,'Tanpa Tarif','LAIN','Tim B');
 const ind=await mk(l2,B,'Tukang','TK','Tim A',{induk_id:w2});

 const bor=(await k.r('workers.php','POST',{project_id:A,nama:'Tim Alumunium',borongan:true})).j.id;
 [w1,w2,w3,w4,ind,bor].forEach((w,i)=>WK.set(w,'W'+(i+1)));
 const day=(c,P,d,e)=>c.r('attendance.php','POST',{project_id:P,tgl:d,entries:e});
 const rows=[['2026-10-16',[[w1,1,0],[w2,1,2],[w3,1,0]]],['2026-10-17',[[w1,1,3],[w2,0.5,0],[w3,1,0],[w2,0,0]]],['2026-10-19',[[w1,1,0],[w2,1,10],[w3,0.5,0]]],['2026-10-20',[[w1,1,0],[w2,0.5,0],[w3,1,1]]],['2026-10-21',[[w1,0,0],[w2,1,0],[w3,1,0]]],['2026-10-22',[[w1,1,0],[w2,1,0],[w3,1,0]]]];
 for(const [d,e] of rows) rec('absen A '+d,await day(a,A,d,e.map(([w,h,j])=>({worker_id:w,hari:h,lembur_jam:j}))));
 rec('absen B setengah hari',await day(a,B,'2026-10-19',[{worker_id:ind,hari:0.5,lembur_jam:0}]));
 rec('adj tambah',await k.r('payroll_adjustments.php','POST',{project_id:A,tgl:'2026-10-22',jenis:'tambah',kategori:'tambahan',label:'Bonus',worker_id:w1,jumlah:50000}));
 rec('adj borongan',await k.r('payroll_adjustments.php','POST',{project_id:A,tgl:'2026-10-22',jenis:'tambah',kategori:'tambahan',label:'Borongan rangka',worker_id:bor,jumlah:4000000}));
 rec('adj potong',await k.r('payroll_adjustments.php','POST',{project_id:A,tgl:'2026-10-22',jenis:'potong',kategori:'potongan',label:'Denda',worker_id:w3,jumlah:20000}));
 rec('kasbon w2',await k.r('kasbon.php','POST',{project_id:A,tgl:'2026-10-15',worker_id:w2,jumlah:300000,ket:'kasbon'}));
 rec('kasbon tim',await k.r('kasbon.php','POST',{project_id:A,tgl:'2026-10-16',worker_id:bor,jumlah:500000,ket:'kasbon tim'}));
 rec('kasbon besar w3',await k.r('kasbon.php','POST',{project_id:A,tgl:'2026-10-18',worker_id:w3,jumlah:2000000,ket:'besar'}));
 const R=(label,p,c=k)=>c.r(p).then(x=>rec(label,x));
 await R('rekap A minggu','payroll.php?project_id='+A+'&tgl_from=2026-10-16&tgl_to=2026-10-22');
 await R('rekap semua','payroll.php?tgl_from=2026-10-16&tgl_to=2026-10-29');
 await R('rekap semua owner','payroll.php?tgl_from=2026-10-16&tgl_to=2026-10-29',o);
 await R('rekap B','payroll.php?project_id='+B+'&tgl_from=2026-10-16&tgl_to=2026-10-22');
 await R('periode A','payroll_periods.php?project_id='+A+'&tgl=2026-10-22');
 await R('daftar periode','payroll_periods.php');
 rec('ajukan A',await l.r('payroll_periods.php','POST',{action:'ajukan',project_id:A,tgl:'2026-10-22',pj_nama:'Arya'}));
 rec('ajukan lagi',await l.r('payroll_periods.php','POST',{action:'ajukan',project_id:A,tgl:'2026-10-22',pj_nama:'Arya'}));
 rec('kembalikan',await k.r('payroll_periods.php','POST',{action:'kembalikan',project_id:A,tgl:'2026-10-22',alasan:'cek lembur'}));
 rec('ajukan ulang',await l.r('payroll_periods.php','POST',{action:'ajukan',project_id:A,tgl:'2026-10-22',pj_nama:'Arya'}));
 await R('kasbon_plan','payroll_periods.php?project_id='+A+'&tgl=2026-10-22&kasbon_plan=1');
 rec('bayar sebelum setuju',await k.r('payroll_periods.php','POST',{action:'bayar',project_id:A,tgl:'2026-10-22',semua:true}));
 rec('setujui',await k.r('payroll_periods.php','POST',{action:'setujui',project_id:A,tgl:'2026-10-22'}));
 await R('rekap A setelah setuju','payroll.php?project_id='+A+'&tgl_from=2026-10-16&tgl_to=2026-10-22');
 await R('periode A setuju','payroll_periods.php?project_id='+A+'&tgl=2026-10-22');
 await R('kasbon buku','kasbon.php');
 const rk=(await k.r('payroll.php?project_id='+A+'&tgl_from=2026-10-16&tgl_to=2026-10-22')).j.rows;
 rec('bayar sebagian',await k.r('payroll_periods.php','POST',{action:'bayar',project_id:A,tgl:'2026-10-22',tgl_bayar:'2026-10-27',metode:'transfer',items:[{worker_id:w1,jumlah:100000},{worker_id:bor,jumlah:1000000}]}));
 await R('rekap A setelah bayar sebagian','payroll.php?project_id='+A+'&tgl_from=2026-10-16&tgl_to=2026-10-22');
 rec('bayar semua',await k.r('payroll_periods.php','POST',{action:'bayar',project_id:A,tgl:'2026-10-22',tgl_bayar:'2026-10-27',metode:'cash',semua:true}));
 await R('periode A lunas','payroll_periods.php?project_id='+A+'&tgl=2026-10-22');
 await R('rekap A akhir','payroll.php?project_id='+A+'&tgl_from=2026-10-16&tgl_to=2026-10-22');
 // minggu berjalan, dipotong (susulan)
 for(const d of ['2026-10-23','2026-10-24','2026-10-25']) await day(a,A,d,[{worker_id:w2,hari:1,lembur_jam:0},{worker_id:w3,hari:1,lembur_jam:0}]);
 rec('ajukan dipotong',await l.r('payroll_periods.php','POST',{action:'ajukan',project_id:A,tgl:'2026-10-25',pj_nama:'Arya'}));
 await R('periode 23-25','payroll_periods.php?project_id='+A+'&tgl=2026-10-25');
 await R('periode susulan','payroll_periods.php?project_id='+A+'&tgl=2026-10-27');
 await R('kasbon_plan minggu 2','payroll_periods.php?project_id='+A+'&tgl=2026-10-25&kasbon_plan=1');
 rec('setujui minggu 2',await k.r('payroll_periods.php','POST',{action:'setujui',project_id:A,tgl:'2026-10-25'}));
 await R('rekap minggu 2','payroll.php?project_id='+A+'&tgl_from=2026-10-23&tgl_to=2026-10-25');
 await R('kasbon buku 2','kasbon.php');
 await R('daftar periode akhir','payroll_periods.php');
 await R('rekap semua akhir','payroll.php?tgl_from=2026-10-16&tgl_to=2026-10-29');
 rec('absen terkunci',await day(a,A,'2026-10-21',[{worker_id:w1,hari:1,lembur_jam:0}]));
 // pengajuan dengan tarif nol harus ditolak (w4 LAIN tanpa tarif)
 for(const d of ['2026-10-09']) await day(a,A,d,[{worker_id:w4,hari:1,lembur_jam:0}]);
 rec('ajukan tarif nol',await l.r('payroll_periods.php','POST',{action:'ajukan',project_id:A,tgl:'2026-10-09',pj_nama:'Arya'}));
 rec('setujui tarif nol',await k.r('payroll_periods.php','POST',{action:'setujui',project_id:A,tgl:'2026-10-09'}));
 const s=JSON.stringify(LOG,null,1);
 if(process.argv[2]==='simpan'){fs.writeFileSync(OUT,s);console.log('golden disimpan:',Object.keys(LOG).length,'respons');return}
if(process.env.DUMP)fs.writeFileSync(process.env.DUMP,s);
 const g=JSON.parse(fs.readFileSync(OUT,'utf8'));let bad=0;
 for(const key of new Set([...Object.keys(g),...Object.keys(LOG)])){if(JSON.stringify(g[key])!==JSON.stringify(LOG[key])){bad++;console.log('BEDA:',key);if(bad<4){console.log(' lama:',JSON.stringify(g[key]).slice(0,400));console.log(' baru:',JSON.stringify(LOG[key]).slice(0,400))}}}
 console.log(bad?'GOLDEN GAGAL: '+bad+' beda':'GOLDEN LULUS: '+Object.keys(LOG).length+' respons identik');process.exit(bad?1:0);
})();
