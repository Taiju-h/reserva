'use strict';
(function(){
const el=id=>document.getElementById(id);
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const days=['日','月','火','水','木','金','土'];
let state=null;
function opts(rows,placeholder){
  return '<option value="0">'+esc(placeholder)+'</option>'+rows.map(x=>'<option value="'+Number(x.id)+'">'+esc(x.name)+'</option>').join('');
}
function scopeTargets(){
  const scope=el('ruleScope').value;
  const target=el('ruleTargetRow');
  target.hidden=scope==='store';
  if(scope==='teacher')el('ruleTarget').innerHTML=opts(state.teachers.filter(x=>Number(x.active)), '先生を選択');
  else if(scope==='menu')el('ruleTarget').innerHTML=opts(state.menus,'メニューを選択');
}
function shortDays(text){return String(text).split(',').map(x=>days[Number(x)]).filter(Boolean).join('・');}
function draw(){
  const host=el('schedulingAdmin');if(!host||!state)return;
  const teacherRows=state.teachers.map(x=>'<tr><td>'+esc(x.name)+'</td><td>'+(Number(x.active)?'有効':'停止')+'</td><td><button type="button" class="secondary" data-teacher-toggle="'+Number(x.id)+'" data-active="'+(Number(x.active)?0:1)+'">'+(Number(x.active)?'停止':'再開')+'</button></td></tr>').join('');
  const menuRows=state.menus.map(m=>{
    const options=opts(state.teachers.filter(x=>Number(x.active)||Number(x.id)===Number(m.teacher_id)),'担当を指定しない');
    return '<div class="hana-schedule-menu"><span>'+esc(m.name)+'</span><select data-menu-teacher="'+Number(m.id)+'">'+options+'</select></div>';
  }).join('');
  const ruleRows=state.rules.filter(x=>Number(x.enabled)).map(r=>{
    let who=r.scope==='store'?'店舗全体':r.scope==='teacher'?state.teachers.find(t=>Number(t.id)===Number(r.target_id))?.name:state.menus.find(m=>Number(m.id)===Number(r.target_id))?.name;
    return '<div class="hana-schedule-rule"><div><strong>'+esc(r.decision==='deny'?'受付禁止':'受付許可')+'</strong> · '+esc(who||'削除された対象')+
      '<p>'+esc(r.label||'名称なし')+' / '+esc(shortDays(r.weekdays))+' / '+esc(r.start_time)+'〜'+esc(r.end_time)+
      ' / '+esc(r.start_date||'開始日なし')+'〜'+esc(r.end_date||'終了日なし')+'</p></div><button type="button" class="secondary" data-rule-remove="'+Number(r.id)+'">削除</button></div>';
  }).join('');
  host.innerHTML=
  '<div class="hana-schedule-guide"><strong>条件の優先順位</strong><p>店舗 → 先生 → メニューのすべての条件を照合します。「受付禁止」が1件でも該当すれば予約できません。「受付許可」を登録した階層は、その許可時間内だけ予約できます。営業時間、既存予約も別途適用します。</p></div>'+
  '<h3>1. 先生の登録</h3><form id="teacherForm" class="hana-inline-form"><input name="name" maxlength="100" placeholder="先生のお名前" required><button>先生を追加</button></form>'+
  '<div class="scroll"><table><thead><tr><th>先生</th><th>状態</th><th>操作</th></tr></thead><tbody>'+teacherRows+'</tbody></table></div>'+
  '<h3>2. 各メニューの担当先生</h3><p class="muted">メニューごとに先生を1人割り当てられます。担当を指定しないメニューには店舗とメニューの条件のみ適用します。</p>'+
  '<div class="hana-schedule-menu-list">'+menuRows+'</div>'+
  '<h3>3. 受付条件の追加</h3><form id="scheduleRuleForm">'+
  '<div class="row"><div><label>どこに適用しますか？</label><select id="ruleScope" name="scope"><option value="store">店舗全体</option><option value="teacher">先生</option><option value="menu">メニュー</option></select></div>'+
  '<div id="ruleTargetRow" hidden><label>対象</label><select id="ruleTarget" name="target_id"></select></div></div>'+
  '<div class="row"><div><label>条件の種類</label><select name="decision"><option value="deny">受付禁止（予約不可）</option><option value="allow">受付許可（予約可能な時間を限定）</option></select></div>'+
  '<div><label>条件名（例：毎週火曜休み）</label><input name="label" maxlength="120" placeholder="例：毎週火曜の午後は受付禁止"></div></div>'+
  '<div class="row"><div><label>開始日（空欄なら期限なし）</label><input name="start_date" type="date"></div><div><label>終了日（空欄なら期限なし）</label><input name="end_date" type="date"></div></div>'+
  '<fieldset class="hana-weekday-fieldset"><legend>該当する曜日</legend><div class="hana-weekdays">'+days.map((day,i)=>'<label><input type="checkbox" name="weekday" value="'+i+'" checked>'+day+'</label>').join('')+'</div></fieldset>'+
  '<div class="row"><div><label>開始時刻</label><input name="start_time" type="time" value="09:00" required></div><div><label>終了時刻</label><input name="end_time" type="time" value="18:00" required></div></div>'+
  '<button>この受付条件を登録</button><p id="scheduleRuleResult" role="status"></p></form>'+
  '<h3>4. 登録済みの条件</h3><div class="hana-schedule-rules">'+(ruleRows||'<p class="muted">受付条件は未登録です。従来の営業時間が適用されます。</p>')+'</div>';
  el('ruleScope').onchange=scopeTargets;scopeTargets();
  el('teacherForm').onsubmit=async e=>{
    e.preventDefault();
    try{await api('teacherSave',{},Object.fromEntries(new FormData(e.target)));await refresh();}catch(err){alert(err.message);}
  };
  document.querySelectorAll('[data-teacher-toggle]').forEach(b=>b.onclick=async()=>{
    if(!confirm('先生の有効・停止状態を変更しますか？'))return;
    try{await api('teacherStatus',{}, {id:Number(b.dataset.teacherToggle),active:Number(b.dataset.active)});await refresh();}catch(err){alert(err.message);}
  });
  document.querySelectorAll('[data-menu-teacher]').forEach(select=>{
    const menu=state.menus.find(x=>Number(x.id)===Number(select.dataset.menuTeacher));
    select.value=String(menu.teacher_id??0);
    select.onchange=async()=>{
      try{await api('assignMenuTeacher',{}, {menu_id:Number(select.dataset.menuTeacher),teacher_id:Number(select.value)});}catch(err){alert(err.message);await refresh();}
    };
  });
  el('scheduleRuleForm').onsubmit=async e=>{
    e.preventDefault();
    const f=e.target;
    const v=Object.fromEntries(new FormData(f));
    v.target_id=Number(v.target_id||0);
    v.weekdays=Array.from(f.querySelectorAll('input[name="weekday"]:checked')).map(x=>x.value);
    try{await api('scheduleRuleSave',{},v);await refresh();}catch(err){el('scheduleRuleResult').textContent=err.message;}
  };
  document.querySelectorAll('[data-rule-remove]').forEach(b=>b.onclick=async()=>{
    if(!confirm('この受付条件を削除しますか？'))return;
    try{await api('scheduleRuleDelete',{}, {id:Number(b.dataset.ruleRemove)});await refresh();}catch(err){alert(err.message);}
  });
}
async function refresh(){
  const target=el('schedulingAdmin');if(!target)return;
  try{state=await api('schedulingData');draw();}
  catch(e){target.innerHTML='<p class="error">'+esc(e.message)+'</p>';}
}
window.refreshSchedulingAdmin=refresh;
})();
