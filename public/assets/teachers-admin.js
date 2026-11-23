'use strict';
(function(){
 const get=id=>document.getElementById(id);
 const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 let teachers=[],master=false;
 async function refresh(){
   const holder=get('teacherDirectoryRows');
   if(!holder)return;
   try{
     const result=await api('teacherDirectory');
     teachers=result.teachers||[];
     master=Boolean(result.master);
     const count=get('teacherDirectoryCount');
     if(count)count.textContent='先生 '+teachers.length+'名 / プロフィール '+Number(result.statistics?.profiles||0)+'名 / 経歴 '+Number(result.statistics?.careers||0)+'名 / 写真 '+Number(result.statistics?.photos||0)+'名 / 資格紐付け '+Number(result.statistics?.qualifications||0)+'件';
     const message=get('teacherDirectoryResult');
     if(message && !result.profiles_ready)message.textContent='先生の名前一覧は表示できます。プロフィール・写真・資格のDB移行が未完了です（migrate-teacher-profiles.php）。';
     else if(message && message.textContent.includes('DB移行が未完了'))message.textContent='';
     renderRows();
     const button=get('bulkImportTeachers');
     button.hidden=!master;
     const migrate=get('migrateTeacherProfiles');
     if(migrate)migrate.hidden=!master;
     const sync=get('syncTeacherDetails');if(sync)sync.hidden=!master;
     const retry=get('syncMissingTeacherDetails');if(retry)retry.hidden=!master;
   }catch(err){
     const message=err.message||String(err);
     get('teacherDirectoryResult').textContent='先生一覧を取得できません：'+message;
     holder.innerHTML='<tr><td colspan="4">一覧の読み込みに失敗しました。上のエラーをご確認ください。</td></tr>';
     const count=get('teacherDirectoryCount');
     if(count)count.textContent='先生一覧の取得に失敗しました';
   }
 }
 function renderRows(){
   const query=(get('teacherDirectorySearch')?.value||'').replace(/\s+/g,'').toLowerCase();
   const rows=teachers.filter(t=>t.name.replace(/\s+/g,'').toLowerCase().includes(query));
   const tbody=get('teacherDirectoryRows');
   tbody.innerHTML=rows.map(t=>
      '<tr><td>'+Number(t.id)+'</td><td><div class="hana-directory-person">'+(t.photo_path?'<img src="'+escape(t.photo_path)+'" alt="'+escape(t.name)+'先生の写真" loading="lazy">':'<span class="hana-directory-photo-empty">写真なし</span>')+'<div><strong>'+escape(t.name)+'</strong><div style="font-size:12px;color:#687686">'+(Number(t.has_photo)?'写真あり':'写真なし')+'・'+(Number(t.has_career)?'経歴あり':'経歴なし')+'・資格'+Number(t.qualification_count||0)+'件</div></div></div></td><td>'+(Number(t.active)?'有効':'停止中')+'</td><td>'+
      '<button type="button" data-teacher-profile="'+Number(t.id)+'">プロフィール・写真・資格</button> '+
      (master?'<button type="button" class="secondary" data-teacher-edit="'+Number(t.id)+'">名前変更</button> ':'')+
      '<button type="button" class="secondary" data-teacher-toggle="'+Number(t.id)+'" data-teacher-active="'+(Number(t.active)?'0':'1')+'">'+(Number(t.active)?'停止':'再開')+'</button></td></tr>'
   ).join('')||'<tr><td colspan="4">該当する先生はいません。</td></tr>';
   tbody.querySelectorAll('[data-teacher-profile]').forEach(btn=>btn.onclick=()=>{
     if(window.openTeacherProfile)window.openTeacherProfile(Number(btn.dataset.teacherProfile));
     else get('teacherDirectoryResult').textContent='プロフィール画面を読み込めません。ページを更新してください。';
   });
   tbody.querySelectorAll('[data-teacher-toggle]').forEach(btn=>btn.onclick=async()=>{
     if(!confirm('先生の状態を変更しますか？'))return;
     try{await api('teacherStatus',{}, {id:Number(btn.dataset.teacherToggle),active:Number(btn.dataset.teacherActive)});await refresh();if(window.refreshSchedulingAdmin)await window.refreshSchedulingAdmin();}
     catch(err){get('teacherDirectoryResult').textContent=err.message;}
   });
   tbody.querySelectorAll('[data-teacher-edit]').forEach(btn=>btn.onclick=async()=>{
     const t=teachers.find(t=>Number(t.id)===Number(btn.dataset.teacherEdit));
     if(!t)return;
     const name=prompt('先生の名前を変更',t.name);
     if(name===null||!name.trim()||name===t.name)return;
     try{await api('teacherSave',{}, {id:Number(t.id),name:name.trim()});await refresh();if(window.refreshSchedulingAdmin)await window.refreshSchedulingAdmin();}
     catch(err){get('teacherDirectoryResult').textContent=err.message;}
   });
 }
 document.addEventListener('DOMContentLoaded',()=>{
   const form=get('teacherDirectoryForm');if(!form)return;
   form.onsubmit=async e=>{
     e.preventDefault();
     const name=String(new FormData(form).get('name')||'').trim();
     if(!name)return;
     const button=form.querySelector('button');
     button.disabled=true;
     try{await api('teacherSave',{}, {name});form.reset();get('teacherDirectoryResult').textContent='先生を登録しました。';await refresh();}
     catch(err){get('teacherDirectoryResult').textContent=err.message;}
     finally{button.disabled=false;}
   };
   const migrate=get('migrateTeacherProfiles');
   if(migrate)migrate.onclick=async()=>{
     if(!confirm('先生のプロフィール概要と資格マスターをDBに移行します。既存の経歴や写真は上書きしません。よろしいですか？'))return;
     migrate.disabled=true;
     const status=get('teacherDirectoryResult');
     status.textContent='プロフィールと資格のDB移行を実行中…';
     try{
       const result=await api('migrateTeacherProfiles',{},{});
       const m=result.migration||{};
       status.textContent='移行完了：先生 '+m.teachers+'名／紹介文 '+m.summaries+'名／資格マスター '+m.qualification_master_count+'件／資格紐付け '+m.qualification_links_in_db+'件／写真 '+m.photos_in_db+'名。詳細経歴・写真は別途取得が必要です。';
       await refresh();
       if(window.refreshTeacherQualifications)await window.refreshTeacherQualifications();
     }catch(err){status.textContent='DB移行に失敗しました：'+(err.message||String(err));}
     finally{migrate.disabled=false;}
   };
   let stopSync=false;
   const syncBtn=get('syncTeacherDetails');
   const retryBtn=get('syncMissingTeacherDetails');
   const cancelBtn=get('cancelTeacherSync');
   if(cancelBtn)cancelBtn.onclick=()=>{stopSync=true;cancelBtn.disabled=true;};
   async function runTeacherSync(onlyMissing){
     const mode=onlyMissing?'写真・経歴・資格の未取得者だけ':'42名全員';
     if(!confirm(mode+'の掲載元データを照合します。手入力内容は維持します。よろしいですか？'))return;
     const progress=get('teacherSyncProgress');
     const summary=get('teacherDirectoryResult');
     stopSync=false;
     syncBtn.disabled=true;if(retryBtn)retryBtn.disabled=true;
     cancelBtn.hidden=false;cancelBtn.disabled=false;
     const outcomes=[];
     try{
       progress.textContent='プロフィールDBと資格マスターを確認中…';
       await api('migrateTeacherProfiles',{},{});
       // Directory order is fixed in the source snapshot, not the DB auto increment ID.
       // The returned source_index must be used for each request.
       const source=await api('teacherSourceStatus');
       const candidates=(source.teachers||[]).filter(t=>!onlyMissing||t.missing);
       if(!candidates.length){
         progress.textContent='未取得の先生は現在いません。';
         return;
       }
       for(let n=0;n<candidates.length;n++){
         if(stopSync)break;
         const target=candidates[n];
         progress.textContent='取得中 '+(n+1)+' / '+candidates.length+'名：'+target.name;
         try{
           const response=await api('teacherSyncOne',{}, {index:target.source_index});
           outcomes.push(response.result);
         }catch(e){outcomes.push({name:target.name,ok:false,status:e.message||String(e),photo:false});}
         const success=outcomes.filter(x=>x.ok).length;
         const photos=outcomes.filter(x=>x.photo).length;
         progress.innerHTML='<strong>処理済み '+outcomes.length+' / '+candidates.length+'名・個別ページ '+success+'名・写真取得 '+photos+'名</strong>'+
           '<div class="hana-sync-results">'+outcomes.map(x=>'<div>'+(x.ok?'✓ ':'! ')+escape(x.name)+'：'+escape(x.status||'')+
           '／写真 '+(x.photo?'取得':escape(x.photo_status||'未取得'))+
           (x.qualifications!=null?'／個別ページ資格 '+Number(x.qualifications)+'件':'')+'</div>').join('')+'</div>';
       }
       const refreshed=await api('teacherSourceStatus');
       summary.textContent=(stopSync?'中止しました。':'補完処理完了。')+
         ' DB保存済み：写真 '+refreshed.photos+'名、経歴 '+refreshed.careers+'名、資格紐付け '+refreshed.qualification_links+'件。未取得 '+refreshed.missing_count+'名。';
       await refresh();
       if(window.refreshTeacherQualifications)await window.refreshTeacherQualifications();
     }catch(e){
       summary.textContent='補完処理でエラー：'+(e.message||String(e));
     }finally{
       syncBtn.disabled=false;if(retryBtn)retryBtn.disabled=false;
       cancelBtn.hidden=true;
     }
   }
   if(syncBtn)syncBtn.onclick=()=>runTeacherSync(false);
   if(retryBtn)retryBtn.onclick=()=>runTeacherSync(true);
   get('teacherDirectorySearch').oninput=renderRows;
   get('bulkImportTeachers').onclick=async()=>{
     if(!confirm('ハナマル総合研究所の掲載者42名を登録します。登録済みの先生は重複追加しません。よろしいですか？'))return;
     const button=get('bulkImportTeachers');button.disabled=true;
     try{
       const data=await api('teacherBulkImport',{},{});
       get('teacherDirectoryResult').textContent='新規 '+data.added+'名 / 登録済み '+data.already_registered+'名を確認しました。';
       await refresh();
       if(window.refreshSchedulingAdmin)await window.refreshSchedulingAdmin();
     }catch(err){get('teacherDirectoryResult').textContent=err.message;}
     finally{button.disabled=false;}
   };
 });
 window.refreshTeacherDirectory=refresh;
})();
