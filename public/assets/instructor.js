'use strict';
(()=>{
const $=id=>document.getElementById(id);
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let csrf='';
let monthCursor='2026-12';
let currentSlots=[];
function jpToday(){
 return new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tokyo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
}
function renderCalendar(){
 const monthEl=$('teacherCalendarMonth'),grid=$('teacherCalendarGrid');
 if(!monthEl||!grid)return;
 const [y,m]=monthCursor.split('-').map(Number);
 const first=new Date(Date.UTC(y,m-1,1,12));
 const days=new Date(Date.UTC(y,m,0,12)).getUTCDate();
 const offset=first.getUTCDay();
 monthEl.textContent=y+'年'+m+'月';
 let html=['日','月','火','水','木','金','土'].map(x=>'<div class="teacher-day-head">'+x+'</div>').join('');
 for(let i=0;i<offset;i++)html+='<div aria-hidden="true"></div>';
 const today=jpToday();
 const selected=$('teacherSlotForm')?.elements.date?.value||'';
 for(let n=1;n<=days;n++){
   const date=monthCursor+'-'+String(n).padStart(2,'0');
   const booked=currentSlots.filter(x=>x.starts_at.startsWith(date)&&x.state!=='空き').length;
   const open=currentSlots.filter(x=>x.starts_at.startsWith(date)&&x.state==='空き').length;
   const disabled=date<'2026-12-20'||date>'2027-02-28'||
      (date>='2026-12-28'&&date<='2027-01-03')||date<today;
   html+='<button type="button" class="teacher-cal-day'+(date===selected?' selected':'')+'" data-day="'+date+'"'+(disabled?' disabled':'')+'>'+
     '<strong>'+n+'</strong><span>空き '+open+(booked?' / 予約 '+booked:'')+'</span></button>';
 }
 grid.innerHTML=html;
 grid.querySelectorAll('[data-day]').forEach(b=>b.onclick=()=>{
   $('teacherSlotForm').elements.date.value=b.dataset.day;
   renderCalendar();
 });
 const min='2026-12',max='2027-02';
 $('teacherCalendarPrev').disabled=monthCursor<=min;
 $('teacherCalendarNext').disabled=monthCursor>=max;
}
function moveMonth(by){
 const [y,m]=monthCursor.split('-').map(Number);
 const next=new Date(Date.UTC(y,m-1+by,1,12));
 monthCursor=next.getUTCFullYear()+'-'+String(next.getUTCMonth()+1).padStart(2,'0');
 renderCalendar();
}
$('teacherCalendarPrev').onclick=()=>moveMonth(-1);
$('teacherCalendarNext').onclick=()=>moveMonth(1);
$('teacherSlotForm').elements.date.addEventListener('change',e=>{
 if(e.target.value)monthCursor=e.target.value.slice(0,7);
 renderCalendar();
});

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
 currentSlots=slots;
 const currentMonth=jpToday().slice(0,7);
 if(currentMonth>'2026-12' && currentMonth<='2027-02' && monthCursor==='2026-12')
   monthCursor=currentMonth;
 if(!monthCursor || monthCursor<'2026-12' || monthCursor>'2027-02')monthCursor='2026-12';
 renderCalendar();
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