// Audit keamanan dengan data uji (mengacu pada daftar periksa cloudflare/security-audit-skill: sesi & pencabutan, isolasi data, DOM XSS, kehabisan sumber daya).
// Jalankan pada database baru setelah up_env.sh. Hanya memakai akun uji.
const {chromium}=require('/opt/node22/lib/node_modules/playwright');const fs=require('fs');const {C}=require('./lib.js');
const R=[];const ok=(n,c,d='')=>R.push((c?'PASS':'FAIL')+' | '+n+(d?' | '+String(d).slice(0,170):''));
(async()=>{const a=new C(),k=new C(),o=new C(),l1=new C(),l2=new C(),anon=new C();
 await a.login('adminp','AdminTest123');await k.login('akun1','AkuntanTest123');await o.login('owner1','OwnerTest123');await l1.login('lap1','LapanganTest123');await l2.login('lap2','LapanganTest123');
 // ---- A. sesi dan pencabutan
 const mk=async(u,role,extra={})=>{const x=await a.r('users.php','POST',{username:u,password:'SandiUji12345',nama:u,role,...extra});return x.j&&x.j.id};
 const idDel=await mk('tmp_hapus','lapangan',{projectIds:['proj_a']});const sDel=new C();await sDel.login('tmp_hapus','SandiUji12345');
 ok('sebelum dihapus: sesi bekerja',(await sDel.r('workers.php?project_id=proj_a')).s===200);
 await a.r('users.php?id='+idDel,'DELETE');
 ok('akun dihapus: sesi lamanya langsung tidak berlaku (401)',(await sDel.r('workers.php?project_id=proj_a')).s===401,(await sDel.r('workers.php?project_id=proj_a')).s);
 const idRole=await mk('tmp_admin','admin');const sRole=new C();await sRole.login('tmp_admin','SandiUji12345');
 await a.r('users.php','POST',{id:idRole,username:'tmp_admin',nama:'tmp_admin',role:'owner'});
 const w=await sRole.r('workers.php','POST',{project_id:'proj_a',nama:'Dari Sesi Lama',grade:'TK'});
 ok('peran diturunkan admin->owner: sesi lama tidak bisa menulis (403)',w.s===403,w.s);
 const idPw=await mk('tmp_pw','akuntan');const sPw=new C();await sPw.login('tmp_pw','SandiUji12345');
 await a.r('users.php','POST',{id:idPw,username:'tmp_pw',nama:'tmp_pw',role:'akuntan',password:'SandiBaru123456'});
 ok('kata sandi diganti: sesi lama tidak berlaku lagi (401)',(await sPw.r('workers.php')).s===401,(await sPw.r('workers.php')).s);
 ok('kata sandi baru bisa dipakai login',(await new C().login('tmp_pw','SandiBaru123456')).s===200);
 // proyek dicabut dari lapangan
 const idPr=await mk('tmp_lap','lapangan',{projectIds:['proj_a']});const sPr=new C();await sPr.login('tmp_lap','SandiUji12345');
 await a.r('users.php','POST',{id:idPr,username:'tmp_lap',nama:'tmp_lap',role:'lapangan',projectIds:['proj_b']});
 {const q=await sPr.r('workers.php?project_id=proj_a');ok('proyek dicabut dari lapangan: daftar proyek lama kosong / ditolak',q.s===403||(q.s===200&&Array.isArray(q.j)&&q.j.length===0),q.s+' '+JSON.stringify(q.j).slice(0,60));const w2=await sPr.r('workers.php','POST',{project_id:'proj_a',nama:'Tembus',grade:'TK'});ok('proyek dicabut dari lapangan: tambah pekerja ke proyek lama ditolak (403)',w2.s===403,w2.s)}
 // ---- B. isolasi data antar peran/proyek
 await l1.r('workers.php','POST',{project_id:'proj_a',nama:'RahasiaA',grade:'KTK',tim:'Tim A'});
 await k.r('labor_rates.php','POST',{project_id:'proj_a',grade:'KTK',tarif_harian:777000,tarif_lembur:1000});
 const endpoints=fs.readdirSync('../../backend/api').filter(f=>f.endsWith('.php')&&!['config.sample.php','db.php','helpers.php','payroll_lib.php','resource_crud.php','scoped_resource.php','auth_login.php','auth_logout.php','auth_me.php','config.php'].includes(f));
 let bocorProyek=[],bocorUang=[],anonBolong=[],ownerTulis=[];
 for(const e of endpoints){
  let x=await anon.r(e);if(x.s!==401)anonBolong.push(e+':'+x.s);
  x=await l2.r(e+'?project_id=proj_a');const t=JSON.stringify(x.j||'');if(x.s===200&&(t.includes('RahasiaA')||t.includes('"proj_a"')||t.includes('Proyek A')))bocorProyek.push(e);
  x=await l1.r(e);const t1=JSON.stringify(x.j||'');if(x.s===200&&/777000|tarif_harian|no_rek|"net"|"gaji"/.test(t1))bocorUang.push(e);
  x=await o.r(e,'POST',{project_id:'proj_a',nama:'x'});if(x.s===200)ownerTulis.push(e);
 }
 ok('tanpa login: semua endpoint 401',!anonBolong.length,anonBolong.join(' '));
 ok('lapangan proyek B tidak menerima data proyek A dari endpoint mana pun',!bocorProyek.length,bocorProyek.join(' '));
 ok('lapangan tidak menerima nominal uang/tarif/rekening dari endpoint mana pun',!bocorUang.length,bocorUang.join(' '));
 ok('Owner (hanya-baca) tidak bisa menulis lewat endpoint mana pun',!ownerTulis.length,ownerTulis.join(' '));
 let akLuar=[];for(const e of endpoints){const x=await k.r(e);if(x.s===200&&!['projects.php','workers.php','labor_rates.php','attendance.php','payroll.php','payroll_periods.php','payroll_adjustments.php','kasbon.php','kasbon_requests.php'].includes(e))akLuar.push(e)}
 ok('Akuntan tidak bisa membuka endpoint di luar daftar tugasnya',!akLuar.length,akLuar.join(' '));
 for(const e of ['users.php','audit_log.php']){for(const [n,c] of [['lapangan',l1],['akuntan',k],['owner',o]]){const x=await c.r(e);if(e==='users.php'||(e==='audit_log.php'&&n==='lapangan'))ok(e+' tertutup bagi '+n,x.s===403,x.s)}}
 // ---- C. DOM XSS: isi nama/keterangan dengan payload lalu buka layar
 const P='<img src=x onerror="window.__xss=(window.__xss||0)+1">';
 const wP=(await a.r('workers.php','POST',{project_id:'proj_a',nama:P,grade:'TK',tim:P,no_rek:P})).j;
 await k.r('payroll_adjustments.php','POST',{project_id:'proj_a',tgl:'2026-10-22',jenis:'tambah',kategori:'tambahan',label:P,worker_id:wP.id,jumlah:1000});
 await k.r('kasbon.php','POST',{project_id:'proj_a',tgl:'2026-10-20',worker_id:wP.id,jumlah:5000,ket:P});
 await l1.r('kasbon_requests.php','POST',{project_id:'proj_a',worker_id:wP.id,jumlah:1000,ket:P});
 await a.r('projects.php','POST',{id:'proj_x',nama:P,lokasi:P}).catch(()=>{});
 await a.r('attendance.php','POST',{project_id:'proj_a',tgl:'2026-10-22',entries:[{worker_id:wP.id,hari:1,lembur_jam:0,ket:P}]});
 const br=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
 const visit=async(user,pw,mobile,pages)=>{const ctx=await br.newContext(mobile?{viewport:{width:390,height:800},isMobile:true,hasTouch:true}:{viewport:{width:1280,height:900}});const p=await ctx.newPage();let dlg=0;p.on('dialog',d=>{dlg++;d.dismiss()});
  await p.goto('http://127.0.0.1:8899/frontend/index.html');await p.fill('#loginEmail',user);await p.fill('#loginPassword',pw);await p.click('#loginSubmit');await p.waitForTimeout(2300);
  for(const pg of pages){const sel=pg[0]==='#'||pg[0]==='['?pg:'.nav-link[data-page="'+pg+'"]';await p.click(sel,{timeout:2500}).catch(()=>{});await p.waitForTimeout(1300);
   if(pg==='pengajuan'){await p.click('[data-pj-adj]',{timeout:2500}).catch(()=>{});await p.waitForTimeout(500);await p.keyboard.press('Escape')}}
  const x=await p.evaluate(()=>window.__xss||0);await ctx.close();return x+dlg};
 const xa=await visit('akun1','AkuntanTest123',false,['pengajuan','rekap','pekerja']);
 const xp=await visit('adminp','AdminTest123',false,['dashboard','pekerja','proyek','pengguna','log','audit']);
 const xl=await visit('lap1','LapanganTest123',true,['#mNav [data-sec="absen"]','#mNav [data-sec="bahan"]','#mNav [data-sec="stok"]']);
 ok('XSS: Akuntan membuka Pengajuan/Rekap/Pekerja dengan payload di data: tidak ada skrip berjalan',xa===0,xa);
 ok('XSS: Admin Pusat membuka halaman utama dan master: tidak ada skrip berjalan',xp===0,xp);
 ok('XSS: Lapangan (HP) membuka Absen/Bahan/Stok: tidak ada skrip berjalan',xl===0,xl);
 await br.close();
 // ---- D. kehabisan sumber daya
 let t=Date.now();let x=await k.r('payroll.php?tgl_from=1990-01-01&tgl_to=2100-12-31');const dt=Date.now()-t;
 ok('rekap dengan rentang 110 tahun ditolak atau dijawab cepat (<3 dtk)',x.s===422||dt<3000,x.s+' '+dt+'ms');
 x=await a.r('audit_log.php?limit=99999999');ok('log audit dengan limit raksasa dibatasi',x.s===200&&(Array.isArray(x.j)?x.j.length:(x.j.rows||[]).length)<=1000,JSON.stringify(x.j).length+' byte');
 const big=await fetch('http://127.0.0.1:8899/backend/api/workers.php',{method:'POST',headers:{'Content-Type':'application/json',Cookie:a.c},body:JSON.stringify({project_id:'proj_a',nama:'x'.repeat(8*1024*1024),grade:'TK'})});
 ok('badan permintaan 8 MB tidak membuat server error 500',big.status!==500,big.status);
 x=await a.r('attendance.php','POST',{project_id:'proj_a',tgl:'2026-10-22',entries:Array.from({length:401},()=>({worker_id:'x',hari:1}))});ok('absen >400 baris ditolak',x.s===422);
 console.log(R.join('\n'));console.log('== audit keamanan: PASS '+R.filter(r=>r.startsWith('PASS')).length+' / FAIL '+R.filter(r=>r.startsWith('FAIL')).length);})();
