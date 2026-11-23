'use strict';
(function(){
 const get=id=>document.getElementById(id);
 const safe=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 let selectedTeacher=null,master=[];
 const fields=[
   ['summary','プロフィール・肩書き'],
   ['career','経歴・キャリア'],
   ['qualifications_text','資格（掲載元の原文）'],
   ['training','担当研修・専門分野'],
   ['activity_area','活動エリア'],
   ['message','先生からのメッセージ']
 ];
 function drawMaster(){
   const host=get('qualificationMasterArea');
   if(!host)return;
   host.innerHTML='<h3>資格マスター</h3><p class="muted">資格の名称と分類を管理します。先生との紐付けはプロフィール画面から設定できます。</p>'+
   '<form id="qualificationMasterForm" class="hana-qual-form">'+
   '<input type="hidden" name="id" value="0"><label>資格名<input name="name" maxlength="250" required placeholder="例：国家資格キャリアコンサルタント"></label>'+
   '<label>分類<input name="category" maxlength="80" value="未分類" required placeholder="例：キャリア・相談"></label>'+
   '<button type="submit">資格を登録・保存</button><button type="button" class="secondary" id="qualificationMasterReset">新規入力</button></form>'+
   '<label for="qualificationMasterSearch">資格を検索</label><input id="qualificationMasterSearch" type="search" placeholder="資格名・分類" style="max-width:450px">'+
   '<div id="qualificationMasterRows"></div><p id="qualificationMasterResult" role="status"></p>';
   get('qualificationMasterSearch').oninput=drawMasterRows;
   get('qualificationMasterReset').onclick=()=>get('qualificationMasterForm').reset();
   get('qualificationMasterForm').onsubmit=async e=>{
     e.preventDefault();const form=e.target;const v=Object.fromEntries(new FormData(form));
     try{
       await api('qualificationSave',{},v);
       form.reset();
       await refreshMaster();
       get('qualificationMasterResult').textContent='資格マスターを保存しました。';
     }catch(err){get('qualificationMasterResult').textContent=err.message;}
   };
   drawMasterRows();
 }
 function drawMasterRows(){
   const box=get('qualificationMasterRows');if(!box)return;
   const q=(get('qualificationMasterSearch')?.value||'').toLowerCase();
   const list=master.filter(x=>(x.name+' '+x.category).toLowerCase().includes(q));
   box.innerHTML='<p class="muted">登録済み '+master.length+'資格</p><div class="scroll"><table><thead><tr><th>資格名</th><th>分類</th><th>先生数</th><th>編集</th></tr></thead><tbody>'+
   list.map(x=>'<tr><td>'+safe(x.name)+'</td><td>'+safe(x.category)+'</td><td>'+Number(x.teacher_count)+'</td><td><button type="button" class="secondary" data-qual-edit="'+Number(x.id)+'">編集</button></td></tr>').join('')+'</tbody></table></div>';
   box.querySelectorAll('[data-qual-edit]').forEach(btn=>btn.onclick=()=>{
     const q=master.find(x=>Number(x.id)===Number(btn.dataset.qualEdit));if(!q)return;
     const form=get('qualificationMasterForm');
     form.elements.id.value=q.id;form.elements.name.value=q.name;form.elements.category.value=q.category;
     form.scrollIntoView({behavior:'smooth',block:'center'});
   });
 }
 async function refreshMaster(){
   const d=await api('qualificationMaster');
   master=d.qualifications||[];
   drawMaster();
 }
 async function showProfile(id){
   selectedTeacher=Number(id);
   const host=get('teacherProfileArea');
   host.innerHTML='<p class="muted">先生のプロフィールを読み込んでいます…</p>';
   try{
     const data=await api('teacherProfile',{teacher_id:id});
     const t=data.teacher,quals=data.qualifications||[];
     if(selectedTeacher!==Number(t.id))return;
     const photo=t.photo_path?
       '<img class="hana-teacher-photo" alt="'+safe(t.name)+'先生の写真" src="'+safe(t.photo_path)+'" loading="lazy">':
       '<div class="hana-teacher-photo-placeholder">写真未登録</div>';
     const fieldsHtml=fields.map(([key,label])=>
       '<label>'+label+'<textarea name="'+key+'" rows="'+(key==='career'?7:3)+'">'+safe(t[key]||'')+'</textarea></label>').join('');
     host.innerHTML='<div class="hana-profile-header"><div>'+photo+'</div><div><h3>'+safe(t.name)+'</h3>'+
       '<p class="muted">元サイト：'+(t.source_url?'<a href="'+safe(t.source_url)+'" target="_blank" rel="noopener noreferrer">掲載元を開く</a>':'不明')+'</p>'+
       '<p class="muted">最終取得：'+safe(t.source_checked_at||'未取得')+'</p></div></div>'+
       '<form id="teacherPhotoForm" class="hana-inline-form"><input type="hidden" name="teacher_id" value="'+Number(t.id)+'">'+
       '<label>先生の写真を登録・変更（JPEG・PNG・WebP、5MB以下）<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label>'+
       '<button type="submit">写真を保存</button></form><p id="teacherPhotoResult" role="status"></p>'+
       '<form id="teacherProfileForm"><input type="hidden" name="teacher_id" value="'+Number(t.id)+'">'+
       fieldsHtml+'<button type="submit">プロフィールを保存</button><p id="teacherProfileResult"></p></form>'+
       '<h3>先生の資格（資格マスターとの紐付け）</h3><div id="linkedTeacherQualifications">'+
       (quals.length?quals.map(q=>'<div class="hana-teacher-qualification">'+safe(q.name)+
       ' <button type="button" class="secondary" data-qualification-remove="'+Number(q.id)+'">解除</button></div>').join(''):'<p class="muted">資格マスターとの紐付けは未登録です。</p>')+'</div>'+
       '<div class="hana-qualification-link"><select id="qualificationSelect">'+
       '<option value="">資格を選択</option>'+master.map(q=>'<option value="'+Number(q.id)+'">'+safe(q.name)+'</option>').join('')+
       '</select><button type="button" id="qualificationAdd">資格を紐付け</button></div>';
     get('teacherPhotoForm').onsubmit=async e=>{
       e.preventDefault();
       const form=e.target;const button=form.querySelector('button');button.disabled=true;
       try{
         const response=await fetch('/?action=teacherPhotoUpload',{
           method:'POST',credentials:'same-origin',
           headers:{'X-CSRF-Token':csrfToken},
           body:new FormData(form)
         });
         const result=await response.json();
         if(!response.ok)throw Error(result.error||'写真の登録に失敗しました');
         get('teacherPhotoResult').textContent='写真を登録しました。';
         await showProfile(selectedTeacher);
         if(window.refreshTeacherDirectory)await window.refreshTeacherDirectory();
       }catch(err){get('teacherPhotoResult').textContent=err.message;}
       finally{button.disabled=false;}
     };
     get('teacherProfileForm').onsubmit=async e=>{
       e.preventDefault();
       try{await api('teacherProfileSave',{},Object.fromEntries(new FormData(e.target)));
         get('teacherProfileResult').textContent='保存しました。';}
       catch(err){get('teacherProfileResult').textContent=err.message;}
     };
     get('qualificationAdd').onclick=async()=>{
       const qid=Number(get('qualificationSelect').value);if(!qid)return;
       try{await api('teacherQualificationLink',{}, {teacher_id:selectedTeacher,qualification_id:qid,operation:'add'});await showProfile(selectedTeacher);await refreshMaster();}
       catch(err){alert(err.message);}
     };
     host.querySelectorAll('[data-qualification-remove]').forEach(b=>b.onclick=async()=>{
       if(!confirm('この先生と資格の紐付けを解除しますか？'))return;
       try{await api('teacherQualificationLink',{}, {teacher_id:selectedTeacher,qualification_id:Number(b.dataset.qualificationRemove),operation:'remove'});await showProfile(selectedTeacher);await refreshMaster();}
       catch(err){alert(err.message);}
     });
     host.scrollIntoView({behavior:'smooth',block:'start'});
   }catch(err){host.innerHTML='<p class="error">プロフィールの取得に失敗しました：'+safe(err.message)+'</p>';}
 }
 window.openTeacherProfile=showProfile;
 window.refreshTeacherQualifications=refreshMaster;
})();
