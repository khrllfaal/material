// Tim borongan dikelola pusat saja: lapangan tidak mengisi absen sama sekali.
const {C,ok,done}=require('./lib.js');
(async()=>{const a=new C(),k=new C(),l=new C();
 await a.login('adminp','AdminTest123');await k.login('akun1','AkuntanTest123');await l.login('lap1','LapanganTest123');
 const P='proj_a',D='2026-10-27';
 let x=await k.r('workers.php','POST',{project_id:P,nama:'Tim Alumunium',borongan:true,no_rek:'BCA 999'});const alu=x.j.id;ok('Akuntan membuat tim borongan (tanpa lapangan)',x.s===200&&x.j.borongan===1,JSON.stringify(x.j));
 x=await k.r('payroll_periods.php','POST',{action:'ajukan',project_id:P,tgl:D,pj_nama:'Akuntan'});ok('tanpa absen dan tanpa nominal: ajukan ditolak',x.s===422,JSON.stringify(x.j));
 x=await k.r('payroll_adjustments.php','POST',{project_id:P,tgl:D,jenis:'tambah',kategori:'tambahan',label:'Borongan rangka jendela',worker_id:alu,jumlah:8000000});ok('Akuntan mengisi nominal borongan 8.000.000',x.s===200,JSON.stringify(x.j));
 x=await k.r('kasbon.php','POST',{project_id:P,tgl:'2026-10-26',worker_id:alu,jumlah:1000000,ket:'kasbon tim'});ok('kasbon tim 1.000.000',x.s===200);
 x=await k.r('payroll.php?project_id='+P+'&tgl_from=2026-10-23&tgl_to=2026-10-29');const r=x.j.rows.find(w=>w.worker_id===alu);ok('baris tim tampil walau tanpa absen; tanpa peringatan nominal kosong',r&&r.net===8000000&&!r.flags.includes('borongan_kosong'),JSON.stringify(r&&[r.net,r.flags]));
 x=await k.r('payroll_periods.php','POST',{action:'ajukan',project_id:P,tgl:D,pj_nama:'Akuntan'});ok('Akuntan mengajukan minggu yang hanya berisi borongan',x.s===200,JSON.stringify(x.j));
 x=await k.r('payroll_periods.php','POST',{action:'setujui',project_id:P,tgl:D});ok('disetujui; kasbon tim 1.000.000 terpotong otomatis',x.s===200&&x.j.potong_kasbon===1000000,JSON.stringify(x.j));
 x=await k.r('payroll_periods.php','POST',{action:'bayar',project_id:P,tgl:D,tgl_bayar:D,metode:'transfer',semua:true});ok('dibayar 7.000.000 sampai lunas',x.s===200&&x.j.dibayar===7000000&&x.j.lunas===true,JSON.stringify(x.j));
 x=await l.r('workers.php?project_id='+P);ok('lapangan tidak melihat uang; tim borongan hanya berupa nama',(x.j||[]).every(w=>!('tarif_harian' in w)));
 done('borongan pusat');})();
