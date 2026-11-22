'use strict';
(function(){
const el=id=>document.getElementById(id);
const escapeHtml=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function setHero(){
  const hero=(cfg.media||[]).find(m=>m.kind==='hero');
  if(hero && el('siteHero')) {
    el('siteHero').style.backgroundImage='linear-gradient(90deg,rgba(243,250,247,.94),rgba(233,242,248,.68)),url("/?action=media&id='+Number(hero.id)+'")';
    el('siteHero').style.backgroundSize='cover';
    el('siteHero').style.backgroundPosition='center';
  }
}
window.initSiteExtras=function(){
  setHero();
  const select=el('waitlistMenu');
  if(select)select.innerHTML=cfg.menus.map(m=>'<option value="'+Number(m.id)+'">'+escapeHtml(m.name)+'</option>').join('');
  const inquiry=el('inquiryForm');
  if(inquiry)inquiry.onsubmit=async function(e){
    e.preventDefault();
    const out=el('inquiryResult');
    try {
      await api('inquiry',{},Object.fromEntries(new FormData(inquiry)));
      inquiry.reset();out.textContent='お問い合わせを受け付けました。';
    }catch(err){out.textContent=err.message;}
  };
  const wait=el('waitlistForm');
  if(wait)wait.onsubmit=async function(e){
    e.preventDefault();
    const out=el('waitlistResult');
    const form=Object.fromEntries(new FormData(wait));
    form.menu_id=Number(form.menu_id);
    try {
      await api('waitlist',{},form);wait.reset();
      out.textContent='キャンセル待ちを登録しました（自動通知は未対応です）。';
    }catch(err){out.textContent=err.message;}
  };
};
window.refreshSiteExtras=async function(){
  const ab=el('adminBookingForm');
  const abs=el('adminBookMenu');
  if(abs)abs.innerHTML=cfg.menus.map(m=>'<option value="'+Number(m.id)+'">'+escapeHtml(m.name)+'</option>').join('');
  if(ab && !ab.dataset.bound){ab.dataset.bound='1';ab.onsubmit=async e=>{e.preventDefault();const result=el('adminBookResult');const data=Object.fromEntries(new FormData(ab));data.menu_id=Number(data.menu_id);try {const v=await api('adminBook',{},data);result.textContent='予約番号 '+v.booking_id+' を登録しました';await dashboard();}catch(err){result.textContent=err.message;}};}
  const sel=el('mediaMenu');
  if(sel)sel.innerHTML=cfg.menus.map(m=>'<option value="'+Number(m.id)+'">'+escapeHtml(m.name)+'</option>').join('');
  const kind=el('mediaKind');
  if(kind){kind.onchange=()=>{sel.disabled=kind.value==='hero';};kind.onchange();}
  const d=await api('extraAdminData');
  const gal=el('mediaGallery');
  if(gal) {
    gal.innerHTML=d.media.map(m=>'<div class="panel" style="padding:10px"><img alt="登録画像" style="width:100%;height:125px;object-fit:contain" src="/?action=media&id='+Number(m.id)+'"><p>'+(m.kind==='hero'?'トップ画像':'メニュー ID: '+Number(m.menu_id))+'</p><button type="button" class="secondary" data-remove-media="'+Number(m.id)+'">削除</button></div>').join('')||'<p>登録画像はありません。</p>';
    gal.querySelectorAll('[data-remove-media]').forEach(btn=>btn.onclick=async()=>{
      if(!confirm('画像を削除しますか？'))return;
      try{await api('mediaDelete',{}, {id:Number(btn.dataset.removeMedia)});await window.refreshSiteExtras();}catch(e){alert(e.message);}
    });
  }
  const box=el('extraAdmin');
  if(box){
    const inquiry=d.inquiries.map(x=>'<tr><td>'+escapeHtml(x.created_at)+'</td><td>'+escapeHtml(x.name)+'</td><td>'+escapeHtml(x.email)+'</td><td>'+escapeHtml(x.message)+'</td></tr>').join('');
    const wait=d.waitlist.map(x=>'<tr><td>'+escapeHtml(x.created_at)+'</td><td>'+escapeHtml(x.menu_name)+'</td><td>'+escapeHtml(x.preferred_date)+'</td><td>'+escapeHtml(x.email)+'</td></tr>').join('');
    box.innerHTML='<h3>お問い合わせ（最新200件）</h3><div class="scroll"><table><thead><tr><th>日時</th><th>氏名</th><th>メール</th><th>内容</th></tr></thead><tbody>'+inquiry+'</tbody></table></div><h3>キャンセル待ち（最新200件）</h3><div class="scroll"><table><thead><tr><th>登録日時</th><th>メニュー</th><th>希望日</th><th>メール</th></tr></thead><tbody>'+wait+'</tbody></table></div>';
  }
  const form=el('mediaUploadForm');
  if(form && !form.dataset.bound) {
    form.dataset.bound='1';
    form.onsubmit=async e=>{
      e.preventDefault();const out=el('mediaError');out.textContent='アップロード中…';
      try {
        const response=await fetch('/?action=mediaUpload',{method:'POST',body:new FormData(form),headers:{'X-CSRF-Token':csrfToken},credentials:'same-origin'});
        const data=await response.json();
        if(!response.ok)throw Error(data.error||'登録できませんでした');
        out.textContent='画像を登録しました';
        form.reset();await window.refreshSiteExtras();
      }catch(err){out.textContent=err.message;}
    };
  }
};
})();
