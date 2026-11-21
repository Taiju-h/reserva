/* Admin scheduling and customer directory. No customer data stored in browser. */
(() => {
  'use strict';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
  }[ch]));
  const money = n => Number(n || 0).toLocaleString('ja-JP');
  const monthNow = () => {
    const p = new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tokyo',year:'numeric',month:'2-digit'}).format(new Date());
    return p.slice(0,7);
  };
  async function get(action, params = {}) {
    const u = '/?'+new URLSearchParams({action,...params});
    const r = await fetch(u,{credentials:'same-origin',cache:'no-store'});
    const data = await r.json();
    if(!r.ok) throw Error(data.error || '取得できませんでした');
    return data;
  }
  const render = () => {
    const target = document.getElementById('operations');
    if(!target) return;
    target.innerHTML = `
      <div class="actions" style="margin-bottom:14px">
        <label style="margin:0" for="calendarMonth">表示月</label>
        <input style="width:180px" type="month" id="calendarMonth" value="${monthNow()}">
        <button type="button" id="calendarLoad">予定を表示</button>
        <a id="calendarCsv" class="secondary" style="padding:12px;border-radius:8px;background:#ecf0f2" href="#">CSV出力</a>
      </div>
      <p class="muted">選択月の予約を表示します。顧客情報は管理者のみ閲覧できます。</p>
      <div id="calendarMessage" role="status"></div>
      <div id="monthGrid" class="scroll"></div>
      <hr style="margin:28px 0;border:0;border-top:1px solid #e5e9ef">
      <h3>顧客一覧</h3>
      <div class="actions">
        <input id="customerQuery" maxlength="100" style="max-width:360px" placeholder="名前・メール・電話番号で検索">
        <button type="button" id="customerFind">検索</button>
      </div>
      <div id="customerResults" class="scroll" style="margin-top:15px"></div>
    `;
    document.getElementById('calendarLoad').onclick = loadCalendar;
    document.getElementById('calendarMonth').onchange = loadCalendar;
    document.getElementById('customerFind').onclick = loadCustomers;
    document.getElementById('customerQuery').onkeydown = e => {
      if(e.key === 'Enter') { e.preventDefault(); loadCustomers(); }
    };
    loadCalendar();
    loadCustomers();
  };
  async function loadCalendar() {
    const month = document.getElementById('calendarMonth').value;
    if(!/^\d{4}-(0[1-9]|1[0-2])$/.test(month)) return;
    document.getElementById('calendarCsv').href = '/?'+new URLSearchParams({action:'exportBookings',month});
    const el = document.getElementById('monthGrid');
    el.textContent = '読み込み中…';
    try {
      const {bookings} = await get('calendar',{month});
      const count = bookings.filter(b => b.status === 'confirmed').length;
      document.getElementById('calendarMessage').textContent =
        month+' / 予約確定 '+count+'件、取消 '+(bookings.length-count)+'件';
      const groups = new Map();
      for(const b of bookings) {
        const day = b.starts_at.slice(0,10);
        if(!groups.has(day)) groups.set(day,[]);
        groups.get(day).push(b);
      }
      const first = new Date(month+'-01T12:00:00+09:00');
      const days = new Date(Number(month.slice(0,4)),Number(month.slice(5,7)),0).getDate();
      let html = '<div style="display:grid;grid-template-columns:repeat(7,minmax(110px,1fr));min-width:770px;gap:5px">';
      for(const w of ['日','月','火','水','木','金','土']) html+='<div style="text-align:center;padding:8px;background:#edf3f4"><b>'+w+'</b></div>';
      for(let i=0;i<first.getUTCDay();i++)html+='<div></div>';
      for(let d=1;d<=days;d++){
        const key=month+'-'+String(d).padStart(2,'0');
        const bs=groups.get(key)||[];
        html+='<div style="min-height:82px;border:1px solid #e5e9ef;padding:7px;border-radius:6px"><b>'+d+'</b>';
        for(const b of bs) html+='<div style="font-size:12px;padding:4px;margin-top:3px;border-radius:4px;background:'+
          (b.status==='confirmed'?'#e6f5f0':'#f1f1f1')+';color:#35434c">'+esc(b.starts_at.slice(11,16))+
          ' '+esc(b.customer_name)+'<br><small>'+esc(b.menu_name)+(b.status==='confirmed'?'':'（取消）')+'</small></div>';
        html+='</div>';
      }
      el.innerHTML=html+'</div>';
    } catch(e) { el.innerHTML='<p class="error">'+esc(e.message)+'</p>'; }
  }
  async function loadCustomers(){
    const el=document.getElementById('customerResults');
    el.textContent='読み込み中…';
    try{
      const {customers}=await get('customers',{q:document.getElementById('customerQuery').value});
      let html='<table><thead><tr><th>お名前</th><th>メール</th><th>電話</th><th>予約回数</th><th>確定件数</th><th>最終予約日</th></tr></thead><tbody>';
      for(const c of customers)html+='<tr><td>'+esc(c.name)+'</td><td>'+esc(c.email)+'</td><td>'+esc(c.phone)+'</td><td>'+money(c.booking_count)+'</td><td>'+money(c.confirmed_count)+'</td><td>'+esc(c.last_booking)+'</td></tr>';
      el.innerHTML=html+'</tbody></table>'+(customers.length?'':'<p>該当する顧客はありません。</p>');
    }catch(e){el.innerHTML='<p class="error">'+esc(e.message)+'</p>';}
  }
  document.addEventListener('DOMContentLoaded',render);
})();
