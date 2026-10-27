// Uji beban: banyak proyek/data + banyak pengguna bersamaan. Butuh data besar (lihat README) dan php -S dengan PHP_CLI_SERVER_WORKERS.
const {C}=require('./lib.js');
const pct=(a,p)=>{const s=[...a].sort((x,y)=>x-y);return s[Math.min(s.length-1,Math.floor(s.length*p))]};
const fmt=a=>`median ${pct(a,0.5)} ms | p95 ${pct(a,0.95)} ms | terburuk ${Math.max(...a)} ms`;
const timed=async(c,p,m,b)=>{const s=Date.now();const r=await c.r(p,m,b);return {ms:Date.now()-s,s:r.s,j:r.j}};
(async()=>{
 const a=new C(),k=new C();await a.login('adminp','AdminTest123');await k.login('akun1','AkuntanTest123');
 console.log('== A. Satu pengguna, data besar (82 proyek, 2.400 pekerja, 132 ribu baris absen)');
 for(const [n,c,p] of [['Rekap gaji SEMUA proyek, 30 hari (Akuntan)',k,'payroll.php?tgl_from=2026-09-27&tgl_to=2026-10-26'],['Rekap gaji SEMUA proyek, 60 hari (Akuntan)',k,'payroll.php?tgl_from=2026-08-28&tgl_to=2026-10-26'],['Rekap 1 proyek, 1 minggu (Akuntan)',k,'payroll.php?project_id=pr010&tgl_from=2026-10-16&tgl_to=2026-10-22'],['Daftar 2.400 pekerja (Admin)',a,'workers.php?all=1'],['Daftar periode gaji',k,'payroll_periods.php'],['Daftar proyek (82)',a,'projects.php']]){
  const ts=[];let sz=0,st=0;for(let i=0;i<4;i++){const r=await timed(c,p);ts.push(r.ms);st=r.s;sz=JSON.stringify(r.j).length}
  console.log('  '+n.padEnd(48),fmt(ts),'|',(sz/1024).toFixed(0)+' KB','| status',st);}
 console.log('== B. 60 pengguna lapangan login SERENTAK (bcrypt) lalu memuat layar Absen dan menyimpan, 3 putaran, bersama 4 Akuntan membuka rekap');
 const us=Array.from({length:60},(_,i)=>({c:new C(),u:'lapx'+String(i+1).padStart(3,'0')}));
 let t0=Date.now();const lg=await Promise.all(us.map(x=>timed(x.c,'auth_login.php','POST',{username:x.u,password:'LapanganTest123'})));
 console.log('  login serentak 60 orang:',fmt(lg.map(r=>r.ms)),'| gagal',lg.filter(r=>r.s!==200).length,'| total',Date.now()-t0,'ms');
 const proj={};for(const x of us){const r=await x.c.r('projects.php');x.p=r.j[0].id;}
 const lat={load:[],save:[],rekap:[]};let err=0,n=0;
 for(let round=0;round<3;round++){
  const jobs=us.map(async x=>{
   const w=await timed(x.c,'workers.php?project_id='+x.p);lat.load.push(w.ms);if(w.s!==200)err++;
   const at=await timed(x.c,'attendance.php?project_id='+x.p+'&tgl_from=2026-10-23&tgl_to=2026-10-29');lat.load.push(at.ms);if(at.s!==200)err++;
   const pp=await timed(x.c,'payroll_periods.php?project_id='+x.p+'&tgl=2026-10-27');lat.load.push(pp.ms);if(pp.s!==200)err++;
   const entries=w.j.slice(0,30).map(q=>({worker_id:q.id,hari:1,lembur_jam:0}));
   const sv=await timed(x.c,'attendance.php','POST',{project_id:x.p,tgl:'2026-10-2'+(5+round),entries});lat.save.push(sv.ms);if(sv.s!==200){err++;if(err<4)console.log('   gagal simpan',sv.s,JSON.stringify(sv.j).slice(0,100))}n+=4;});
  const rk=[0,1,2,3].map(async()=>{const r=await timed(k,'payroll.php?tgl_from=2026-09-27&tgl_to=2026-10-26');lat.rekap.push(r.ms);if(r.s!==200)err++;n++});
  await Promise.all([...jobs,...rk]);}
 console.log('  memuat layar Absen (3 request)  :',fmt(lat.load));
 console.log('  menyimpan absen 30 pekerja      :',fmt(lat.save));
 console.log('  Akuntan membuka rekap 30 hari   :',fmt(lat.rekap));
 console.log('  total permintaan',n+60,'| error',err);
})();
