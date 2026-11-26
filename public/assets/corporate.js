'use strict';
(()=>{
const $=id=>document.getElementById(id);
const escapeHtml=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const state={user:null,teachers:[],teacher:null,slot:null,csrf:'',requireEmailVerification:false};
async function request(action,payload){
  const response=await fetch('/?action='+encodeURIComponent(action),{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrf},
    body:JSON.stringify(payload)
  });
  const data=await response.json();
  if(!response.ok)throw Error(data.error||'通信に失敗しました');
  return data;
}
function show(page){
  for(let i=1;i<=3;i++){
    $('step'+i).hidden=page!==i;
    $('step'+i+'label').classList.toggle('active',page===i);
  }
  $('complete').hidden=page!=='complete';
  $('emailVerification').hidden=page!=='verify';
}
async function start(){
  try{
    const response=await fetch('/?action=config',{credentials:'same-origin'});
    const data=await response.json();
    if(!response.ok)throw Error(data.error||'設定を読み込めません');
    state.csrf=data.csrf;
    const policyResponse=await fetch('/?action=corporateEmailPolicy',{credentials:'same-origin'});
    if(!policyResponse.ok)throw Error('メール確認設定を取得できません');
    const policy=await policyResponse.json();
    state.requireEmailVerification=Boolean(policy.verification_required);
  }catch(e){$('identityError').textContent=e.message;$('identity').querySelector('button').disabled=true;}
  const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tokyo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  $('consultDate').min=today>'2026-12-20'?today:'2026-12-20';
  $('consultDate').value=$('consultDate').min;
}
$('identity').addEventListener('submit',async event=>{
  event.preventDefault();
  const form=event.currentTarget;
  if(!form.reportValidity())return;
  const button=form.querySelector('button[type=submit]');
  button.disabled=true;$('identityError').textContent='';
  try{
    const user=Object.fromEntries(new FormData(form));
    state.user=user;
    if(state.requireEmailVerification){
      await request('corporateEmailSendCode',user);
      $('verificationDestination').textContent='送信先：'+user.email;
      $('verificationForm').reset();
      $('verificationError').textContent='';
      show('verify');
    }else{
      await finishIdentity();
    }
  }catch(e){$('identityError').textContent=e.message;}
  finally{button.disabled=false;}
});
async function finishIdentity(){
    const result=await request('corporateAccess',state.user);
    state.teachers=result.teachers||[];
    if(!state.teachers.length)throw Error('現在公開されている先生がいません。事務局へご確認ください');
    $('teacherCards').innerHTML=state.teachers.map(t=>'<article class="teacher">'+
       (t.photo_url?'<img src="'+escapeHtml(t.photo_url)+'" alt="'+escapeHtml(t.name)+'先生の写真" loading="lazy">':'<div class="no-photo">写真準備中</div>')+
       '<div class="teacher-text"><h3>'+escapeHtml(t.name)+'</h3><p>'+escapeHtml(t.summary||t.career?.slice(0,200)||'詳しいプロフィールは準備中です')+'</p></div>'+
       '<button type="button" data-teacher="'+Number(t.id)+'">この先生の空き時間を探す</button></article>').join('');
    document.querySelectorAll('[data-teacher]').forEach(btn=>btn.addEventListener('click',()=>{
      state.teacher=state.teachers.find(t=>Number(t.id)===Number(btn.dataset.teacher));
      state.slot=null;
      $('selectedTeacherLabel').textContent=state.teacher.name+'先生の空き時間';
      show(3);loadSlots();
    }));
    show(2);
}
$('verificationForm').addEventListener('submit',async e=>{
  e.preventDefault();
  const button=e.currentTarget.querySelector('button');
  button.disabled=true;
  $('verificationError').textContent='';
  try{
    await request('corporateEmailVerifyCode',{code:new FormData(e.currentTarget).get('code')});
    await finishIdentity();
  }catch(err){$('verificationError').textContent=err.message;}
  finally{button.disabled=false;}
});
$('verificationBack').addEventListener('click',()=>show(1));
async function loadSlots(){
  state.slot=null;$('confirmBooking').disabled=true;$('chosenSlot').hidden=true;
  $('bookingMessage').textContent='';
  const date=$('consultDate').value;
  if(!date||!state.teacher||!state.user)return;
  $('consultSlots').textContent='空き枠を確認しています…';
  try{
    const result=await request('corporateAvailability',{
      access_code:state.user.access_code,teacher_id:Number(state.teacher.id),date
    });
    if(date!==$('consultDate').value)return;
    const slots=result.slots||[];
    $('consultSlots').innerHTML=slots.length?slots.map(slot=>'<button type="button" data-slot="'+Number(slot.id)+'">'+escapeHtml(slot.time)+'</button>').join(''):
      '<p>この先生の空き枠はありません。別の日か、別の先生をお選びください。</p>';
    $('consultSlots').querySelectorAll('[data-slot]').forEach(btn=>btn.addEventListener('click',()=>{
      state.slot=slots.find(s=>Number(s.id)===Number(btn.dataset.slot));
      $('consultSlots').querySelectorAll('button').forEach(el=>el.classList.toggle('selected',el===btn));
      $('chosenSlot').hidden=false;
      $('chosenSlot').textContent=state.teacher.name+'先生 / '+date+' '+state.slot.time+'〜'+
       String(Number(state.slot.time.slice(0,2))+1).padStart(2,'0')+':00';
      $('confirmBooking').disabled=false;
    }));
  }catch(e){$('consultSlots').textContent='空き枠を取得できません：'+e.message;}
}
$('consultDate').addEventListener('change',loadSlots);
$('backToIdentity').addEventListener('click',()=>show(1));
$('backToTeachers').addEventListener('click',()=>{state.slot=null;show(2);});
$('confirmBooking').addEventListener('click',async()=>{
  if(!state.user||!state.teacher||!state.slot)return;
  const btn=$('confirmBooking');btn.disabled=true;
  $('bookingMessage').textContent='';
  try{
    const d=await request('corporateBook',{
      ...state.user,teacher_id:Number(state.teacher.id),slot_id:Number(state.slot.id)
    });
    $('confirmation').textContent='予約番号 '+d.booking_id+' / '+state.teacher.name+'先生 / '+d.date+' '+d.time+'開始（50分相談＋10分）';
    show('complete');
  }catch(e){
    $('bookingMessage').textContent=e.message;
    await loadSlots();
  }finally{btn.disabled=false;}
});
start();
})();