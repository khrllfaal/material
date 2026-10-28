// Pembatas percobaan masuk per IP + header CF palsu diabaikan. Database baru; membersihkan tabel login_attempts sendiri.
const {execSync}=require('child_process');const B='http://127.0.0.1:8899/backend/api/';
const R=[];const ok=(n,c,d='')=>R.push((c?'PASS':'FAIL')+' | '+n+(d?' | '+String(d).slice(0,140):''));
const login=async(u,p,h={})=>{const r=await fetch(B+'auth_login.php',{method:'POST',headers:{'Content-Type':'application/json',...h},body:JSON.stringify({username:u,password:p})});let j=null;try{j=await r.json()}catch{}return {s:r.status,j}};
(async()=>{execSync("mysql -uroot material -e \"DELETE FROM login_attempts\" 2>/dev/null || true");
 let x=await login('akun1','AkuntanTest123');ok('login benar berjalan',x.s===200,x.s);
 const st=[];for(let i=0;i<20;i++){st.push((await login('tidak_ada'+i,'salah')).s)}
 ok('20 percobaan salah ke 20 nama berbeda (tebak banyak akun) dijawab 401',st.every(s=>s===401),st.join(','));
 x=await login('tidak_ada99','salah',{'CF-Connecting-IP':'8.8.8.8'});ok('percobaan ke-21: 429 meski header CF-Connecting-IP palsu dikirim',x.s===429&&/Terlalu banyak/.test(x.j.error),x.s+' '+JSON.stringify(x.j));
 x=await login('owner1','OwnerTest123');ok('login benar dari IP yang sama ikut tertahan (sementara)',x.s===429,x.s);
 execSync("mysql -uroot material -e \"DELETE FROM login_attempts; UPDATE users SET failed_attempts=0, locked_until=NULL\"");
 x=await login('owner1','OwnerTest123');ok('setelah jendela berlalu (tabel dibersihkan) login normal lagi',x.s===200,x.s);
 const n=execSync("mysql -uroot material -N -e \"SELECT COUNT(*) FROM login_attempts\"").toString().trim();ok('login berhasil tidak menambah catatan gagal',n==='0',n);
 console.log(R.join('\n'));console.log('== keamanan: PASS '+R.filter(r=>r.startsWith('PASS')).length+' / FAIL '+R.filter(r=>r.startsWith('FAIL')).length);})();
