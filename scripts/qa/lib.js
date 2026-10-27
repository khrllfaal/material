const B='http://127.0.0.1:8899/backend/api/';
class C{constructor(){this.c=''}
 async r(p,m='GET',b){const res=await fetch(B+p,{method:m,headers:{'Content-Type':'application/json',Cookie:this.c},body:b?JSON.stringify(b):undefined});
  const sc=res.headers.get('set-cookie');if(sc)this.c=sc.split(';')[0];let j=null;try{j=await res.json()}catch{}return {s:res.status,j}}
 login(u,p){return this.r('auth_login.php','POST',{username:u,password:p})}}
const R=[];const ok=(n,c,d='')=>R.push((c?'PASS':'FAIL')+' | '+n+(d?' | '+String(d).slice(0,150):''));
const done=name=>{console.log(R.join('\n'));console.log('== '+name+': PASS '+R.filter(r=>r.startsWith('PASS')).length+' / FAIL '+R.filter(r=>r.startsWith('FAIL')).length)};
module.exports={C,ok,done};
