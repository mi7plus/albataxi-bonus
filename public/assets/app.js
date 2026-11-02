'use strict';
const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
const endpoint=(action)=>'?page=api&action='+encodeURIComponent(action);
const notify=(text)=>{const el=document.getElementById('toast');if(el){el.textContent=text;setTimeout(()=>el.textContent='',6500);}};
const node=(tag,text,className)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(className)el.className=className;return el;};
async function api(action,data,key){
 const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),30000);
 try{
  const response=await fetch(endpoint(action),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,...(key?{'Idempotency-Key':key}:{})},body:JSON.stringify(data),signal:controller.signal});
  const result=await response.json();if(!response.ok){throw new Error(result.message||'Operațiune nereușită.');}return result;
 }finally{clearTimeout(timeout);}
}
document.querySelectorAll('[data-password]').forEach(button=>button.addEventListener('click',()=>{const input=document.getElementById(button.dataset.password);input.type=input.type==='password'?'text':'password';button.textContent=input.type==='password'?'Arată':'Ascunde';button.setAttribute('aria-label',button.textContent+' parola');}));
document.getElementById('role-switch')?.addEventListener('change',event=>location.href='?role='+encodeURIComponent(event.target.value));
document.querySelectorAll('[data-print]').forEach(el=>el.addEventListener('click',()=>window.print()));
document.querySelectorAll('[data-refresh]').forEach(el=>el.addEventListener('click',()=>location.reload()));
document.querySelectorAll('[data-csv-file]').forEach(input=>input.addEventListener('change',async()=>{const file=input.files[0];if(!file)return;if(file.size>1500000){notify('Împarte importul în fișiere mai mici de 1,5 MB.');input.value='';return;}input.form.elements.csv.value=await file.text();}));
function network(){const banner=document.getElementById('offline-warning');if(banner)banner.hidden=navigator.onLine;}
window.addEventListener('online',network);window.addEventListener('offline',network);network();
document.querySelectorAll('form[data-command]').forEach((form,index)=>{
 const draftKey='alba:fare:'+(form.dataset.draftId||location.search+':'+index);
 if(form.dataset.command==='preview'){
  try{const saved=JSON.parse(sessionStorage.getItem(draftKey)||'{}');if(saved.at&&Date.now()-saved.at<900000){for(const k of ['fare','code']){if(saved[k]&&form.elements[k]&&form.elements[k].type!=='hidden')form.elements[k].value=saved[k];}}}catch{}
  form.addEventListener('input',()=>{try{sessionStorage.setItem(draftKey,JSON.stringify({fare:form.elements.fare?.value,code:form.elements.code?.value,at:Date.now()}));}catch{}});
 }
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(form.dataset.busy)return;form.dataset.busy='1';
  const button=form.querySelector('[type="submit"],button:not([type="button"])');if(button)button.disabled=true;
  let resultBox=form.querySelector('.form-result');if(!resultBox){resultBox=node('div',undefined,'form-result');resultBox.setAttribute('role','status');form.append(resultBox);}resultBox.replaceChildren(node('p','Se procesează…'));
  const data=Object.fromEntries(new FormData(form).entries());let key;
  // Retain the same key after timeouts and reloads. Only hashes and random keys are stored.
  const material=JSON.stringify([form.dataset.command,location.search,index,data]);
  const hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(material)))).map(v=>v.toString(16).padStart(2,'0')).join('');
  const storageKey='alba:operation:'+hash;
  try{key=sessionStorage.getItem(storageKey);if(!key){key=crypto.randomUUID();sessionStorage.setItem(storageKey,key);}}catch{key=crypto.randomUUID();}
  try{
   const result=await api(form.dataset.command,data,key);
   try{sessionStorage.removeItem(storageKey);sessionStorage.removeItem(draftKey);}catch{}
   if(result.redirect){location.href=result.redirect;return;}
   if(result.url){
    resultBox.replaceChildren(node('p','Invitație creată. Copiază linkul pentru persoana invitată.'));
    const input=node('input');input.readOnly=true;input.value=result.url;input.setAttribute('aria-label','Link invitație');resultBox.append(input);
    const a=node('a','Actualizează lista →');a.href=location.href;resultBox.append(a);return;
   }
   if(result.message){resultBox.replaceChildren(node('p',result.message,'alert'));return;}
   if(result.status==='manual_recovery'){resultBox.replaceChildren(node('p','Creditul a fost deja cheltuit. Cazul a fost marcat pentru recuperare manuală; soldul nu a fost modificat.','alert'));return;}
   notify('Operațiune înregistrată.');location.reload();
  }catch(error){
   resultBox.replaceChildren(node('p',error.name==='AbortError'?'Răspuns întârziat. Verifică rezultatul înainte de reluare; cheia operațiunii a fost păstrată.':error.message,'alert error'));
   if(form.hasAttribute('data-financial')){
    const check=node('button','Verifică rezultatul operațiunii','button secondary');check.type='button';check.addEventListener('click',async()=>{
     try{const response=await fetch(endpoint('operation')+'&kind='+encodeURIComponent(form.dataset.command)+'&key='+encodeURIComponent(key));const info=await response.json();
      if(info.found){if(info.result.redirect){location.href=info.result.redirect;}else location.reload();}
      else notify('Operațiunea nu apare încă. Reîncearcă folosind același formular.');}catch{notify('Conexiune indisponibilă. Păstrează pagina și reîncearcă.');}
    });resultBox.append(check);
   }
  }finally{delete form.dataset.busy;if(button)button.disabled=false;}
 });
});
document.querySelectorAll('select[data-participants]').forEach(select=>{
 const controls=node('div',undefined,'participant-search');const input=node('input');input.placeholder='Caută participant…';input.setAttribute('aria-label','Caută participant după nume');
 const search=node('button','Caută','button secondary');search.type='button';const more=node('button','Mai multe','button secondary');more.type='button';let offset=0;
 async function load(append){
  if(!append)offset=0;
  try{const response=await fetch(endpoint('participants')+'&search='+encodeURIComponent(input.value)+'&offset='+offset);const result=await response.json();if(!response.ok)throw new Error(result.message);
   if(!append){select.replaceChildren();if(!select.required){const all=node('option','Toți cei permiși');all.value='';select.append(all);}}
   result.items.forEach(item=>{const option=node('option','#'+item.id+' · '+item.name);option.value=item.id;select.append(option);});offset+=result.items.length;more.hidden=offset>=result.total;if(!result.items.length)notify('Niciun participant găsit.');
  }catch(e){notify(e.message||'Nu putem încărca participanții.');}
 }
 search.addEventListener('click',()=>load(false));more.addEventListener('click',()=>load(true));input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();load(false);}});
 controls.append(input,search,more);select.parentElement.append(controls);
});
const verifyForm=document.getElementById('verify-form');
if(verifyForm){
 const show=async(offset=0)=>{
  const result=document.getElementById('verify-result');result.replaceChildren(node('p','Se încarcă…'));
  const params=new URLSearchParams(new FormData(verifyForm));params.set('page','api');params.set('action','verify');params.set('offset',offset);
  try{const response=await fetch('?'+params);const data=await response.json();if(!response.ok)throw new Error(data.message);result.replaceChildren(node('p',data.total+' înregistrări'));
   data.items.forEach(item=>{const details=node('details');details.append(node('summary','Înregistrare #'+item.id));Object.entries(item).forEach(([k,v])=>details.append(node('p',k+': '+v,'mono')));result.append(details);});
   const actions=node('div',undefined,'actions');if(offset>0){const prev=node('button','← Anterior','button secondary');prev.type='button';prev.onclick=()=>show(offset-50);actions.append(prev);}if(offset+50<data.total){const next=node('button','Următor →','button secondary');next.type='button';next.onclick=()=>show(offset+50);actions.append(next);}result.append(actions);
  }catch(e){result.replaceChildren(node('p',e.message,'alert error'));}
 };verifyForm.addEventListener('submit',event=>{event.preventDefault();show(0);});
}

