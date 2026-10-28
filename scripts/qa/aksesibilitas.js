// Audit tampilan: kontras teks (WCAG 4,5:1), tombol berikon tanpa nama, isian tanpa label, ukuran sentuh di HP. Hanya membaca; tidak mengubah data.
const {chromium,devices}=require('/opt/node22/lib/node_modules/playwright');
const U='http://127.0.0.1:8899/frontend/index.html';
const AUDIT=()=>{
  const parse=c=>{const m=c.match(/rgba?\(([^)]+)\)/);if(!m)return null;const p=m[1].split(/[ ,\/]+/).filter(Boolean).map(Number);return {r:p[0],g:p[1],b:p[2],a:p.length>3?p[3]:1}};
  const lum=({r,g,b})=>{const f=v=>{v/=255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4)};return 0.2126*f(r)+0.7152*f(g)+0.0722*f(b)};
  const blend=(f,b)=>({r:f.r*f.a+b.r*(1-f.a),g:f.g*f.a+b.g*(1-f.a),b:f.b*f.a+b.b*(1-f.a),a:1});
  const bgOf=el=>{let layers=[];for(let e=el;e;e=e.parentElement){const c=parse(getComputedStyle(e).backgroundColor);if(c&&c.a>0){layers.push(c);if(c.a>=1)break}}let base={r:255,g:255,b:255,a:1};for(let i=layers.length-1;i>=0;i--)base=blend(layers[i],base);return base};
  const out={kontras:[],tanpaNama:[],tanpaLabel:[],kecil:[]};
  const vis=el=>{const r=el.getBoundingClientRect();const s=getComputedStyle(el);return r.width>0&&r.height>0&&s.visibility!=='hidden'&&s.display!=='none'&&s.opacity!=='0'};
  const seen=new Set();
  document.querySelectorAll('body *').forEach(el=>{
    if(['SCRIPT','STYLE','svg','path'].includes(el.tagName))return;
    const own=[...el.childNodes].filter(n=>n.nodeType===3&&n.textContent.trim()).map(n=>n.textContent.trim()).join(' ');
    if(!own||!vis(el))return;
    const s=getComputedStyle(el);let fg=parse(s.color);if(!fg)return;const bg=bgOf(el);
    let op=1;for(let e=el;e;e=e.parentElement)op*=parseFloat(getComputedStyle(e).opacity);fg={...fg,a:fg.a*op};fg=blend(fg,bg);
    const L1=lum(fg),L2=lum(bg),ratio=(Math.max(L1,L2)+0.05)/(Math.min(L1,L2)+0.05);
    const px=parseFloat(s.fontSize),bold=parseInt(s.fontWeight)>=700,big=px>=24||(px>=18.66&&bold);
    if(ratio<(big?3:4.5)){const k=el.className+'|'+s.color;if(!seen.has(k)){seen.add(k);out.kontras.push({teks:own.slice(0,40),rasio:+ratio.toFixed(2),kelas:String(el.className).slice(0,60)})}}
  });
  document.querySelectorAll('button,a,[role=button]').forEach(el=>{
    if(!vis(el))return;const nama=(el.innerText||'').trim()||el.getAttribute('aria-label')||el.getAttribute('title');
    if(!nama)out.tanpaNama.push(el.outerHTML.slice(0,90));
    const r=el.getBoundingClientRect();if(r.height<40||r.width<40){if((el.innerText||'').trim().length>2&&r.height>=36)return;out.kecil.push(Math.round(r.width)+'x'+Math.round(r.height)+' '+((el.innerText||el.getAttribute('aria-label')||el.title||'').trim().slice(0,20)))}
  });
  document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea').forEach(el=>{
    if(!vis(el))return;const id=el.id;const lab=(id&&document.querySelector('label[for="'+id+'"]'))||el.closest('label');
    if(!lab&&!el.getAttribute('aria-label')&&!el.getAttribute('title'))out.tanpaLabel.push((el.id||el.name||el.type)+' '+(el.placeholder||''));
  });
  return out;
};
(async()=>{const br=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});const tot={kontras:0,tanpaNama:0,tanpaLabel:0,kecil:0};const ex={kontras:{},tanpaNama:{},tanpaLabel:{},kecil:{}};
 const run=async(label,ctxOpt,user,pw,pages)=>{const ctx=await br.newContext(ctxOpt);const p=await ctx.newPage();await p.goto(U);await p.fill('#loginEmail',user);await p.fill('#loginPassword',pw);await p.click('#loginSubmit');await p.waitForTimeout(2300);
  for(const pg of pages){const sel=pg.startsWith('#')||pg.startsWith('.')||pg.startsWith('[')?pg:'.nav-link[data-page="'+pg+'"]';await p.click(sel).catch(()=>{});await p.waitForTimeout(1200);const o=await p.evaluate(AUDIT);
   for(const k of Object.keys(tot)){tot[k]+=o[k].length;for(const e of o[k]){const key=typeof e==='string'?e:JSON.stringify(e);ex[k][key]=(ex[k][key]||0)+1}}}
  await ctx.close()};
 await run('akuntan',{viewport:{width:1280,height:900}},'akun1','AkuntanTest123',['pengajuan','rekap','pekerja']);
 await run('admin',{viewport:{width:1280,height:900}},'adminp','AdminTest123',['dashboard','pekerja','proyek'].filter(Boolean));
 await run('lapangan HP',{...devices['Pixel 7']},'lap2','LapanganTest123',['#mNav [data-sec="absen"]','#mNav [data-sec="bahan"]','#mNav [data-sec="stok"]']);
 console.log('TOTAL temuan:',JSON.stringify(tot));
 for(const k of Object.keys(ex)){const top=Object.entries(ex[k]).sort((a,b)=>b[1]-a[1]).slice(0,+(process.env.TOP||8));console.log('\n'+k+' (jenis unik '+Object.keys(ex[k]).length+')');top.forEach(([e,n])=>console.log('  x'+n+' '+e.slice(0,150)))}
 await br.close()})();
