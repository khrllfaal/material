// Bukti Pembayaran: nomor berurut tanpa ganda/lubang (juga saat bayar bersamaan), isi bukti = angka rekap, akses.
const {C}=require('./lib.js');
const R=[];const ok=(n,c,d='')=>R.push((c?'PASS':'FAIL')+' | '+n+(d?' | '+String(d).slice(0,170):''));
(async()=>{const a=new C(),k=new C(),o=new C(),l=new C();await a.login('adminp','AdminTest123');await k.login('akun1','AkuntanTest123');await o.login('owner1','OwnerTest123');await l.login('lap1','LapanganTest123');
 const D='2026-10-20';
 const prep=async(P,names)=>{await a.r('projects.php','POST',{id:P,nama:'Proyek '+P});await a.r('labor_rates.php','POST',{project_id:P,grade:'TK',tarif_harian:150000,tarif_lembur:15000});
  const ws=[];for(const n of names){ws.push((await a.r('workers.php','POST',{project_id:P,nama:n,grade:'TK',tim:'Tim A'})).j.id)}
  await a.r('attendance.php','POST',{project_id:P,tgl:D,entries:ws.map((w,i)=>({worker_id:w,hari:1,lembur_jam:i===0?2:0}))});
  await k.r('payroll_periods.php','POST',{action:'ajukan',project_id:P,tgl:D,pj_nama:'PJ'});await k.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:D});return ws};
 // A. dua kali bayar pada satu periode: nomor 0001 dan 0002, isi benar
 const ws=await prep('pbA',['Asep','Budi']);
 let x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:'pbA',tgl:D,tgl_bayar:'2026-10-28',metode:'transfer',items:[{worker_id:ws[0],jumlah:100000}]});
 const n1=x.j.bukti_no;ok('bayar pertama menghasilkan nomor BP/2026/10/0001',n1==='BP/2026/10/0001',JSON.stringify(x.j));
 x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:'pbA',tgl:D,tgl_bayar:'2026-10-29',metode:'tunai',semua:true});
 const n2=x.j.bukti_no;ok('bayar kedua (semua sisa) menghasilkan nomor 0002 dan periode lunas',n2==='BP/2026/10/0002'&&x.j.lunas===true,JSON.stringify(x.j));
 let b1=await k.r('payroll_periods.php?action=bukti&bukti_no='+encodeURIComponent(n1)),b2=await k.r('payroll_periods.php?action=bukti&bukti_no='+encodeURIComponent(n2));
 ok('bukti 1: satu penerima (Asep) dibayar 100.000; sisa setelahnya 180.000 (gaji 150.000 + lembur 2 jam x 15.000 = 180.000? tidak: Asep diterima 180.000 sisa 80.000)',b1.s===200&&b1.j.lines.length===1&&b1.j.lines[0].diterima===180000&&b1.j.lines[0].dibayar_ini===100000&&b1.j.lines[0].sebelumnya===0&&b1.j.lines[0].sisa_setelah===80000,JSON.stringify(b1.j.lines));
 const ln=Object.fromEntries(b2.j.lines.map(l=>[l.nama,l]));
 ok('bukti 2: Asep dibayar sisa 80.000 (sebelumnya 100.000, sisa 0); Budi dibayar penuh 150.000',ln.Asep.dibayar_ini===80000&&ln.Asep.sebelumnya===100000&&ln.Asep.sisa_setelah===0&&ln.Budi.dibayar_ini===150000&&ln.Budi.sebelumnya===0&&ln.Budi.sisa_setelah===0,JSON.stringify(b2.j.lines.map(l=>[l.nama,l.sebelumnya,l.dibayar_ini,l.sisa_setelah])));
 ok('total bukti = jumlah baris (230.000) dan metode/tanggal/pembuat benar',b2.j.total===230000&&b2.j.metode==='tunai'&&b2.j.tgl_bayar==='2026-10-29'&&b2.j.dibuat_oleh==='Akuntan'&&b2.j.project_nama==='Proyek pbA',JSON.stringify([b2.j.total,b2.j.metode,b2.j.tgl_bayar,b2.j.dibuat_oleh]));
 ok('bukti 1 tetap menunjukkan keadaan saat itu setelah bukti 2 terbit (sisa_setelah 80.000)',(await k.r('payroll_periods.php?action=bukti&bukti_no='+encodeURIComponent(n1))).j.lines[0].sisa_setelah===80000);
 const rek=await k.r('payroll.php?project_id=pbA&tgl_from=2026-10-16&tgl_to=2026-10-22');
 ok('jumlah semua bukti = total dibayar di rekap',rek.j.totals.dibayar===b1.j.total+b2.j.total,rek.j.totals.dibayar+' vs '+(b1.j.total+b2.j.total));
 ok('setiap pembayaran di rekap membawa nomor bukti',rek.j.periods[0].payments.every(p=>/^BP\/2026\/10\/000[12]$/.test(p.bukti_no)),JSON.stringify(rek.j.periods[0].payments.map(p=>p.bukti_no)));
 // B. bulan lain: penghitung sendiri
 const wsB=await prep('pbB',['Cici']);x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:'pbB',tgl:D,tgl_bayar:'2026-09-30',metode:'tunai',semua:true});
 ok('tanggal bayar September: nomor BP/2026/09/0001 (penghitung per bulan)',x.j.bukti_no==='BP/2026/09/0001',JSON.stringify(x.j));
 // C. bayar bersamaan pada 8 periode berbeda: nomor unik dan tanpa lubang
 const Ps=[];for(let i=1;i<=8;i++){const P='pbC'+i;await prep(P,['W'+i]);Ps.push(P)}
 const rs=await Promise.all(Ps.map(P=>k.r('payroll_periods.php','POST',{action:'bayar',project_id:P,tgl:D,tgl_bayar:'2026-10-29',metode:'tunai',semua:true})));
 const nums=rs.map(r=>r.j.bukti_no).filter(Boolean).map(n=>+n.split('/')[3]).sort((a,b)=>a-b);
 ok('8 pembayaran serentak: semua berhasil, 8 nomor unik berurutan 0003..0010 tanpa lubang',rs.every(r=>r.s===200)&&nums.join(',')==='3,4,5,6,7,8,9,10',rs.map(r=>r.s).join(',')+' '+nums.join(','));
 // D. pembayaran gagal tidak menghabiskan nomor
 const wsD=await prep('pbD',['Dedi']);x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:'pbD',tgl:D,tgl_bayar:'2026-10-29',metode:'tunai',items:[{worker_id:wsD[0],jumlah:99999999}]});
 ok('pembayaran melebihi sisa ditolak (422)',x.s===422,x.s);
 x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:'pbD',tgl:D,tgl_bayar:'2026-10-29',metode:'tunai',semua:true});
 ok('pembayaran gagal tadi tidak menghabiskan nomor: berikutnya BP/2026/10/0011',x.j.bukti_no==='BP/2026/10/0011',JSON.stringify(x.j));
 // E. akses
 ok('bukti tidak ada: 404',(await k.r('payroll_periods.php?action=bukti&bukti_no=BP%2F2026%2F10%2F9999')).s===404);
 ok('Owner boleh melihat bukti',(await o.r('payroll_periods.php?action=bukti&bukti_no='+encodeURIComponent(n1))).s===200);
 ok('Admin Pusat boleh melihat bukti',(await a.r('payroll_periods.php?action=bukti&bukti_no='+encodeURIComponent(n1))).s===200);
 ok('Lapangan tidak boleh melihat bukti (403)',(await l.r('payroll_periods.php?action=bukti&bukti_no='+encodeURIComponent(n1))).s===403);
 ok('tanpa login: 401',(await new C().r('payroll_periods.php?action=bukti&bukti_no='+encodeURIComponent(n1))).s===401);
 console.log(R.join('\n'));console.log('== bukti: PASS '+R.filter(r=>r.startsWith('PASS')).length+' / FAIL '+R.filter(r=>r.startsWith('FAIL')).length);})();
