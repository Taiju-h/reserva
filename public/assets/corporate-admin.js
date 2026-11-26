'use strict';
(()=>{
const host=()=>document.getElementById('corporateAdminRoot');
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let data=null;
async function refresh(){
  if(!host())return;
  try{data=await api('corporateAdmin');render();}
  catch(e){host().innerHTML='<p class="error">'+esc(e.message)+'</p><p>未移行の場合は migrate-corporate-bookings.php を実行してください。</p>';}
}
function render(){
  const campaign=data.campaign||{};
  const assigned=new Set((data.assigned||[]).map(Number));
  const active=data.teachers.filter(t=>Number(t.active));
  const teachers=active.map(t=>'<label class="corporate-teacher-check"><input type="checkbox" value="'+Number(t.id)+'" '+(assigned.has(Number(t.id))?'checked':'')+'><span>'+esc(t.name)+'</span></label>').join('');
  const slots=(data.slots||[]).slice(-120).map(s=>'<tr><td>'+esc(s.starts_at)+'</td><td>'+esc(s.teacher_name)+'</td><td>'+(s.booked_id?'予約済み':'空き')+'</td><td>'+(s.booked_id?'—':'<button type="button" class="secondary" data-remove-slot="'+Number(s.id)+'">削除</button>')+'</td></tr>').join('');
  const bookings=(data.bookings||[]).map(b=>'<tr><td>'+Number(b.id)+'</td><td>'+esc(b.starts_at)+'</td><td>'+esc(b.teacher_name)+'</td><td>'+esc(b.customer_name)+'</td><td>'+esc(b.status)+'</td><td>'+
    (b.status==='confirmed'?'<button type="button" class="secondary" data-complete="'+Number(b.id)+'">実施済みにする</button> <button type="button" class="secondary" data-cancel="'+Number(b.id)+'">取消</button>':'')+'</td></tr>').join('');
  host().innerHTML='<h3>案件設定</h3><p class="muted">専用URL：<a target="_blank" rel="noopener noreferrer" href="/tanseisha">/tanseisha</a>　期間：2026/12/20〜2027/2/28　休業：12/28〜1/3</p>'+
    '<form id="corporateSetupForm"><label>案件名<input name="name" maxlength="150" value="'+esc(campaign.name||'丹青社 60歳以降のセカンドキャリア相談')+'" required></label>'+
    '<label>利用者用の企業専用コード（12文字以上）<input name="access_code" type="password" minlength="12" placeholder="'+(campaign.name?'変更するときだけ入力':'必須：初期コードを入力')+'" '+(campaign.name?'':'required')+' autocomplete="new-password"></label>'+
    '<h3>参加する講師10名を選択</h3><p class="muted">既存の42名から選択します。10名が確定するまでは公開できません。</p>'+
    '<div class="corporate-teachers">'+teachers+'</div><p id="corporateTeacherCount">選択 '+assigned.size+' / 10名</p><button type="submit">案件と担当講師を保存</button></form>'+
    '<p id="corporateSetupStatus" role="status"></p>'+
    '<h3>講師の予約枠をまとめて追加</h3>'+
    '<p class="muted">該当日の許可時間のみ追加されます。平日は8・18・19時開始、土日祝は9〜19時開始、年末年始は受付禁止。入力したすべての時間帯から実際の許可枠だけ生成します。</p>'+
    '<form id="corporateSlotsForm"><div class="row"><div><label>担当講師<select name="teacher_id" required><option value="">選んでください</option>'+active.filter(t=>assigned.has(Number(t.id))).map(t=>'<option value="'+Number(t.id)+'">'+esc(t.name)+'</option>').join('')+'</select></label></div>'+
    '<div><label>開始日<input type="date" name="from" min="2026-12-20" max="2027-02-28" value="2026-12-20" required></label></div>'+
    '<div><label>終了日<input type="date" name="until" min="2026-12-20" max="2027-02-28" value="2027-02-28" required></label></div></div>'+
    '<fieldset><legend>この先生が受付できる開始時刻</legend><div class="corporate-hours">'+[8,9,10,11,12,13,14,15,16,17,18,19].map(h=>'<label><input type="checkbox" name="hours" value="'+h+'"> '+String(h).padStart(2,'0')+':00</label>').join('')+'</div></fieldset>'+
    '<button type="submit">許可された日時に空き枠を作成</button></form><p id="corporateSlotsStatus" role="status"></p>'+
    '<h3>公開状態</h3><p><strong>'+(campaign.published?'公開中':'非公開（準備中）')+'</strong></p>'+
    '<button type="button" id="corporatePublish" class="secondary" '+(!campaign.name?'disabled':'')+'>'+(campaign.published?'予約受付を停止する':'10名と空き枠を確認して公開する')+'</button>'+
    '<h3>予約枠一覧（直近120件表示）</h3><div class="scroll"><table><thead><tr><th>日時</th><th>先生</th><th>状態</th><th>操作</th></tr></thead><tbody>'+slots+'</tbody></table></div>'+
    '<h3>実績・請求集計</h3><p>見積：30名 × 8,000円 = 240,000円（構築費別）</p>'+
    '<p><strong>実施済み '+Number(data.counts?.completed||0)+'件 × 8,000円 = '+Number(data.completed_total_jpy||0).toLocaleString('ja-JP')+'円</strong></p>'+
    '<p class="muted">予約数ではなく「実施済み」件数のみ請求対象とします。</p>'+
    '<div class="scroll"><table><thead><tr><th>ID</th><th>相談日時</th><th>先生</th><th>氏名</th><th>状態</th><th>操作</th></tr></thead><tbody>'+bookings+'</tbody></table></div>';
  const setup=document.getElementById('corporateSetupForm');
  const checkboxes=[...setup.querySelectorAll('input[type=checkbox]')];
  checkboxes.forEach(c=>c.addEventListener('change',()=>{document.getElementById('corporateTeacherCount').textContent='選択 '+checkboxes.filter(x=>x.checked).length+' / 10名';}));
  setup.addEventListener('submit',async e=>{
    e.preventDefault();
    const ids=checkboxes.filter(x=>x.checked).map(x=>Number(x.value));
    if(ids.length>10){document.getElementById('corporateSetupStatus').textContent='最大10名です';return;}
    const payload={...Object.fromEntries(new FormData(setup)),teacher_ids:ids};
    try{await api('corporateSetup',{},payload);await refresh();document.getElementById('corporateSetupStatus').textContent='保存しました。公開はまだされていません。';}
    catch(err){document.getElementById('corporateSetupStatus').textContent=err.message;}
  });
  document.getElementById('corporateSlotsForm').addEventListener('submit',async e=>{
    e.preventDefault();
    const f=e.target;
    const payload={...Object.fromEntries(new FormData(f)),hours:[...f.querySelectorAll('[name=hours]:checked')].map(x=>Number(x.value))};
    const status=document.getElementById('corporateSlotsStatus');
    try{const r=await api('corporateGenerateSlots',{},payload);await refresh();document.getElementById('corporateSlotsStatus').textContent=r.created+'枠を追加しました。重複は追加しません。';}
    catch(err){status.textContent=err.message;}
  });
  document.getElementById('corporatePublish').addEventListener('click',async()=>{
    const publish=!campaign.published;
    if(!confirm(publish?'講師10名と予約枠を確認しましたか？ 企業利用者へ公開します。':'予約受付を停止しますか？'))return;
    try{await api('corporatePublish',{}, {published:publish});await refresh();}
    catch(err){alert(err.message);}
  });
  host().querySelectorAll('[data-remove-slot]').forEach(btn=>btn.addEventListener('click',async()=>{
    if(!confirm('この未予約枠を削除しますか？'))return;
    try{await api('corporateSlotDelete',{}, {id:Number(btn.dataset.removeSlot)});await refresh();}
    catch(e){alert(e.message);}
  }));
  host().querySelectorAll('[data-complete],[data-cancel]').forEach(btn=>btn.addEventListener('click',async()=>{
    const id=Number(btn.dataset.complete||btn.dataset.cancel);
    const status=btn.dataset.complete?'completed':'cancelled';
    if(!confirm(status==='completed'?'実施済みと記録し請求対象にしますか？':'この予約を取り消しますか？'))return;
    try{await api('corporateBookingStatus',{}, {id,status});await refresh();}
    catch(err){alert(err.message);}
  }));
}
window.refreshCorporateAdmin=refresh;
})();