function updateFareEstimate(form){
 const output=form.querySelector('[data-earn-estimate]');if(!output)return;
 const raw=(form.elements.fare?.value||'').trim().replace(',','.');
 if(!/^\d{1,7}(?:\.\d{1,2})?$/.test(raw)){output.textContent=raw?'Verifică suma':'0,00 lei';return;}
 const [whole,fraction='']=raw.split('.');
 const bani=BigInt(whole)*100n+BigInt(fraction.padEnd(2,'0'));
 const earned=form.dataset.earningPaused==='1'?0n:bani*BigInt(form.dataset.earnBps||0)/10000n;
 output.textContent=Number(earned/100n).toLocaleString('ro-RO')+','+String(earned%100n).padStart(2,'0')+' lei';
}
document.querySelectorAll('.fare-entry').forEach(form=>{updateFareEstimate(form);form.elements.fare.addEventListener('input',()=>updateFareEstimate(form));});
const targeted=document.getElementById(decodeURIComponent(location.hash.slice(1)));
if(targeted?.tagName==='DETAILS'){targeted.open=true;}
document.querySelectorAll('[data-reconcile]').forEach(button=>button.addEventListener('click',async()=>{
 const result=button.closest('section').querySelector('[data-reconcile-result]');button.disabled=true;result.replaceChildren(node('p','Se verifică soldurile și registrul…'));
 try{
  const response=await fetch(endpoint('reconcile'));const data=await response.json();if(!response.ok)throw new Error(data.message);
  const box=node('div',undefined,data.differences.length?'alert error':'reconcile-ok');
  box.append(node('strong',data.differences.length?data.differences.length+' diferențe necesită verificare.':'✓ Soldurile corespund registrului.'));
  box.append(node('p','Credit disponibil: '+moneyRo(data.credit)+' lei · Numerar în fond: '+moneyRo(data.cash)+' lei.'));
  box.append(node('small','Verificare efectuată: '+localRo(data.checked_at)));result.replaceChildren(box);
  data.differences.slice(0,10).forEach(item=>result.append(node('p','Pasager #'+item.passenger_id+': sold '+moneyRo(item.balance)+' lei / registru '+moneyRo(item.ledger)+' lei','mono')));
 }catch(error){result.replaceChildren(node('p',error.message||'Nu putem rula verificarea. Reîncearcă.','alert error'));}
 finally{button.disabled=false;}
}));
function moneyRo(value){return (Number(value)/100).toLocaleString('ro-RO',{minimumFractionDigits:2,maximumFractionDigits:2});}
function localRo(value){return new Date(String(value).replace(' ','T')+'Z').toLocaleString('ro-RO',{timeZone:'Europe/Bucharest'});}
const reportForm=document.getElementById('report-form');
if(reportForm){
 let snapshot='',reportTotal=0;
 const filterKeys=['template','start','end','participant','driver','vehicle','status','sort','group','columns'];
 const filterLabels={template:'Raport',start:'De la',end:'Până la',participant:'Participant',driver:'Șofer',vehicle:'Vehicul',status:'Stare',sort:'Sortare',group:'Grupare',columns:'Coloane'};
 const filtersChanged=()=>{snapshot='';reportTotal=0;reportForm.elements.row_start.value=1;};
 function syncColumns(){
  const checkboxes=Array.from(reportForm.querySelectorAll('[data-report-column]'));
  const checked=checkboxes.filter(el=>el.checked);
  reportForm.elements.columns.value=checked.length===checkboxes.length?'':checked.map(el=>el.value).join(',');
 }
 reportForm.querySelectorAll('[data-report-column]').forEach(input=>input.addEventListener('change',()=>{
  if(!reportForm.querySelector('[data-report-column]:checked')){input.checked=true;notify('Păstrează cel puțin o coloană.');}
  syncColumns();filtersChanged();
 }));
 reportForm.addEventListener('input',event=>{if(filterKeys.includes(event.target.name))filtersChanged();});
 reportForm.addEventListener('change',event=>{if(filterKeys.includes(event.target.name))filtersChanged();});
 async function preview(offset=0){
  syncColumns();const result=document.getElementById('report-result');result.replaceChildren(node('p','Se generează previzualizarea…','empty'));
  const params=new URLSearchParams(new FormData(reportForm));params.set('page','api');params.set('action','report');params.set('offset',offset);
  if(snapshot)params.set('snapshot',snapshot);
  try{
   const response=await fetch('?'+params);const data=await response.json();if(!response.ok)throw new Error(data.message);
   snapshot=data.snapshot;reportTotal=data.total;
   for(const key of ['start','end'])reportForm.elements[key].value=data.filters[key];
   result.replaceChildren();
   result.append(node('p',data.template_label+' · '+data.total+' rânduri','report-title'));
   result.append(node('p','Moment fix: '+localRo(data.snapshot_at)+' · Valabil până la '+localRo(data.snapshot_expires),'snapshot-note'));
   if(data.explanation)result.append(node('p',data.explanation,'fine'));
   const chips=node('div',undefined,'filter-chips');
   Object.entries(data.filters).filter(([k,v])=>v&&k!=='columns').forEach(([k,v])=>{
    const control=reportForm.elements[k];const text=control?.tagName==='SELECT'?control.selectedOptions[0]?.textContent:v;
    chips.append(node('span',(filterLabels[k]||k)+': '+text,'pill'));
   });result.append(chips);
   if('net_earned' in data.totals){
    const grid=node('div',undefined,'financial-summary');
    for(const [title,gross,adjustment,net] of [
     ['Credit câștigat','earned','earned_adjustment','net_earned'],
     ['Credit folosit','redeemed','redeemed_adjustment','net_redeemed'],
     ['Contribuții','contribution','contribution_adjustment','net_contribution']]){
      const card=node('article');card.append(node('h3',title));
      card.append(node('p','Brut: '+moneyRo(data.totals[gross])+' lei'));
      card.append(node('p','Corecții: '+moneyRo(data.totals[adjustment])+' lei'));
      card.append(node('strong','Net: '+moneyRo(data.totals[net])+' lei'));grid.append(card);
    }result.append(grid);
   }
   const totals=node('div',undefined,'report-totals');
   for(const [key,value] of Object.entries(data.totals)){
    if(['earned','earned_adjustment','net_earned','redeemed','redeemed_adjustment','net_redeemed','contribution','contribution_adjustment','net_contribution'].includes(key)&&'net_earned' in data.totals)continue;
    totals.append(node('p',(data.total_labels[key]||key)+': '+moneyRo(value)+' lei'));
   }result.append(totals);
   const wrap=node('div',undefined,'table-scroll');const table=node('table');const head=node('thead');const tr=node('tr');
   data.columns.forEach(c=>tr.append(node('th',c)));head.append(tr);table.append(head);const body=node('tbody');
   data.rows.forEach(row=>{
    const tr=node('tr');row.forEach((cell,index)=>{
     const td=node('td');
     if(['Referință','Cursă'].includes(data.columns[index])&&/^[a-f0-9]{32}$/.test(String(cell))){const a=node('a',cell+' ↗');a.href='?page=ride&id='+encodeURIComponent(cell);td.append(a);}else td.textContent=/RON$/.test(data.columns[index])&&/^-?\d+\.\d{2}$/.test(String(cell))?Number(cell).toLocaleString('ro-RO',{minimumFractionDigits:2,maximumFractionDigits:2}):cell;tr.append(td);
    });body.append(tr);
   });table.append(body);wrap.append(table);result.append(wrap);
   if(!data.rows.length)result.append(node('p','Nicio înregistrare pentru filtrele alese.','empty'));
   const nav=node('div',undefined,'actions');
   if(offset>0){const previous=node('button','← Anterior','button secondary');previous.type='button';previous.onclick=()=>preview(Math.max(0,offset-50));nav.append(previous);}
   if(offset+data.rows.length<data.total){const next=node('button','Următor →','button secondary');next.type='button';next.onclick=()=>preview(offset+50);nav.append(next);}result.append(nav);
   return true;
  }catch(error){snapshot='';result.replaceChildren(node('p',error.message||'Raport indisponibil. Reîncearcă.','alert error'));return false;}
 }
 reportForm.addEventListener('submit',event=>{event.preventDefault();filtersChanged();preview(0);});
 reportForm.addEventListener('reset',()=>setTimeout(()=>{filtersChanged();syncColumns();document.getElementById('report-result').replaceChildren(node('p','Filtre șterse. Previzualizează din nou.','empty'));},0));
 document.getElementById('download-report').addEventListener('click',async()=>{
  if(!reportForm.reportValidity())return;
  if(!snapshot&&!await preview(0))return;
  const params=new URLSearchParams(new FormData(reportForm));params.set('page','download');params.set('snapshot',snapshot);params.set('offset',Math.max(0,Number(reportForm.elements.row_start.value)-1));params.delete('row_start');
  location.href='?'+params;
 });
 document.getElementById('next-download').addEventListener('click',()=>{
  const next=Number(reportForm.elements.row_start.value)+Number(reportForm.elements.limit.value);
  if(!snapshot){notify('Previzualizează raportul înainte de a pregăti loturile.');return;}
  if(next>reportTotal){notify('Ai ajuns la ultimul lot al raportului.');return;}
  reportForm.elements.row_start.value=next;notify('Lotul următor începe de la rândul '+next+'. Apasă Descarcă acest lot.');
 });
 document.getElementById('save-preset').addEventListener('click',async()=>{
  syncColumns();try{await api('preset',Object.fromEntries(new FormData(reportForm)));location.reload();}catch(error){notify(error.message);}
 });
 document.getElementById('presets').addEventListener('change',event=>{
  const raw=event.target.selectedOptions[0]?.dataset.filters;if(!raw)return;
  const filters=JSON.parse(raw);
  Object.entries(filters).forEach(([key,value])=>{const control=reportForm.elements[key];if(control){
   if(control.tagName==='SELECT'&&!Array.from(control.options).some(o=>o.value===value)){const opt=node('option',value);opt.value=value;control.append(opt);}control.value=value;
  }});
  const chosen=filters.columns?filters.columns.split(','):null;
  reportForm.querySelectorAll('[data-report-column]').forEach(input=>input.checked=!chosen||chosen.includes(input.value));
  filtersChanged();preview(0);
 });
}

