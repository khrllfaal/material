const {chromium,devices}=require('/opt/node22/lib/node_modules/playwright');
const U='http://127.0.0.1:8899/frontend/index.html';const R=[];const ok=(n,c,d='')=>R.push((c?'PASS':'FAIL')+' | '+n+(d?' | '+String(d).slice(0,160):''));
(async()=>{const br=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
 for(const [u,pw] of [['adminp','AdminTest123'],['akun1','AkuntanTest123'],['owner1','OwnerTest123']]){
  const ctx=await br.newContext({viewport:{width:1280,height:800}});const p=await ctx.newPage();const errs=[],bad=[];
  p.on('pageerror',e=>errs.push(e.message));p.on('response',r=>{if(r.status()>=400&&!/favicon|auth_me/.test(r.url()))bad.push(r.status()+' '+r.url().split('/').slice(-1)[0])});
  await p.goto(U);await p.fill('#loginEmail',u);await p.fill('#loginPassword',pw);await p.click('#loginSubmit');await p.waitForTimeout(2300);
  const pages=await p.$$eval('.nav-link[data-page]',e=>e.map(x=>x.getAttribute('data-page')));
  for(const pg of pages){await p.click('.nav-link[data-page="'+pg+'"]').catch(()=>{});await p.waitForTimeout(900);}
  ok(u+': '+pages.length+' halaman dibuka tanpa error halaman',errs.length===0,errs.join('|'));ok(u+': tanpa respons gagal',bad.length===0,bad.join(' | '));
  await ctx.close();}
 // 3 layar HP: lapangan
 for(const name of ['Pixel 7','Galaxy S9+','iPhone SE','iPhone 13']){
  const d=devices[name];const ctx=await br.newContext({...d});const p=await ctx.newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message));
  await p.goto(U);await p.fill('#loginEmail','lap2');await p.fill('#loginPassword','LapanganTest123');await p.click('#loginSubmit');await p.waitForTimeout(2300);
  await p.evaluate(()=>{const s=document.getElementById('projPick');if(s){s.value='proj_b';s.dispatchEvent(new Event('change'))}});
  await p.click('#mNav [data-sec="absen"]');await p.waitForSelector('[data-ab]');
  const o1=await p.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);
  await p.tap('[data-ab-add]');await p.waitForSelector('#nw_borong');const o2=await p.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);
  await p.tap('#nw_borong');const o3=await p.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);
  await p.tap('#nw_kelola');await p.waitForSelector('[data-mw]');const o4=await p.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);
  ok(name+': Absen, Tambah Pekerja (biasa & borongan), Kelola daftar: tanpa geser samping',Math.max(o1,o2,o3,o4)<=1,[o1,o2,o3,o4].join('/'));
  ok(name+': tanpa error halaman',errs.length===0,errs.join('|'));await ctx.close();}
 await br.close();console.log(R.join('\n'));console.log('== crawl+HP: PASS '+R.filter(r=>r.startsWith('PASS')).length+' / FAIL '+R.filter(r=>r.startsWith('FAIL')).length);})();
