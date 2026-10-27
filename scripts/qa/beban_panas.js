// Titik panas: banyak orang menyimpan absen proyek yang SAMA di hari yang SAMA bersamaan (butuh data dari beban.js).
// Uji beban: banyak proyek/data + banyak pengguna bersamaan. Butuh data besar (lihat README) dan php -S dengan PHP_CLI_SERVER_WORKERS.
const {C}=require('./lib.js');
const pct=(a,p)=>{const s=[...a].sort((x,y)=>x-y);return s[Math.min(s.length-1,Math.floor(s.length*p))]};
const fmt=a=>`median ${pct(a,0.5)} ms | p95 ${pct(a,0.95)} ms | terburuk ${Math.max(...a)} ms`;
const timed=async(c,p,m,b)=>{const s=Date.now();const r=await c.r(p,m,b);return {ms:Date.now()-s,s:r.s,j:r.j}};
(async()=>{
 const a=new C();await a.login('adminp','AdminTest123');
 const ws=(await a.r('workers.php?project_id=pr001')).j;
 for(const N of [20,60]){
  const t0=Date.now();
  const res=await Promise.all(Array.from({length:N},(_,i)=>timed(a,'attendance.php','POST',{project_id:'pr001',tgl:'2026-10-20',entries:ws.slice(0,30).map(q=>({worker_id:q.id,hari:(i%2?1:0.5),lembur_jam:0}))})));
  const codes={};res.forEach(r=>codes[r.s]=(codes[r.s]||0)+1);
  console.log(N+' penyimpanan bersamaan, proyek dan hari yang sama:',fmt(res.map(r=>r.ms)),'| status',JSON.stringify(codes),'| total',Date.now()-t0,'ms');}
})();
