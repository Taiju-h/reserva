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
     if(count)count.textContent='登録済み '+teachers.length+'名 / 参考名簿 '+result.reference_count+'名';
     renderRows();
     const button=get('bulkImportTeachers');
     button.hidden=!master;
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
      '<tr><td>'+Number(t.id)+'</td><td><strong>'+escape(t.name)+'</strong></td><td>'+(Number(t.active)?'有効':'停止中')+'</td><td>'+
      (master?'<button type="button" class="secondary" data-teacher-edit="'+Number(t.id)+'">名前変更</button> ':'')+
      '<button type="button" class="secondary" data-teacher-toggle="'+Number(t.id)+'" data-teacher-active="'+(Number(t.active)?'0':'1')+'">'+(Number(t.active)?'停止':'再開')+'</button></td></tr>'
   ).join('')||'<tr><td colspan="4">該当する先生はいません。</td></tr>';
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
