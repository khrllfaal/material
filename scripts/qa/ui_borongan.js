// Menu Borongan di layar: tim baru -> kontrak -> ajukan -> setujui -> termin -> setujui -> bayar -> bukti PDF -> buku besar -> jurnal; Owner hanya melihat.
const {chromium}=require('/opt/node22/lib/node_modules/playwright');const {C}=require('./lib.js');const {execSync}=require('child_process');const fs=require('fs');
const R=[];const ok=(n,c,d='')=>R.push((c?'PASS':'FAIL')+' | '+n+(d?' | '+String(d).slice(0,170):''));
const F='/tmp/claude-0/-home-user-prjk/626f22e2-ee5f-5bdd-9093-72109340d68f/scratchpad/bukti_borongan_uji.pdf';
(async()=>{const k=new C();await k.login('akun1','AkuntanTest123');
 const br=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});const ctx=await br.newContext({viewport:{width:1280,height:900},acceptDownloads:true});const d=await ctx.newPage();const errs=[];d.on('pageerror',e=>errs.push(e.message));
 await d.goto('http://127.0.0.1:8899/frontend/index.html');await d.fill('#loginEmail','akun1');await d.fill('#loginPassword','AkuntanTest123');await d.click('#loginSubmit');await d.waitForTimeout(2300);
 ok('menu "Borongan" ada di menu Akuntan',(await d.$('.nav-link[data-page="borongan"]'))!==null);
 await d.click('.nav-link[data-page="borongan"]');await d.waitForSelector('#bgTimBaru');
 let t=await d.evaluate(()=>document.getElementById('bgBody').innerText);ok('layar kosong: pesan jelas untuk membuat tim dulu',/Belum ada tim borongan/.test(t),t.replace(/\s+/g,' '));
 // tim baru
 await d.click('#bgTimBaru');await d.waitForSelector('#tb_n');await d.selectOption('#tb_p','proj_a');await d.fill('#tb_n','Tim Kaca Pak Ujang');await d.fill('#tb_r','BCA 1234567890');await d.click('#mSave');
 await d.waitForSelector('#bgBack',{timeout:8000});ok('tim dibuat dan langsung terbuka (buku besar tim)',/Tim Kaca Pak Ujang/.test(await d.evaluate(()=>document.getElementById('bgBody').innerText)));
 // kasbon tim 5jt via layar
 await d.click('[data-tab="kasbon"]');await d.click('#bgKasbonBaru');await d.waitForSelector('#kb_j');await d.fill('#kb_j','5000000');await d.fill('#kb_k','beli material');await d.click('#mSave');await d.waitForTimeout(1200);
 t=await d.evaluate(()=>document.getElementById('bgTab').innerText);ok('kasbon tim 5.000.000 tercatat di tab Kasbon',/beli material/.test(t)&&/5\.000\.000/.test(t));
 // kontrak
 await d.click('[data-tab="kontrak"]');await d.click('#bgKontrakTim');await d.waitForSelector('#kk_n');await d.fill('#kk_n','Pasang kaca lantai 1');await d.fill('#kk_v','100000000');await d.fill('#kk_r','5');await d.click('#mSave');
 await d.waitForSelector('[data-ka="ajukan"]',{timeout:8000});t=await d.evaluate(()=>document.getElementById('bgTab').innerText);
 ok('kontrak dibuat sebagai Draft: nomor BK/…/0001, nilai Rp 100.000.000, retensi 5%',/Draft/.test(t)&&/BK\/\d{4}\/\d{2}\/0001/.test(t)&&/100\.000\.000/.test(t)&&/retensi 5%/.test(t),t.replace(/\s+/g,' '));
 await d.click('[data-ka="ajukan"]');await d.waitForSelector('#mSave');await d.click('#mSave');await d.waitForSelector('[data-ka="setujui"]',{timeout:8000});
 ok('setelah diajukan: status Menunggu persetujuan dan tombol Setujui Kontrak',/Menunggu persetujuan/.test(await d.evaluate(()=>document.getElementById('bgTab').innerText)));
 await d.click('[data-ka="setujui"]');await d.waitForSelector('#mSave');await d.click('#mSave');await d.waitForSelector('[data-ka="termin"]',{timeout:8000});
 ok('setelah disetujui: status Berjalan, tombol + Termin',/Berjalan/.test(await d.evaluate(()=>document.getElementById('bgTab').innerText)));
 // termin
 await d.click('[data-ka="termin"]');await d.waitForSelector('#tm_b');await d.fill('#tm_b','30000000');await d.dispatchEvent('#tm_b','input');await d.waitForTimeout(200);
 const pv=await d.evaluate(()=>document.getElementById('tm_prev').innerText.replace(/\s+/g,' '));
 ok('pratinjau termin: retensi 1.500.000, potong kasbon 5.000.000, diterima 23.500.000',/Retensi 5%\s*Rp 1\.500\.000/.test(pv)&&/Potong kasbon\s*Rp 5\.000\.000/.test(pv)&&/Diterima tim\s*Rp 23\.500\.000/.test(pv),pv);
 await d.click('#mSave');await d.waitForSelector('[data-ta="setujui"]',{timeout:8000});
 await d.click('[data-ta="setujui"]');await d.waitForSelector('#mSave');await d.click('#mSave');await d.waitForSelector('[data-ta="bayar"]',{timeout:8000});
 await d.click('[data-ta="bayar"]');await d.waitForSelector('#by_d');await d.selectOption('#by_m','transfer');await d.click('#mSave');
 await d.waitForSelector('text=Pembayaran tercatat',{timeout:8000});const m=await d.evaluate(()=>document.getElementById('modalBody').innerText);
 const no=(m.match(/BP\/\d{4}\/\d{2}\/\d{4}/)||[''])[0];ok('setelah bayar: nomor bukti BP/…',/^BP\/\d{4}\/\d{2}\/0001$/.test(no),m.replace(/\s+/g,' '));
 const [dl]=await Promise.all([d.waitForEvent('download',{timeout:15000}),d.click('#mSave')]);await dl.saveAs(F);
 ok('PDF bukti borongan terunduh',/^Bukti_Borongan_BP-\d{4}-\d{2}-0001\.pdf$/.test(dl.suggestedFilename()),dl.suggestedFilename());
 const txt=execSync('pdftotext -layout '+F+' -').toString().replace(/[ \t]+/g,' ');
 ok('isi PDF: judul, nomor, tim, rekening, kontrak, termin ke-1',/BUKTI PEMBAYARAN BORONGAN/.test(txt)&&/BP\/\d{4}\/\d{2}\/0001/.test(txt)&&/Tim Kaca Pak Ujang/.test(txt)&&/BCA 1234567890/.test(txt)&&/Pasang kaca lantai 1/.test(txt)&&/Termin ke-1/.test(txt),txt.slice(0,500).replace(/\n/g,' | '));
 ok('isi PDF: nilai kontrak 100.000.000; termin 30.000.000; retensi (1.500.000); kasbon (5.000.000); jumlah dibayar 23.500.000; terbilang',/100\.000\.000/.test(txt)&&/30\.000\.000/.test(txt)&&/\(1\.500\.000\)/.test(txt)&&/\(5\.000\.000\)/.test(txt)&&/23\.500\.000/.test(txt)&&/dua puluh tiga juta lima ratus ribu rupiah/.test(txt));
 // buku besar
 await d.waitForTimeout(800);await d.click('[data-tab="buku"]');await d.waitForSelector('#bgXls');t=await d.evaluate(()=>document.getElementById('bgTab').innerText.replace(/\s+/g,' '));
 ok('buku besar: kontrak 100jt, dibayar 23,5jt, potong kasbon 5jt, saldo akhir 71.500.000',/Kontrak BK/.test(t)&&/23\.500\.000/.test(t)&&/potong kasbon/.test(t)&&/SALDO AKHIR\s*Rp 71\.500\.000/.test(t),t.slice(0,420));
 await d.click('[data-tab="kasbon"]');t=await d.evaluate(()=>document.getElementById('bgTab').innerText.replace(/\s+/g,' '));ok('tab Kasbon: potongan dari termin tercatat, saldo kasbon 0',/Potong dari termin 1/.test(t)&&/Rp 0/.test(t),t.slice(0,300));
 // Rekap & Pembukuan: jurnal
 await d.click('.nav-link[data-page="rekap_gaji"]');await d.waitForSelector('#gjTabs',{timeout:8000});
 await d.evaluate(()=>{const s=document.getElementById('gjPreset');s.value='30hari';s.dispatchEvent(new Event('change'))});await d.waitForTimeout(1500);
 await d.click('[data-tab="buku"], #gjTabs button:has-text("Pembukuan")').catch(()=>{});await d.waitForTimeout(800);
 const jt=await d.evaluate(()=>document.getElementById('tab_buku').innerText.replace(/\s+/g,' '));
 ok('Pembukuan & Jurnal memuat jurnal borongan (Beban Borongan, Hutang Borongan, Hutang Retensi, Piutang Kasbon)',/Beban Borongan/.test(jt)&&/Hutang Borongan/.test(jt)&&/Hutang Retensi/.test(jt)&&/Piutang Kasbon/.test(jt),jt.slice(0,300));
 const tot=jt.match(/TOTAL\s*([\d.]+)\s*([\d.]+)/);ok('jurnal seimbang: total debit = total kredit',tot&&tot[1]===tot[2],tot&&tot[0]);
 // Pekerja & Tarif -> kontrak
 await d.click('.nav-link[data-page="pekerja"]');await d.waitForSelector('[data-w-kontrak]',{timeout:8000});await d.click('[data-w-kontrak]');await d.waitForSelector('#bgBack',{timeout:8000});
 ok('Pekerja & Tarif: tombol "Kontrak & pembayaran" membuka tim di menu Borongan',/Tim Kaca Pak Ujang/.test(await d.evaluate(()=>document.getElementById('bgBody').innerText)));
 ok('tidak ada error halaman (Akuntan)',errs.length===0,errs.join('|'));
 // Owner: hanya melihat
 const ctx2=await br.newContext({viewport:{width:1280,height:900}});const o=await ctx2.newPage();const e2=[];o.on('pageerror',e=>e2.push(e.message));
 await o.goto('http://127.0.0.1:8899/frontend/index.html');await o.fill('#loginEmail','owner1');await o.fill('#loginPassword','OwnerTest123');await o.click('#loginSubmit');await o.waitForTimeout(2300);
 await o.click('.nav-link[data-page="borongan"]');await o.waitForSelector('[data-bt]',{timeout:8000});
 ok('Owner: melihat daftar tim, tanpa tombol buat tim/kontrak',(await o.$('#bgKontrakBaru'))===null&&(await o.$('#bgTimBaru'))===null);
 await o.click('[data-bt]');await o.waitForSelector('#bgBack');await o.click('[data-ko]').catch(()=>{});await o.waitForTimeout(400);
 ok('Owner: detail tim tanpa tombol aksi (setujui/bayar/termin/addendum)',(await o.$$('[data-ka],[data-ta="setujui"],[data-ta="bayar"],[data-aa]')).length===0&&(await o.$('#bgKontrakTim'))===null);
 ok('tidak ada error halaman (Owner)',e2.length===0,e2.join('|'));
 await br.close();console.log(R.join('\n'));console.log('== ui borongan: PASS '+R.filter(r=>r.startsWith('PASS')).length+' / FAIL '+R.filter(r=>r.startsWith('FAIL')).length);})();
