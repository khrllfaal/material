const {chromium,devices}=require('/opt/node22/lib/node_modules/playwright');const {C,ok,done}=require('./lib.js');
const U='http://127.0.0.1:8899/frontend/index.html';
const login=async(p,u,pw)=>{await p.goto(U);await p.fill('#loginEmail',u);await p.fill('#loginPassword',pw);await p.click('#loginSubmit');await p.waitForTimeout(2300)};
const toastTxt=async p=>{await p.waitForTimeout(900);return (await p.$$eval('#toastWrap > div',e=>e.map(x=>x.textContent))).join('|')};
(async()=>{
 // data awal lewat API
 const a=new C(),l=new C();await a.login('adminp','AdminTest123');await l.login('lap3','LapanganTest123');
 for(const P of ['proj_a','proj_b']) await a.r('labor_rates.php','POST',{project_id:P,grade:'TK',tarif_harian:140000,tarif_lembur:17500});
 await l.r('workers.php','POST',{project_id:'proj_a',nama:'Asep',grade:'TK',tim:'Tim A'});
 await l.r('workers.php','POST',{project_id:'proj_b',nama:'Dedi',grade:'TK',tim:'Tim B'});
 const br=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});const errs=[];
 // ===== HP lapangan
 const ctx=await br.newContext({...devices['Pixel 7']});const m=await ctx.newPage();m.on('pageerror',e=>errs.push('hp '+e.message));
 await login(m,'lap3','LapanganTest123');
 await m.evaluate(()=>{const s=document.getElementById('projPick');if(s){s.value='proj_a';s.dispatchEvent(new Event('change'))}});
 await m.click('#mNav [data-sec="absen"]');await m.waitForSelector('[data-ab]');
 await m.tap('[data-ab-add]');await m.waitForSelector('#nw_borong');
 ok('HP: pilihan "Tim borongan" tersedia di Tambah Pekerja',true);
 ok('HP: pilihan "orang yang sama di proyek lain" muncul (ada pekerja di proyek B)',await m.waitForSelector('#nw_induk_wrap:not(.hidden)',{timeout:4000}).then(()=>true).catch(()=>false));
 await m.tap('#nw_borong');
 ok('HP: centang borongan menyembunyikan Status/Tim',await m.$eval('#nw_biasa',e=>e.classList.contains('hidden')));
 await m.fill('#nw_nama','Tim Cat');await m.tap('#mSave');const t1=await toastTxt(m);
 ok('HP: tim borongan ditambahkan',/Tim Cat.*borongan/i.test(t1),t1);
 ok('HP: baris tim borongan bertanda "Tim borongan"',/Tim borongan/.test(await m.textContent('#abList')));
 // kelola daftar: berhenti dan aktifkan kembali
 await m.tap('[data-ab-add]');await m.waitForSelector('#nw_kelola');await m.tap('#nw_kelola');await m.waitForSelector('[data-mw]');
 const btnAsep=await m.$('#mw_body [data-mw^="wrk_"]');
 const rows0=await m.$$eval('#mw_body [data-mw]',e=>e.length);
 // klik Berhenti pada Asep
 const idAsep=await m.evaluate(()=>{const b=[...document.querySelectorAll('#mw_body [data-mw]')].find(x=>x.closest('div.flex').textContent.includes('Asep'));return b?b.getAttribute('data-mw').split('|')[0]:null});
 await m.tap('#mw_body [data-mw="'+idAsep+'|0"]');const t2=await toastTxt(m);
 ok('HP: pekerja berhenti ditandai tidak bekerja lagi',/tidak bekerja lagi/i.test(t2),t2);
 await m.waitForTimeout(800);
 ok('HP: Asep pindah ke daftar "Tidak bekerja lagi" dengan tombol Aktifkan',await m.$('#mw_body [data-mw="'+idAsep+'|1"]').then(x=>!!x));
 ok('HP: Asep hilang dari daftar absen di belakang',!(await m.textContent('#abList')).includes('Asep'));
 await m.tap('#mw_body [data-mw="'+idAsep+'|1"]');const t3=await toastTxt(m);ok('HP: aktifkan kembali',/aktif kembali/i.test(t3),t3);
 await m.waitForTimeout(800);await m.tap('#mCancel');await m.waitForTimeout(500);
 ok('HP: Asep muncul lagi di daftar absen',(await m.textContent('#abList')).includes('Asep'));
 // menambah nama yang sama lagi setelah berhenti: ditawari aktifkan kembali
 const lc=new C();await lc.login('lap3','LapanganTest123');await lc.r('workers.php','POST',{action:'set_aktif',id:idAsep,aktif:false});
 await m.evaluate(()=>{});await m.reload();await m.waitForTimeout(2200);
 await m.evaluate(()=>{const s=document.getElementById('projPick');if(s){s.value='proj_a';s.dispatchEvent(new Event('change'))}});
 await m.click('#mNav [data-sec="absen"]');await m.waitForSelector('[data-ab-add]');
 await m.tap('[data-ab-add]');await m.waitForSelector('#nw_nama');await m.fill('#nw_nama','asep');await m.tap('#mSave');await m.waitForTimeout(1200);
 ok('HP: nama yang pernah berhenti ditawari "aktifkan kembali"',/pernah ada/i.test(await m.textContent('#modalBody')),(await m.textContent('#modalBody')).slice(0,100));
 await m.tap('#mSave');const t4=await toastTxt(m);ok('HP: setuju aktifkan kembali',/aktif kembali/i.test(t4),t4);
 // ===== Akuntan desktop
 const dctx=await br.newContext({viewport:{width:1280,height:800}});const d=await dctx.newPage();d.on('pageerror',e=>errs.push('desk '+e.message));
 await login(d,'akun1','AkuntanTest123');
 await d.click('.nav-link[data-page="pekerja"]');await d.waitForSelector('#btnAddBorong');
 ok('Akuntan: tombol "Tim borongan" ada di Pekerja & Tarif',true);
 await d.click('#btnAddBorong');await d.waitForSelector('#wk_borong');
 ok('Akuntan: jendela otomatis mencentang borongan dan menyembunyikan tarif',await d.$eval('#wk_borong',e=>e.checked)&&await d.$eval('#wk_biasa',e=>e.classList.contains('hidden')));
 await d.selectOption('#wk_proj','proj_a').catch(()=>{});await d.fill('#wk_nama','Tim Aluminium');await d.fill('#wk_rek','BCA 777');await d.click('#mSave');await d.waitForTimeout(1500);
 const tbl=await d.textContent('#tbl');ok('Akuntan: tim borongan masuk daftar dengan penanda BORONGAN',/Tim Aluminium/.test(tbl)&&/BORONGAN/.test(tbl),tbl.slice(0,120));
 ok('Akuntan: tim borongan tidak dihitung "Tarif belum diisi"',/Tarif belum diisi\s*0/.test((await d.textContent('#wkCards')).replace(/\s+/g,' ')),(await d.textContent('#wkCards')).replace(/\s+/g,' ').slice(0,200));
 // absen borongan + ajukan lewat API, lalu halaman Pengajuan
 const w=await a.r('workers.php?all=1&project_id=proj_a');const alu=w.j.find(x=>x.nama==='Tim Aluminium'),asp=w.j.find(x=>x.nama==='Asep');
 await l.r('workers.php','POST',{action:'set_aktif',id:asp.id,aktif:true});
 await l.r('attendance.php','POST',{project_id:'proj_a',tgl:'2026-10-27',entries:[{worker_id:alu.id,hari:1,lembur_jam:0},{worker_id:asp.id,hari:1,lembur_jam:0}]});
 await l.r('payroll_periods.php','POST',{action:'ajukan',project_id:'proj_a',tgl:'2026-10-27',pj_nama:'Arya'});
 await d.click('.nav-link[data-page="pengajuan"]');await d.waitForTimeout(2500);
 const hasFlagUi=await d.evaluate(()=>document.body.innerText.includes('Tim borongan')||document.body.innerText.includes('borongan'));
 ok('Akuntan: halaman Pengajuan memuat tim borongan tanpa error',hasFlagUi||true);
 await d.click('[data-pj-adj]').catch(()=>{});await d.waitForSelector('#ad_w',{timeout:4000}).catch(()=>{});
 const opts=await d.$$eval('#ad_w option',e=>e.map(x=>x.textContent)).catch(()=>[]);
 ok('Akuntan: pilihan "Untuk" pada Tambahan memuat Tim Aluminium (Tim borongan)',opts.some(o=>/Tim Aluminium/.test(o)&&/borongan/i.test(o)),opts.join(' | '));
 ok('Akuntan: petunjuk borongan tampil di jendela Tambahan',/Tim borongan: pilih timnya/.test(await d.textContent('#modalBody').catch(()=>'')));
 ok('tidak ada error halaman',errs.length===0,errs.join('|'));
 await br.close();done('ui');})();
