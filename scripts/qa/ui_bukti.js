// Layar: catat pembayaran -> tawaran cetak bukti -> PDF terunduh; isi PDF diperiksa (pdftotext). Juga uji terbilang.
const {chromium}=require('/opt/node22/lib/node_modules/playwright');const {C}=require('./lib.js');const {execSync}=require('child_process');const fs=require('fs');
const R=[];const ok=(n,c,d='')=>R.push((c?'PASS':'FAIL')+' | '+n+(d?' | '+String(d).slice(0,170):''));
(async()=>{const a=new C(),k=new C();await a.login('adminp','AdminTest123');await k.login('akun1','AkuntanTest123');
 const P='pbX',D='2026-10-20';await a.r('projects.php','POST',{id:P,nama:'Bina Marga Kodim Bangun Jalan Cibalong'});await a.r('labor_rates.php','POST',{project_id:P,grade:'TK',tarif_harian:175000,tarif_lembur:20000});
 const ws=[];for(const n of ['Asep Sunandar','Budi Hartono']) ws.push((await a.r('workers.php','POST',{project_id:P,nama:n,grade:'TK',tim:'Tim Pondasi',no_rek:'BCA 1234567'})).j.id);
 await a.r('attendance.php','POST',{project_id:P,tgl:D,entries:ws.map((w,i)=>({worker_id:w,hari:1,lembur_jam:i===0?2:0}))});
 await k.r('payroll_periods.php','POST',{action:'ajukan',project_id:P,tgl:D,pj_nama:'Pak Budi'});await k.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:D});
 const br=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});const ctx=await br.newContext({viewport:{width:1280,height:900},acceptDownloads:true});const d=await ctx.newPage();const errs=[];d.on('pageerror',e=>errs.push(e.message));
 await d.goto('http://127.0.0.1:8899/frontend/index.html');await d.fill('#loginEmail','akun1');await d.fill('#loginPassword','AkuntanTest123');await d.click('#loginSubmit');await d.waitForTimeout(2300);
 // terbilang
 const tb=await d.evaluate(()=>[0,1,11,12,19,20,21,99,100,101,111,200,999,1000,1001,1999,2000,10000,11000,100000,150000,1234567,2500000,10000000,1000000000,1500000000].map(n=>[n,terbilang(n)]));
 const exp={0:'nol',1:'satu',11:'sebelas',12:'dua belas',19:'sembilan belas',20:'dua puluh',21:'dua puluh satu',99:'sembilan puluh sembilan',100:'seratus',101:'seratus satu',111:'seratus sebelas',200:'dua ratus',999:'sembilan ratus sembilan puluh sembilan',1000:'seribu',1001:'seribu satu',1999:'seribu sembilan ratus sembilan puluh sembilan',2000:'dua ribu',10000:'sepuluh ribu',11000:'sebelas ribu',100000:'seratus ribu',150000:'seratus lima puluh ribu',1234567:'satu juta dua ratus tiga puluh empat ribu lima ratus enam puluh tujuh',2500000:'dua juta lima ratus ribu',10000000:'sepuluh juta',1000000000:'satu miliar',1500000000:'satu miliar lima ratus juta'};
 const salah=tb.filter(([n,t])=>exp[n]!==t);ok('terbilang benar untuk 26 angka (0, belasan, ratusan, ribuan, juta, miliar)',!salah.length,JSON.stringify(salah));
 await d.click('.nav-link[data-page="pengajuan"]');await d.waitForSelector('[data-pj-bayar]',{timeout:8000});
 await d.click('[data-pj-bayar]');await d.waitForSelector('#py_tgl');await d.fill('#py_tgl','2026-10-27');await d.click('#mSave');
 await d.waitForSelector('text=Pembayaran tercatat',{timeout:8000});
 const m=await d.evaluate(()=>document.getElementById('modalBody').innerText);ok('setelah bayar muncul tawaran cetak dengan nomor BP/2026/10/0001',/BP\/2026\/10\/0001/.test(m),m.replace(/\s+/g,' '));
 const [dl]=await Promise.all([d.waitForEvent('download',{timeout:15000}),d.click('#mSave')]);
 const f='/tmp/claude-0/-home-user-prjk/626f22e2-ee5f-5bdd-9093-72109340d68f/scratchpad/bukti_uji.pdf';await dl.saveAs(f);
 ok('PDF terunduh dengan nama Bukti_Pembayaran_BP-2026-10-0001.pdf',dl.suggestedFilename()==='Bukti_Pembayaran_BP-2026-10-0001.pdf',dl.suggestedFilename());
 const buf=fs.readFileSync(f);ok('berkas adalah PDF valid',buf.slice(0,5).toString()==='%PDF-'&&buf.length>2000,buf.length);
 const txt=execSync('pdftotext -layout '+f+' -').toString().replace(/[ \t]+/g,' ');
 ok('isi: judul, nomor, proyek, periode, PJ, tanggal & metode',/BUKTI PEMBAYARAN UPAH/.test(txt)&&/BP\/2026\/10\/0001/.test(txt)&&/Bina Marga Kodim Bangun Jalan Cibalong/.test(txt)&&/16 Okt – 22 Okt 2026/.test(txt)&&/Pak Budi/.test(txt)&&/27 Oktober 2026/.test(txt)&&/Tunai/.test(txt),txt.slice(0,400).replace(/\n/g,' | '));
 ok('isi: kedua penerima dengan rekening, diterima 215.000 dan 175.000, total 390.000',/Asep Sunandar/.test(txt)&&/Budi Hartono/.test(txt)&&/BCA 1234567/.test(txt)&&/215\.000/.test(txt)&&/175\.000/.test(txt)&&/390\.000/.test(txt));
 ok('isi: terbilang "tiga ratus sembilan puluh ribu rupiah" dan kolom tanda tangan',/tiga ratus sembilan puluh ribu rupiah/.test(txt)&&/Penerima/.test(txt)&&/Dibayar oleh/.test(txt)&&/Mengetahui/.test(txt));
 // tombol bukti pada periode lunas
 await d.waitForTimeout(800);await d.click('[data-chip=""]');await d.waitForTimeout(500);
 ok('periode lunas: tombol "Bukti Pembayaran" tersedia dan bisa dicetak ulang',(await d.$('[data-pj-bukti]'))!==null);
 const [dl2]=await Promise.all([d.waitForEvent('download',{timeout:15000}),d.click('[data-pj-bukti]')]);ok('cetak ulang menghasilkan berkas yang sama namanya',dl2.suggestedFilename()==='Bukti_Pembayaran_BP-2026-10-0001.pdf');
 ok('tidak ada error halaman',errs.length===0,errs.join('|'));
 await br.close();console.log(R.join('\n'));console.log('== ui bukti: PASS '+R.filter(r=>r.startsWith('PASS')).length+' / FAIL '+R.filter(r=>r.startsWith('FAIL')).length);})();
