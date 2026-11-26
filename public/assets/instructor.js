'use strict';
(()=>{
const $=id=>document.getElementById(id);
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let csrf='';
async function api(action,data=null){
 const options=data===null?{credentials:'same-origin'}:{method:'POST',credentials:'same-origin',
   headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)};
 const r=await fetch('/?action='+encodeURIComponent(action),options);
 const x=await r.json();
 if(!r.ok)throw Error(x.error||'操作に失敗しました');
 return x;
}
async function refresh(){
 const data=await api('instructorMySlots');
 $('teacherLoginArea').hidden=true;$('teacherScheduleArea').hidden=false;
 $('teacherWelcome').textContent=data.teacher.name+'先生';
 const form=$('teacherSlotForm');form.querySelector('button').disabled=!data.assigned;
 if(!data.assigned)$('teacherSlotStatus').textContent='企業案件の担当講師に登録されていません。管理者にご確認ください。';
 const slots=data.slots||[];
 $('teacherSlots').innerHTML=slots.length?'<div class="slot-table"><table><thead><tr><th>日時</th><th>状態</th><th>操作</th></tr></thead><tbody>'+
 slots.map(s=>'<tr><td>'+esc(s.starts_at.slice(0,16))+'</td><td>'+esc(s.state)+'</td><td>'+
 (s.state==='空き'?'<button type="button" class="secondary" data-delete="'+Number(s.id)+'">削除</button>':'予約済みのため変更不可')+
 '</td></tr>').join('')+'</tbody></table></div>':'<p>登録済みの予約枠はありません。</p>';
 $('teacherSlots').querySelectorAll('[data-delete]').forEach(b=>b.onclick=async()=>{
   if(!confirm('この空き枠を削除しますか？'))return;
   try{await api('instructorSlotDelete',{id:Number(b.dataset.delete)});await refresh();}
   catch(e){$('teacherSlotStatus').textContent=e.message;}
 });
}
$('teacherLoginForm').onsubmit=async e=>{
 e.preventDefault();const button=e.target.querySelector('button');button.disabled=true;
 $('teacherLoginStatus').textContent='';
 try{await api('instructorLogin',Object.fromEntries(new FormData(e.target)));e.target.reset();await refresh();}
 catch(err){$('teacherLoginStatus').textContent=err.message;}
 finally{button.disabled=false;}
};
$('teacherSlotForm').onsubmit=async e=>{
 e.preventDefault();const button=e.target.querySelector('button');button.disabled=true;
 $('teacherSlotStatus').textContent='';
 try{await api('instructorSlotAdd',Object.fromEntries(new FormData(e.target)));await refresh();$('teacherSlotStatus').textContent='空き枠を登録しました。';}
 catch(err){$('teacherSlotStatus').textContent=err.message;}
 finally{button.disabled=false;}
};
$('teacherBatchForm').onsubmit=async e=>{
 e.preventDefault();const form=e.target;
 const button=form.querySelector('button[type=submit]');button.disabled=true;
 const status=$('teacherBatchStatus');status.textContent='';
 const v=Object.fromEntries(new FormData(form));
 v.days=[...form.querySelectorAll('input[name=days]:checked')].map(x=>Number(x.value));
 v.hours=[...form.querySelectorAll('input[name=hours]:checked')].map(x=>Number(x.value));
 if(!v.days.length||!v.hours.length){status.textContent='曜日と開始時間を選択してください';button.disabled=false;return;}
 try{const r=await api('instructorSlotBatch',v);await refresh();status.textContent='新しく'+r.created+'件の空き枠を登録しました。';}
 catch(err){status.textContent=err.message;}
 finally{button.disabled=false;}
};
$('teacherLogout').onclick=async()=>{await api('instructorLogout',{});location.reload();};
(async()=>{
 try{const config=await api('config');csrf=config.csrf;await refresh();}
 catch(e){$('teacherLoginArea').hidden=false;$('teacherScheduleArea').hidden=true;}
})();
})();