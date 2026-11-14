(function(){
const state={index:null,settings:null,notebookId:'',noteId:'',note:null,sort:'updated_desc',loadingNoteId:'',backgroundBusy:false,noteLoading:false,noteMemCache:{},noteFetches:{},editorHistory:{undo:[],last:'',max:80},dirty:false,saving:false,saveAgain:false,savePromise:null,pendingNoteId:'',noteGenerations:{},officePollers:{},officeSyncs:{}};
const $=id=>document.getElementById(id); const route=p=>OC.generateUrl('/apps/joplix'+p);

function formatJoplixDateTime(value){
  if(value===undefined||value===null)return '';
  const raw=String(value).trim();
  if(!raw)return '';
  const cleanMatch=raw.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:[, ]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/);
  if(cleanMatch){
    const dd=String(cleanMatch[1]).padStart(2,'0');
    const mm=String(cleanMatch[2]).padStart(2,'0');
    const yyyy=cleanMatch[3];
    if(cleanMatch[4]!==undefined){
      const hh=String(cleanMatch[4]).padStart(2,'0');
      const mi=String(cleanMatch[5]).padStart(2,'0');
      const ss=String(cleanMatch[6]||'00').padStart(2,'0');
      return dd+'.'+mm+'.'+yyyy+' '+hh+':'+mi+':'+ss;
    }
    return dd+'.'+mm+'.'+yyyy;
  }
  let d=null;
  if(/^\d{13}$/.test(raw))d=new Date(Number(raw));
  else if(/^\d{10}$/.test(raw))d=new Date(Number(raw)*1000);
  else d=new Date(raw);
  if(!d||Number.isNaN(d.getTime()))return raw;
  const pad=n=>String(n).padStart(2,'0');
  return pad(d.getDate())+'.'+pad(d.getMonth()+1)+'.'+d.getFullYear()+' '+pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
}
function jpOverlay(){return document.getElementById('jp-settings-overlay');}
function jpForceOpenSettings(){
  const o=jpOverlay();
  if(!o){alert('Einstellungsbereich wurde nicht gefunden.');return;}
  const p=document.getElementById('jp-setting-joplin-path');
  if(p&&!p.value)p.value='/JoplinV2';
  o.classList.add('open');
  o.setAttribute('aria-hidden','false');
  jpLoadFolderDatalist();
}
function jpForceCloseSettings(){const o=jpOverlay();if(o){o.classList.remove('open');o.setAttribute('aria-hidden','true');}}
async function jpLoadFolderDatalist(){
  try{
    const r=await fetch(route('/api/folders'),{headers:{'requesttoken':OC.requestToken||''}});
    if(!r.ok)return;
    const data=await r.json();
    const list=document.getElementById('jp-folder-list');
    if(list){list.innerHTML='';(data.folders||[]).forEach(f=>{const opt=document.createElement('option');opt.value=f;list.appendChild(opt);});}
    const p=document.getElementById('jp-setting-joplin-path');
    if(p&&!p.value)p.value=data.current||'/JoplinV2';
  }catch(e){}
}
window.JoplixOpenSettings=jpForceOpenSettings;
window.JoplixCloseSettings=jpForceCloseSettings;
let jpPathApplyTimer=null;
function jpStatusText(t,cls){const el=document.getElementById('jp-index-status');if(el){el.textContent=t;el.className='jp-index-status '+(cls||'');}}
function jpNormalizePath(p){p=String(p||'').trim();if(!p)return '/JoplinV2';if(!p.startsWith('/'))p='/'+p;return p.replace(/\\/g,'/').replace(/\/+/g, '/').replace(/\s+$/,'');}
async function jpApi(p,o={}){o.headers=Object.assign({'Content-Type':'application/json','requesttoken':OC.requestToken||''},o.headers||{});if(o.body&&typeof o.body!=='string')o.body=JSON.stringify(o.body);const r=await fetch(route(p),o);let d={};try{d=await r.json();}catch(e){}if(!r.ok||d.error)throw new Error(d.error||('HTTP '+r.status));return d;}

function normalizeCodebergRepoUrlInput(value){
  let v=String(value||'').trim();
  if(!v)return 'https://codeberg.org/Stoneubi/joplix/releases';
  v=v.replace(/^<a\s+[^>]*href=["']([^"']+)["'][^>]*>.*$/i,'$1');
  v=v.replace(/^<a\s+[^>]*>(.*?)<\/a>$/i,'$1');
  v=v.replace(/&amp;/g,'&');
  v=v.replace(/\s+/g,'');
  v=v.replace(/^http:\/\//i,'https://');
  if(/^codeberg\.(org|com)\//i.test(v))v='https://'+v;
  v=v.replace(/^https:\/\/www\.codeberg\./i,'https://codeberg.');
  v=v.replace(/\.git$/i,'');
  v=v.replace(/[?#].*$/,'');
  v=v.replace(/\/+$/,'');
  const m=v.match(/^https:\/\/codeberg\.(?:org|com)\/([^\/]+)\/([^\/]+)(?:\/releases)?$/i);
  if(m)return 'https://codeberg.org/'+m[1]+'/'+m[2].replace(/\.git$/i,'')+'/releases';
  return v;
}

function jpLocalSettings(){let local={};try{local=JSON.parse(localStorage.getItem('joplix.settings')||'{}')||{};}catch(e){local={};}return Object.assign({remember_last_location:'yes',sort_order:'updated_desc',joplin_path:'/JoplinV2',update_source:'codeberg',update_repo_url:'https://codeberg.org/Stoneubi/joplix/releases',update_channel:'main',office_open_mode:'window',update_backup_enabled:'no'},local,window.JoplixServerSettings||{});}
function jpSetLocalSettings(local){try{localStorage.setItem('joplix.settings',JSON.stringify(local));}catch(e){}}
async function jpApplySettingsFromUi(forceReindex){
  const pathEl=document.getElementById('jp-setting-joplin-path');
  const sortEl=document.getElementById('jp-setting-sort');
  const old=(window.JoplixServerSettings&&window.JoplixServerSettings.joplin_path)||jpLocalSettings().joplin_path||'/JoplinV2';
  const local=jpLocalSettings();
  local.joplin_path=jpNormalizePath(pathEl?pathEl.value:local.joplin_path);
  local.sort_order=sortEl?sortEl.value:(local.sort_order||'updated_desc');
  local.remember_last_location=local.remember_last_location||'yes';
  const urlEl=document.getElementById('jp-update-repo-url'); const chEl=document.getElementById('jp-update-channel');
  local.update_source='codeberg'; local.update_repo_url=urlEl?urlEl.value:(local.update_repo_url||'https://codeberg.org/Stoneubi/joplix/releases'); local.update_channel=chEl?chEl.value:(local.update_channel||'main');
  if(pathEl)pathEl.value=local.joplin_path;
  jpSetLocalSettings(local);
  window.JoplixServerSettings=Object.assign({},local,window.JoplixServerSettings||{});
  await jpApi('/api/settings',{method:'POST',body:local});
  const pathChanged=local.joplin_path!==old;
  if(pathChanged||forceReindex){
    try{localStorage.removeItem('joplix.lastGoodIndex');localStorage.removeItem('joplix.lastLocation');}catch(e){}
    jpStatusText('Einstellungen übernommen. Index wird mit '+local.joplin_path+' neu aufgebaut...','warning');
    try{
      const r=await jpApi('/api/index/rebuild',{method:'POST'});
      if(window.JoplixAfterRebuild)window.JoplixAfterRebuild(r.index||r);
      else location.reload();
      jpStatusText('Einstellungen übernommen, Index aktualisiert','ok');
    }catch(e){jpStatusText('Einstellungen gespeichert, aber Index-Neuaufbau fehlgeschlagen: '+e.message,'error');}
  } else {
    jpStatusText('Einstellungen übernommen','ok');
  }
}
function jpActivateSettingsTab(tab){
  document.querySelectorAll('[data-settings-tab]').forEach(b=>b.classList.toggle('active',b.dataset.settingsTab===tab));
  document.querySelectorAll('[data-settings-section]').forEach(sec=>sec.classList.toggle('active',sec.dataset.settingsSection===tab));
}
document.addEventListener('click',function(ev){
  const openBtn=ev.target&&ev.target.closest?ev.target.closest('#jp-open-settings'):null;
  if(openBtn){ev.preventDefault();ev.stopPropagation();jpForceOpenSettings();return;}
  const closeBtn=ev.target&&ev.target.closest?ev.target.closest('#jp-close-settings'):null;
  if(closeBtn){ev.preventDefault();ev.stopPropagation();jpApplySettingsFromUi(false).finally(()=>jpForceCloseSettings());return;}
  const backdrop=ev.target&&ev.target.id==='jp-settings-backdrop';
  if(backdrop){ev.preventDefault();jpApplySettingsFromUi(false).finally(()=>jpForceCloseSettings());return;}
  const tab=ev.target&&ev.target.closest?ev.target.closest('[data-settings-tab]'):null;
  if(tab){ev.preventDefault();ev.stopPropagation();jpActivateSettingsTab(tab.dataset.settingsTab);return;}
  const rebuild=ev.target&&ev.target.closest?ev.target.closest('#jp-settings-rebuild'):null;
  if(rebuild){ev.preventDefault();ev.stopPropagation();jpApplySettingsFromUi(true);return;}
  const remember=ev.target&&ev.target.closest?ev.target.closest('#jp-setting-remember-toggle'):null;
  if(remember){ev.preventDefault();ev.stopPropagation();const local=jpLocalSettings();local.remember_last_location=(local.remember_last_location||'yes')==='yes'?'no':'yes';remember.classList.toggle('active',local.remember_last_location==='yes');remember.setAttribute('aria-checked',local.remember_last_location==='yes'?'true':'false');jpSetLocalSettings(local);jpApi('/api/settings',{method:'POST',body:local}).then(()=>jpStatusText('Einstellungen übernommen','ok')).catch(e=>jpStatusText('Einstellungen konnten nicht gespeichert werden: '+e.message,'error'));return;}
},true);
document.addEventListener('change',function(ev){
  if(ev.target&&ev.target.id==='jp-setting-sort'){jpApplySettingsFromUi(false);}
  if(ev.target&&ev.target.id==='jp-setting-joplin-path'){jpApplySettingsFromUi(true);}
},true);
document.addEventListener('keydown',function(ev){
  if(ev.key==='Escape'){jpApplySettingsFromUi(false).finally(()=>jpForceCloseSettings());}
  if(ev.key==='Enter'&&ev.target&&ev.target.id==='jp-setting-joplin-path'){ev.preventDefault();jpApplySettingsFromUi(true);}
},true);
document.addEventListener('input',function(ev){
  if(ev.target&&ev.target.id==='jp-setting-joplin-path'){
    clearTimeout(jpPathApplyTimer);
    jpPathApplyTimer=setTimeout(()=>jpApplySettingsFromUi(true),1200);
  }
},true);
const storage={
  get(k,d=null){try{const v=localStorage.getItem('joplix.'+k);return v?JSON.parse(v):d;}catch(e){return d;}},
  set(k,v){try{localStorage.setItem('joplix.'+k,JSON.stringify(v));}catch(e){}},
  del(k){try{localStorage.removeItem('joplix.'+k);}catch(e){}}
};
function clamp(n,min,max){return Math.max(min,Math.min(max,n));}
function applyColumnWidths(w){
  const app=$('joplix-app'); if(!app)return;
  w=w||storage.get('columnWidths',{left:310,right:370});
  const appWidth=Math.max(900,Math.floor((app.getBoundingClientRect&&app.getBoundingClientRect().width)||window.innerWidth||1400));
  const gutter=16;
  const minLeft=240, maxLeft=Math.min(420,Math.floor(appWidth*.30));
  const minRight=300, maxRight=Math.min(520,Math.floor(appWidth*.34));
  const minMiddle=Math.min(520,Math.max(360,Math.floor(appWidth*.28)));
  let left=clamp(Number(w.left)||310,minLeft,Math.max(minLeft,maxLeft));
  let right=clamp(Number(w.right)||370,minRight,Math.max(minRight,maxRight));
  const maxSideSpace=Math.max(minLeft+minRight,appWidth-gutter-minMiddle-16);
  if(left+right>maxSideSpace){
    const over=left+right-maxSideSpace;
    const reduceLeft=Math.min(Math.max(0,left-minLeft),Math.ceil(over/2));
    left-=reduceLeft;
    right=Math.max(minRight,right-(over-reduceLeft));
  }
  app.style.setProperty('grid-template-columns',left+'px 8px minmax('+minMiddle+'px,1fr) 8px '+right+'px','important');
  app.style.setProperty('--jp-left',left+'px');
  app.style.setProperty('--jp-right',right+'px');
  storage.set('columnWidths',{left,right});
}
function setupResizableColumns(){
  const app=$('joplix-app'); if(!app)return;
  applyColumnWidths();
  window.addEventListener('resize',()=>applyColumnWidths(storage.get('columnWidths',{left:310,right:370})));
  document.querySelectorAll('.jp-resizer').forEach(handle=>{
    /* Existing oversized local column widths from 1.0.25 are normalized by applyColumnWidths. */
    const side=handle.dataset.resize;
    const beginResize=ev=>{
      if(ev.button!==undefined&&ev.button!==0)return;
      ev.preventDefault();
      const startX=ev.clientX;
      const cur=storage.get('columnWidths',{left:310,right:370});
      const startLeft=Number(cur.left)||310, startRight=Number(cur.right)||370;
      document.body.classList.add('jp-resizing'); handle.classList.add('dragging');
      try{handle.setPointerCapture&&handle.setPointerCapture(ev.pointerId);}catch(e){}
      const move=e=>{
        const dx=e.clientX-startX;
        const next={left:startLeft,right:startRight};
        if(side==='left')next.left=startLeft+dx;
        if(side==='right')next.right=startRight-dx;
        applyColumnWidths(next);
      };
      const up=e=>{
        document.body.classList.remove('jp-resizing');
        handle.classList.remove('dragging');
        try{handle.releasePointerCapture&&handle.releasePointerCapture(e.pointerId);}catch(err){}
        handle.removeEventListener('pointermove',move);
        handle.removeEventListener('pointerup',up);
        handle.removeEventListener('pointercancel',up);
      };
      handle.addEventListener('pointermove',move);
      handle.addEventListener('pointerup',up);
      handle.addEventListener('pointercancel',up);
    };
    handle.addEventListener('pointerdown',beginResize);
    handle.addEventListener('dblclick',()=>{
      const defaults=side==='left'?{left:310,right:(storage.get('columnWidths',{right:370}).right||370)}:{left:(storage.get('columnWidths',{left:310}).left||310),right:370};
      applyColumnWidths(defaults);
    });
  });
}
function isEmptyIndex(idx){return !idx||(!(idx.tree||[]).length&&!(idx.notebooks||[]).length&&!(idx.notes||[]).length);}
function hasFullTextIndex(idx){return !!(idx&&idx.search_version>=1&&(idx.notes||[]).every(n=>Object.prototype.hasOwnProperty.call(n,'search_text')));}
function hasCurrentCountIndex(idx){return !!(idx&&idx.count_version>=2);}
function visibleNoteCountForNotebook(id){return ((state.index&&state.index.notes)||[]).filter(n=>n.notebook_id===id).length;}
function notebookTitleWithCount(n){const c=visibleNoteCountForNotebook(n.id);return (n.title||'')+(c>0?' ('+c+')':'');}
function normalizeSearchText(v){return String(v||'').toLocaleLowerCase();}
function noteMatchesQuery(n,q){if(!q)return true;const hay=[n.title,n.search_text,n.notebook_title,(n.tag_titles||[]).join(' '),(n.resource_titles||[]).join(' ')].map(normalizeSearchText).join(' ');return q.split(/\s+/).filter(Boolean).every(part=>hay.includes(part));}
function showNotebookNotes(){if(!state.index)return; if(state.notebookId)selectNotebook(state.notebookId); else renderNotes([]);}
function runFullTextSearch(raw){const q=normalizeSearchText(raw).trim();if(!q){showNotebookNotes();status(state.index?'Index aktuell':'Index wird geladen...','ok');return;}const notes=((state.index&&state.index.notes)||[]).filter(n=>noteMatchesQuery(n,q)).sort(sorter());renderNotes(notes);$('jp-breadcrumb').textContent='Volltextsuche: '+raw;status(notes.length+' Treffer für „'+raw+'“','ok');}
function rememberIndex(idx){if(idx&&!isEmptyIndex(idx))storage.set('lastGoodIndex',idx);}
function rememberedIndex(){return storage.get('lastGoodIndex',null);}
function localLocation(){return storage.get('lastLocation',{notebook_id:'',note_id:''});}
function firstNotebookId(idx){const tree=(idx&&idx.tree)||[];return tree.length?tree[0].id:'';}

function jpEscapeHtml(v){return String(v||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function jpInlineMarkdown(t){return jpEscapeHtml(t).replace(/`([^`]+)`/g,'<code>$1</code>').replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>').replace(/\*([^*]+)\*/g,'<em>$1</em>').replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,'<a href="$2" target="_blank" rel="noreferrer">$1</a>');}
function jpRenderMarkdown(md){
  const lines=String(md||'').split(/\r?\n/);
  let html='',listType='',inCode=false,code=[];
  function closeList(){
    if(listType){html+=(listType==='ol'?'</ol>':'</ul>');listType='';}
  }
  function openList(type){
    if(listType===type)return;
    closeList();
    if(type==='task')html+='<ul class="jp-md-list jp-md-task-list">';
    else if(type==='ul')html+='<ul class="jp-md-list">';
    else html+='<ol class="jp-md-list">';
    listType=type;
  }
  for(let i=0;i<lines.length;i++){
    const line=lines[i];
    if(line.trim().startsWith('```')){
      if(inCode){html+='<pre><code>'+jpEscapeHtml(code.join('\n'))+'</code></pre>';code=[];inCode=false;}
      else{closeList();inCode=true;}
      continue;
    }
    if(inCode){code.push(line);continue;}
    if(/^\s*$/.test(line)){
      const nextLine=lines[i+1]||'';
      const nextIsTask=/^\s*[-*]\s+\[([ xX])\]\s+/.test(nextLine);
      // Leerzeilen zwischen Checkbox-Zeilen duerfen die Task-Liste nicht
      // schliessen. Joplin speichert Listen oft mit Leerzeilen zwischen den
      // Eintraegen; optisch gehoeren sie trotzdem zu einer gemeinsamen Liste.
      if(listType==='task'&&nextIsTask)continue;
      closeList();html+='<p></p>';continue;
    }
    let m=line.match(/^\s*[-*]\s+\[([ xX])\]\s+(.*)$/);
    if(m){
      openList('task');
      html+='<li class="jp-md-task"><label class="jp-md-task-label"><input type="checkbox" class="jp-md-checkbox" data-line="'+i+'" '+(m[1].toLowerCase()==='x'?'checked':'')+'><span class="jp-md-task-text">'+jpInlineMarkdown(m[2])+'</span></label></li>';
      continue;
    }
    m=line.match(/^\s*[-*]\s+(.*)$/);
    if(m){openList('ul');html+='<li>'+jpInlineMarkdown(m[1])+'</li>';continue;}
    m=line.match(/^\s*\d+\.\s+(.*)$/);
    if(m){openList('ol');html+='<li>'+jpInlineMarkdown(m[1])+'</li>';continue;}
    closeList();
    if(/^###\s+/.test(line))html+='<h3>'+jpInlineMarkdown(line.replace(/^###\s+/,''))+'</h3>';
    else if(/^##\s+/.test(line))html+='<h2>'+jpInlineMarkdown(line.replace(/^##\s+/,''))+'</h2>';
    else if(/^#\s+/.test(line))html+='<h1>'+jpInlineMarkdown(line.replace(/^#\s+/,''))+'</h1>';
    else if(/^---+$/.test(line.trim()))html+='<hr>';
    else html+='<p>'+jpInlineMarkdown(line)+'</p>';
  }
  closeList();
  if(inCode)html+='<pre><code>'+jpEscapeHtml(code.join('\n'))+'</code></pre>';
  return html||'<p class="jp-muted">Keine Notizinhalte.</p>';
}
function renderDisplayMode(){const d=$('jp-note-display'),ta=$('jp-body');if(!d||!ta)return;d.innerHTML=jpRenderMarkdown(ta.value||'');d.querySelectorAll('.jp-md-checkbox').forEach(cb=>cb.addEventListener('change',onDisplayCheckboxChange));updateSaveButton();}
function setNoteMode(mode){state.displayMode=mode==='edit'?'edit':'view';const app=$('joplix-app');if(app){app.classList.toggle('jp-edit-mode',state.displayMode==='edit');app.classList.toggle('jp-view-mode',state.displayMode!=='edit');}if(state.displayMode!=='edit')renderDisplayMode();}
function onDisplayCheckboxChange(ev){const cb=ev.currentTarget,ta=$('jp-body');if(!ta||!state.note)return;const idx=parseInt(cb.getAttribute('data-line'),10);const lines=(ta.value||'').split(/\r?\n/);if(isNaN(idx)||!lines[idx])return;lines[idx]=lines[idx].replace(/^(\s*[-*]\s+\[)[ xX](\]\s+)/,'$1'+(cb.checked?'x':' ')+'$2');ta.value=lines.join('\n');markDirty();}

function noteSnapshot(){return {title:($('jp-title')?$('jp-title').value:''),body:($('jp-body')?$('jp-body').value:'')};}
function sameSnapshot(a,b){return !!a&&!!b&&String(a.title||'')===String(b.title||'')&&String(a.body||'')===String(b.body||'');}
function setOriginalFromEditor(){state.originalNote=noteSnapshot();state.dirty=false;updateSaveButton();}
function markDirty(){state.dirty=!sameSnapshot(state.originalNote,noteSnapshot());updateSaveButton();}
function updateSaveButton(){const buttons=[$('jp-save-note'),$('jp-save-note-top')].filter(Boolean);const active=!!state.dirty&&!state.saving;buttons.forEach(btn=>{btn.disabled=!active;btn.classList.toggle('is-dirty',active);btn.title=active?'Änderungen speichern':'Keine Änderungen zum Speichern';});const app=$('joplix-app');if(app)app.classList.toggle('jp-note-dirty',active);}
function setSavingUi(active){
  const app=$('joplix-app');
  if(app)app.classList.toggle('jp-saving',!!active);
  const meta=$('jp-meta');
  if(!meta)return;
  if(active){
    if(!meta.dataset.beforeSaving)meta.dataset.beforeSaving=meta.textContent||'';
    meta.innerHTML='<span class="jp-saving-spinner" aria-hidden="true"></span><span>Aenderungen werden gespeichert...</span>';
  }else if(meta.dataset.beforeSaving){
    meta.textContent=meta.dataset.beforeSaving;
    delete meta.dataset.beforeSaving;
  }
}
function noteCacheKey(id){return 'noteCache_'+id;}
function cachedNote(id){
  const note=storage.get(noteCacheKey(id),null);
  return note?.bodyFormat===2?note:null;
}
function rememberNote(n){
  if(!n||!n.id)return;
  noteMemorySet(n);
  const item={id:n.id,title:n.title||'',body:String(n.body||''),updated_time:n.updated_time||'',notebook_title:n.notebook_title||'',tags:n.tags||[],resources:n.resources||[],cached_at:Date.now()};
  // Older cache entries may contain a body stripped by Beta 63.
  item.bodyFormat=2;
  if(item.body.length>700000){
    storage.del(noteCacheKey(n.id));
    return;
  }
  storage.set(noteCacheKey(n.id),item);
  const ids=(storage.get('recentNoteCacheIds',[])||[]).filter(x=>x!==n.id);
  ids.unshift(n.id);
  while(ids.length>30){const old=ids.pop();storage.del(noteCacheKey(old));}
  storage.set('recentNoteCacheIds',ids);
}
function noteMemoryGet(id){return id&&state.noteMemCache?state.noteMemCache[id]:null;}
function noteMemorySet(n){if(!n||!n.id)return n;state.noteMemCache[n.id]=n;const keys=Object.keys(state.noteMemCache);while(keys.length>50){delete state.noteMemCache[keys.shift()];}return n;}
function apiNote(id){
  if(!id)return Promise.reject(new Error('Keine Notiz-ID'));
  if(state.noteFetches[id])return state.noteFetches[id];
  const generation=state.noteGenerations[id]||0;
  state.noteFetches[id]=api('/api/notes/'+id).then(n=>{
    // Eine Anfrage, die vor einem erfolgreichen lokalen Speichern gestartet wurde,
    // darf den gerade gespeicherten Body nicht mit einer aelteren Antwort ersetzen.
    if((state.noteGenerations[id]||0)!==generation){return noteMemoryGet(id)||n;}
    noteMemorySet(n);rememberNote(n);return n;
  }).finally(()=>{delete state.noteFetches[id];});
  return state.noteFetches[id];
}
function prefetchNote(id){if(!id||noteMemoryGet(id)||cachedNote(id)||state.noteFetches[id])return;apiNote(id).catch(()=>{});}
function prefetchVisibleNotes(notes){(notes||[]).slice(0,4).forEach((n,i)=>setTimeout(()=>prefetchNote(n.id),250+i*350));}
function findNoteMeta(id){return ((state.index&&state.index.notes)||[]).find(n=>n.id===id)||{};}
function showNoteLoading(id,title=''){
  state.noteLoading=true;
  state.noteId=id||state.noteId||'';
  ensureNoteSelected(state.noteId);
  const meta=findNoteMeta(id);
  if($('jp-title'))$('jp-title').value=title||meta.title||'Notiz wird geladen...';
  if($('jp-meta'))$('jp-meta').innerHTML='<span class="jp-note-loading-dot"></span> Notizinhalt wird geladen...';
  if($('jp-body')){
    $('jp-body').value='';renderDisplayMode();
    $('jp-body').placeholder='Notiz wird geladen. Der Inhalt wird gleich angezeigt.';
    $('jp-body').classList.add('jp-note-loading');
  }
  if($('jp-tags'))$('jp-tags').innerHTML='';
  if($('jp-resources'))$('jp-resources').innerHTML='<div class="jp-note-loading-card">Notiz wird geladen...</div>';
  updateNotePath();
  updateSaveButton();
}
function renderNoteToEditor(n,fromCache=false){
  state.note=n;state.noteId=n.id;state.noteLoading=false;
  ensureNoteSelected(n.id);
  if($('jp-title'))$('jp-title').value=n.title||'';
  if($('jp-body')){$('jp-body').classList.remove('jp-note-loading');$('jp-body').placeholder='Notizinhalt';$('jp-body').value=n.body||'';renderDisplayMode();setNoteMode('view');}
  if($('jp-meta'))$('jp-meta').textContent=(fromCache?'Aus lokalem Schnellcache, wird aktualisiert... ':'')+'Aktualisiert: '+formatJoplixDateTime(n.updated_time)+' | Notizbuch: '+(n.notebook_title||'');
  renderTags(n.tags||[]);
  renderResources(n.resources||[]);
  updateNotePath();
  setOriginalFromEditor();
  resetEditorHistory($('jp-body')?$('jp-body').value:'');renderDisplayMode();
}
function ensureNoteSelected(id){document.querySelectorAll('.jp-note-item').forEach(b=>b.classList.toggle('active',b.dataset.id===id));}
async function api(p,o={}){o.headers=Object.assign({'Content-Type':'application/json','requesttoken':OC.requestToken||''},o.headers||{});if(o.body&&typeof o.body!=='string')o.body=JSON.stringify(o.body);const r=await fetch(route(p),o);const d=await r.json();if(!r.ok||d.error)throw new Error(d.error||'HTTP '+r.status);noteLocalChangeForSync(p,o,d);return d;}
function status(t,cls=''){const el=$('jp-index-status');el.textContent=t;el.className='jp-index-status '+cls;}
function restoreLocationWithoutClearing(idx){
  const loc=localLocation();
  const nb=(loc.notebook_id&&((idx.notebooks||[]).find(n=>n.id===loc.notebook_id)))?loc.notebook_id:firstNotebookId(idx);
  if(nb){
    selectNotebook(nb,{keepNote:true,skipSave:true});
    if(loc.note_id)openNote(loc.note_id,{silent:true,preferCache:true}).catch(()=>{});
  }
}
function renderNotesForCurrentNotebook(keepNote=true){
  if(!state.index||!state.notebookId)return;
  const notes=(state.index.notes||[]).filter(n=>n.notebook_id===state.notebookId).sort(sorter());
  renderNotes(notes);
  ensureNoteSelected(state.noteId);
  $('jp-breadcrumb').textContent=breadcrumb(state.notebookId);
  if(!keepNote)clearNote();
}
function applyIndexPreservingFocus(idx,reason=''){
  if(!idx||isEmptyIndex(idx))return;
  const previousNotebook=state.notebookId;
  const previousNote=state.noteId;
  const hadDirty=!!state.dirty;
  state.index=idx;
  rememberIndex(idx);
  renderTree(idx.tree||[]);
  if(previousNotebook&&((idx.notebooks||[]).find(n=>n.id===previousNotebook))){
    state.notebookId=previousNotebook;
    document.querySelectorAll('.jp-tree-item').forEach(b=>b.classList.toggle('active',b.dataset.id===previousNotebook));
    document.querySelectorAll('.jp-tree-row').forEach(r=>r.classList.toggle('active',r.dataset.id===previousNotebook));
    renderNotesForCurrentNotebook(true);
    if(previousNote){state.noteId=previousNote;ensureNoteSelected(previousNote);}
    if(!hadDirty&&previousNote){openNote(previousNote,{silent:true,preferCache:true}).catch(()=>{});} else {updateNotePath();}
  } else {
    restoreLocationWithoutClearing(idx);
  }
  if(reason)status(reason,'ok');
}
async function load(){
  state.settings=await api('/api/settings').catch(()=>({remember_last_location:'yes',sort_order:'updated_desc',joplin_path:'/JoplinV2'})); window.JoplixServerSettings=Object.assign({},state.settings);
  state.sort=state.settings.sort_order||(storage.get('settings',{}).sort_order)||'updated_desc';
  translateStaticUi();
  fillSettings();
  const cached=rememberedIndex();
  let usedCached=false;
  if(cached&&hasFullTextIndex(cached)&&hasCurrentCountIndex(cached)){
    usedCached=true;
    state.index=cached;
    renderIndex(cached);
    status('Lokaler Index sofort geladen. Änderungen werden im Hintergrund geprüft...','ok');
    restoreLocation(cached);
  }
  let idx=await api('/api/index').catch(e=>null);
  if(idx&&!isEmptyIndex(idx)&&(!hasFullTextIndex(idx)||!hasCurrentCountIndex(idx))){
    if(!usedCached)status(!hasFullTextIndex(idx)?'Alter Index ohne Volltextsuche erkannt, Neuaufbau läuft...':'Alter Index mit fehlerhaften Zählerständen erkannt, Neuaufbau läuft...','warning');
    const rebuilt=await api('/api/index/rebuild',{method:'POST'}).catch(e=>{status('Index-Neuaufbau fehlgeschlagen: '+e.message,'error');return null;});
    idx=rebuilt?(rebuilt.index||rebuilt):idx;
  }
  if(!idx||isEmptyIndex(idx)){
    if(!usedCached)status('Index leer oder nicht vorhanden, erster Neuaufbau läuft...','warning');
    const rebuilt=await api('/api/index/rebuild',{method:'POST'}).catch(e=>{status('Index-Neuaufbau fehlgeschlagen: '+e.message,'error');return null;});
    idx=rebuilt?(rebuilt.index||rebuilt):idx;
  }
  if(idx&&!isEmptyIndex(idx)){
    if(usedCached){
      applyIndexPreservingFocus(idx,'Server-Index geladen. Änderungen werden im Hintergrund geprüft...');
    } else {
      state.index=idx;
      rememberIndex(idx);
      renderIndex(idx);
      status('Index aktuell','ok');
      restoreLocation(idx);
    }
    scheduleBackgroundRefresh(900);
  } else if(!cached){
    renderIndex({tree:[],notes:[],notebooks:[]});
    status('Kein Index vorhanden. Bitte Joplix-Ordner in den Einstellungen prüfen.','error');
  } else {
    scheduleBackgroundRefresh(900);
  }
}
function restoreLocation(idx){
  const loc=localLocation();
  const nb=(loc.notebook_id&&((idx.notebooks||[]).find(n=>n.id===loc.notebook_id)))?loc.notebook_id:firstNotebookId(idx);
  if(nb){
    selectNotebook(nb,{keepNote:true,skipSave:true});
    if(loc.note_id)openNote(loc.note_id,{preferCache:true,silent:true}).catch(()=>{});
  }
}
function fillSettings(){/* Einstellungen in 1.0.6 voruebergehend deaktiviert */}
function renderIndex(idx){rememberIndex(idx);renderTree(idx.tree||[]);renderNotes([]);}
function renderTree(nodes){const root=$('jp-tree');root.innerHTML='';const walk=(n,l)=>{const row=document.createElement('div');row.className='jp-tree-row';row.dataset.id=n.id;row.style.paddingLeft=(4+l*18)+'px';const b=document.createElement('button');b.className='jp-tree-item';b.dataset.id=n.id;b.onclick=()=>selectNotebook(n.id);const icon=document.createElement('span');icon.className='jp-folder-icon';icon.setAttribute('aria-hidden','true');const label=document.createElement('span');label.className='jp-tree-label';label.textContent=notebookTitleWithCount(n);b.appendChild(icon);b.appendChild(label);const menu=document.createElement('button');menu.className='jp-tree-menu';menu.textContent='⋯';menu.title='Aktionen';menu.onclick=(ev)=>{ev.stopPropagation();showNotebookMenu(n,row,menu);};row.appendChild(b);row.appendChild(menu);root.appendChild(row);(n.children||[]).forEach(c=>walk(c,l+1));};nodes.forEach(n=>walk(n,0));}
function selectNotebook(id,opts={}){state.notebookId=id;document.querySelectorAll('.jp-tree-item').forEach(b=>b.classList.toggle('active',b.dataset.id===id));document.querySelectorAll('.jp-tree-row').forEach(r=>r.classList.toggle('active',r.dataset.id===id));const notes=(state.index.notes||[]).filter(n=>n.notebook_id===id).sort(sorter());renderNotes(notes);$('jp-breadcrumb').textContent=breadcrumb(id);if(!opts.keepNote)clearNote();else ensureNoteSelected(state.noteId);if(!opts.skipSave)saveLastLocation();}
function breadcrumb(id){const names=[];let guard=0;while(id&&guard<20){const nb=(state.index.notebooks||[]).find(n=>n.id===id);if(!nb)break;names.unshift(nb.title);id=nb.parent_id;guard++;}return names.join(' › ')||'Notizbuch';}
function sorter(){return (a,b)=>state.sort==='title_asc'?a.title.localeCompare(b.title):(state.sort==='updated_asc'?String(a.updated_time).localeCompare(String(b.updated_time)):String(b.updated_time).localeCompare(String(a.updated_time)));}
function renderNotes(notes){const box=$('jp-notes');box.innerHTML='';if(!notes.length){box.innerHTML='<p>Keine Notizen gefunden.</p>';return;}notes.forEach(n=>{const b=document.createElement('button');b.className='jp-note-item';b.dataset.id=n.id;b.innerHTML='<strong></strong><span></span><small></small>';b.querySelector('strong').textContent=n.title;b.querySelector('span').textContent=formatJoplixDateTime(n.updated_time);b.querySelector('small').textContent=(n.resource_count?('📎 '+n.resource_count+' '):'')+(n.tag_count?('🏷 '+n.tag_count):'');b.onclick=()=>openNote(n.id);b.addEventListener('contextmenu',ev=>showNoteContextMenu(ev,n));box.appendChild(b);});}
async function handleDirtyBeforeNoteChange(targetId){
  // Wenn der Benutzer unmittelbar nach "Speichern" zu einer anderen Notiz wechselt,
  // wartet der Wechsel zuerst auf denselben laufenden Speichervorgang. Dadurch wird
  // keine falsche Dirty-State-Warnung angezeigt.
  if(state.savePromise){
    state.pendingNoteId=targetId;
    ensureNoteSelected(targetId);
    setSavingUi(true);
    try{await state.savePromise;}
    catch(e){alert('Speichern fehlgeschlagen: '+(e&&e.message?e.message:e));state.pendingNoteId='';ensureNoteSelected(state.noteId);return false;}
    finally{setSavingUi(false);}
    state.pendingNoteId='';
  }
  if(!state.dirty||!state.noteId||state.noteId===targetId)return true;
  const fromId=state.noteId;
  const saveFirst=confirm('Diese Notiz hat ungespeicherte Änderungen.\n\nOK = Änderungen speichern und wechseln.\nAbbrechen = weitere Auswahl.');
  if(saveFirst){
    try{
      state.pendingNoteId=targetId;
      const ok=await saveNote({keepMode:true,skipReload:true,fastDirtySave:true});
      state.pendingNoteId='';
      if(ok===false){ensureNoteSelected(fromId);return false;}
      return !state.dirty;
    }catch(e){
      state.pendingNoteId='';
      alert('Speichern vor dem Wechsel fehlgeschlagen: '+(e&&e.message?e.message:e));
      ensureNoteSelected(fromId);
      return false;
    }
  }
  const discard=confirm('Änderungen verwerfen und trotzdem zu einer anderen Notiz wechseln?');
  if(discard){state.dirty=false;updateSaveButton();return true;}
  ensureNoteSelected(fromId);
  return false;
}
async function openNote(id,opts={}){
  if(!opts.silent&&!(await handleDirtyBeforeNoteChange(id))){ensureNoteSelected(state.noteId);return;}
  state.loadingNoteId=id;
  const meta=findNoteMeta(id);
  const mem=noteMemoryGet(id);
  const cached=cachedNote(id);
  if(mem){renderNoteToEditor(mem,false);if(!opts.silent)saveLastLocation();if(!opts.forceRefresh)return;}
  else if(cached&&(opts.preferCache||opts.silent||state.noteId!==id)){renderNoteToEditor(cached,true);}
  else if(!(state.note&&state.noteId===id)){showNoteLoading(id,meta.title||'');}
  else {state.noteLoading=true;if($('jp-meta'))$('jp-meta').textContent='Notiz wird aktualisiert...';}
  try{
    const n=await apiNote(id);
    if(state.loadingNoteId!==id)return;
    if(opts.silent&&state.dirty&&state.noteId===id){rememberNote(n);return;}
    renderNoteToEditor(n,false);
    if(!opts.silent)saveLastLocation();
  }catch(e){
    state.noteLoading=false;
    if(cached||mem){const cn=mem||cached;if($('jp-meta'))$('jp-meta').textContent='Schnellcache: '+formatJoplixDateTime(cn.updated_time)+' | Aktualisierung fehlgeschlagen: '+e.message;status('Notiz aus Schnellcache angezeigt. Aktualisierung fehlgeschlagen: '+e.message,'warning');return;}
    showNoteLoading(id,meta.title||'Notiz');
    if($('jp-meta'))$('jp-meta').textContent='Notiz konnte nicht geladen werden: '+e.message;
    throw e;
  }
}
function clearNote(){state.note=null;state.noteId='';state.originalNote=null;state.dirty=false;state.noteLoading=false;$('jp-title').value='';$('jp-body').classList.remove('jp-note-loading');$('jp-body').value='';renderDisplayMode();$('jp-body').placeholder='Bitte rechts eine Notiz auswählen.';$('jp-meta').textContent='Keine Notiz geöffnet';$('jp-tags').innerHTML='';$('jp-resources').innerHTML='';updateNotePath();updateSaveButton();}
function renderTags(tags){
  const box=$('jp-tags');box.innerHTML='';
  normalizeLocalTags(tags).forEach(t=>{
    const chip=document.createElement('span');chip.className='jp-tag-chip';
    const label=document.createElement('span');label.className='jp-tag-label';label.textContent=t.title;chip.appendChild(label);
    const remove=document.createElement('button');remove.type='button';remove.className='jp-tag-remove';remove.textContent='×';remove.title='Tag von dieser Notiz entfernen';remove.setAttribute('aria-label','Tag '+t.title+' entfernen');
    remove.onclick=ev=>{ev.preventDefault();ev.stopPropagation();removeTag(t);};
    chip.appendChild(remove);box.appendChild(chip);
  });
  const add=document.createElement('button');add.textContent='+ Tag';add.onclick=addTag;box.appendChild(add);
}
function resourceIsPdf(r){return !!(r&&((r.is_pdf===true)||String(r.mime||'').toLowerCase()==='application/pdf'||String(r.file_extension||'').toLowerCase()==='pdf'||String(r.title||'').toLowerCase().endsWith('.pdf')));}
function resourceIsOffice(r){const ext=String(r&&r.file_extension||'').toLowerCase();const title=String(r&&r.title||'').toLowerCase();return !!(r&&((r.is_office===true)||['doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp'].includes(ext)||['.doc','.docx','.xls','.xlsx','.ppt','.pptx','.odt','.ods','.odp'].some(e=>title.endsWith(e))));}
function resourceIsExternallyOpenable(r){return resourceIsPdf(r)||resourceIsOffice(r)||(r&&r.is_openable===true);}
function resourceFileName(r,p){let name=String((p&&p.title)||r.title||r.id||'resource').trim()||'resource';const ext=String((p&&p.file_extension)||r.file_extension||'').trim().replace(/^\./,'');if(ext&&!name.toLowerCase().endsWith('.'+ext.toLowerCase()))name+='.'+ext;return name.replace(/[\\/:*?"<>|]+/g,'_');}
function dataUrlToBlobUrl(dataUrl,mime){const parts=String(dataUrl||'').split(',');if(parts.length<2)throw new Error('Ressource konnte nicht gelesen werden.');const bin=atob(parts[1]);const bytes=new Uint8Array(bin.length);for(let i=0;i<bin.length;i++)bytes[i]=bin.charCodeAt(i);return URL.createObjectURL(new Blob([bytes],{type:mime||'application/octet-stream'}));}
function formatBytes(n){n=Number(n||0);if(!n)return '';const u=['B','KB','MB','GB'];let i=0;while(n>=1024&&i<u.length-1){n/=1024;i++;}return (i?n.toFixed(1):Math.round(n))+' '+u[i];}
async function openResourceExternal(r){const p=await api('/api/resources/'+r.id+'/data');if(p.error)throw new Error(p.error);const mime=p.mime||r.mime||'application/octet-stream';const objectUrl=dataUrlToBlobUrl(p.data_url,mime);const filename=resourceFileName(r,p);const a=document.createElement('a');a.href=objectUrl;a.target='_blank';a.rel='noopener';a.download=filename;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(objectUrl),120000);}
function stopOfficePolling(resourceId){if(state.officePollers[resourceId]){clearInterval(state.officePollers[resourceId]);delete state.officePollers[resourceId];}}
function officeStatusText(el,text,kind){if(!el)return;el.textContent=text;el.className='jp-resource-edit-status '+(kind||'');}
function officeSyncState(resourceId){
  if(!state.officeSyncs[resourceId])state.officeSyncs[resourceId]={inFlight:null,closed:false,lastHash:''};
  return state.officeSyncs[resourceId];
}
async function pollOfficeEdit(resourceId,statusEl,options={}){
  const sync=officeSyncState(resourceId);
  if(sync.inFlight)return sync.inFlight;
  sync.inFlight=(async()=>{
    try{
      const result=await api('/api/resources/'+resourceId+'/office/status');
      if(!result.active){
        stopOfficePolling(resourceId);
        if(!options.silentInactive)officeStatusText(statusEl,result.error||'Bearbeitungssitzung beendet.','warning');
        return result;
      }
      if(result.updated||result.already_applied){
        sync.lastHash=String(result.sha256||sync.lastHash||'');
        officeStatusText(statusEl,'Änderungen wurden in die Joplix-Ressource übernommen und können synchronisiert werden.','ok');
        scheduleBackgroundRefresh(350);
      }
      return result;
    }catch(e){
      officeStatusText(statusEl,'Übernahme fehlgeschlagen: '+e.message,'error');
      throw e;
    }finally{
      sync.inFlight=null;
    }
  })();
  return sync.inFlight;
}
async function finalizeOfficeEdit(resourceId,statusEl){
  const sync=officeSyncState(resourceId);
  if(sync.inFlight)await sync.inFlight;
  officeStatusText(statusEl,'Office wurde geschlossen. Die endgültige Office-Speicherung wird abgewartet...','busy');
  // EuroOffice/Document Server may write the final file several seconds after
  // its browser window has already closed. Keep checking the working copy until
  // it stayed unchanged for three checks and a minimum grace period elapsed.
  const started=Date.now();
  let lastWorkingHash='';
  let stableChecks=0;
  let lastResult=null;
  await new Promise(resolve=>setTimeout(resolve,2000));
  for(let attempt=0;attempt<12;attempt++){
    lastResult=await pollOfficeEdit(resourceId,statusEl,{silentInactive:true});
    if(!lastResult||!lastResult.active)break;
    const workingHash=String(lastResult.working_sha256||'');
    if(workingHash&&workingHash===lastWorkingHash)stableChecks++;
    else stableChecks=0;
    lastWorkingHash=workingHash;
    const elapsed=Date.now()-started;
    if(elapsed>=8000&&stableChecks>=3)break;
    await new Promise(resolve=>setTimeout(resolve,2000));
  }
  if(statusEl.classList.contains('error'))return lastResult;
  officeStatusText(statusEl,'Office wurde geschlossen. Die endgültige Datei wurde in die Joplix-Ressource übernommen.','ok');
  scheduleBackgroundRefresh(350);
  return lastResult;
}
function cssEscapeValue(value){
  const text=String(value||'');
  if(window.CSS&&typeof window.CSS.escape==='function')return window.CSS.escape(text);
  return text.replace(/([\"'\[\]#.:>+~*=()])/g,'\\$1');
}
function findNextcloudFileRow(doc,fileId,fileName){
  const id=String(fileId||'');
  const name=String(fileName||'');
  const selectors=[
    '[data-cy-files-list-row-fileid="'+cssEscapeValue(id)+'"]',
    '[data-fileid="'+cssEscapeValue(id)+'"]',
    '[data-id="'+cssEscapeValue(id)+'"]',
    'tr[data-id="'+cssEscapeValue(id)+'"]',
    '.files-list__row[data-id="'+cssEscapeValue(id)+'"]'
  ];
  for(const selector of selectors){try{const row=doc.querySelector(selector);if(row)return row;}catch(e){}}
  const rows=Array.from(doc.querySelectorAll('tr,.files-list__row,[data-cy-files-list-row]'));
  return rows.find(row=>String(row.textContent||'').includes(name))||null;
}
function activateNextcloudFileRow(row,fileName){
  if(!row)return false;
  const name=String(fileName||'');
  const candidates=[
    '[data-cy-files-list-row-name]',
    '.files-list__row-name-link',
    '.files-list__row-name',
    'a[href]',
    'button'
  ];
  let target=null;
  for(const selector of candidates){
    const items=Array.from(row.querySelectorAll(selector));
    target=items.find(item=>!name||String(item.textContent||'').trim().includes(name))||items[0]||null;
    if(target)break;
  }
  target=target||row;
  try{target.dispatchEvent(new MouseEvent('click',{view:row.ownerDocument.defaultView,bubbles:true,cancelable:true,button:0}));return true;}catch(e){}
  try{target.click();return true;}catch(e){}
  return false;
}
function triggerNextcloudDefaultFileAction(editorWindow,fileId,fileName,statusEl){
  let attempts=0;
  const timer=setInterval(()=>{
    attempts++;
    if(!editorWindow||editorWindow.closed){clearInterval(timer);return;}
    try{
      const doc=editorWindow.document;
      if(!doc||!doc.body)return;
      const row=findNextcloudFileRow(doc,fileId,fileName);
      if(row&&activateNextcloudFileRow(row,fileName)){
        clearInterval(timer);
        officeStatusText(statusEl,'Nextcloud-Dateiaktion wurde gestartet. Gespeicherte Änderungen werden automatisch zurück in Joplix übernommen.','busy');
        return;
      }
    }catch(e){
      // Sobald Nextcloud Office in einen anderen Dokumentkontext wechselt, ist die Dateiaktion bereits gestartet.
      if(attempts>3){clearInterval(timer);return;}
    }
    if(attempts>=40){
      clearInterval(timer);
      officeStatusText(statusEl,'Die Arbeitskopie wurde geöffnet. Falls der Office-Editor nicht automatisch startet, bitte einmal auf den Dateinamen klicken.','warning');
    }
  },500);
}
async function startOfficeEdit(r,statusEl){
  const openMode=(currentLocalSettings().office_open_mode==='tab')?'tab':'window';
  const popupFeatures='popup=yes,width='+Math.max(900,Math.min(1500,Math.floor((window.screen&&screen.availWidth||1400)*0.88)))+',height='+Math.max(700,Math.min(1000,Math.floor((window.screen&&screen.availHeight||900)*0.88)))+',left=40,top=30,resizable=yes,scrollbars=yes';
  const windowName=openMode==='window'?('joplix-office-'+r.id):'_blank';
  const editorWindow=window.open('about:blank',windowName,openMode==='window'?popupFeatures:'noopener');
  if(!editorWindow){officeStatusText(statusEl,'Das Office-Fenster wurde vom Browser blockiert. Bitte Pop-ups für diese Nextcloud-Adresse erlauben.','error');throw new Error('Office-Fenster wurde vom Browser blockiert.');}
  try{editorWindow.document.title='Joplix – Office wird vorbereitet';editorWindow.document.body.innerHTML='<p style="font-family:system-ui;padding:24px">Nextcloud Office wird vorbereitet…</p>';}catch(e){}
  officeStatusText(statusEl,'Arbeitskopie wird für Nextcloud Office vorbereitet...','busy');
  try{
    const result=await api('/api/resources/'+r.id+'/office/start',{method:'POST',body:{}});
    const rawFileId=String(result.file_id||'');
    const fileId=encodeURIComponent(rawFileId);
    const fileName=String(result.file_name||resourceFileName(r,result));
    if(!fileId)throw new Error('Die Nextcloud-Datei-ID der Arbeitskopie fehlt.');
    const officeUrl=OC.generateUrl(String(result.editor_path||('/apps/eurooffice/'+fileId)));
    editorWindow.location.replace(officeUrl);
    officeStatusText(statusEl,(openMode==='window'?'Bearbeitung wurde in einem eigenen Fenster angefordert. ':'Bearbeitung läuft in einem neuen Tab. ')+'EuroOffice wird direkt geöffnet. Gespeicherte Änderungen werden automatisch zurück in Joplix übernommen.','busy');
    stopOfficePolling(r.id);
    state.officeSyncs[r.id]={inFlight:null,closed:false,lastHash:''};
    state.officePollers[r.id]=setInterval(()=>pollOfficeEdit(r.id,statusEl).catch(()=>{}),3000);
    setTimeout(()=>pollOfficeEdit(r.id,statusEl).catch(()=>{}),1200);
    const closeWatcher=setInterval(async()=>{
      if(!editorWindow.closed)return;
      clearInterval(closeWatcher);
      stopOfficePolling(r.id);
      const sync=officeSyncState(r.id);
      sync.closed=true;
      officeStatusText(statusEl,'Office wurde geschlossen. Letzte gespeicherte Änderungen werden geprüft...','busy');
      try{
        await finalizeOfficeEdit(r.id,statusEl);
      }catch(e){
        officeStatusText(statusEl,'Abschließende Übernahme fehlgeschlagen: '+e.message,'error');
      }
    },1000);
  }catch(e){try{editorWindow.close();}catch(ignore){}officeStatusText(statusEl,e.message,'error');throw e;}
}
function resourceKind(r){const ext=String(r.file_extension||'').toLowerCase();const mime=String(r.mime||'').toLowerCase();if(resourceIsOffice(r))return 'office';if(resourceIsPdf(r))return 'pdf';if(r.is_image||mime.startsWith('image/'))return 'image';if(mime.startsWith('audio/')||['mp3','ogg','wav','m4a','flac'].includes(ext))return 'audio';if(mime.startsWith('video/')||['mp4','webm','mov','mkv','avi'].includes(ext))return 'video';if(['zip','7z','rar','tar','gz','bz2'].includes(ext))return 'archive';return 'file';}
function resourcePrimaryLabel(kind){return {office:'Bearbeiten',pdf:'Öffnen',image:'Öffnen',audio:'Abspielen',video:'Abspielen'}[kind]||'';}
function renderResources(rs){const box=$('jp-resources');box.innerHTML='';if(!rs||!rs.length)return;const head=document.createElement('div');head.className='jp-resources-head';head.textContent='Anhänge';box.appendChild(head);rs.forEach(async r=>{const kind=resourceKind(r);const card=document.createElement('div');card.className='jp-resource jp-resource-'+kind;const title=document.createElement('strong');title.textContent=r.title||r.id;const meta=document.createElement('span');meta.textContent=[r.mime||'',r.file_extension?('.'+r.file_extension):'',r.exists?'':'Datei fehlt'].filter(Boolean).join(' ');card.appendChild(title);card.appendChild(meta);box.appendChild(card);if(!r.exists)return;
 if(kind==='image'){try{const p=await api('/api/resources/'+r.id+'/data');if(p.data_url){const img=document.createElement('img');img.src=p.data_url;img.alt=r.title||'Bild';card.appendChild(img);}}catch(e){}}
 const actions=document.createElement('div');actions.className='jp-resource-actions';const primary=resourcePrimaryLabel(kind);if(primary){const primaryBtn=document.createElement('button');primaryBtn.type='button';primaryBtn.className='jp-resource-action jp-resource-action-primary';primaryBtn.textContent=primary;actions.appendChild(primaryBtn);if(kind==='office'){const status=document.createElement('div');status.className='jp-resource-edit-status';card.appendChild(actions);card.appendChild(status);primaryBtn.onclick=async()=>{primaryBtn.disabled=true;try{await startOfficeEdit(r,status);}catch(e){alert(e.message);}finally{primaryBtn.disabled=false;}};}else{primaryBtn.onclick=async()=>{try{await openResourceExternal(r);}catch(e){alert(e.message);}};card.appendChild(actions);}}
 const downloadBtn=document.createElement('button');downloadBtn.type='button';downloadBtn.className='jp-resource-action jp-resource-action-secondary';downloadBtn.textContent='Download';downloadBtn.onclick=async()=>{try{await openResourceExternal(r);}catch(e){alert(e.message);}};actions.appendChild(downloadBtn);if(!actions.parentNode)card.appendChild(actions);
 });}
function openPdfViewer(p){return;}
function closePdfViewer(){const o=$('jp-pdf-viewer-overlay'),f=$('jp-pdf-viewer-frame');if(f)f.src='about:blank';if(o){o.classList.remove('open');o.setAttribute('aria-hidden','true');}}

function closeNotebookMenu(){
  const old=document.querySelector('.jp-context-menu');
  if(old)old.remove();
  document.removeEventListener('scroll',closeNotebookMenu,true);
  window.removeEventListener('resize',closeNotebookMenu);
  document.removeEventListener('keydown',closeNotebookMenuByEscape,true);
}
function closeNotebookMenuByEscape(ev){
  if(ev && ev.key==='Escape')closeNotebookMenu();
}
function showNotebookMenu(node,row,button){
  closeNotebookMenu();
  const menu=document.createElement('div');
  menu.className='jp-context-menu jp-tree-context-menu';
  menu.setAttribute('role','menu');
  const en=resolvedLanguage()==='en';
  menu.innerHTML='<button data-act="rename" role="menuitem">'+(en?'Rename':'Umbenennen')+'</button><button data-act="newchild" role="menuitem">'+(en?'New sub-notebook':'Neues Unter-Notizbuch')+'</button><button data-act="delete" role="menuitem" class="danger">'+(en?'Delete':'Löschen')+'</button>';
  menu.addEventListener('click',ev=>ev.stopPropagation());
  document.body.appendChild(menu);

  const rect=button.getBoundingClientRect();
  const gap=10;
  const margin=12;
  const menuWidth=Math.max(menu.offsetWidth||220,220);
  const menuHeight=menu.offsetHeight||156;
  const viewportWidth=window.innerWidth||document.documentElement.clientWidth;
  const viewportHeight=window.innerHeight||document.documentElement.clientHeight;

  /*
   * Joplix 1.0.28: Das Menue soll bei unteren Kapiteln nicht wie ein abgeschnittenes Drop-down wirken.
   * Stattdessen wird es als sauberer Nextcloud-aehnlicher Popover seitlich neben dem Drei-Punkte-Button
   * positioniert und vertikal am Button ausgerichtet. Nur wenn wirklich kein Platz ist, wird begrenzt.
   */
  let placement='left';
  let left=rect.left-menuWidth-gap;
  if(left<margin){
    const rightCandidate=rect.right+gap;
    if(rightCandidate+menuWidth<=viewportWidth-margin){
      left=rightCandidate;
      placement='right';
    }else{
      left=Math.min(Math.max(margin,rect.right-menuWidth),viewportWidth-menuWidth-margin);
      placement='inside';
    }
  }

  let top=rect.top+(rect.height/2)-(menuHeight/2);
  if(top<margin)top=margin;
  if(top+menuHeight>viewportHeight-margin)top=viewportHeight-menuHeight-margin;
  if(top<margin){
    top=margin;
    menu.style.maxHeight=Math.max(120,viewportHeight-(margin*2))+'px';
    menu.style.overflowY='auto';
  }

  menu.dataset.placement=placement;
  menu.style.left=Math.round(left)+'px';
  menu.style.top=Math.round(top)+'px';
  menu.querySelector('[data-act="rename"]').onclick=()=>{closeNotebookMenu();renameNotebookNode(node);};
  menu.querySelector('[data-act="newchild"]').onclick=()=>{closeNotebookMenu();newNotebook(node.id);};
  menu.querySelector('[data-act="delete"]').onclick=()=>{closeNotebookMenu();deleteNotebookNode(node);};
  setTimeout(()=>{
    document.addEventListener('click',closeNotebookMenu,{once:true});
    document.addEventListener('scroll',closeNotebookMenu,true);
    window.addEventListener('resize',closeNotebookMenu);
    document.addEventListener('keydown',closeNotebookMenuByEscape,true);
  },0);
}

async function renameNotebookNode(node){const en=resolvedLanguage()==='en';const title=prompt(en?'Rename notebook:':'Notizbuch umbenennen:',node.title);if(!title||title===node.title)return;const nb=(state.index.notebooks||[]).find(n=>n.id===node.id);await api('/api/notebooks/'+node.id,{method:'PUT',body:{title,known_updated_time:nb?nb.updated_time:''}});await rebuildAndRestore();}
function confirmNotebookDeletion(node){
  const en=resolvedLanguage()==='en';
  const isSub=!!(node.parent_id||'');
  const title=String(node.title||'').trim()|| (en?'Untitled':'Ohne Titel');
  return new Promise(resolve=>{
    const old=document.getElementById('jp-delete-notebook-overlay');
    if(old)old.remove();
    const overlay=document.createElement('div');
    overlay.id='jp-delete-notebook-overlay';
    overlay.className='jp-delete-notebook-overlay open';
    overlay.setAttribute('aria-hidden','false');
    const heading=en
      ?('Delete '+(isSub?'sub-notebook':'notebook')+' “'+title+'”?')
      :((isSub?'Unter-Notizbuch':'Notizbuch')+' „'+title+'“ löschen?');
    const body=en
      ?(isSub?'All notes and nested sub-notebooks in it will also be permanently deleted.':'All notes and sub-notebooks in this notebook will also be permanently deleted.')
      :(isSub?'Alle Notizen und weiteren Unter-Notizbücher darin werden ebenfalls endgültig gelöscht.':'Alle Notizen und Unter-Notizbücher in diesem Notizbuch werden ebenfalls endgültig gelöscht.');
    overlay.innerHTML='<div class="jp-delete-notebook-backdrop"></div><section class="jp-delete-notebook-modal" role="dialog" aria-modal="true" aria-labelledby="jp-delete-notebook-title"><h2 id="jp-delete-notebook-title"></h2><p></p><div class="jp-delete-notebook-actions"><button type="button" data-delete-cancel></button><button type="button" class="danger" data-delete-confirm></button></div></section>';
    overlay.querySelector('h2').textContent=heading;
    overlay.querySelector('p').textContent=body;
    overlay.querySelector('[data-delete-cancel]').textContent=en?'Cancel':'Abbrechen';
    overlay.querySelector('[data-delete-confirm]').textContent=en?'Delete':'Löschen';
    document.body.appendChild(overlay);
    let done=false;
    const finish=value=>{if(done)return;done=true;document.removeEventListener('keydown',onKey,true);overlay.remove();resolve(value);};
    const onKey=ev=>{if(ev.key==='Escape'){ev.preventDefault();finish(false);}};
    document.addEventListener('keydown',onKey,true);
    overlay.querySelector('[data-delete-cancel]').onclick=()=>finish(false);
    overlay.querySelector('.jp-delete-notebook-backdrop').onclick=()=>finish(false);
    const del=overlay.querySelector('[data-delete-confirm]');
    del.onclick=()=>finish(true);
    setTimeout(()=>del.focus(),0);
  });
}
async function deleteNotebookNode(node){
  const en=resolvedLanguage()==='en';
  const isSub=!!(node.parent_id||'');
  const kind=en?(isSub?'sub-notebook':'notebook'):(isSub?'Unter-Notizbuch':'Notizbuch');
  if(!(await confirmNotebookDeletion(node)))return;
  status(en?'Deleting…':'Wird gelöscht…','warning');
  try{
    await api('/api/notebooks/'+node.id,{method:'DELETE'});
    if(state.notebookId===node.id){state.notebookId='';clearNote();}
    await rebuildAndRestore(true);
    status(en?'Deleted permanently':'Endgültig gelöscht','ok');
  }catch(e){
    const msg=(en?'Could not delete '+kind+': ':'Löschen fehlgeschlagen: ')+e.message;
    status(msg,'error');
    alert(msg);
  }
}
async function saveNote(options={}){
  if(state.savePromise){
    state.saveAgain=true;
    return state.savePromise;
  }
  state.savePromise=(async()=>{
    if(!state.notebookId&&!state.note){alert('Bitte Notizbuch auswaehlen.');return false;}
    do{
      state.saveAgain=false;
      if(!state.dirty){updateSaveButton();break;}
      const titleEl=$('jp-title');
      const bodyEl=$('jp-body');
      const titleSnapshot=titleEl?titleEl.value:'';
      const bodySnapshot=bodyEl?bodyEl.value:'';
      const wasNew=!state.note;
      state.saving=true;
      setSavingUi(true);
      updateSaveButton();
      try{
        const body={title:titleSnapshot,body:bodySnapshot};
        if(state.note){
          body.known_updated_time=state.note.updated_time;
          const r=await api('/api/notes/'+state.note.id,{method:'PUT',body});
          state.note.title=titleSnapshot;
          state.note.body=bodySnapshot;
          if(r&&r.updated_time)state.note.updated_time=r.updated_time;
        }else{
          body.parent_id=state.notebookId;
          const r=await api('/api/notes',{method:'POST',body});
          state.noteId=r.id;
          state.note={id:r.id,title:titleSnapshot,body:bodySnapshot,updated_time:(r&&r.updated_time)||'',notebook_id:state.notebookId,tags:[],resources:[]};
        }

        // Erst nach erfolgreicher API-Antwort wird der gespeicherte Body zur lokalen
        // Wahrheit. Die bestehende serverseitige Joplin-Sync-Schreiblogik bleibt
        // vollständig unverändert.
        if(state.note){
          state.noteGenerations[state.note.id]=(state.noteGenerations[state.note.id]||0)+1;
          state.note.title=titleSnapshot;
          state.note.body=bodySnapshot;
          rememberNote(state.note);
        }
        const currentSnapshot=noteSnapshot();
        const changedDuringSave=!sameSnapshot(currentSnapshot,{title:titleSnapshot,body:bodySnapshot});
        state.originalNote={title:titleSnapshot,body:bodySnapshot};
        state.dirty=changedDuringSave;
        if(changedDuringSave){
          // Weitere Eingaben waehrend des Speicherns bleiben erhalten und werden
          // unmittelbar in einem zweiten Durchlauf gespeichert.
          state.saveAgain=true;
        }else{
          if(titleEl)titleEl.value=titleSnapshot;
          if(bodyEl)bodyEl.value=bodySnapshot;
        }
        updateSaveButton();
        if(options.keepMode)renderDisplayMode();else if(!changedDuringSave)setNoteMode('view');

        // Bestehende Notizen brauchen keinen blockierenden Index-Rebuild. Der Index
        // wird im Hintergrund aktualisiert; dadurch kann sofort gewechselt werden.
        if(wasNew&&!options.skipReload){
          await rebuildAndRestore();
        }else{
          scheduleBackgroundRefresh(options.fastDirtySave?300:800);
        }
      }finally{
        state.saving=false;
        setSavingUi(false);
        updateSaveButton();
      }
    }while(state.saveAgain);
    return true;
  })();
  try{return await state.savePromise;}
  finally{state.savePromise=null;state.saving=false;state.saveAgain=false;setSavingUi(false);updateSaveButton();}
}
async function newNote(){if(!(await handleDirtyBeforeNoteChange('__new__')))return;state.note=null;state.noteId='';setNoteMode('edit');$('jp-title').value='Neue Notiz';$('jp-body').classList.remove('jp-note-loading');$('jp-body').value='';renderDisplayMode();$('jp-body').placeholder='Notizinhalt';$('jp-meta').textContent='Neue Notiz in diesem Notizbuch';$('jp-tags').innerHTML='';$('jp-resources').innerHTML='';updateNotePath();state.originalNote={title:'',body:''};resetEditorHistory('');state.dirty=true;updateSaveButton();}
async function deleteNote(){if(!state.note||!confirm('Notiz wirklich löschen?'))return;await api('/api/notes/'+state.note.id,{method:'DELETE'});await rebuildAndRestore(true);clearNote();}
async function newNotebook(parent){const en=resolvedLanguage()==='en';const title=prompt(parent?(en?'Sub-notebook name:':'Name des Unter-Notizbuchs:'):(en?'Notebook name:':'Name des Notizbuchs:'));if(!title)return;await api('/api/notebooks',{method:'POST',body:{title,parent_id:parent||''}});await rebuildAndRestore();}
async function renameNotebook(){if(!state.notebookId){alert('Bitte Notizbuch auswählen.');return;}const nb=(state.index.notebooks||[]).find(n=>n.id===state.notebookId);const title=prompt('Neuer Name',nb?nb.title:'');if(!title)return;await api('/api/notebooks/'+state.notebookId,{method:'PUT',body:{title,known_updated_time:nb?nb.updated_time:''}});await rebuildAndRestore();}
function mimeFromFilename(name){const ext=String(name||'').toLowerCase().split('.').pop();const map={pdf:'application/pdf',docx:'application/vnd.openxmlformats-officedocument.wordprocessingml.document',doc:'application/msword',xlsx:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',xls:'application/vnd.ms-excel',pptx:'application/vnd.openxmlformats-officedocument.presentationml.presentation',ppt:'application/vnd.ms-powerpoint',odt:'application/vnd.oasis.opendocument.text',ods:'application/vnd.oasis.opendocument.spreadsheet',odp:'application/vnd.oasis.opendocument.presentation'};return map[ext]||'application/octet-stream';}
async function uploadResource(file){
  if(!state.note){alert('Bitte zuerst eine Notiz öffnen.');return;}
  const noteId=state.note.id;
  const data=await new Promise((resolve,reject)=>{
    const reader=new FileReader();
    reader.onload=()=>resolve(String(reader.result).split(',')[1]);
    reader.onerror=reject;
    reader.readAsDataURL(file);
  });
  if(state.note?.id!==noteId||state.noteLoading)throw new Error('Die ursprüngliche Notiz ist nicht mehr geöffnet.');
  const resource=await api('/api/resources',{method:'POST',body:{filename:file.name,mime:file.type||mimeFromFilename(file.name),data_base64:data}});
  if(state.note?.id!==noteId||state.noteLoading)throw new Error('Anhang hochgeladen. Der Link konnte der ursprünglichen Notiz nicht hinzugefügt werden: '+resource.markdown);
  $('jp-body').value+='\n\n'+resource.markdown;
  markDirty();
  await saveNote();
}
function normalizeLocalTags(tags){
  const seen=new Set();
  return (Array.isArray(tags)?tags:[]).map(t=>({
    id:String((t&&t.id)||''),
    title:String((t&&(t.title||t._body))||'').trim()
  })).filter(t=>t.id&&t.title&&!seen.has(t.id)&&seen.add(t.id));
}
function applyTagsLocally(noteId,tags){
  const normalized=normalizeLocalTags(tags);
  // Laufende, vor der Tag-Aenderung gestartete Notizabfragen duerfen den
  // aktualisierten Tag-Stand nicht wieder mit einer alten Antwort ersetzen.
  state.noteGenerations[noteId]=(state.noteGenerations[noteId]||0)+1;
  const current=state.noteId===noteId&&state.note?state.note:(noteMemoryGet(noteId)||cachedNote(noteId));
  if(current){
    current.tags=normalized;
    noteMemorySet(current);
    rememberNote(current);
    if(state.noteId===noteId&&state.note){
      state.note.tags=normalized;
      renderTags(normalized);
    }
  }
  const meta=findNoteMeta(noteId);
  if(meta&&Object.keys(meta).length){
    meta.tag_count=normalized.length;
    meta.tag_titles=normalized.map(t=>t.title);
  }
  if(state.index){
    state.index.tags=Array.isArray(state.index.tags)?state.index.tags:[];
    normalized.forEach(tag=>{
      const existing=state.index.tags.find(t=>String(t.id)===tag.id);
      if(existing){existing.title=tag.title;existing._body=tag.title;}
      else state.index.tags.push({id:tag.id,title:tag.title,_body:tag.title});
    });
    rememberIndex(state.index);
  }
  renderNotesForCurrentNotebook(true);
  ensureNoteSelected(noteId);
}
async function removeTag(tag){
  if(!state.note||!tag||!tag.id)return;
  const noteId=state.note.id;
  const currentTags=normalizeLocalTags(state.note.tags||[]);
  const remaining=currentTags.filter(t=>t.id!==String(tag.id));
  applyTagsLocally(noteId,remaining);
  status('Tag wird entfernt...','warning');
  try{
    await api('/api/notes/'+encodeURIComponent(noteId)+'/tags/'+encodeURIComponent(tag.id),{method:'DELETE'});
    status('Tag entfernt','ok');
    scheduleBackgroundRefresh(450);
  }catch(e){
    applyTagsLocally(noteId,currentTags);
    status('Tag konnte nicht entfernt werden: '+e.message,'error');
    alert('Tag konnte nicht entfernt werden: '+e.message);
  }
}
async function addTag(){
  if(!state.note){alert('Bitte zuerst eine Notiz öffnen.');return;}
  const entered=prompt('Neuer Tag');
  const title=String(entered||'').trim();
  if(!title)return;
  const noteId=state.note.id;
  const currentTags=normalizeLocalTags(state.note.tags||[]);
  if(currentTags.some(t=>t.title.toLocaleLowerCase()===title.toLocaleLowerCase())){
    alert('Dieser Tag ist der Notiz bereits zugeordnet.');
    return;
  }

  // Optimistische Anzeige: Der Benutzer sieht den Tag sofort. Die provisorische
  // ID wird nach der Serverantwort durch die echte Joplin-ID ersetzt.
  const temporaryTag={id:'pending-'+Date.now()+'-'+Math.random().toString(16).slice(2),title};
  applyTagsLocally(noteId,[...currentTags,temporaryTag]);
  status('Tag wird gespeichert...','warning');

  try{
    const saved=await api('/api/notes/'+noteId+'/tags/create',{method:'POST',body:{title}});
    const realTag={id:String(saved.id||''),title:String(saved.title||title).trim()||title};
    if(!realTag.id)throw new Error('Der gespeicherte Tag hat keine gültige ID erhalten.');
    const latest=normalizeLocalTags((state.noteId===noteId&&state.note?state.note.tags:currentTags)||[])
      .filter(t=>t.id!==temporaryTag.id&&t.id!==realTag.id);
    applyTagsLocally(noteId,[...latest,realTag]);
    status('Tag gespeichert','ok');
    scheduleBackgroundRefresh(450);
  }catch(e){
    const rollback=normalizeLocalTags((state.noteId===noteId&&state.note?state.note.tags:currentTags)||[])
      .filter(t=>t.id!==temporaryTag.id);
    applyTagsLocally(noteId,rollback.length?rollback:currentTags);
    status('Tag konnte nicht gespeichert werden: '+e.message,'error');
    alert('Tag konnte nicht gespeichert werden: '+e.message);
  }
}
function closeNoteContextMenu(){const m=$('jp-note-context-menu');if(m){m.classList.remove('open');m.setAttribute('aria-hidden','true');m.innerHTML='';}}
function showNoteContextMenu(ev,note){ev.preventDefault();ev.stopPropagation();closeNoteContextMenu();const m=$('jp-note-context-menu');if(!m)return;m.dataset.noteId=note.id;m.innerHTML='<button data-act="move">Verschieben in Notizbuch</button><button data-act="tags">Tags</button><button data-act="delete" class="danger">Löschen</button>';m.style.left=Math.min(ev.clientX,window.innerWidth-240)+'px';m.style.top=Math.min(ev.clientY,window.innerHeight-150)+'px';m.classList.add('open');m.setAttribute('aria-hidden','false');m.querySelector('[data-act="delete"]').onclick=()=>{closeNoteContextMenu();deleteNoteById(note.id,note.title);};m.querySelector('[data-act="move"]').onclick=()=>{closeNoteContextMenu();moveNoteByPrompt(note.id);};m.querySelector('[data-act="tags"]').onclick=()=>{closeNoteContextMenu();editTagsByPrompt(note.id);};setTimeout(()=>document.addEventListener('click',closeNoteContextMenu,{once:true}),0);}
function flatNotebooks(){return (state.index&&state.index.notebooks?state.index.notebooks:[]).slice().sort((a,b)=>String(a.title||'').localeCompare(String(b.title||'')));}
async function deleteNoteById(id,title){if(!confirm('Notiz wirklich löschen?\n\n'+(title||id)))return;await api('/api/notes/'+id,{method:'DELETE'});if(state.noteId===id)clearNote();await rebuildAndRestore(true);}
async function moveNoteByPrompt(id){const books=flatNotebooks();if(!books.length){alert('Keine Notizbücher gefunden.');return;}const msg=books.map((b,i)=>(i+1)+'. '+b.title).join('\n');const ans=prompt('Ziel-Notizbuch auswählen:\n\n'+msg+'\n\nBitte Nummer eingeben:');if(!ans)return;const idx=parseInt(ans,10)-1;if(idx<0||idx>=books.length){alert('Ungültige Auswahl.');return;}await api('/api/notes/'+id+'/move',{method:'POST',body:{target_notebook_id:books[idx].id}});await rebuildAndRestore(true);if(state.noteId===id)await openNote(id).catch(()=>{});}
async function editTagsByPrompt(id){
  const note=state.noteId===id&&state.note?state.note:await api('/api/notes/'+id);
  const allRaw=await api('/api/tags').catch(()=>[]);
  const all=normalizeLocalTags(allRaw);
  const currentTags=normalizeLocalTags(note.tags||[]);
  const current=currentTags.map(t=>t.title).join(', ');
  const input=prompt('Tags bearbeiten. Mehrere Tags mit Komma trennen. Entfernte Namen werden von dieser Notiz gelöst.\n\nVorhandene Tags:\n'+(all.map(t=>t.title).join(', ')||'Keine')+'\n\nTags für diese Notiz:',current);
  if(input===null)return;
  const names=[...new Set(input.split(',').map(s=>s.trim()).filter(Boolean))];
  const selected=[];
  for(const name of names){
    let tag=all.find(t=>t.title.toLocaleLowerCase()===name.toLocaleLowerCase());
    if(!tag){
      const created=await api('/api/tags',{method:'POST',body:{title:name}});
      tag={id:String(created.id||''),title:name};
    }
    if(tag&&tag.id&&!selected.some(x=>x.id===tag.id))selected.push(tag);
  }
  const selectedIds=new Set(selected.map(t=>t.id));
  const removed=currentTags.filter(tag=>!selectedIds.has(tag.id));
  const added=selected.filter(tag=>!currentTags.some(x=>x.id===tag.id));
  applyTagsLocally(id,selected);
  status('Tags werden gespeichert...','warning');
  try{
    if(added.length)await api('/api/notes/'+id+'/tags',{method:'POST',body:{tag_ids:added.map(t=>t.id)}});
    for(const tag of removed)await api('/api/notes/'+encodeURIComponent(id)+'/tags/'+encodeURIComponent(tag.id),{method:'DELETE'});
    status('Tags gespeichert','ok');
    scheduleBackgroundRefresh(450);
  }catch(e){
    applyTagsLocally(id,currentTags);
    status('Tags konnten nicht gespeichert werden: '+e.message,'error');
    alert('Tags konnten nicht gespeichert werden: '+e.message);
  }
}
async function rebuildAndRestore(clear=false){status('Index wird neu aufgebaut...','warning');const r=await api('/api/index/rebuild',{method:'POST'});const idx=r.index||r;if(clear){state.index=idx;rememberIndex(idx);renderTree(idx.tree||[]);if(state.notebookId)selectNotebook(state.notebookId);else restoreLocation(idx);status('Index aktualisiert','ok');}else{applyIndexPreservingFocus(idx,'Index aktualisiert');}if(!clear&&state.noteId&&!state.dirty)openNote(state.noteId,{silent:true,preferCache:true}).catch(()=>{});if($('jp-search')&&$('jp-search').value.trim())runFullTextSearch($('jp-search').value);}
window.JoplixAfterRebuild=function(idx){applyIndexPreservingFocus(idx,'Index aktualisiert');if($('jp-search')&&$('jp-search').value.trim())runFullTextSearch($('jp-search').value);};
function scheduleBackgroundRefresh(delay=1200){setTimeout(()=>backgroundRefresh().catch(()=>{}),delay);}
async function backgroundRefresh(){
  if(state.backgroundBusy)return;
  state.backgroundBusy=true;
  try{
    const st=await api('/api/index/status');
    if(!st.current){
      status('Änderungen erkannt. Index wird im Hintergrund aktualisiert...','warning');
      const r=await api('/api/index/rebuild',{method:'POST'});
      applyIndexPreservingFocus(r.index||r,state.dirty?'Index aktualisiert. Geöffnete Notiz wegen ungespeicherter Änderungen nicht überschrieben.':'Index im Hintergrund aktualisiert');
    } else {
      status('Index aktuell','ok');
    }
  }catch(e){status('Index-Prüfung fehlgeschlagen: '+e.message,'error');}
  finally{state.backgroundBusy=false;}
}
async function saveLastLocation(){const loc={notebook_id:state.notebookId||'',note_id:state.noteId||''};storage.set('lastLocation',loc);api('/api/state/last-location',{method:'POST',body:loc}).catch(()=>{});}
function setSwitch(el,on){if(!el)return;el.classList.toggle('active',!!on);el.setAttribute('aria-checked',on?'true':'false');}
function currentLocalSettings(){return Object.assign({remember_last_location:'yes',sort_order:state.sort||'updated_desc',joplin_path:'/JoplinV2',update_source:'codeberg',update_repo_url:'https://codeberg.org/Stoneubi/joplix/releases',update_channel:'main',office_open_mode:'window',update_backup_enabled:'no',language:'system',is_admin:false},storage.get('settings',{}),state.settings||{});}
async function persistSettings(extra={}){
  const local=Object.assign(currentLocalSettings(),extra);
  local.sort_order=local.sort_order||state.sort||'updated_desc';
  local.remember_last_location=local.remember_last_location||'yes';
  local.joplin_path=normalizePath(local.joplin_path||'/JoplinV2');
  storage.set('settings',local);
  state.settings=Object.assign(state.settings||{},local);
  await api('/api/settings',{method:'POST',body:local}).catch(e=>{status('Einstellungen konnten nicht gespeichert werden: '+e.message,'error');throw e;});
}
function normalizePath(p){p=String(p||'').trim();if(!p)return '/JoplinV2';if(!p.startsWith('/'))p='/'+p;return p.replace(/\/+/g,'/').replace(/\/\/+/, '/').replace(/\s+$/,'');}
function openSettings(){const o=$('jp-settings-overlay');if(!o){alert('Einstellungsbereich wurde nicht gefunden.');return;}syncSettingsUi();loadFoldersForSettings();o.classList.add('open');o.setAttribute('aria-hidden','false');}
function closeSettings(){const o=$('jp-settings-overlay');if(!o)return;o.classList.remove('open');o.setAttribute('aria-hidden','true');}
function activateSettingsTab(tab){document.querySelectorAll('[data-settings-tab]').forEach(b=>b.classList.toggle('active',b.dataset.settingsTab===tab));document.querySelectorAll('[data-settings-section]').forEach(sec=>sec.classList.toggle('active',sec.dataset.settingsSection===tab));}
function syncSettingsUi(){const local=currentLocalSettings();if($('jp-setting-language'))$('jp-setting-language').value=local.language||'system';if($('jp-setting-sort'))$('jp-setting-sort').value=local.sort_order||state.sort||'updated_desc';if($('jp-setting-joplin-path'))$('jp-setting-joplin-path').value=local.joplin_path||'/JoplinV2';if($('jp-setting-office-open-mode'))$('jp-setting-office-open-mode').value=local.office_open_mode==='tab'?'tab':'window';if($('jp-setting-update-backup'))$('jp-setting-update-backup').value=local.update_backup_enabled==='yes'?'yes':'no';setSwitch($('jp-setting-remember-toggle'),(local.remember_last_location||'yes')==='yes');syncUpdateUi();}
async function loadFoldersForSettings(){try{const data=await api('/api/folders');const list=$('jp-folder-list');if(list){list.innerHTML='';(data.folders||[]).forEach(f=>{const o=document.createElement('option');o.value=f;list.appendChild(o);});}if($('jp-setting-joplin-path')&&!$('jp-setting-joplin-path').value)$('jp-setting-joplin-path').value=data.current||'/JoplinV2';}catch(e){status('Ordnerliste konnte nicht geladen werden: '+e.message,'warning');}}
async function applyJoplinPathFromInput(){const el=$('jp-setting-joplin-path');if(!el)return;const newPath=normalizePath(el.value);el.value=newPath;const oldPath=(state.settings&&state.settings.joplin_path)||'/JoplinV2';await persistSettings({joplin_path:newPath});if(newPath!==oldPath){storage.del('lastGoodIndex');state.index=null;status('Joplix-Ordner geändert. Index wird neu aufgebaut...','warning');await rebuildAndRestore(true).catch(e=>status('Index-Neuaufbau fehlgeschlagen: '+e.message,'error'));}}
function showWelcomeIfNeeded(){
  const key='joplix.welcome.1.0.43.done';
  if(localStorage.getItem(key)==='yes')return;
  const o=$('jp-welcome-overlay');if(!o)return;
  o.classList.add('open');o.setAttribute('aria-hidden','false');
}
function closeWelcome(){const key='joplix.welcome.1.0.43.done';try{localStorage.setItem(key,'yes');}catch(e){}const o=$('jp-welcome-overlay');if(o){o.classList.remove('open');o.setAttribute('aria-hidden','true');}const local=currentLocalSettings();local.first_run_completed='yes';storage.set('settings',local);api('/api/settings',{method:'POST',body:local}).catch(()=>{});}
function setupWelcome(){const done=$('jp-welcome-done');if(done)done.onclick=closeWelcome;const close=$('jp-welcome-close');if(close)close.onclick=closeWelcome;const settings=$('jp-welcome-settings');if(settings)settings.onclick=()=>{closeWelcome();openSettings();};setTimeout(showWelcomeIfNeeded,600);}


function currentNotebookTitle(){
  if(state.note&&state.note.notebook_title)return state.note.notebook_title;
  const id=state.notebookId;
  let found='';
  const walk=nodes=>{(nodes||[]).forEach(n=>{if(n.id===id)found=n.title;(n.children||[]).length&&walk(n.children);});};
  if(state.index)walk(state.index.tree||[]);
  return found||'Notizbuch';
}
function updateNotePath(){
  const el=$('jp-note-path'); if(!el)return;
  const title=$('jp-title')&&$('jp-title').value?$('jp-title').value:(state.note&&state.note.title?state.note.title:'Neue Notiz');
  if(!state.note&&!state.noteId&&!state.dirty){el.textContent='Keine Notiz geöffnet';return;}
  el.textContent=currentNotebookTitle()+' / '+title;
}
function resetEditorHistory(value){state.editorHistory={undo:[],last:String(value||''),max:80};}
function pushEditorUndo(value){const h=state.editorHistory||(state.editorHistory={undo:[],last:String(value||''),max:80});value=String(value||'');if(h.undo[h.undo.length-1]!==value&&h.last!==value){h.undo.push(value);while(h.undo.length>(h.max||80))h.undo.shift();}h.last=value;}
function applyEditorValue(value,pos){const ta=$('jp-body');if(!ta)return;ta.value=value;ta.focus();const p=typeof pos==='number'?pos:value.length;ta.setSelectionRange(p,p);state.editorHistory.last=value;markDirty();}
function editorUndo(){const ta=$('jp-body');if(!ta)return;const h=state.editorHistory||(state.editorHistory={undo:[],last:ta.value||'',max:80});if(!h.undo.length)return;const prev=h.undo.pop();applyEditorValue(prev,prev.length);}
function jpReplaceSelection(before,after='',placeholder=''){const ta=$('jp-body');if(!ta)return;const start=ta.selectionStart||0,end=ta.selectionEnd||0;const val=ta.value||'';pushEditorUndo(val);const sel=val.slice(start,end)||placeholder;const next=val.slice(0,start)+before+sel+after+val.slice(end);const pos=start+before.length+sel.length+after.length;applyEditorValue(next,pos);}
function jpInsertAtCursor(text){const ta=$('jp-body');if(!ta)return;const start=ta.selectionStart||0,end=ta.selectionEnd||0,val=ta.value||'';pushEditorUndo(val);const next=val.slice(0,start)+text+val.slice(end);const pos=start+text.length;applyEditorValue(next,pos);}
function jpFormatDateTime(){const d=new Date();try{return new Intl.DateTimeFormat('de-AT',{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}).format(d).replace(',', '');}catch(e){return d.toLocaleString();}}
function continueMarkdownList(ev){const ta=$('jp-body');if(!ta||ev.key!=='Enter'||ev.shiftKey||ev.altKey||ev.ctrlKey||ev.metaKey)return;const val=ta.value||'',pos=ta.selectionStart||0;if(pos!==ta.selectionEnd)return;const lineStart=val.lastIndexOf('\n',pos-1)+1;const line=val.slice(lineStart,pos);const indent=(line.match(/^\s*/)||[''])[0];let marker='';if(/^\s*- \[ \]\s*$/.test(line)||/^\s*-\s*$/.test(line)||/^\s*\d+\.\s*$/.test(line)){ev.preventDefault();pushEditorUndo(val);applyEditorValue(val.slice(0,lineStart)+val.slice(pos),lineStart);return;}if(/^\s*- \[ \] /.test(line))marker=indent+'- [ ] ';else if(/^\s*- /.test(line))marker=indent+'- ';else{const m=line.match(/^(\s*)(\d+)\. /);if(m)marker=m[1]+(parseInt(m[2],10)+1)+'. ';}if(marker){ev.preventDefault();jpInsertAtCursor('\n'+marker);}}
function handleEditorTool(action){const ta=$('jp-body');if(!ta)return;if(action==='undo'){editorUndo();return;}if(action==='bold')return jpReplaceSelection('**','**','Text');if(action==='italic')return jpReplaceSelection('*','*','Text');if(action==='link')return jpReplaceSelection('[','](https://)','Linktext');if(action==='code')return jpReplaceSelection('`','`','code');if(action==='resource'){const f=$('jp-resource-file');if(f)f.click();return;}if(action==='ul')return jpInsertAtCursor((ta.selectionStart>0&&!ta.value.slice(0,ta.selectionStart).endsWith('\n')?'\n':'')+'- ');if(action==='ol')return jpInsertAtCursor((ta.selectionStart>0&&!ta.value.slice(0,ta.selectionStart).endsWith('\n')?'\n':'')+'1. ');if(action==='todo')return jpInsertAtCursor((ta.selectionStart>0&&!ta.value.slice(0,ta.selectionStart).endsWith('\n')?'\n':'')+'- [ ] ');if(action==='h2')return jpInsertAtCursor('\n## ');if(action==='hr')return jpInsertAtCursor('\n\n---\n\n');if(action==='datetime')return jpInsertAtCursor(jpFormatDateTime());if(action==='marker')return jpReplaceSelection('==','==','Markierung');}
function setupEditorFormatbar(){document.querySelectorAll('[data-jp-editor]').forEach(b=>b.addEventListener('click',ev=>{ev.preventDefault();handleEditorTool(b.dataset.jpEditor);}));const ta=$('jp-body');if(ta){resetEditorHistory(ta.value||'');ta.addEventListener('keydown',continueMarkdownList);ta.addEventListener('input',()=>{const h=state.editorHistory||(state.editorHistory={undo:[],last:'',max:80});if(h.last!==ta.value){h.undo.push(h.last);while(h.undo.length>(h.max||80))h.undo.shift();h.last=ta.value;}});}}

function getInstalledVersion(){const app=document.getElementById('joplix-app');const v=(app&&app.dataset&&app.dataset.joplixVersion)||'';return parseJoplixVersionText(v)?parseJoplixVersionText(v).text:v||'0.0.0';}
function syncInstalledVersionUi(){const v=getInstalledVersion();const el=$('jp-update-installed-version');if(el)el.textContent='Joplix '+v;}
function parseJoplixVersionText(value){const raw=String(value||'');const m=raw.match(/(?:^|[^0-9])v?(\d+)\.(\d+)\.(\d+)(?:[.-]?(beta|alpha|rc|pre|dev)?[.-]?(\d+)?)?/i);if(!m)return null;return {major:parseInt(m[1],10),minor:parseInt(m[2],10),patch:parseInt(m[3],10),suffix:(m[4]||'').toLowerCase(),build:m[5]?parseInt(m[5],10):0,text:m[1]+'.'+m[2]+'.'+m[3]};}
function compareJoplixVersions(a,b){a=typeof a==='string'?parseJoplixVersionText(a):a;b=typeof b==='string'?parseJoplixVersionText(b):b;if(!a||!b)return 0;for(const k of ['major','minor','patch']){if(a[k]>b[k])return 1;if(a[k]<b[k])return -1;}if((a.build||0)>(b.build||0))return 1;if((a.build||0)<(b.build||0))return -1;return 0;}
function releaseVersion(rel){return parseJoplixVersionText((rel&&rel.version)||'')||parseJoplixVersionText((rel&&rel.tag)||'')||parseJoplixVersionText((rel&&rel.name)||'')||null;}
function normalizeChannelName(value){value=String(value||'').toLowerCase();if(value==='stable')return 'main';if(value==='pre-release'||value==='prerelease')return 'beta';return value||'main';}
function releaseChannel(rel){const raw=(rel&&(rel.channel||rel.release_channel||rel.type||rel.tag||rel.name))||'';const text=String(raw).toLowerCase();if(text.includes('beta')||text.includes('pre-release')||text.includes('prerelease'))return 'beta';return 'main';}
function installedChannel(){return normalizeChannelName((currentLocalSettings().installed_channel)||storage.get('installed_channel','main')||'main');}
function releaseUrl(rel){return (rel&&(rel.html_url||rel.url||rel.release_url))||'';}
function isAllowedUpdateTransition(rel,installed){const v=releaseVersion(rel);if(!v||!installed)return false;const cmp=compareJoplixVersions(v,installed);const relCh=releaseChannel(rel);const instCh=installedChannel();if(cmp>0)return true;if(cmp<0)return false;if(instCh==='beta'&&relCh==='main')return true;return false;}
function newestUpdateFromReleases(data){const installed=parseJoplixVersionText(getInstalledVersion());let best=null;for(const rel of ((data&&data.releases)||[])){if(!isAllowedUpdateTransition(rel,installed))continue;const v=releaseVersion(rel);if(!best||compareJoplixVersions(v,best.version)>0||(compareJoplixVersions(v,best.version)===0&&releaseChannel(rel)==='main'&&releaseChannel(best.release)==='beta'))best={release:rel,version:v};}return best;}
function joplixIsAdmin(){const app=document.getElementById('joplix-app');return !!(app&&app.dataset&&app.dataset.joplixIsAdmin==='1');}
function setBrandUpdate(update){const el=$('jp-brand-update');if(!el)return;if(!joplixIsAdmin()){el.textContent='';el.hidden=true;el.removeAttribute('href');return;}if(update&&update.version){el.textContent=update.version.text+' Neues Update!';const url=releaseUrl(update.release);if(url)el.href=url;else el.href=normalizeCodebergRepoUrlInput((currentLocalSettings().update_repo_url)||'https://codeberg.org/Stoneubi/joplix/releases');el.hidden=false;el.title='Codeberg Release öffnen';}else{el.textContent='';el.hidden=true;el.removeAttribute('href');}}
async function checkUpdateOnStart(){try{if(!joplixIsAdmin())return;const local=currentLocalSettings();if(!local.is_admin)return;if((local.update_source||'codeberg')!=='codeberg')return;const data=await api('/api/updates/releases');const upd=newestUpdateFromReleases(data);setBrandUpdate(upd);}catch(e){console.warn('Joplix Update Startprüfung fehlgeschlagen:',e.message);}}
function syncUpdateUi(){const local=currentLocalSettings();syncInstalledVersionUi();if($('jp-update-repo-url'))$('jp-update-repo-url').value=normalizeCodebergRepoUrlInput(local.update_repo_url||'https://codeberg.org/Stoneubi/joplix/releases');if($('jp-update-channel'))$('jp-update-channel').value=local.update_channel||'main';}
function updateStatus(txt,cls=''){const el=$('jp-update-status');if(el){el.textContent=txt;el.className='jp-update-status '+cls;}}
function isInstallableJoplixAsset(asset){const n=String((asset&&asset.name)||'').trim().replace(/\s+/g,'');return /^JoplixNC_v\d+\.\d+\.\d+(?:-beta)?\.zip$/i.test(n);}
function releaseIsNewer(rel,installed){return isAllowedUpdateTransition(rel,installed);}
function renderUpdateList(data){const box=$('jp-update-list');if(!box)return;box.innerHTML='';syncInstalledVersionUi();const installed=parseJoplixVersionText(getInstalledVersion());let rels=((data&&data.releases)||[]).filter(rel=>releaseIsNewer(rel,installed));rels=rels.filter(rel=>((rel.assets||[]).some(isInstallableJoplixAsset)));if(!rels.length){box.innerHTML='<p>Keine neuere Version für diesen Channel gefunden. Aktuell installiert: Joplix '+getInstalledVersion()+' ('+(installedChannel()==='beta'?'Beta':'Main')+').</p>';return;}rels.sort((a,b)=>{const c=compareJoplixVersions(releaseVersion(b),releaseVersion(a));if(c!==0)return c;return releaseChannel(a)==='main'?-1:1;});rels.forEach(rel=>{const card=document.createElement('div');card.className='jp-update-release';const h=document.createElement('div');h.className='jp-update-release-head';h.innerHTML='<strong></strong><span></span>';h.querySelector('strong').textContent=(rel.name||rel.tag||'Release')+' ['+(releaseChannel(rel)==='beta'?'Beta':'Main')+']';h.querySelector('span').textContent=rel.published_at||'';card.appendChild(h);(rel.assets||[]).filter(isInstallableJoplixAsset).forEach(asset=>{const row=document.createElement('div');row.className='jp-update-asset';const name=document.createElement('span');name.textContent=asset.name;const btn=document.createElement('button');btn.type='button';btn.textContent='Installieren';btn.onclick=()=>installUpdateAsset(asset,rel);row.appendChild(name);row.appendChild(btn);card.appendChild(row);});box.appendChild(card);});}
async function persistUpdateSettings(){
  const repo=normalizeCodebergRepoUrlInput($('jp-update-repo-url')?.value||'https://codeberg.org/Stoneubi/joplix/releases');
  const channel=$('jp-update-channel')?.value||'main';
  await api('/api/settings',{method:'POST',body:{update_source:'codeberg',update_repo_url:repo,update_channel:channel}});
  const updates={update_source:'codeberg',update_repo_url:repo,update_channel:channel};
  storage.set('settings',Object.assign(storage.get('settings',{}),updates));
  state.settings=Object.assign({},state.settings||{},updates);
}
async function checkUpdates(){
  try{
    if(!joplixIsAdmin())throw new Error('Administratorrechte erforderlich.');
    await persistUpdateSettings();
    updateStatus('Update-Liste wird von Codeberg geladen...','warning');
    const data=await api('/api/updates/releases');
    renderUpdateList(data);
    const shown=(data.releases||[]).filter(rel=>releaseIsNewer(rel,parseJoplixVersionText(getInstalledVersion()))&&(rel.assets||[]).some(isInstallableJoplixAsset)).length;
    const update=newestUpdateFromReleases(data);
    setBrandUpdate(update);
    updateStatus(shown+' installierbare Release(s) gefunden.'+(update?' Neues Update: '+update.version.text+' ['+(releaseChannel(update.release)==='beta'?'Beta':'Main')+']':'')+' Aktuell installiert: Joplix '+getInstalledVersion()+' ('+(installedChannel()==='beta'?'Beta':'Main')+').','ok');
  }catch(e){
    updateStatus('Update-Prüfung fehlgeschlagen: '+e.message,'error');
  }
}
async function installUpdateAsset(asset,rel){if(!asset?.id||!rel?.id)return;const backupEnabled=currentLocalSettings().update_backup_enabled==='yes';if(!confirm('Joplix Update jetzt installieren?\n\n'+asset.name+'\n\n'+(backupEnabled?'Vor dem Update wird ein optionales App-Backup erstellt.':'Es wird kein App-Backup erstellt. Bitte stellen Sie sicher, dass ein vollständiges Nextcloud-Backup vorhanden ist.')))return;try{updateStatus('Update wird heruntergeladen und installiert...','warning');const r=await api('/api/updates/install',{method:'POST',body:{release_id:rel.id,asset_id:asset.id}});const ch=releaseChannel(rel||{});storage.set('installed_channel',ch);const settings=storage.get('settings',{});settings.installed_channel=ch;storage.set('settings',settings);updateStatus((r.message||'Update installiert. Bitte Seite neu laden.')+' Version: '+(r.version||''),'ok');alert((r.message||'Update installiert. Bitte Seite neu laden.')+(r.backup?'\n\nBackup: '+r.backup:'')+'\nVersion: '+(r.version||''));}catch(e){updateStatus('Update-Installation fehlgeschlagen: '+e.message,'error');alert('Update-Installation fehlgeschlagen:\n'+e.message);}}


const JP_EN={
'Einstellungen':'Settings','Allgemein':'General','Aussehen':'Appearance','Sprache':'Language','Datenschutz':'Privacy','Über':'About',
'Joplix-Ordner':'Joplix folder','Nextcloud-Ordner mit den Joplix-Daten auswählen oder manuell eingeben':'Select the Nextcloud folder containing your Joplix data or enter it manually',
'Sortierung':'Sorting','Legt fest, wie Notizen angezeigt werden':'Controls how notes are displayed','Neueste zuerst':'Newest first','Älteste zuerst':'Oldest first','Titel A-Z':'Title A-Z',
'Office-Dateien öffnen':'Open Office files','Legt fest, ob die Bearbeitung in einem eigenen Fenster oder in einem neuen Browser-Tab startet.':'Choose whether editing opens in a separate window or a new browser tab.','Eigenes Fenster':'Separate window','Neuer Tab':'New tab',
'Automatische App-Backups':'Automatic app backups','Keine Backups (empfohlen)':'No backups (recommended)','Backup vor Update erstellen':'Create backup before update',
'Letzte Position merken':'Remember last position','Beim Öffnen wieder zum zuletzt verwendeten Notizbuch springen':'Return to the last used notebook when opening Joplix','Layout':'Layout','Vertikale Aufteilung':'Vertical layout','Spalten zurücksetzen':'Reset columns',
'Index neu aufbauen':'Rebuild index','Wenn Notizbücher oder Notizen fehlen, kann der lokale Index neu aufgebaut werden.':'Rebuild the local index if notebooks or notes are missing.','Starten':'Start',
'Update-Quelle':'Update source','Codeberg Repository für Joplix Updates':'Codeberg repository for Joplix updates','Aktuell installierte Version':'Currently installed version','Codeberg Adresse':'Codeberg address','Release Channel':'Release channel','Verfügbare Versionen':'Available versions','Updates suchen':'Check for updates','Noch nicht geprüft.':'Not checked yet.',
'Systemsprache':'System language','Systemsprache verwendet Deutsch bei einer deutschen Systemsprache, andernfalls Englisch.':'System language uses German when the system language is German; otherwise English.',
'Lokale Speicherung':'Local storage','Spaltenbreiten, Sortierung und letzte Position werden nur lokal im Browser gespeichert.':'Column widths, sorting and the last position are stored locally in the browser.',
'+ Notizbuch':'+ New notebook','Notizbücher':'Notebooks','Notizbuch':'Notebook','Notiz':'Note','Bearbeiten':'Edit','Speichern':'Save','Anzeige':'View','Löschen':'Delete','Ressource':'Attachment','Volltextsuche...':'Full-text search...','Bitte Notizbuch auswählen':'Please select a notebook','Keine Notiz geöffnet':'No note open','Index neu':'Rebuild index','Einstellungen schließen':'Close settings',
"Synchronisation":"Synchronisation","Synchronisieren":"Sync","Joplix gleicht den Joplix-Ordner mit einem Joplin Server ab. Der Ordner in Nextcloud ist zugleich eine versionierte Sicherung aller Notizen.":"Joplix synchronises the Joplix folder with a Joplin Server. The folder in Nextcloud doubles as a versioned backup of all notes.","Server-Adresse":"Server address","Adresse des Joplin Servers, zum Beispiel https://joplin.example.com":"Address of the Joplin Server, for example https://joplin.example.com","E-Mail":"E-mail","Anmeldung des Joplin-Server-Kontos":"Login of the Joplin Server account","Passwort":"Password","Wird verschlüsselt in Nextcloud gespeichert und nie wieder angezeigt. Leer lassen, um das gespeicherte Passwort zu behalten.":"Stored encrypted in Nextcloud and never shown again. Leave empty to keep the stored password.","Synchronisation aktivieren":"Enable synchronisation","Gleicht beim Öffnen, nach dem Speichern und regelmäßig im Hintergrund ab.":"Synchronises on opening, after saving and regularly in the background.","Lokale Server-Adressen erlauben":"Allow local server addresses","Nötig, wenn der Joplin Server im eigenen Netzwerk oder auf demselben Rechner läuft. Gilt für alle Benutzer.":"Needed when the Joplin Server runs in your own network or on the same machine. Applies to all users.","Verbindung testen":"Test connection","Jetzt synchronisieren":"Synchronise now","Noch nicht synchronisiert.":"Not synchronised yet.","Joplix zeigt deine synchronisierten Notizen direkt in Nextcloud an. Für die erste Nutzung muss der Joplix-Ordner korrekt gesetzt und der Index aufgebaut sein. Unter Einstellungen › Synchronisation kann Joplix den Ordner selbst mit einem Joplin Server abgleichen.":"Joplix shows your synchronised notes directly in Nextcloud. For first use, the Joplix folder must be set correctly and the index built. Under Settings › Synchronisation, Joplix can synchronise the folder with a Joplin Server itself."
};
function resolvedLanguage(){const v=(currentLocalSettings().language||'system');if(v==='de'||v==='en')return v;return String(navigator.language||'en').toLowerCase().startsWith('de')?'de':'en';}
function translateStaticUi(){const lang=resolvedLanguage();document.documentElement.lang=lang;if(lang!=='en')return;const root=document.body;const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);const nodes=[];while(walker.nextNode())nodes.push(walker.currentNode);nodes.forEach(n=>{const raw=n.nodeValue,trim=raw.trim();if(JP_EN[trim])n.nodeValue=raw.replace(trim,JP_EN[trim]);});document.querySelectorAll('[placeholder],[title],[aria-label]').forEach(el=>['placeholder','title','aria-label'].forEach(a=>{const v=el.getAttribute(a);if(v&&JP_EN[v])el.setAttribute(a,JP_EN[v]);}));}


function setupSettings(){
  const local=currentLocalSettings();
  if(local.sort_order)state.sort=local.sort_order;
  const openBtn=$('jp-open-settings'); if(openBtn)openBtn.addEventListener('click',ev=>{ev.preventDefault();openSettings();});
  const closeBtn=$('jp-close-settings'); if(closeBtn)closeBtn.addEventListener('click',ev=>{ev.preventDefault();closeSettings();});
  const backdrop=$('jp-settings-backdrop'); if(backdrop)backdrop.addEventListener('click',()=>closeSettings());
  document.addEventListener('keydown',e=>{if(e.key==='Escape')closeSettings();});
  document.querySelectorAll('[data-settings-tab]').forEach(b=>b.addEventListener('click',()=>activateSettingsTab(b.dataset.settingsTab)));
  const languageEl=$('jp-setting-language'); if(languageEl)languageEl.addEventListener('change',async e=>{await persistSettings({language:e.target.value});location.reload();});
  const sortEl=$('jp-setting-sort'); if(sortEl)sortEl.addEventListener('change',async e=>{state.sort=e.target.value;await persistSettings({sort_order:state.sort});if(state.notebookId)selectNotebook(state.notebookId);});
  const officeMode=$('jp-setting-office-open-mode'); if(officeMode)officeMode.addEventListener('change',async e=>{await persistSettings({office_open_mode:e.target.value==='tab'?'tab':'window'});jpStatusText('Office-Öffnungsart gespeichert.','ok');});const backupMode=$('jp-setting-update-backup');if(backupMode)backupMode.addEventListener('change',async e=>{await persistSettings({update_backup_enabled:e.target.value==='yes'?'yes':'no'});jpStatusText('Backup-Einstellung gespeichert.','ok');});
  const pathEl=$('jp-setting-joplin-path'); if(pathEl){pathEl.addEventListener('change',()=>applyJoplinPathFromInput().catch(e=>alert(e.message)));pathEl.addEventListener('blur',()=>applyJoplinPathFromInput().catch(e=>alert(e.message)));}
  const remember=$('jp-setting-remember-toggle'); if(remember)remember.addEventListener('click',async()=>{const local=currentLocalSettings();local.remember_last_location=(local.remember_last_location||'yes')==='yes'?'no':'yes';setSwitch(remember,local.remember_last_location==='yes');await persistSettings({remember_last_location:local.remember_last_location});});
  const reset=$('jp-layout-reset'); if(reset)reset.addEventListener('click',()=>{storage.del('columnWidths');applyColumnWidths({left:300,right:360});});
  const rebuild=$('jp-settings-rebuild'); if(rebuild)rebuild.addEventListener('click',()=>rebuildAndRestore().catch(e=>alert(e.message)));
  const upd=$('jp-update-check'); if(upd)upd.addEventListener('click',()=>checkUpdates());
  const updChannel=$('jp-update-channel'); if(updChannel)updChannel.addEventListener('change',()=>persistUpdateSettings().then(()=>updateStatus('Update-Einstellungen gespeichert.','ok')).catch(e=>updateStatus('Update-Einstellungen konnten nicht gespeichert werden: '+e.message,'error')));
  const updRepo=$('jp-update-repo-url'); if(updRepo)updRepo.addEventListener('change',()=>{updRepo.value=normalizeCodebergRepoUrlInput(updRepo.value);persistUpdateSettings().then(()=>updateStatus('Update-Einstellungen gespeichert.','ok')).catch(e=>updateStatus('Update-Einstellungen konnten nicht gespeichert werden: '+e.message,'error'));});
  syncSettingsUi();
}
// ---------------------------------------------------------------------------
// Joplin Server synchronisation
// ---------------------------------------------------------------------------
const syncState={status:null,running:false,timer:null,again:false,lastAuto:0};
function L(de,en){return resolvedLanguage()==='en'?en:de;}
function syncPaneStatus(text,cls=''){const el=$('jp-sync-status');if(el){el.textContent=text;el.className='jp-update-status '+cls;}}
function syncBusy(on){['jp-sync-now','jp-sync-run'].forEach(id=>{const b=$(id);if(b)b.disabled=!!on;});}
function syncEnabled(){return !!(syncState.status&&syncState.status.enabled&&syncState.status.configured);}
function describeSyncCounts(r){
  const parts=[];
  if(r.pulled)parts.push(r.pulled+' '+L('empfangen','received'));
  if(r.pushed)parts.push(r.pushed+' '+L('gesendet','sent'));
  if(r.deleted_local)parts.push(r.deleted_local+' '+L('lokal gelöscht','deleted locally'));
  if(r.deleted_remote)parts.push(r.deleted_remote+' '+L('auf dem Server gelöscht','deleted on the server'));
  if(r.conflicts)parts.push(r.conflicts+' '+L('Konflikt(e)','conflict(s)'));
  if(r.rejected)parts.push(r.rejected+' '+L('vom Server abgelehnt','rejected by the server'));
  return parts.join(', ');
}
function describeLastSync(st){
  if(!st)return L('Status nicht verfügbar.','Status not available.');
  if(!st.configured)return L('Noch nicht eingerichtet.','Not set up yet.');
  if(!st.enabled)return L('Synchronisation ist ausgeschaltet.','Synchronisation is switched off.');
  if(!st.last_run)return L('Noch nicht synchronisiert.','Not synchronised yet.');
  const when=formatJoplixDateTime(String(st.last_run*1000));
  if(st.last_status==='error')return L('Letzter Versuch ','Last attempt ')+when+': '+(st.last_message||L('Fehler','error'));
  const counts=st.last_result?describeSyncCounts(st.last_result):'';
  return L('Zuletzt synchronisiert: ','Last synchronised: ')+when+(counts?' ('+counts+')':'')+(st.last_status==='partial'?L(' – wird fortgesetzt',' – continuing'):'');
}
function fillSyncUi(){
  const st=syncState.status;
  const btn=$('jp-sync-now');if(btn)btn.hidden=!syncEnabled();
  if(!st)return;
  if($('jp-sync-url')&&document.activeElement!==$('jp-sync-url'))$('jp-sync-url').value=st.server_url||'';
  if($('jp-sync-email')&&document.activeElement!==$('jp-sync-email'))$('jp-sync-email').value=st.email||'';
  if($('jp-sync-password')){$('jp-sync-password').value='';$('jp-sync-password').placeholder=st.password_set?L('(gespeichert)','(stored)'):'';}
  setSwitch($('jp-sync-enabled'),!!st.enabled);
  setSwitch($('jp-sync-allow-local'),!!st.allow_local);
  syncPaneStatus(describeLastSync(st),st.last_status==='error'&&st.enabled?'error':(st.enabled&&st.last_run?'ok':''));
}
async function loadSyncStatus(){
  try{syncState.status=await api('/api/sync/status');}catch(e){syncState.status=null;}
  fillSyncUi();
  return syncState.status;
}
function syncFormValues(){
  const v={server_url:$('jp-sync-url')?$('jp-sync-url').value.trim():'',email:$('jp-sync-email')?$('jp-sync-email').value.trim():'',enabled:($('jp-sync-enabled')&&$('jp-sync-enabled').classList.contains('active'))?'yes':'no'};
  const pw=$('jp-sync-password')?$('jp-sync-password').value:'';
  if(pw)v.password=pw;
  if($('jp-sync-allow-local'))v.allow_local=$('jp-sync-allow-local').classList.contains('active')?'yes':'no';
  return v;
}
async function saveSyncSettings(){
  syncPaneStatus(L('Einstellungen werden gespeichert...','Saving settings...'),'warning');
  syncState.status=await api('/api/sync/settings',{method:'POST',body:syncFormValues()});
  fillSyncUi();
  syncPaneStatus(L('Einstellungen gespeichert.','Settings saved.'),'ok');
  return syncState.status;
}
async function testSyncConnection(){
  // Save first so the test uses exactly what is in the form, but never switch sync on as a side effect of testing.
  const values=syncFormValues();
  if(!(syncState.status&&syncState.status.enabled))values.enabled='no';
  syncPaneStatus(L('Verbindung wird geprüft...','Testing connection...'),'warning');
  syncState.status=await api('/api/sync/settings',{method:'POST',body:values});
  if($('jp-sync-password'))$('jp-sync-password').value='';
  const r=await api('/api/sync/test',{method:'POST',body:{}});
  if(!r.has_data)syncPaneStatus(L('Anmeldung erfolgreich, aber das Konto enthält noch keine Sync-Daten. Bitte zuerst einmal mit einer Joplin-App synchronisieren.','Login successful, but the account holds no sync data yet. Synchronise once from a Joplin app first.'),'warning');
  else if(r.e2ee)syncPaneStatus(L('Anmeldung erfolgreich, aber die Ende-zu-Ende-Verschlüsselung ist aktiv. Joplix kann verschlüsselte Notizen nicht lesen.','Login successful, but end-to-end encryption is enabled. Joplix cannot read encrypted notes.'),'error');
  else if(r.version!==3)syncPaneStatus(L('Anmeldung erfolgreich, aber die Sync-Version ','Login successful, but sync version ')+r.version+L(' wird nicht unterstützt.',' is not supported.'),'error');
  else syncPaneStatus(L('Verbindung erfolgreich. Der Server ist bereit für die Synchronisation.','Connection successful. The server is ready for synchronisation.'),'ok');
}
async function runSync(opts={}){
  if(!syncEnabled()){if(opts.manual)syncPaneStatus(L('Bitte die Synchronisation zuerst einrichten und aktivieren.','Please set up and enable synchronisation first.'),'warning');return null;}
  if(syncState.running){syncState.again=true;return null;}
  syncState.running=true;syncBusy(true);
  const total={pulled:0,pushed:0,deleted_local:0,deleted_remote:0,conflicts:0,rejected:0};
  let last=null,confirmDelete=false;
  try{
    if(opts.manual){status(L('Synchronisation läuft...','Synchronising...'),'warning');syncPaneStatus(L('Synchronisation läuft...','Synchronising...'),'warning');}
    for(let round=0;round<2000;round++){
      last=await api('/api/sync/run',{method:'POST',body:{confirm_delete:confirmDelete?'yes':'no'}});
      Object.keys(total).forEach(k=>{total[k]+=Number(last[k]||0);});
      if(last.status==='partial'){
        const text=L('Synchronisation läuft... ','Synchronising... ')+describeSyncCounts(total);
        status(text,'warning');syncPaneStatus(text,'warning');
        continue;
      }
      if(last.status==='error'&&last.code==='massDelete'&&opts.manual&&!confirmDelete){
        if(window.confirm(last.message+'\n\n'+L('Diese Elemente jetzt auch auf dem Joplin Server löschen?','Delete these items on the Joplin Server as well?'))){confirmDelete=true;continue;}
      }
      break;
    }
    const counts=describeSyncCounts(total);
    if(last&&last.status==='done'){
      const text=L('Synchronisiert','Synchronised')+(counts?': '+counts:'');
      if(opts.manual||counts)status(text,'ok');
      syncPaneStatus(text,'ok');
    }else if(last&&last.status==='busy'){
      if(opts.manual){status(last.message||L('Synchronisation läuft bereits.','A synchronisation is already running.'),'warning');syncPaneStatus(last.message||'','warning');}
    }else if(last&&last.status==='error'){
      const text=L('Synchronisation fehlgeschlagen: ','Synchronisation failed: ')+(last.message||'');
      status(text,'error');syncPaneStatus(text+(last.code==='massDelete'&&!opts.manual?L(' Zum Bestätigen „Jetzt synchronisieren“ wählen.',' Choose "Synchronise now" to confirm.'):''),'error');
    }
    if(total.pulled||total.deleted_local||total.conflicts){
      // Files in the folder changed: refresh the index and the open note (unless it has unsaved edits).
      Object.keys(state.noteMemCache).forEach(id=>{if(!(state.dirty&&id===state.noteId))delete state.noteMemCache[id];});
      await backgroundRefresh().catch(()=>{});
    }
    if(last&&last.status!=='busy'&&last.status!=='disabled'&&syncState.status){
      syncState.status.last_run=Math.floor(Date.now()/1000);syncState.status.last_status=last.status;syncState.status.last_message=last.message||'';syncState.status.last_result=total;
    }
    return last;
  }catch(e){
    const text=L('Synchronisation fehlgeschlagen: ','Synchronisation failed: ')+e.message;
    if(opts.manual)status(text,'error');
    syncPaneStatus(text,'error');
    return null;
  }finally{
    syncState.running=false;syncBusy(false);
    if(syncState.again){syncState.again=false;scheduleSync(1500);}
  }
}
function scheduleSync(delay=2500){
  if(!syncEnabled())return;
  clearTimeout(syncState.timer);
  syncState.timer=setTimeout(()=>{runSync().catch(()=>{});},delay);
}
// Called by api() after every successful request: local edits are sent to the server shortly afterwards.
function noteLocalChangeForSync(path,options,data){
  const method=String((options&&options.method)||'GET').toUpperCase();
  if(method!=='GET'&&/^\/api\/(notes|notebooks|tags|resources)(\/|$)/.test(path))scheduleSync(2500);
  else if(/\/office\/status$/.test(path)&&data&&data.updated)scheduleSync(2500);
}
function setupSync(){
  const toggle=id=>{const el=$(id);if(el)el.addEventListener('click',()=>setSwitch(el,!el.classList.contains('active')));};
  toggle('jp-sync-enabled');toggle('jp-sync-allow-local');
  const fail=e=>syncPaneStatus(e.message,'error');
  if($('jp-sync-save'))$('jp-sync-save').addEventListener('click',()=>saveSyncSettings().then(()=>{if(syncEnabled())runSync({manual:true});}).catch(fail));
  if($('jp-sync-test'))$('jp-sync-test').addEventListener('click',()=>testSyncConnection().catch(fail));
  if($('jp-sync-run'))$('jp-sync-run').addEventListener('click',()=>runSync({manual:true}));
  if($('jp-sync-now'))$('jp-sync-now').addEventListener('click',()=>runSync({manual:true}));
  if($('jp-open-settings'))$('jp-open-settings').addEventListener('click',()=>{loadSyncStatus().catch(()=>{});});
  // While Joplix is open, look for changes from other devices every two minutes.
  setInterval(()=>{if(document.visibilityState==='visible'&&syncEnabled()&&!state.saving)runSync().catch(()=>{});},120000);
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'&&syncEnabled()&&Date.now()-syncState.lastAuto>60000){syncState.lastAuto=Date.now();scheduleSync(800);}});
}
document.addEventListener('DOMContentLoaded',()=>{
  try{setupResizableColumns();}catch(e){console.error('Joplix resizable init failed',e);} try{setupEditorFormatbar();}catch(e){console.error('Joplix editor toolbar init failed',e);} try{setupSettings();}catch(e){console.error('Joplix settings init failed',e);} try{setupWelcome();}catch(e){console.error('Joplix welcome init failed',e);} try{setupSync();}catch(e){console.error('Joplix sync init failed',e);} try{setTimeout(()=>checkUpdateOnStart(),1200);}catch(e){console.warn('Joplix update startup init failed',e);} if($('jp-pdf-viewer-close'))$('jp-pdf-viewer-close').onclick=closePdfViewer; if($('jp-pdf-viewer-backdrop'))$('jp-pdf-viewer-backdrop').onclick=closePdfViewer;
  if($('jp-new-root'))$('jp-new-root').onclick=()=>newNotebook('');
  if($('jp-new-note-main'))$('jp-new-note-main').onclick=newNote;
  if($('jp-save-note'))$('jp-save-note').onclick=()=>saveNote({skipReload:true}).catch(e=>alert(e.message)); if($('jp-save-note-top'))$('jp-save-note-top').onclick=()=>saveNote({keepMode:true,skipReload:true,fastDirtySave:true}).catch(e=>alert(e.message)); if($('jp-edit-note'))$('jp-edit-note').onclick=()=>setNoteMode('edit'); if($('jp-cancel-edit'))$('jp-cancel-edit').onclick=()=>setNoteMode('view'); if($('jp-title'))$('jp-title').addEventListener('input',()=>{markDirty();updateNotePath();}); if($('jp-body'))$('jp-body').addEventListener('input',()=>{markDirty();renderDisplayMode();});
  if($('jp-delete-note'))$('jp-delete-note').onclick=()=>deleteNote().catch(e=>alert(e.message));
  if($('jp-rebuild-index'))$('jp-rebuild-index').onclick=()=>rebuildAndRestore().catch(e=>alert(e.message));
  if($('jp-resource-file'))$('jp-resource-file').onchange=e=>{if(e.target.files[0])uploadResource(e.target.files[0]).catch(err=>alert(err.message));};
  if($('jp-search'))$('jp-search').addEventListener('input',e=>runFullTextSearch(e.target.value));
  if($('jp-sort-button'))$('jp-sort-button').onclick=()=>{state.sort=state.sort==='updated_desc'?'updated_asc':'updated_desc';persistSettings({sort_order:state.sort}).catch(()=>{});if(state.notebookId)selectNotebook(state.notebookId);};
  updateSaveButton(); load().catch(e=>alert(e.message)).then(()=>loadSyncStatus()).then(()=>{if(syncEnabled()){syncState.lastAuto=Date.now();runSync().catch(()=>{});}});
});
})();

