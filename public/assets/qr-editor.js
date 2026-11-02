'use strict';
(()=>{
 const root=document.getElementById('design-editor');if(!root)return;
 const canvas=document.getElementById('qr-canvas'),status=document.getElementById('design-status'),select=document.getElementById('design-element');
 let design=JSON.parse(root.dataset.design),saved=JSON.stringify(design),selected='title',past=[],future=[],drag=null;
 const height=root.dataset.orientation==='landscape'?707:1414;
 const field=k=>document.getElementById('design-'+k),isQr=()=>['bonus','review'].includes(selected);
 function snapshot(){past.push(JSON.stringify(design));if(past.length>60)past.shift();future=[];}
 function clamp(e,qr){e.w=Math.round(Math.max(qr?160:60,Math.min(1000,e.w)));e.h=Math.round(Math.max(qr?160:30,Math.min(height,e.h)));if(qr)e.h=e.w=Math.min(e.w,height);e.x=Math.round(Math.max(0,Math.min(1000-e.w,e.x)));e.y=Math.round(Math.max(0,Math.min(height-e.h,e.y)));e.size=Math.round(Math.max(12,Math.min(100,e.size)));}
 function draw(){
  canvas.style.background=design.background;canvas.style.fontFamily={'sans':'"DejaVu Sans",sans-serif','serif':'"DejaVu Serif",serif','mono':'"DejaVu Sans Mono",monospace'}[design.font];
  for(const [id,e] of Object.entries(design.elements)){
   const el=document.getElementById('piece-'+id);Object.assign(el.style,{left:e.x/10+'%',top:e.y/height*100+'%',width:e.w/10+'%',height:e.h/height*100+'%',fontSize:e.size/10+'cqw',color:e.color,fontWeight:e.bold?'700':'400',textAlign:e.align});
   if(!['bonus','review'].includes(id)){let text=el.querySelector('.piece-text');if(!text){text=document.createElement('span');text.className='piece-text';el.replaceChildren(text,el.querySelector('.resize-handle'));}text.textContent=e.text;}
   el.classList.toggle('selected',id===selected);el.setAttribute('aria-pressed',String(id===selected));
  }
  select.value=selected;field('background').value=design.background;field('font').value=design.font;
  const e=design.elements[selected];for(const k of ['x','y','w','h','size','text','color','align']){field(k).value=e[k];}field('bold').checked=e.bold;
  for(const k of ['text','size','color','align','bold'])field(k).disabled=isQr();field('h').disabled=isQr();
  field('undo').disabled=!past.length;field('redo').disabled=!future.length;
  status.textContent=JSON.stringify(design)===saved?'Nicio modificare nesalvată. Starea publicării este afișată mai sus.':'Modificări nesalvate.';
  const clipped=[...canvas.querySelectorAll('[data-piece]')].filter(el=>!['bonus','review'].includes(el.dataset.piece)&&(el.scrollHeight>el.clientHeight+1||el.scrollWidth>el.clientWidth+1));
  if(clipped.length)status.textContent+=' Atenție: textul depășește caseta. Mărește caseta sau micșorează textul.';
 }
 select.addEventListener('change',()=>{selected=select.value;draw();});
 for(const k of ['background','font','x','y','w','h','size','text','color','align','bold']){
  field(k).addEventListener('focus',snapshot);
  field(k).addEventListener('input',()=>{
   if(k==='background'||k==='font')design[k]=field(k).value;
   else {const e=design.elements[selected];e[k]=k==='bold'?field(k).checked:['x','y','w','h','size'].includes(k)?Number(field(k).value):field(k).value;clamp(e,isQr());}
   // Preserve text caret during typing; draw only assigns the same input value.
   draw();
  });
 }
 canvas.addEventListener('pointerdown',event=>{
  const el=event.target.closest('[data-piece]');if(!el)return;event.preventDefault();selected=el.dataset.piece;snapshot();
  drag={id:selected,startX:event.clientX,startY:event.clientY,base:{...design.elements[selected]},resize:event.target.classList.contains('resize-handle'),width:canvas.getBoundingClientRect().width};
  canvas.setPointerCapture(event.pointerId);draw();
 });
 canvas.addEventListener('pointermove',event=>{
  if(!drag)return;const dx=(event.clientX-drag.startX)*1000/drag.width,dy=(event.clientY-drag.startY)*1000/drag.width;
  const e=design.elements[drag.id];if(drag.resize){e.w=drag.base.w+dx;e.h=drag.base.h+dy;}else{e.x=drag.base.x+dx;e.y=drag.base.y+dy;}clamp(e,isQr());draw();
 });
 canvas.addEventListener('pointerup',()=>drag=null);canvas.addEventListener('pointercancel',()=>drag=null);
 canvas.addEventListener('keydown',event=>{
  const el=event.target.closest('[data-piece]');if(!el)return;selected=el.dataset.piece;
  if(event.key==='Enter'||event.key===' '){event.preventDefault();draw();return;}
  const offsets={ArrowLeft:[-1,0],ArrowRight:[1,0],ArrowUp:[0,-1],ArrowDown:[0,1]}[event.key];if(!offsets)return;
  event.preventDefault();snapshot();const e=design.elements[selected],step=event.shiftKey?10:1;e.x+=offsets[0]*step;e.y+=offsets[1]*step;clamp(e,isQr());draw();
 });
 field('undo').addEventListener('click',()=>{if(!past.length)return;future.push(JSON.stringify(design));design=JSON.parse(past.pop());draw();});
 field('redo').addEventListener('click',()=>{if(!future.length)return;past.push(JSON.stringify(design));design=JSON.parse(future.pop());draw();});
 field('reset').addEventListener('click',()=>{snapshot();design=JSON.parse(root.dataset.default);draw();});
 let saving=false;
 field('save').addEventListener('click',async()=>{
  if(saving)return;saving=true;field('save').disabled=true;const payload=JSON.stringify(design);
  try{const result=await api('qr-design',{scope:root.dataset.scope,owner:root.dataset.driver,revision:Number(root.dataset.revision),orientation:root.dataset.orientation,design:payload});root.dataset.revision=result.revision;saved=payload;draw();status.textContent=result.message;if(JSON.stringify(design)===saved)location.reload();}
  catch(error){status.textContent=error.message;}finally{saving=false;field('save').disabled=false;}
 });
 async function decide(decision){
  const message=document.getElementById('template-action-status');
  if(JSON.stringify(design)!==saved){message.textContent='Salvați sau anulați modificările înainte de această acțiune.';return;}
  if(saving)return;saving=true;
  try{await api('qr-template-review',{scope:root.dataset.scope,owner:root.dataset.driver,orientation:root.dataset.orientation,revision:Number(root.dataset.revision),decision,note:document.getElementById('template-review-note')?.value||''});location.reload();}
  catch(error){message.textContent=error.message;}finally{saving=false;}
 }
 document.querySelectorAll('[data-template-decision]').forEach(button=>button.addEventListener('click',()=>decide(button.dataset.templateDecision)));
 document.getElementById('design-inherit')?.addEventListener('click',()=>decide('inherit'));
 window.addEventListener('beforeunload',event=>{if(JSON.stringify(design)!==saved){event.preventDefault();event.returnValue='';}});
 draw();
 document.fonts.ready.then(draw);
})();
