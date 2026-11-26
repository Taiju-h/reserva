'use strict';
(()=>{
const $=id=>document.getElementById(id);
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function refresh(){
 const host=$('instructorAdminRoot');if(!host)return;
 try{
   const accounts=await api('instructorAccounts');
   const details=await api('corporateAdmin');
   const eligible=details.teachers.filter(t=>Number(t.active));
   host.innerHTML='<h3>先生専用ログインの発行</h3>'+
    '<p class="muted">先生本人は専用画面から、自分の受付枠だけ登録・削除できます。管理者パスワードは渡さないでください。</p>'+
    '<p><a href="/instructor" target="_blank" rel="noopener noreferrer">先生専用スケジュール画面（/instructor）</a></p>'+
    '<form id="instructorCredentials"><label>先生を選択<select name="teacher_id" required><option value="">選択してください</option>'+
    eligible.map(t=>'<option value="'+Number(t.id)+'">'+esc(t.name)+'</option>').join('')+'</select></label>'+
    '<label>ログインID（半角英数字・記号）<input name="login_id" pattern="[a-zA-Z0-9_.-]{3,100}" maxlength="100" required autocomplete="off"></label>'+
    '<label>初期／変更パスワード（12文字以上、変更しないときは空欄）<input type="password" name="password" minlength="12" autocomplete="new-password"></label>'+
    '<label><input type="checkbox" name="active" value="1" checked> ログインを有効にする</label>'+
    '<button type="submit">ログインを登録・変更</button></form><p id="instructorCredentialResult" role="status"></p>'+
    '<div class="scroll"><table><thead><tr><th>先生</th><th>ログインID</th><th>状態</th><th>編集</th></tr></thead><tbody>'+
    accounts.accounts.map(a=>'<tr><td>'+esc(a.name)+'</td><td>'+esc(a.login_id)+'</td><td>'+(Number(a.active)?'有効':'停止')+'</td><td><button class="secondary" type="button" data-account="'+Number(a.teacher_id)+'">設定を開く</button></td></tr>').join('')+
    '</tbody></table></div>';
   const form=$('instructorCredentials');
   form.onsubmit=async e=>{
     e.preventDefault();
     const v=Object.fromEntries(new FormData(form));v.teacher_id=Number(v.teacher_id);
     v.active=Boolean(v.active);
     try{await api('instructorAccountSave',{},v);await refresh();$('instructorCredentialResult').textContent='先生ログインを保存しました。';}
     catch(err){$('instructorCredentialResult').textContent=err.message;}
   };
   host.querySelectorAll('[data-account]').forEach(b=>b.onclick=()=>{
     const a=accounts.accounts.find(x=>Number(x.teacher_id)===Number(b.dataset.account));
     if(!a)return;
     form.elements.teacher_id.value=a.teacher_id;form.elements.login_id.value=a.login_id;
     form.elements.password.value='';form.elements.active.checked=Boolean(Number(a.active));
     form.scrollIntoView({behavior:'smooth',block:'center'});
   });
 }catch(e){
   // Ordinary admins cannot create teacher accounts; only master may do so.
   host.innerHTML=e.message?.includes('マスター')?'':'<p class="muted">先生ログイン管理を取得できません：'+esc(e.message)+'</p>';
 }
}
window.refreshInstructorAdmin=refresh;
})();