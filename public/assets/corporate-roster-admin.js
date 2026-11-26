'use strict';
(()=>{
 const $=id=>document.getElementById(id);
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 async function refresh(){
  const host=$('corporateRosterAdmin');if(!host)return;
  try{
   const info=await api('corporateRoster');
   if(!info.campaign_ready){
    host.innerHTML='<h3>丹青社・利用対象者名簿</h3><p>先に案件設定を保存すると名簿を登録できます。</p>';
    return;
   }
   const roster=info.roster||[];
   host.innerHTML='<h3>丹青社・利用対象者名簿（事前登録）</h3>'+
    '<p class="muted">予約者名簿は公開しません。利用者にはご案内したメールアドレスを入力していただき、事前登録のメールと照合します。本人確認のメール送信は別途整備が必要です。</p>'+
    '<p><strong>登録済み '+roster.filter(r=>Number(r.active)).length+'名</strong> ／ 照合：'+(info.required?'有効':'無効（準備中）')+'</p>'+
    '<form id="corporateRosterForm"><label>事前登録名簿（1行につきメールアドレス,氏名。氏名は省略可）</label>'+
    '<textarea name="entries" rows="7" placeholder="example@company.jp,山田太郎" required></textarea>'+
    '<button type="submit">名簿に追加・更新</button></form><p id="corporateRosterMessage" role="status"></p>'+
    '<div class="corporate-roster-controls"><button type="button" id="corporateRosterToggle" class="secondary">'+
       (info.required?'名簿照合を解除':'名簿照合を有効にする')+'</button>'+
     '<p class="muted">打ち合わせまで照合は初期設定で無効です。有効化する前に、対象者・メール確認・個人情報の取り扱いを確定してください。</p></div>'+
    '<details><summary>登録済み名簿を確認（管理者のみ）</summary><div class="scroll"><table><thead><tr><th>メールアドレス</th><th>氏名</th><th>状態</th><th>操作</th></tr></thead><tbody>'+
       roster.map(r=>'<tr><td>'+esc(r.email)+'</td><td>'+esc(r.display_name||'未設定')+'</td><td>'+(Number(r.active)?'有効':'停止')+'</td><td><button type="button" class="secondary" data-invitee-id="'+Number(r.id)+'" data-enable="'+(Number(r.active)?0:1)+'">'+(Number(r.active)?'停止':'再開')+'</button></td></tr>').join('')+
    '</tbody></table></div></details>';
   $('corporateRosterForm').onsubmit=async e=>{
    e.preventDefault();const button=e.target.querySelector('button');button.disabled=true;
    try{
     const result=await api('corporateRosterImport',{}, {entries:e.target.elements.entries.value});
     await refresh();$('corporateRosterMessage').textContent=result.processed+'名を名簿に登録・更新しました。';
    }catch(err){$('corporateRosterMessage').textContent=err.message;}
    finally{button.disabled=false;}
   };
   $('corporateRosterToggle').onclick=async()=>{
    const required=!info.required;
    if(!confirm(required?'メールアドレスによる事前名簿照合を有効にしますか？ 名簿にない人は予約できなくなります。':'事前名簿照合を解除しますか？'))return;
    try{await api('corporateRosterEnforcement',{}, {required});await refresh();}
    catch(e){$('corporateRosterMessage').textContent=e.message;}
   };
   host.querySelectorAll('[data-invitee-id]').forEach(btn=>btn.onclick=async()=>{
    if(!confirm('名簿の利用許可状態を変更しますか？'))return;
    try{await api('corporateRosterStatus',{}, {id:Number(btn.dataset.inviteeId),active:Boolean(Number(btn.dataset.enable))});await refresh();}
    catch(e){$('corporateRosterMessage').textContent=e.message;}
   });
  }catch(e){
   // Normal admins should not see pre-registered employee personal data.
   host.innerHTML='';
  }
 }
 window.refreshCorporateRoster=refresh;
})();