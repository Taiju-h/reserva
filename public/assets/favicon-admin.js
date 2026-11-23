'use strict';
(function(){
  function refreshFavicons(kind){
    const stamp=Date.now();
    const preview=document.getElementById(kind==='public'?'faviconPreviewPublic':'faviconPreviewAdmin');
    if(preview)preview.src='/?action=favicon&kind='+kind+'&v='+stamp;
    const icon=document.getElementById('siteFavicon');
    const admin=location.pathname==='/admin';
    if(icon && (kind===(admin?'admin':'public')))icon.href='/?action=favicon&kind='+kind+'&v='+stamp;
  }
  document.addEventListener('DOMContentLoaded',()=>{
    const msg=document.getElementById('faviconMessage');
    if(!msg)return;
    document.querySelectorAll('.faviconUpload').forEach(form=>form.addEventListener('submit',async event=>{
      event.preventDefault();
      const kind=form.dataset.kind,button=form.querySelector('button[type="submit"]');
      if(!form.querySelector('input[type="file"]').files.length)return;
      const payload=new FormData(form);payload.set('kind',kind);
      button.disabled=true;msg.textContent='保存中…';
      try{
        const response=await fetch('/?action=faviconUpload',{method:'POST',credentials:'same-origin',
          headers:{'X-CSRF-Token':csrfToken},body:payload});
        const result=await response.json();
        if(!response.ok)throw Error(result.error||'保存できませんでした');
        form.reset();refreshFavicons(kind);
        msg.textContent=(kind==='admin'?'管理画面':'公開サイト')+'のアイコンを更新しました。';
      }catch(e){msg.textContent='アイコン更新失敗：'+e.message;}
      finally{button.disabled=false;}
    }));
    document.querySelectorAll('.faviconReset').forEach(button=>button.addEventListener('click',async()=>{
      const kind=button.dataset.kind;
      if(!confirm('選択したアイコンを初期設定に戻しますか？'))return;
      button.disabled=true;
      try{await api('faviconReset',{}, {kind});refreshFavicons(kind);msg.textContent='初期アイコンへ戻しました。';}
      catch(e){msg.textContent='リセット失敗：'+e.message;}
      finally{button.disabled=false;}
    }));
  });
})();
