(function(){
const state={index:null,settings:null,notebookId:'',noteId:'',note:null,sort:'updated_desc',loadingNoteId:'',backgroundBusy:false,noteLoading:false,noteMemCache:{},noteFetches:{},editorHistory:{undo:[],last:'',max:80},dirty:false,saving:false,saveAgain:false,savePromise:null,pendingNoteId:'',noteGenerations:{},officePollers:{},officeSyncs:{}};
const $=id=>document.getElementById(id); const route=p=>OC.generateUrl('/apps/joplix'+p);
// The browser cache (index with full note text, recent note bodies, last location, local settings) belongs to
// one Nextcloud user. localStorage is shared by everyone who uses this browser profile, so the cache records
// its owner in joplix.cacheOwner and is emptied before anything reads it when another user (or an unknown
// one) loads the app. A regular Nextcloud logout already wipes localStorage (login page with ?clear=1);
// this covers the cases without one: expired session, closed browser, a second account logging in.
// Layout keys (column widths, collapsed notebooks) hold no note content and stay.
const JP_USER_CACHE_KEYS=/^joplix\.(lastGoodIndex|lastLocation|recentNoteCacheIds|noteCache_|settings$|attachFolder$)/;
function jpPurgeUserCache(){try{Object.keys(localStorage).filter(k=>JP_USER_CACHE_KEYS.test(k)).forEach(k=>localStorage.removeItem(k));}catch(e){}}
function jpCurrentUser(){return String((document.head&&document.head.getAttribute('data-user'))||(window.OC&&OC.currentUser)||'');}
(function(){const uid=jpCurrentUser();let owner=null;try{owner=localStorage.getItem('joplix.cacheOwner');}catch(e){}if(!uid||owner!==uid){jpPurgeUserCache();try{localStorage.setItem('joplix.cacheOwner',uid);}catch(e){}}})();
// ---------------------------------------------------------------------------
// UI helpers: icons, panes, dialogs, toasts.
// - Icons are inline SVG (Material Design Icons paths, the set Nextcloud uses) so they follow the text
//   colour of the theme. Template: <span class="jp-icon" data-icon="name"> filled by hydrateIcons().
//   JS-built DOM: jpIconEl('name') or jpIcon('name') for an HTML string. Add new icons to JP_ICONS only.
// - jpAlert / jpConfirm / jpPrompt / jpDialog replace the browser's alert/confirm/prompt. They return
//   promises: confirm and prompt must be awaited, alert may be fired without waiting.
// - Menus and dialogs are appended to jpRoot() (#joplix), which css/style.css scopes every rule to.
// ---------------------------------------------------------------------------
const JP_ICONS={
menu:'M3,6H21V8H3V6M3,11H21V13H3V11M3,16H21V18H3V16Z',
chevronRight:'M8.59,16.58L13.17,12L8.59,7.41L10,6L16,12L10,18L8.59,16.58Z',
plus:'M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z',
close:'M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z',
magnify:'M9.5,3A6.5,6.5 0 0,1 16,9.5C16,11.11 15.41,12.59 14.44,13.73L14.71,14H15.5L20.5,19L19,20.5L14,15.5V14.71L13.73,14.44C12.59,15.41 11.11,16 9.5,16A6.5,6.5 0 0,1 3,9.5A6.5,6.5 0 0,1 9.5,3M9.5,5C7,5 5,7 5,9.5C5,12 7,14 9.5,14C12,14 14,12 14,9.5C14,7 12,5 9.5,5Z',
cog:'M12,15.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.34 19.43,11L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.5,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.5,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.21,8.95 2.27,9.22 2.46,9.37L4.57,11C4.53,11.34 4.5,11.67 4.5,12C4.5,12.33 4.53,12.65 4.57,12.97L2.46,14.63C2.27,14.78 2.21,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.94C7.96,18.34 8.5,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.5,18.67 16.04,18.34 16.56,17.94L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z',
pencil:'M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z',
eye:'M12,9A3,3 0 0,0 9,12A3,3 0 0,0 12,15A3,3 0 0,0 15,12A3,3 0 0,0 12,9M12,17A5,5 0 0,1 7,12A5,5 0 0,1 12,7A5,5 0 0,1 17,12A5,5 0 0,1 12,17M12,4.5C7,4.5 2.73,7.61 1,12C2.73,16.39 7,19.5 12,19.5C17,19.5 21.27,16.39 23,12C21.27,7.61 17,4.5 12,4.5Z',
save:'M15,9H5V5H15M12,19A3,3 0 0,1 9,16A3,3 0 0,1 12,13A3,3 0 0,1 15,16A3,3 0 0,1 12,19M17,3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V7L17,3Z',
delete:'M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z',
paperclip:'M16.5,6V17.5A4,4 0 0,1 12.5,21.5A4,4 0 0,1 8.5,17.5V5A2.5,2.5 0 0,1 11,2.5A2.5,2.5 0 0,1 13.5,5V15.5A1,1 0 0,1 12.5,16.5A1,1 0 0,1 11.5,15.5V6H10V15.5A2.5,2.5 0 0,0 12.5,18A2.5,2.5 0 0,0 15,15.5V5A4,4 0 0,0 11,1A4,4 0 0,0 7,5V17.5A5.5,5.5 0 0,0 12.5,23A5.5,5.5 0 0,0 18,17.5V6H16.5Z',
bold:'M13.5,15.5H10V12.5H13.5A1.5,1.5 0 0,1 15,14A1.5,1.5 0 0,1 13.5,15.5M10,6.5H13A1.5,1.5 0 0,1 14.5,8A1.5,1.5 0 0,1 13,9.5H10M15.6,10.79C16.57,10.11 17.25,9 17.25,8C17.25,5.74 15.5,4 13.25,4H7V18H14.04C16.14,18 17.75,16.3 17.75,14.21C17.75,12.69 16.89,11.39 15.6,10.79Z',
italic:'M10,4V7H12.21L8.79,15H6V18H14V15H11.79L15.21,7H18V4H10Z',
link:'M10.59,13.41C11,13.8 11,14.44 10.59,14.83C10.2,15.22 9.56,15.22 9.17,14.83C7.22,12.88 7.22,9.71 9.17,7.76V7.76L12.71,4.22C14.66,2.27 17.83,2.27 19.78,4.22C21.73,6.17 21.73,9.34 19.78,11.29L18.29,12.78C18.3,11.96 18.17,11.14 17.89,10.36L18.36,9.88C19.54,8.71 19.54,6.81 18.36,5.64C17.19,4.46 15.29,4.46 14.12,5.64L10.59,9.17C9.41,10.34 9.41,12.24 10.59,13.41M13.41,9.17C13.8,8.78 14.44,8.78 14.83,9.17C16.78,11.12 16.78,14.29 14.83,16.24V16.24L11.29,19.78C9.34,21.73 6.17,21.73 4.22,19.78C2.27,17.83 2.27,14.66 4.22,12.71L5.71,11.22C5.7,12.04 5.83,12.86 6.11,13.65L5.64,14.12C4.46,15.29 4.46,17.19 5.64,18.36C6.81,19.54 8.71,19.54 9.88,18.36L13.41,14.83C14.59,13.66 14.59,11.76 13.41,10.59C13,10.2 13,9.56 13.41,9.17Z',
code:'M14.6,16.6L19.2,12L14.6,7.4L16,6L22,12L16,18L14.6,16.6M9.4,16.6L4.8,12L9.4,7.4L8,6L2,12L8,18L9.4,16.6Z',
listBulleted:'M7,5H21V7H7V5M7,13V11H21V13H7M4,4.5A1.5,1.5 0 0,1 5.5,6A1.5,1.5 0 0,1 4,7.5A1.5,1.5 0 0,1 2.5,6A1.5,1.5 0 0,1 4,4.5M4,10.5A1.5,1.5 0 0,1 5.5,12A1.5,1.5 0 0,1 4,13.5A1.5,1.5 0 0,1 2.5,12A1.5,1.5 0 0,1 4,10.5M7,19V17H21V19H7M4,16.5A1.5,1.5 0 0,1 5.5,18A1.5,1.5 0 0,1 4,19.5A1.5,1.5 0 0,1 2.5,18A1.5,1.5 0 0,1 4,16.5Z',
listNumbered:'M7,13V11H21V13H7M7,19V17H21V19H7M7,7V5H21V7H7M3,8V5H2V4H4V8H3M2,17V16H5V20H2V19H4V18.5H3V17.5H4V17H2M4.25,10A0.75,0.75 0 0,1 5,10.75C5,10.95 4.92,11.14 4.79,11.27L3.12,13H5V14H2V13.08L4,11H2V10H4.25Z',
checkbox:'M19,19H5V5H15V3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V11H19M7.91,10.08L6.5,11.5L11,16L21,6L19.59,4.58L11,13.17L7.91,10.08Z',
heading:'M5,4H8V10.5H16V4H19V20H16V13.5H8V20H5Z',
minus:'M19,13H5V11H19V13Z',
clock:'M12,20A8,8 0 0,0 20,12A8,8 0 0,0 12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20M12,2A10,10 0 0,1 22,12A10,10 0 0,1 12,22C6.47,22 2,17.5 2,12A10,10 0 0,1 12,2M12.5,7V12.25L17,14.92L16.25,16.15L11,13V7H12.5Z',
marker:'M4,17L6.75,14.25L6.72,14.23C6.14,13.64 6.14,12.69 6.72,12.11L11.46,7.37L15.7,11.61L10.96,16.35C10.39,16.93 9.46,16.93 8.87,16.37L8.24,17H4M15.91,2.91C16.5,2.33 17.45,2.33 18.03,2.91L20.16,5.03C20.74,5.62 20.74,6.57 20.16,7.16L16.86,10.45L12.62,6.21L15.91,2.91M2,20H22V22H2Z',
undo:'M12.5,8C9.85,8 7.45,9 5.6,10.6L2,7V16H11L7.38,12.38C8.77,11.22 10.54,10.5 12.5,10.5C16.04,10.5 19.05,12.81 20.1,16L22.47,15.22C21.08,11.03 17.15,8 12.5,8Z',
dots:'M16,12A2,2 0 0,1 18,10A2,2 0 0,1 20,12A2,2 0 0,1 18,14A2,2 0 0,1 16,12M10,12A2,2 0 0,1 12,10A2,2 0 0,1 14,12A2,2 0 0,1 12,14A2,2 0 0,1 10,12M4,12A2,2 0 0,1 6,10A2,2 0 0,1 8,12A2,2 0 0,1 6,14A2,2 0 0,1 4,12Z',
sync:'M12,18A6,6 0 0,1 6,12C6,11 6.25,10.03 6.7,9.2L5.24,7.74C4.46,8.97 4,10.43 4,12A8,8 0 0,0 12,20V23L16,19L12,15M12,4V1L8,5L12,9V6A6,6 0 0,1 18,12C18,13 17.75,13.97 17.3,14.8L18.76,16.26C19.54,15.03 20,13.57 20,12A8,8 0 0,0 12,4Z',
refresh:'M17.65,6.35C16.2,4.9 14.21,4 12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20C15.73,20 18.84,17.45 19.73,14H17.65C16.83,16.33 14.61,18 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6C13.66,6 15.14,6.69 16.22,7.78L13,11H20V4L17.65,6.35Z',
sort:'M9,3L5,7H8V14H10V7H13M16,17V10H14V17H11L15,21L19,17H16Z',
arrowLeft:'M20,11V13H8L13.5,18.5L12.08,19.92L4.16,12L12.08,4.08L13.5,5.5L8,11H20Z',
download:'M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z',
tag:'M5.5,7A1.5,1.5 0 0,1 4,5.5A1.5,1.5 0 0,1 5.5,4A1.5,1.5 0 0,1 7,5.5A1.5,1.5 0 0,1 5.5,7M21.41,11.58L12.41,2.58C12.05,2.22 11.55,2 11,2H4C2.89,2 2,2.89 2,4V11C2,11.55 2.22,12.05 2.59,12.41L11.58,21.41C11.95,21.77 12.45,22 13,22C13.55,22 14.05,21.77 14.41,21.41L21.41,14.41C21.78,14.05 22,13.55 22,13C22,12.44 21.77,11.94 21.41,11.58Z',
file:'M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z',
image:'M8.5,13.5L11,16.5L14.5,12L19,18H5M21,19V5C21,3.89 20.1,3 19,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19Z',
note:'M5,3C3.89,3 3,3.89 3,5V19C3,20.11 3.89,21 5,21H19C20.11,21 21,20.11 21,19V5C21,3.89 20.11,3 19,3H5M5,5H19V19H5V5M7,7V9H17V7H7M7,11V13H17V11H7M7,15V17H14V15H7Z',
restore:'M13,3A9,9 0 0,0 4,12H1L4.89,15.89L4.96,16.03L9,12H6A7,7 0 0,1 13,5A7,7 0 0,1 20,12A7,7 0 0,1 13,19C11.07,19 9.32,18.21 8.06,16.94L6.64,18.36C8.27,20 10.5,21 13,21A9,9 0 0,0 22,12A9,9 0 0,0 13,3Z',
folderMove:'M14,18V15H10V11H14V8L19,13M20,6H12L10,4H4C2.89,4 2,4.89 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V8C22,6.89 21.1,6 20,6Z',
folderPlus:'M10,4L12,6H20A2,2 0 0,1 22,8V18A2,2 0 0,1 20,20H4C2.89,20 2,19.1 2,18V6C2,4.89 2.89,4 4,4H10M15,9V12H12V14H15V17H17V14H20V12H17V9H15Z',
folder:'M10,4H4C2.89,4 2,4.89 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V8C22,6.89 21.1,6 20,6H12L10,4Z',
cloud:'M19.35,10.03C18.67,6.59 15.64,4 12,4C9.11,4 6.6,5.64 5.35,8.03C2.34,8.36 0,10.9 0,14A6,6 0 0,0 6,20H19A5,5 0 0,0 24,15C24,12.36 21.95,10.22 19.35,10.03Z',
arrowUp:'M13,20H11V8L5.5,13.5L4.08,12.08L12,4.16L19.92,12.08L18.5,13.5L13,8V20Z',
alert:'M13,14H11V9H13M13,18H11V16H13M1,21H23L12,2L1,21Z'
};
function jpIcon(name){return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="'+(JP_ICONS[name]||'')+'"/></svg>';}
function jpIconEl(name){const s=document.createElement('span');s.className='jp-icon';s.innerHTML=jpIcon(name);return s;}
function hydrateIcons(root){(root||document).querySelectorAll('.jp-icon[data-icon]').forEach(el=>{if(!el.firstChild)el.innerHTML=jpIcon(el.dataset.icon);});}
function jpMenuItem(act,icon,label,cls){return '<button type="button" data-act="'+act+'" role="menuitem"'+(cls?' class="'+cls+'"':'')+'><span class="jp-icon">'+jpIcon(icon)+'</span><span>'+jpEscapeHtml(label)+'</span></button>';}
function jpRoot(){return document.getElementById('joplix')||document.body;}
// Small screens show one column at a time (css/style.css section 11): 'nav' | 'list' | 'note'.
function setPane(p){const app=$('joplix-app');if(app)app.dataset.pane=p;}
// Below 1025px (same breakpoint as CSS section 11) the notebook sidebar is a drawer over the list/note pane,
// open while #joplix-app has jp-nav-open. On wide screens the class is never set.
const jpNarrow=window.matchMedia('(max-width: 1024px)');
function navIsOpen(){const app=$('joplix-app');return !!(app&&app.classList.contains('jp-nav-open'));}
function setNavOpen(open,opts={}){
  const app=$('joplix-app');if(!app)return;
  open=!!open&&jpNarrow.matches;
  const was=app.classList.contains('jp-nav-open');
  app.classList.toggle('jp-nav-open',open);
  const toggle=$('jp-nav-toggle');if(toggle)toggle.setAttribute('aria-expanded',open?'true':'false');
  if(!opts.focus||was===open)return;
  if(open){const target=document.querySelector('#jp-tree .jp-tree-row.active .jp-tree-item')||document.querySelector('#jp-tree .jp-tree-item')||$('jp-new-root');if(target)setTimeout(()=>target.focus({preventScroll:true}),0);}
  else if(toggle)toggle.focus();
}
// Without an open note the editor column shows the empty state instead of title/body/attachments.
function setHasNote(on){const app=$('joplix-app');if(app){app.classList.toggle('jp-no-note',!on);app.classList.remove('jp-trash-note');}if($('jp-title'))$('jp-title').readOnly=false;}
function setTrashNote(on){const app=$('joplix-app');if(app)app.classList.toggle('jp-trash-note',!!on);if($('jp-title'))$('jp-title').readOnly=!!on;}
function jpSettingsOpen(){const o=document.getElementById('jp-settings-overlay');return !!(o&&o.classList.contains('open'));}
function jpDialogOpen(){return !!document.querySelector('.jp-dialog-overlay');}
// One modal for every question. opts: title, message, input (string => text field), select ([{value,label}])
// with value, buttons ([{label,value,primary,danger,submit}]; submit resolves with the field value) and
// cancelValue (resolved on Escape or a click on the backdrop). className: extra class on the modal. mount(modal,finish): adds custom content
// after the message, may resolve the dialog through finish(value), returns the element to focus.
function jpDialog(opts){
  opts=opts||{};
  return new Promise(resolve=>{
    const previous=document.activeElement;
    const overlay=document.createElement('div');overlay.className='jp-overlay jp-dialog-overlay open';
    const backdrop=document.createElement('div');backdrop.className='jp-backdrop';
    const modal=document.createElement('section');modal.className='jp-modal'+(opts.className?' '+opts.className:'');modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');
    if(opts.title){const h=document.createElement('h2');h.textContent=opts.title;modal.appendChild(h);}
    if(opts.message){const p=document.createElement('p');p.className='jp-dialog-message';p.textContent=opts.message;modal.appendChild(p);}
    let field=null;
    if(opts.select){field=document.createElement('select');opts.select.forEach(o=>{const el=document.createElement('option');el.value=o.value;el.textContent=o.label;field.appendChild(el);});if(opts.value!==undefined)field.value=opts.value;}
    else if(opts.input!==undefined){field=document.createElement('input');field.type='text';field.value=String(opts.input||'');}
    if(field){field.setAttribute('aria-label',opts.title||opts.message||'');modal.appendChild(field);}
    let mounted=null;
    if(opts.mount)mounted=opts.mount(modal,value=>finish(value));
    const actions=document.createElement('div');actions.className='jp-modal-actions';modal.appendChild(actions);
    const buttons=opts.buttons||[{label:t('joplix','OK'),value:true,primary:true}];
    const cancelValue=opts.cancelValue!==undefined?opts.cancelValue:null;
    let done=false,defaultBtn=null;
    const finish=value=>{if(done)return;done=true;document.removeEventListener('keydown',onKey,true);overlay.remove();try{if(previous&&previous.focus)previous.focus();}catch(e){}resolve(value);};
    buttons.forEach(b=>{const el=document.createElement('button');el.type='button';el.textContent=b.label;if(b.danger)el.className='danger';else if(b.primary)el.className='primary';el.onclick=()=>finish(b.submit&&field?field.value:b.value);actions.appendChild(el);if(b.primary||b.danger)defaultBtn=el;});
    const onKey=ev=>{
      if(ev.key==='Escape'){ev.preventDefault();ev.stopPropagation();finish(cancelValue);return;}
      if(ev.key==='Enter'&&field&&ev.target===field&&field.tagName==='INPUT'){ev.preventDefault();if(buttons.some(b=>b.submit))finish(field.value);return;}
      if(ev.key==='Tab'){
        const items=Array.from(modal.querySelectorAll('input,select,button'));if(!items.length)return;
        const first=items[0],last=items[items.length-1];
        if(!modal.contains(document.activeElement)){ev.preventDefault();first.focus();}
        else if(ev.shiftKey&&document.activeElement===first){ev.preventDefault();last.focus();}
        else if(!ev.shiftKey&&document.activeElement===last){ev.preventDefault();first.focus();}
      }
    };
    document.addEventListener('keydown',onKey,true);
    backdrop.onclick=()=>finish(cancelValue);
    overlay.appendChild(backdrop);overlay.appendChild(modal);jpRoot().appendChild(overlay);
    const focusTarget=field||mounted||defaultBtn||actions.querySelector('button');
    if(focusTarget){focusTarget.focus();if(field&&field.select)field.select();}
  });
}
function jpAlert(message,title){return jpDialog({title:title||'',message:String(message==null?'':message),cancelValue:true,buttons:[{label:t('joplix','OK'),value:true,primary:true}]});}
function jpConfirm(message,o={}){return jpDialog({title:o.title||'',message:String(message||''),cancelValue:false,buttons:[{label:o.cancelLabel||t('joplix','Cancel'),value:false},{label:o.confirmLabel||t('joplix','OK'),value:true,primary:!o.danger,danger:!!o.danger}]});}
function jpPrompt(title,value='',o={}){return jpDialog({title,message:o.message||'',input:value||'',cancelValue:null,buttons:[{label:t('joplix','Cancel'),value:null},{label:o.confirmLabel||t('joplix','OK'),submit:true,primary:true}]});}
// Short, non-blocking confirmation. Uses Nextcloud's toast when available, otherwise the status line.
function jpToast(message,type){try{if(window.OC&&OC.Notification&&typeof OC.Notification.showTemporary==='function'){OC.Notification.showTemporary(message,type==='error'?{type:'error'}:undefined);return;}}catch(e){}status(message,type==='error'?'error':'ok');}

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

// Update source: GitHub releases. Must match JoplixDevUpdateService::DEFAULT_REPO_URL (PHP).
const JP_UPDATE_REPO_URL='https://github.com/kane9287/joplix-xsync/releases';
function normalizeUpdateRepoUrlInput(value){
  let v=String(value||'').trim();
  if(!v)return JP_UPDATE_REPO_URL;
  v=v.replace(/^<a\s+[^>]*href=["']([^"']+)["'][^>]*>.*$/i,'$1');
  v=v.replace(/^<a\s+[^>]*>(.*?)<\/a>$/i,'$1');
  v=v.replace(/&amp;/g,'&');
  v=v.replace(/\s+/g,'');
  v=v.replace(/^http:\/\//i,'https://');
  // Installations that still store the former Codeberg update source move to the GitHub repository.
  if(/^(https:\/\/)?(www\.)?codeberg\.(org|com)(\/|$)/i.test(v))return JP_UPDATE_REPO_URL;
  if(/^(www\.)?github\.com\//i.test(v))v='https://'+v;
  v=v.replace(/^https:\/\/www\.github\./i,'https://github.');
  v=v.replace(/\.git$/i,'');
  v=v.replace(/[?#].*$/,'');
  v=v.replace(/\/+$/,'');
  const m=v.match(/^https:\/\/github\.com\/([^\/]+)\/([^\/]+)(?:\/releases)?$/i);
  if(m)return 'https://github.com/'+m[1]+'/'+m[2].replace(/\.git$/i,'')+'/releases';
  return v;
}
const storage={
  get(k,d=null){try{const v=localStorage.getItem('joplix.'+k);return v?JSON.parse(v):d;}catch(e){return d;}},
  set(k,v){try{localStorage.setItem('joplix.'+k,JSON.stringify(v));}catch(e){}},
  del(k){try{localStorage.removeItem('joplix.'+k);}catch(e){}}
};
function clamp(n,min,max){return Math.max(min,Math.min(max,n));}
// Column order is navigation | note list | note. Navigation and list have a stored pixel width
// (localStorage joplix.columnWidths2 = {nav,list}); the note takes the remaining space. The widths reach
// the stylesheet as --jp-nav-width / --jp-list-width. Only a finished drag, a double click on a handle or
// "reset" stores a width, so a temporarily narrow window does not shrink the saved layout.
const JP_COLS={nav:{def:300,min:220,max:440},list:{def:340,min:260,max:560}};
function storedColumnWidths(){const w=storage.get('columnWidths2',null)||{};return {nav:Number(w.nav)||JP_COLS.nav.def,list:Number(w.list)||JP_COLS.list.def};}
function applyColumnWidths(w){
  const app=$('joplix-app'); if(!app)return null;
  w=w||storedColumnWidths();
  const appWidth=Math.floor((app.getBoundingClientRect&&app.getBoundingClientRect().width)||window.innerWidth||1400);
  let nav=clamp(Number(w.nav)||JP_COLS.nav.def,JP_COLS.nav.min,JP_COLS.nav.max);
  let list=clamp(Number(w.list)||JP_COLS.list.def,JP_COLS.list.min,JP_COLS.list.max);
  // Leave at least 420px for the note itself; take the space from the list first, then the navigation.
  const over=nav+list+2+420-appWidth;
  if(over>0){const cutList=Math.min(list-JP_COLS.list.min,over);list-=cutList;nav=Math.max(JP_COLS.nav.min,nav-(over-cutList));}
  app.style.setProperty('--jp-nav-width',nav+'px');
  app.style.setProperty('--jp-list-width',list+'px');
  return {nav,list};
}
function setupResizableColumns(){
  const app=$('joplix-app'); if(!app)return;
  storage.del('columnWidths'); // layout before 2026.11.69 (list on the right); its widths do not carry over
  applyColumnWidths();
  window.addEventListener('resize',()=>applyColumnWidths());
  document.querySelectorAll('.jp-resizer').forEach(handle=>{
    const side=handle.dataset.resize==='list'?'list':'nav';
    handle.addEventListener('pointerdown',ev=>{
      if(ev.button!==undefined&&ev.button!==0)return;
      ev.preventDefault();
      const startX=ev.clientX,start=storedColumnWidths();
      let applied=null;
      document.body.classList.add('jp-resizing'); handle.classList.add('dragging');
      try{handle.setPointerCapture&&handle.setPointerCapture(ev.pointerId);}catch(e){}
      const move=e=>{const next={nav:start.nav,list:start.list};next[side]=start[side]+(e.clientX-startX);applied=applyColumnWidths(next)||applied;};
      const up=e=>{
        document.body.classList.remove('jp-resizing');
        handle.classList.remove('dragging');
        try{handle.releasePointerCapture&&handle.releasePointerCapture(e.pointerId);}catch(err){}
        handle.removeEventListener('pointermove',move);
        handle.removeEventListener('pointerup',up);
        handle.removeEventListener('pointercancel',up);
        if(applied)storage.set('columnWidths2',applied);
      };
      handle.addEventListener('pointermove',move);
      handle.addEventListener('pointerup',up);
      handle.addEventListener('pointercancel',up);
    });
    handle.addEventListener('dblclick',()=>{const w=storedColumnWidths();w[side]=JP_COLS[side].def;const applied=applyColumnWidths(w);if(applied)storage.set('columnWidths2',applied);});
  });
}
function isEmptyIndex(idx){return !idx||(!(idx.tree||[]).length&&!(idx.notebooks||[]).length&&!(idx.notes||[]).length);}
function hasFullTextIndex(idx){return !!(idx&&idx.search_version>=1&&(idx.notes||[]).every(n=>Object.prototype.hasOwnProperty.call(n,'search_text')));}
function hasCurrentCountIndex(idx){return !!(idx&&idx.count_version>=5);}
function visibleNoteCountForNotebook(id){return ((state.index&&state.index.notes)||[]).filter(n=>n.notebook_id===id).length;}
function notebookTitleWithCount(n){const c=visibleNoteCountForNotebook(n.id);return (n.title||'')+(c>0?' ('+c+')':'');}
function normalizeSearchText(v){return String(v||'').toLocaleLowerCase();}
function noteMatchesQuery(n,q){if(!q)return true;const hay=[n.title,n.search_text,n.notebook_title,(n.tag_titles||[]).join(' '),(n.resource_titles||[]).join(' ')].map(normalizeSearchText).join(' ');return q.split(/\s+/).filter(Boolean).every(part=>hay.includes(part));}
function showNotebookNotes(){if(!state.index)return; if(state.notebookId)selectNotebook(state.notebookId); else renderNotes([]);}
function runFullTextSearch(raw){const q=normalizeSearchText(raw).trim();if(!q){showNotebookNotes();status(state.index?t('joplix','Index up to date'):t('joplix','Loading index...'),'ok');return;}const notes=((state.index&&state.index.notes)||[]).filter(n=>noteMatchesQuery(n,q)).sort(sorter());renderNotes(notes);$('jp-breadcrumb').textContent=t('joplix','Full-text search: ')+raw;status(t('joplix','{count} results for “{query}”',{count:notes.length,query:raw}),'ok');}
function rememberIndex(idx){if(idx&&!isEmptyIndex(idx))storage.set('lastGoodIndex',idx);}
function rememberedIndex(){return storage.get('lastGoodIndex',null);}
function localLocation(){return storage.get('lastLocation',{notebook_id:'',note_id:''});}
function firstNotebookId(idx){const tree=(idx&&idx.tree)||[];return tree.length?tree[0].id:'';}

function jpEscapeHtml(v){return String(v||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
// ---------------------------------------------------------------------------
// Markdown preview. DISPLAY ONLY: the textarea #jp-body is the single source that is saved, and nothing
// rendered here is ever written back, so the preview cannot change a note that syncs to other Joplin
// clients. The one exception is a task checkbox, which edits exactly its own source line through
// onDisplayCheckboxChange() (data-line = index of the line in the textarea).
// Every piece of note text is HTML-escaped before markup is added; raw HTML in a note is shown as text.
// Joplin resources (":/<32 hex id>") are loaded through /api/resources/{id}/data by hydratePreviewResources().
// Remote images are shown as links: Nextcloud's content security policy blocks third-party image hosts.
// ---------------------------------------------------------------------------
const JP_RES_ID=/^:\/([0-9a-fA-F]{32})(?:[#?].*)?$/;
const JP_LIST_RE=/^(\s*)(?:([-*+])|(\d+)[.)])\s+(.*)$/;
function jpInlineMarkdown(src){
  // Finished HTML is parked in kept[] behind a \u0001n\u0001 marker so later rules cannot alter it.
  const kept=[];
  const keep=html=>{kept.push(html);return '\u0001'+(kept.length-1)+'\u0001';};
  const plain=v=>v.replace(/\u0001\d+\u0001/g,'');
  const target=u=>{const res=u.match(JP_RES_ID);if(res)return {res:res[1].toLowerCase()};if(/^(https?:\/\/|mailto:)/i.test(u))return {url:u};return null;};
  let s=String(src==null?'':src).replace(/\u0001/g,'');
  s=s.replace(/`([^`]+)`/g,(m,c)=>keep('<code>'+jpEscapeHtml(c)+'</code>'));
  s=jpEscapeHtml(s);
  // Joplin desktop writes a resized image as raw HTML: <img src=":/id" width="300" ...>. Only this form is
  // rendered; any other HTML stays text.
  s=s.replace(/&lt;img\s(?:(?!&gt;).)*?src=(?:&quot;|&#39;):\/([0-9a-fA-F]{32})(?:&quot;|&#39;)(?:(?!&gt;).)*&gt;/gi,(m,id)=>{const w=m.match(/\swidth=(?:&quot;|&#39;)?(\d{1,4})/i);const alt=m.match(/\salt=(?:&quot;|&#39;)((?:(?!&quot;|&#39;).)*)/i);return keep('<img class="jp-md-res" data-jp-res="'+id.toLowerCase()+'" alt="'+(alt?alt[1]:'')+'"'+(w?' width="'+w[1]+'"':'')+'>');});
  s=s.replace(/!\[([^\]]*)\]\(\s*([^)\s]+)(?:\s+[^)]*)?\)/g,(m,alt,u)=>{const tg=target(u);if(!tg)return m;if(tg.res)return keep('<img class="jp-md-res" data-jp-res="'+tg.res+'" alt="'+plain(alt)+'">');return keep('<a href="'+tg.url+'" target="_blank" rel="noopener noreferrer">')+(alt||tg.url)+keep('</a>');});
  // Link text may contain backslash-escaped brackets: Joplin writes note titles as [A \[draft\]](:/id).
  s=s.replace(/\[((?:[^\]\\]|\\.)+)\]\(\s*([^)\s]+)(?:\s+[^)]*)?\)/g,(m,text,u)=>{const tg=target(u);if(!tg)return m;return keep(tg.res?'<a href="#" data-jp-res-link="'+tg.res+'">':'<a href="'+tg.url+'" target="_blank" rel="noopener noreferrer">')+text.replace(/\\([\[\]])/g,'$1')+keep('</a>');});
  s=s.replace(/(^|[\s(]|&lt;)(https?:\/\/(?:(?!&gt;|&lt;|&quot;)\S)+)/g,(m,pre,u)=>{const tail=(u.match(/[.,;:!?)\]]+$/)||[''])[0];const url=tail?u.slice(0,-tail.length):u;return pre+keep('<a href="'+url+'" target="_blank" rel="noopener noreferrer">'+url+'</a>')+tail;});
  s=s.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>').replace(/(^|[^\w])__([^_]+)__(?!\w)/g,'$1<strong>$2</strong>')
     .replace(/\*([^*\s][^*]*)\*/g,'<em>$1</em>').replace(/(^|[^\w])_([^_\s][^_]*)_(?!\w)/g,'$1<em>$2</em>')
     .replace(/~~([^~]+)~~/g,'<del>$1</del>').replace(/==([^=]+)==/g,'<mark>$1</mark>');
  return s.replace(/\u0001(\d+)\u0001/g,(m,i)=>kept[Number(i)]);
}
function jpRenderMarkdown(md){return jpRenderBlocks(String(md||'').split(/\r?\n/),true)||'<p class="jp-muted">'+t('joplix','No note content.')+'</p>';}
// lines: source lines. interactive: task checkboxes carry data-line and can be ticked (false inside a
// block quote, where the line numbers of the nested call no longer match the textarea).
function jpRenderBlocks(lines,interactive){
  let html='',para=[];
  const stack=[]; // open lists, outermost first: {indent,type}; the last <li> of each stays open
  const blank=l=>/^\s*$/.test(l);
  const flushPara=()=>{if(para.length){html+='<p>'+para.map(jpInlineMarkdown).join('<br>')+'</p>';para=[];}};
  const closeTag=type=>'</li>'+(type==='ol'?'</ol>':'</ul>');
  const closeLists=(keepIndent=-1)=>{while(stack.length&&stack[stack.length-1].indent>keepIndent)html+=closeTag(stack.pop().type);};
  const closeAll=()=>{flushPara();closeLists();};
  const isTableSep=l=>l.includes('|')&&/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/.test(l);
  const cells=l=>{let s=l.trim();if(s.startsWith('|'))s=s.slice(1);if(s.endsWith('|')&&!s.endsWith('\\|'))s=s.slice(0,-1);return s.split(/(?<!\\)\|/).map(c=>c.trim().replace(/\\\|/g,'|'));};
  for(let i=0;i<lines.length;i++){
    const line=lines[i];
    const fence=line.match(/^\s*(```|~~~)/);
    if(fence){
      closeAll();
      const code=[];let j=i+1;
      for(;j<lines.length&&!lines[j].trim().startsWith(fence[1]);j++)code.push(lines[j]);
      html+='<pre><code>'+jpEscapeHtml(code.join('\n'))+'</code></pre>';
      i=j;continue;
    }
    if(blank(line)){
      flushPara();
      // Joplin often stores list items with blank lines between them; they still belong to one list.
      if(stack.length){let k=i+1;while(k<lines.length&&blank(lines[k]))k++;if(!(k<lines.length&&JP_LIST_RE.test(lines[k])))closeLists();}
      continue;
    }
    const hd=line.match(/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/);
    if(hd){closeAll();const n=hd[1].length;html+='<h'+n+'>'+jpInlineMarkdown(hd[2])+'</h'+n+'>';continue;}
    if(para.length&&!stack.length&&/^\s{0,3}(=+|-+)\s*$/.test(line)){const n=line.trim()[0]==='='?1:2;html+='<h'+n+'>'+para.map(jpInlineMarkdown).join(' ')+'</h'+n+'>';para=[];continue;}
    if(/^\s{0,3}([-*_])(\s*\1){2,}\s*$/.test(line)){closeAll();html+='<hr>';continue;}
    if(/^\s{0,3}>/.test(line)){
      closeAll();
      const quoted=[];let j=i;
      for(;j<lines.length&&/^\s{0,3}>/.test(lines[j]);j++)quoted.push(lines[j].replace(/^\s{0,3}>\s?/,''));
      html+='<blockquote>'+jpRenderBlocks(quoted,false)+'</blockquote>';
      i=j-1;continue;
    }
    if(line.includes('|')&&i+1<lines.length&&isTableSep(lines[i+1])){
      closeAll();
      const head=cells(line);
      const align=cells(lines[i+1]).map(c=>c.startsWith(':')&&c.endsWith(':')?'jp-md-center':(c.endsWith(':')?'jp-md-right':''));
      const cell=(tag,c,k)=>'<'+tag+(align[k]?' class="'+align[k]+'"':'')+'>'+jpInlineMarkdown(c)+'</'+tag+'>';
      html+='<div class="jp-md-table-wrap"><table><thead><tr>'+head.map((c,k)=>cell('th',c,k)).join('')+'</tr></thead><tbody>';
      let j=i+2;
      for(;j<lines.length&&!blank(lines[j])&&lines[j].includes('|');j++){const row=cells(lines[j]);html+='<tr>'+head.map((h,k)=>cell('td',row[k]||'',k)).join('')+'</tr>';}
      html+='</tbody></table></div>';
      i=j-1;continue;
    }
    const li=line.match(JP_LIST_RE);
    if(li){
      flushPara();
      const indent=li[1].replace(/\t/g,'    ').length;
      const ordered=li[3]!==undefined;
      // Same shape as the pattern in onDisplayCheckboxChange(): "- [ ] text" or "* [x] text".
      const task=!ordered&&li[2]!=='+'?li[4].match(/^\[([ xX])\]\s+(.*)$/):null;
      const type=ordered?'ol':(task?'task':'ul');
      closeLists(indent);
      let top=stack[stack.length-1];
      if(top&&top.indent===indent&&top.type!==type){html+=closeTag(stack.pop().type);top=stack[stack.length-1];}
      if(top&&top.indent===indent)html+='</li>';
      else{
        html+=type==='ol'?'<ol class="jp-md-list"'+(li[3]!=='1'?' start="'+Number(li[3])+'"':'')+'>':'<ul class="jp-md-list'+(type==='task'?' jp-md-task-list':'')+'">';
        stack.push({indent,type});
      }
      if(task)html+='<li class="jp-md-task"><label class="jp-md-task-label"><input type="checkbox" class="jp-md-checkbox"'+(interactive?' data-line="'+i+'"':' disabled')+(task[1]!==' '?' checked':'')+'><span class="jp-md-task-text">'+jpInlineMarkdown(task[2])+'</span></label>';
      else html+='<li>'+jpInlineMarkdown(li[4]);
      continue;
    }
    // An indented line directly under a list item continues that item.
    if(stack.length&&/^\s+\S/.test(line)){html+='<br>'+jpInlineMarkdown(line.trim());continue;}
    closeLists();
    para.push(line);
  }
  closeAll();
  return html;
}
// Resource payloads ({data_url,mime,title,...}) by id, shared by the preview and the attachment list.
const resourceDataCache=new Map();
function resourceData(id){
  if(resourceDataCache.has(id))return resourceDataCache.get(id);
  const p=api('/api/resources/'+id+'/data');
  resourceDataCache.set(id,p);
  p.catch(()=>resourceDataCache.delete(id));
  while(resourceDataCache.size>24)resourceDataCache.delete(resourceDataCache.keys().next().value);
  return p;
}
function findResource(id){return ((state.note&&state.note.resources)||[]).find(r=>String(r.id).toLowerCase()===String(id).toLowerCase())||{id};}
function hydratePreviewResources(root){
  root.querySelectorAll('img[data-jp-res]').forEach(img=>{
    const known=findResource(img.dataset.jpRes);
    if(known.mime&&!String(known.mime).toLowerCase().startsWith('image/')){img.classList.add('jp-md-missing');return;}
    resourceData(img.dataset.jpRes).then(p=>{if(p&&/^data:image\//i.test(String(p.data_url||''))){img.src=p.data_url;img.classList.add('jp-md-res-ready');img.title=t('joplix','Preview');img.addEventListener('click',()=>previewResource(findResource(img.dataset.jpRes)).catch(e=>jpAlert(e.message)));}else img.classList.add('jp-md-missing');}).catch(()=>img.classList.add('jp-md-missing'));
  });
  root.querySelectorAll('a[data-jp-res-link]').forEach(a=>{
    const id=a.dataset.jpResLink;
    const linked=findNoteLink(id);
    if(linked){a.classList.add('jp-md-note-link');a.title=linked.title||'';}
    a.addEventListener('click',ev=>{
      ev.preventDefault();
      if(findNoteLink(id)){openLinkedNote(id).catch(e=>jpAlert(e.message));return;}
      const nb=((state.index&&state.index.notebooks)||[]).find(n=>String(n.id).toLowerCase()===String(id).toLowerCase());
      if(nb){handleDirtyBeforeNoteChange('').then(ok=>{if(ok)selectNotebook(nb.id);});return;}
      openResource(findResource(id));
    });
  });
}
// Joplin links notes with the same ":/<id>" form as attachments. The server lists the targets that are notes
// in note.note_links; the index covers notes linked from text typed since the note was loaded.
function findNoteLink(id){
  id=String(id||'').toLowerCase();
  const own=((state.note&&state.note.note_links)||[]).find(l=>String(l.id).toLowerCase()===id);
  if(own)return own;
  const meta=findNoteMeta(id);
  return meta.id?{id:meta.id,title:meta.title,notebook_id:meta.notebook_id,is_deleted:false}:null;
}
async function openLinkedNote(id){
  id=String(id||'').toLowerCase();
  const meta=findNoteMeta(id);
  if(!meta.id){
    // Not in the loaded index: open it anyway if the server knows it as an active note (index not refreshed yet).
    const link=findNoteLink(id);
    if(link&&!link.is_deleted)return openNote(id);
    jpToast(t('joplix','Linked note not found'),'error');return;
  }
  if(meta.notebook_id&&meta.notebook_id!==state.notebookId){
    // Resolve unsaved changes before the list switches, so a cancel leaves everything where it was.
    if(!(await handleDirtyBeforeNoteChange(id)))return;
    selectNotebook(meta.notebook_id,{keepNote:true});
  }
  return openNote(id);
}
function renderDisplayMode(){const d=$('jp-note-display'),ta=$('jp-body');if(!d||!ta)return;d.innerHTML=jpRenderMarkdown(ta.value||'');d.querySelectorAll('.jp-md-checkbox[data-line]').forEach(cb=>cb.addEventListener('change',onDisplayCheckboxChange));hydratePreviewResources(d);updateSaveButton();}
function setNoteMode(mode){state.displayMode=mode==='edit'?'edit':'view';const app=$('joplix-app');if(app){app.classList.toggle('jp-edit-mode',state.displayMode==='edit');app.classList.toggle('jp-view-mode',state.displayMode!=='edit');}if(state.displayMode!=='edit')renderDisplayMode();}
function onDisplayCheckboxChange(ev){const cb=ev.currentTarget,ta=$('jp-body');if(!ta||!state.note)return;const idx=parseInt(cb.getAttribute('data-line'),10);const lines=(ta.value||'').split(/\r?\n/);if(isNaN(idx)||!lines[idx])return;lines[idx]=lines[idx].replace(/^(\s*[-*]\s+\[)[ xX](\]\s+)/,'$1'+(cb.checked?'x':' ')+'$2');ta.value=lines.join('\n');markDirty();}

function noteSnapshot(){return {title:($('jp-title')?$('jp-title').value:''),body:($('jp-body')?$('jp-body').value:'')};}
function sameSnapshot(a,b){return !!a&&!!b&&String(a.title||'')===String(b.title||'')&&String(a.body||'')===String(b.body||'');}
function setOriginalFromEditor(){state.originalNote=noteSnapshot();state.dirty=false;updateSaveButton();}
function markDirty(){state.dirty=!sameSnapshot(state.originalNote,noteSnapshot());updateSaveButton();}
function updateSaveButton(){const buttons=[$('jp-save-note')].filter(Boolean);const active=!!state.dirty&&!state.saving;buttons.forEach(btn=>{btn.disabled=!active;btn.classList.toggle('is-dirty',active);btn.title=active?t('joplix','Save changes'):t('joplix','No changes to save');});const app=$('joplix-app');if(app)app.classList.toggle('jp-note-dirty',active);}
function setSavingUi(active){
  const app=$('joplix-app');
  if(app)app.classList.toggle('jp-saving',!!active);
  const meta=$('jp-meta');
  if(!meta)return;
  if(active){
    if(!meta.dataset.beforeSaving)meta.dataset.beforeSaving=meta.textContent||'';
    meta.innerHTML='<span class="jp-saving-spinner" aria-hidden="true"></span><span>'+t('joplix','Saving changes...')+'</span>';
  }else if(meta.dataset.beforeSaving){
    meta.textContent=meta.dataset.beforeSaving;
    delete meta.dataset.beforeSaving;
  }
}
function noteCacheKey(id){return 'noteCache_'+id;}
function cachedNote(id){
  const note=storage.get(noteCacheKey(id),null);
  return note?.bodyFormat===3?note:null;
}
function rememberNote(n){
  if(!n||!n.id)return;
  noteMemorySet(n);
  const item={id:n.id,title:n.title||'',body:String(n.body||''),updated_time:n.updated_time||'',notebook_title:n.notebook_title||'',is_deleted:!!n.is_deleted,tags:n.tags||[],resources:n.resources||[],cached_at:Date.now()};
  // Format 3 (2026.11.79): title/body split as Joplin does it. Entries of older formats are ignored.
  item.bodyFormat=3;
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
function forgetNote(id){if(!id)return;if(state.noteMemCache)delete state.noteMemCache[id];storage.del(noteCacheKey(id));}
function noteMemoryGet(id){return id&&state.noteMemCache?state.noteMemCache[id]:null;}
function noteMemorySet(n){if(!n||!n.id)return n;state.noteMemCache[n.id]=n;const keys=Object.keys(state.noteMemCache);while(keys.length>50){delete state.noteMemCache[keys.shift()];}return n;}
function apiNote(id){
  if(!id)return Promise.reject(new Error(t('joplix','No note ID')));
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
function findNoteMeta(id){return ((state.index&&state.index.notes)||[]).find(n=>n.id===id)||{};}
function showNoteLoading(id,title=''){
  state.noteLoading=true;
  state.noteId=id||state.noteId||'';
  setHasNote(true);
  ensureNoteSelected(state.noteId);
  const meta=findNoteMeta(id);
  if($('jp-title'))$('jp-title').value=title||meta.title||t('joplix','Loading note...');
  if($('jp-meta'))$('jp-meta').innerHTML='<span class="jp-note-loading-dot"></span> '+t('joplix','Loading note content...');
  if($('jp-body')){
    $('jp-body').value='';renderDisplayMode();
    $('jp-body').placeholder=t('joplix','Loading note. The content will appear shortly.');
    $('jp-body').classList.add('jp-note-loading');
  }
  if($('jp-tags'))$('jp-tags').innerHTML='';
  if($('jp-resources'))$('jp-resources').innerHTML='<div class="jp-note-loading-card">'+t('joplix','Loading note...')+'</div>';
  updateNotePath();
  updateSaveButton();
}
function renderNoteToEditor(n,fromCache=false){
  state.note=n;state.noteId=n.id;state.noteLoading=false;setHasNote(true);setTrashNote(n.is_deleted);
  ensureNoteSelected(n.id);
  if($('jp-title'))$('jp-title').value=n.title||'';
  if($('jp-body')){$('jp-body').classList.remove('jp-note-loading');$('jp-body').placeholder=t('joplix','Note content');$('jp-body').value=n.body||'';renderDisplayMode();setNoteMode('view');}
  if($('jp-meta'))$('jp-meta').textContent=(fromCache?t('joplix','From local quick cache, refreshing... '):'')+t('joplix','Updated: ')+formatJoplixDateTime(n.updated_time)+t('joplix',' | Notebook: ')+(n.notebook_title||'')+(n.is_deleted?' | '+t('joplix','In trash'):'');
  renderTags(n.tags||[]);
  renderResources(n.resources||[]);
  updateNotePath();
  setOriginalFromEditor();
  resetEditorHistory($('jp-body')?$('jp-body').value:'');renderDisplayMode();
}
function ensureNoteSelected(id){document.querySelectorAll('.jp-note-item').forEach(b=>b.classList.toggle('active',b.dataset.id===id));}
async function api(p,o={}){o.headers=Object.assign({'Content-Type':'application/json','requesttoken':OC.requestToken||''},o.headers||{});if(o.body&&typeof o.body!=='string')o.body=JSON.stringify(o.body);const r=await fetch(route(p),o);const d=await r.json();if(!r.ok||d.error){const err=new Error(d.error||'HTTP '+r.status);err.status=r.status;throw err;}noteLocalChangeForSync(p,o,d);return d;}
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
  if(state.notebookId===JP_TRASH_ID){renderTrash();if(!keepNote)clearNote();return;}
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
  if(previousNotebook&&(previousNotebook===JP_TRASH_ID?trashCount(idx)>0:(idx.notebooks||[]).find(n=>n.id===previousNotebook))){
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
  state.settings=await api('/api/settings').catch(()=>({remember_last_location:'yes',sort_order:'updated_desc',joplin_path:'/JoplinV2'})); syncOfficeEditorUi(currentLocalSettings());
  state.sort=state.settings.sort_order||(storage.get('settings',{}).sort_order)||'updated_desc';
  const cached=rememberedIndex();
  let usedCached=false;
  if(cached&&hasFullTextIndex(cached)&&hasCurrentCountIndex(cached)){
    usedCached=true;
    state.index=cached;
    renderIndex(cached);
    status(t('joplix','Local index loaded instantly. Checking for changes in the background...'),'ok');
    restoreLocation(cached);
  }
  let idx=await api('/api/index').catch(e=>null);
  if(idx&&!isEmptyIndex(idx)&&(!hasFullTextIndex(idx)||!hasCurrentCountIndex(idx))){
    if(!usedCached)status(!hasFullTextIndex(idx)?t('joplix','Old index without full-text search detected, rebuilding...'):t('joplix','Old index with incorrect counts detected, rebuilding...'),'warning');
    const rebuilt=await api('/api/index/rebuild',{method:'POST'}).catch(e=>{status(t('joplix','Index rebuild failed: ')+e.message,'error');return null;});
    idx=rebuilt?(rebuilt.index||rebuilt):idx;
  }
  if(!idx||isEmptyIndex(idx)){
    if(!usedCached)status(t('joplix','Index empty or missing, first rebuild in progress...'),'warning');
    const rebuilt=await api('/api/index/rebuild',{method:'POST'}).catch(e=>{status(t('joplix','Index rebuild failed: ')+e.message,'error');return null;});
    idx=rebuilt?(rebuilt.index||rebuilt):idx;
  }
  if(idx&&!isEmptyIndex(idx)){
    if(usedCached){
      applyIndexPreservingFocus(idx,t('joplix','Server index loaded. Checking for changes in the background...'));
    } else {
      state.index=idx;
      rememberIndex(idx);
      renderIndex(idx);
      status(t('joplix','Index up to date'),'ok');
      restoreLocation(idx);
    }
    scheduleBackgroundRefresh(900);
  } else if(!cached){
    renderIndex({tree:[],notes:[],notebooks:[]});
    status(t('joplix','No index available. Please check the Joplix folder in the settings.'),'error');
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
function renderIndex(idx){rememberIndex(idx);renderTree(idx.tree||[]);renderNotes([]);}
// Notebook tree: each node is its row followed by a .jp-tree-children group holding its sub-notebooks.
// Collapsed notebooks (ids in localStorage joplix.collapsedNotebooks) keep that group hidden; every
// notebook starts expanded. Toggling only flips the DOM, so the active row and scroll position stay put.
function jpCollapsed(){const v=storage.get('collapsedNotebooks',[]);return new Set(Array.isArray(v)?v:[]);}
function jpSetCollapsed(id,on){const c=jpCollapsed();if(on)c.add(id);else c.delete(id);storage.set('collapsedNotebooks',[...c]);}
function applyTreeCollapse(id,collapsed){
  const root=$('jp-tree');if(!root)return;
  const group=root.querySelector('.jp-tree-children[data-parent="'+CSS.escape(id)+'"]');if(group)group.hidden=collapsed;
  const tg=root.querySelector('.jp-tree-row[data-id="'+CSS.escape(id)+'"] > button.jp-tree-toggle');
  if(tg){tg.setAttribute('aria-expanded',collapsed?'false':'true');const label=collapsed?t('joplix','Expand'):t('joplix','Collapse');tg.title=label;tg.setAttribute('aria-label',label);}
}
function toggleNotebookCollapsed(id){const collapsed=!jpCollapsed().has(id);jpSetCollapsed(id,collapsed);applyTreeCollapse(id,collapsed);}
// Expands every ancestor of a notebook (e.g. when a note link or the restored location points into a
// collapsed branch) and scrolls its row into view.
function revealNotebook(id){
  const nbs=(state.index&&state.index.notebooks)||[];const c=jpCollapsed();let changed=false;let nb=nbs.find(n=>n.id===id);let guard=0;
  while(nb&&nb.parent_id&&guard<50){if(c.has(nb.parent_id)){c.delete(nb.parent_id);applyTreeCollapse(nb.parent_id,false);changed=true;}nb=nbs.find(n=>n.id===nb.parent_id);guard++;}
  if(changed)storage.set('collapsedNotebooks',[...c]);
  const row=document.querySelector('#jp-tree .jp-tree-row[data-id="'+CSS.escape(id)+'"]');if(row&&row.scrollIntoView)row.scrollIntoView({block:'nearest'});
}
function renderTree(nodes){
  const root=$('jp-tree');root.innerHTML='';
  const stored=jpCollapsed();const collapsed=new Set();
  const walk=(n,l,parent)=>{
    const kids=n.children||[];const isCollapsed=kids.length>0&&stored.has(n.id);if(isCollapsed)collapsed.add(n.id);
    const row=document.createElement('div');row.className='jp-tree-row';row.dataset.id=n.id;row.style.paddingLeft=(4+l*18)+'px';
    let toggle;
    if(kids.length){toggle=document.createElement('button');toggle.type='button';toggle.className='jp-tree-toggle';toggle.appendChild(jpIconEl('chevronRight'));toggle.onclick=(ev)=>{ev.stopPropagation();toggleNotebookCollapsed(n.id);};}
    else{toggle=document.createElement('span');toggle.className='jp-tree-toggle';toggle.setAttribute('aria-hidden','true');}
    const b=document.createElement('button');b.type='button';b.className='jp-tree-item';b.dataset.id=n.id;b.onclick=()=>selectNotebook(n.id);
    const icon=document.createElement('span');icon.className='jp-folder-icon';icon.setAttribute('aria-hidden','true');
    const label=document.createElement('span');label.className='jp-tree-label';label.textContent=notebookTitleWithCount(n);
    b.appendChild(icon);b.appendChild(label);
    const menu=document.createElement('button');menu.type='button';menu.className='jp-tree-menu';menu.appendChild(jpIconEl('dots'));menu.title=t('joplix','Actions');menu.setAttribute('aria-label',t('joplix','Actions'));menu.onclick=(ev)=>{ev.stopPropagation();showNotebookMenu(n,row,menu);};
    row.appendChild(toggle);row.appendChild(b);row.appendChild(menu);parent.appendChild(row);
    if(kids.length){
      const group=document.createElement('div');group.className='jp-tree-children';group.setAttribute('role','group');group.dataset.parent=n.id;
      parent.appendChild(group);kids.forEach(c=>walk(c,l+1,group));applyTreeCollapse(n.id,isCollapsed);
    }
  };
  nodes.forEach(n=>walk(n,0,root));
  // Forget notebooks that no longer exist or no longer have children.
  if(collapsed.size!==stored.size)storage.set('collapsedNotebooks',[...collapsed]);
  renderTrashRow(root);
}
function selectNotebook(id,opts={}){if(id===JP_TRASH_ID&&!trashCount(state.index)){id=firstNotebookId(state.index);if(!id){state.notebookId='';return;}}const app=$('joplix-app');if(app)app.classList.toggle('jp-in-trash',id===JP_TRASH_ID);if(id===JP_TRASH_ID){state.notebookId=id;setPane('list');setNavOpen(false);document.querySelectorAll('.jp-tree-item').forEach(b=>b.classList.toggle('active',b.dataset.id===id));document.querySelectorAll('.jp-tree-row').forEach(r=>r.classList.toggle('active',r.dataset.id===id));renderTrash();if(!opts.keepNote)clearNote();else ensureNoteSelected(state.noteId);return;}state.notebookId=id;setPane('list');setNavOpen(false);document.querySelectorAll('.jp-tree-item').forEach(b=>b.classList.toggle('active',b.dataset.id===id));document.querySelectorAll('.jp-tree-row').forEach(r=>r.classList.toggle('active',r.dataset.id===id));revealNotebook(id);const notes=(state.index.notes||[]).filter(n=>n.notebook_id===id).sort(sorter());renderNotes(notes);$('jp-breadcrumb').textContent=breadcrumb(id);if(!opts.keepNote)clearNote();else ensureNoteSelected(state.noteId);if(!opts.skipSave)saveLastLocation();}
function breadcrumb(id){const names=[];let guard=0;while(id&&guard<20){const nb=(state.index.notebooks||[]).find(n=>n.id===id);if(!nb)break;names.unshift(nb.title);id=nb.parent_id;guard++;}return names.join(' › ')||t('joplix','Notebook');}
function sorter(){return (a,b)=>state.sort==='title_asc'?a.title.localeCompare(b.title):(state.sort==='updated_asc'?String(a.updated_time).localeCompare(String(b.updated_time)):String(b.updated_time).localeCompare(String(a.updated_time)));}
function renderNotes(notes){
  const box=$('jp-notes');box.innerHTML='';
  if(!notes.length){box.innerHTML='<p>'+t('joplix','No notes found.')+'</p>';return;}
  notes.forEach(n=>{
    const b=document.createElement('button');b.type='button';b.className='jp-note-item';b.dataset.id=n.id;
    const title=document.createElement('strong');title.textContent=n.title||t('joplix','Untitled');
    const sub=document.createElement('span');sub.className='jp-note-sub';
    const time=document.createElement('time');time.textContent=formatJoplixDateTime(n.updated_time);sub.appendChild(time);
    const count=(icon,num)=>{if(!num)return;const c=document.createElement('span');c.className='jp-note-count';c.appendChild(jpIconEl(icon));c.appendChild(document.createTextNode(String(num)));sub.appendChild(c);};
    count('paperclip',n.resource_count);count('tag',n.tag_count);
    b.appendChild(title);b.appendChild(sub);
    b.onclick=()=>openNote(n.id);b.addEventListener('contextmenu',ev=>showNoteContextMenu(ev,n));
    box.appendChild(b);
  });
  ensureNoteSelected(state.noteId);
}
async function handleDirtyBeforeNoteChange(targetId){
  // Wenn der Benutzer unmittelbar nach "Speichern" zu einer anderen Notiz wechselt,
  // wartet der Wechsel zuerst auf denselben laufenden Speichervorgang. Dadurch wird
  // keine falsche Dirty-State-Warnung angezeigt.
  if(state.savePromise){
    state.pendingNoteId=targetId;
    ensureNoteSelected(targetId);
    setSavingUi(true);
    try{await state.savePromise;}
    catch(e){jpAlert(t('joplix','Saving failed: ')+(e&&e.message?e.message:e));state.pendingNoteId='';ensureNoteSelected(state.noteId);return false;}
    finally{setSavingUi(false);}
    state.pendingNoteId='';
  }
  if(!state.dirty||!state.noteId||state.noteId===targetId)return true;
  const fromId=state.noteId;
  const choice=await jpDialog({title:t('joplix','Unsaved changes'),message:t('joplix','This note has unsaved changes.'),cancelValue:'cancel',buttons:[{label:t('joplix','Cancel'),value:'cancel'},{label:t('joplix','Discard'),value:'discard'},{label:t('joplix','Save'),value:'save',primary:true}]});
  if(choice==='save'){
    try{
      state.pendingNoteId=targetId;
      const ok=await saveNote({keepMode:true,skipReload:true,fastDirtySave:true});
      state.pendingNoteId='';
      if(ok===false){ensureNoteSelected(fromId);return false;}
      return !state.dirty;
    }catch(e){
      state.pendingNoteId='';
      jpAlert(t('joplix','Saving before switching failed: ')+(e&&e.message?e.message:e));
      ensureNoteSelected(fromId);
      return false;
    }
  }
  if(choice==='discard'){state.dirty=false;updateSaveButton();return true;}
  ensureNoteSelected(fromId);
  return false;
}
async function openNote(id,opts={}){
  if(!opts.silent&&!(await handleDirtyBeforeNoteChange(id))){ensureNoteSelected(state.noteId);return;}
  if(!opts.silent)setPane('note');
  state.loadingNoteId=id;
  const meta=findNoteMeta(id);
  const mem=noteMemoryGet(id);
  const cached=cachedNote(id);
  if(mem){renderNoteToEditor(mem,false);if(!opts.silent)saveLastLocation();if(!opts.forceRefresh)return;}
  else if(cached&&(opts.preferCache||opts.silent||state.noteId!==id)){renderNoteToEditor(cached,true);}
  else if(!(state.note&&state.noteId===id)){showNoteLoading(id,meta.title||'');}
  else {state.noteLoading=true;if($('jp-meta'))$('jp-meta').textContent=t('joplix','Refreshing note...');}
  try{
    const n=await apiNote(id);
    if(state.loadingNoteId!==id)return;
    if(opts.silent&&state.dirty&&state.noteId===id){rememberNote(n);return;}
    renderNoteToEditor(n,false);
    if(!opts.silent)saveLastLocation();
  }catch(e){
    state.noteLoading=false;
    if(cached||mem){const cn=mem||cached;if($('jp-meta'))$('jp-meta').textContent=t('joplix','Quick cache: ')+formatJoplixDateTime(cn.updated_time)+t('joplix',' | Refresh failed: ')+e.message;status(t('joplix','Showing note from quick cache. Refresh failed: ')+e.message,'warning');return;}
    showNoteLoading(id,meta.title||t('joplix','Note'));
    if($('jp-meta'))$('jp-meta').textContent=t('joplix','Note could not be loaded: ')+e.message;
    throw e;
  }
}
function clearNote(){setHasNote(false);state.note=null;state.noteId='';state.originalNote=null;state.dirty=false;state.noteLoading=false;$('jp-title').value='';$('jp-body').classList.remove('jp-note-loading');$('jp-body').value='';renderDisplayMode();$('jp-body').placeholder=t('joplix','Please select a note.');$('jp-meta').textContent=t('joplix','No note open');$('jp-tags').innerHTML='';$('jp-resources').innerHTML='';updateNotePath();updateSaveButton();}
function renderTags(tags){
  const box=$('jp-tags');box.innerHTML='';
  normalizeLocalTags(tags).forEach(tag=>{
    const chip=document.createElement('span');chip.className='jp-tag-chip';
    const label=document.createElement('span');label.className='jp-tag-label';label.textContent=tag.title;chip.appendChild(label);
    const remove=document.createElement('button');remove.type='button';remove.className='jp-tag-remove';remove.appendChild(jpIconEl('close'));remove.title=t('joplix','Remove tag from this note');remove.setAttribute('aria-label',t('joplix','Remove tag {tag}',{tag:tag.title}));
    remove.onclick=ev=>{ev.preventDefault();ev.stopPropagation();removeTag(tag);};
    chip.appendChild(remove);box.appendChild(chip);
  });
  const add=document.createElement('button');add.type='button';add.className='jp-tag-add';add.title=t('joplix','New tag');add.appendChild(jpIconEl('plus'));add.appendChild(document.createTextNode('Tag'));add.onclick=addTag;box.appendChild(add);
}
function resourceIsPdf(r){return !!(r&&((r.is_pdf===true)||String(r.mime||'').toLowerCase()==='application/pdf'||String(r.file_extension||'').toLowerCase()==='pdf'||String(r.title||'').toLowerCase().endsWith('.pdf')));}
// Same list as JoplixModelService::OFFICE_EXTENSIONS (PHP); the server's is_office flag is the primary signal.
const JP_OFFICE_EXTENSIONS=['doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp'];
function resourceIsOffice(r){const ext=String(r&&r.file_extension||'').toLowerCase();const title=String(r&&r.title||'').toLowerCase();return !!(r&&((r.is_office===true)||JP_OFFICE_EXTENSIONS.includes(ext)||JP_OFFICE_EXTENSIONS.some(e=>title.endsWith('.'+e))));}
function resourceFileName(r,p){let name=String((p&&p.title)||r.title||r.id||'resource').trim()||'resource';const ext=String((p&&p.file_extension)||r.file_extension||'').trim().replace(/^\./,'');if(ext&&!name.toLowerCase().endsWith('.'+ext.toLowerCase()))name+='.'+ext;return name.replace(/[\\/:*?"<>|]+/g,'_');}
function dataUrlToBlobUrl(dataUrl,mime){const parts=String(dataUrl||'').split(',');if(parts.length<2)throw new Error(t('joplix','Attachment could not be read.'));const bin=atob(parts[1]);const bytes=new Uint8Array(bin.length);for(let i=0;i<bin.length;i++)bytes[i]=bin.charCodeAt(i);return URL.createObjectURL(new Blob([bytes],{type:mime||'application/octet-stream'}));}
function formatBytes(n){n=Number(n||0);if(!n)return '';const u=['B','KB','MB','GB'];let i=0;while(n>=1024&&i<u.length-1){n/=1024;i++;}return (i?n.toFixed(1):Math.round(n))+' '+u[i];}
// Download is the only path that saves a file. "Open" previews images, audio and video in a dialog and
// shows PDFs in a new browser tab; other files have nothing to show, so opening them downloads.
async function downloadResource(r){const p=await resourceData(r.id);if(!p||p.error||!p.data_url)throw new Error((p&&p.error)||t('joplix','Attachment could not be read.'));const mime=p.mime||r.mime||'application/octet-stream';const objectUrl=dataUrlToBlobUrl(p.data_url,mime);const a=document.createElement('a');a.href=objectUrl;a.download=resourceFileName(r,p);document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(objectUrl),120000);}
function resourcePreviewKind(r){const k=resourceKind(r);return ['image','audio','video'].includes(k)?k:'';}
function openResource(r){
  const fail=e=>jpAlert(e.message);
  if(resourcePreviewKind(r))return previewResource(r).catch(fail);
  if(resourceKind(r)==='pdf')return openResourceInTab(r).catch(fail);
  return downloadResource(r).catch(fail);
}
// The tab is opened synchronously, inside the click, so popup blockers allow it; the content follows.
async function openResourceInTab(r){
  const w=window.open('','_blank');
  try{
    const p=await resourceData(r.id);if(!p||p.error||!p.data_url)throw new Error((p&&p.error)||t('joplix','Attachment could not be read.'));
    if(!w){await downloadResource(r);return;}
    const objectUrl=dataUrlToBlobUrl(p.data_url,p.mime||r.mime||'application/octet-stream');
    try{w.opener=null;}catch(e){}
    w.location.href=objectUrl;
    setTimeout(()=>URL.revokeObjectURL(objectUrl),300000);
  }catch(e){if(w)try{w.close();}catch(ignore){}throw e;}
}
async function previewResource(r){
  const kind=resourcePreviewKind(r)||'image';
  let objectUrl='',closed=false;
  const choice=await jpDialog({title:r.title||r.id,className:'jp-modal-preview',cancelValue:null,buttons:[{label:'Download',value:'download'},{label:t('joplix','Close'),value:null,primary:true}],mount:modal=>{
    const box=document.createElement('div');box.className='jp-preview-media';box.textContent=t('joplix','Loading …');modal.appendChild(box);
    resourceData(r.id).then(p=>{
      if(closed)return;
      if(!p||p.error||!p.data_url)throw new Error((p&&p.error)||t('joplix','Attachment could not be read.'));
      let el;
      if(kind==='image'){el=document.createElement('img');el.src=p.data_url;el.alt=r.title||'';}
      else{objectUrl=dataUrlToBlobUrl(p.data_url,p.mime||r.mime);el=document.createElement(kind);el.controls=true;el.src=objectUrl;}
      box.textContent='';box.appendChild(el);
    }).catch(e=>{if(closed)return;box.textContent=e.message;box.classList.add('jp-preview-error');});
  }});
  closed=true;
  if(objectUrl)URL.revokeObjectURL(objectUrl);
  if(choice==='download')await downloadResource(r);
}
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
      const result=await api('/api/resources/'+resourceId+'/office/status',{method:'POST',body:{}});
      if(!result.active){
        stopOfficePolling(resourceId);
        if(!options.silentInactive)officeStatusText(statusEl,result.error||t('joplix','Editing session ended.'),'warning');
        return result;
      }
      if(result.updated||result.already_applied){
        resourceDataCache.delete(resourceId);
        sync.lastHash=String(result.sha256||sync.lastHash||'');
        officeStatusText(statusEl,t('joplix','Changes were applied to the Joplix attachment and can be synchronised.'),'ok');
        scheduleBackgroundRefresh(350);
      }
      return result;
    }catch(e){
      officeStatusText(statusEl,t('joplix','Applying changes failed: ')+e.message,'error');
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
  officeStatusText(statusEl,t('joplix','Office was closed. Waiting for the final Office save...'),'busy');
  // Every editor (Collabora via WOPI, ONLYOFFICE/Euro Office via callback) may write the final file several seconds after
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
  officeStatusText(statusEl,t('joplix','Office was closed. The final file was applied to the Joplix attachment.'),'ok');
  scheduleBackgroundRefresh(350);
  return lastResult;
}
const JP_OFFICE_EDITOR_NAMES={eurooffice:'Euro Office',richdocuments:'Nextcloud Office',onlyoffice:'ONLYOFFICE'};
function officeEditorName(id){return JP_OFFICE_EDITOR_NAMES[id]||t('joplix','the office editor');}
function officeEditorsAvailable(){const list=currentLocalSettings().office_editors;return Array.isArray(list)?list.filter(id=>JP_OFFICE_EDITOR_NAMES[id]):[];}
/** The editor the server will pick: the chosen one while installed, else the first available (same rule as ApiController::officeEditor). */
function officeEditorExpected(){const all=officeEditorsAvailable();const pick=currentLocalSettings().office_editor;return all.includes(pick)?pick:(all[0]||'');}
async function startOfficeEdit(r,statusEl){
  const openMode=(currentLocalSettings().office_open_mode==='tab')?'tab':'window';
  const popupFeatures='popup=yes,width='+Math.max(900,Math.min(1500,Math.floor((window.screen&&screen.availWidth||1400)*0.88)))+',height='+Math.max(700,Math.min(1000,Math.floor((window.screen&&screen.availHeight||900)*0.88)))+',left=40,top=30,resizable=yes,scrollbars=yes';
  const windowName=openMode==='window'?('joplix-office-'+r.id):'_blank';
  const editorWindow=window.open('about:blank',windowName,openMode==='window'?popupFeatures:'noopener');
  if(!editorWindow){officeStatusText(statusEl,t('joplix','The Office window was blocked by the browser. Please allow pop-ups for this Nextcloud address.'),'error');throw new Error(t('joplix','Office window was blocked by the browser.'));}
  const expectedName=officeEditorName(officeEditorExpected());
  try{editorWindow.document.title=t('joplix','Joplix – preparing Office');const p=editorWindow.document.createElement('p');p.style.cssText='font-family:system-ui;padding:24px';p.textContent=t('joplix','Preparing {editor}…',{editor:expectedName});editorWindow.document.body.replaceChildren(p);}catch(e){}
  officeStatusText(statusEl,t('joplix','Preparing working copy for {editor}...',{editor:expectedName}),'busy');
  try{
    const result=await api('/api/resources/'+r.id+'/office/start',{method:'POST',body:{}});
    const fileId=String(result.file_id||'');
    if(!fileId||!result.editor_path)throw new Error(t('joplix','The Nextcloud file ID of the working copy is missing.'));
    const editorName=officeEditorName(result.editor);
    const officeUrl=OC.generateUrl(String(result.editor_path));
    editorWindow.location.replace(officeUrl);
    officeStatusText(statusEl,(openMode==='window'?t('joplix','Editing was requested in a separate window. '):t('joplix','Editing is running in a new tab. '))+t('joplix','{editor} opens directly. Saved changes are transferred back to Joplix automatically.',{editor:editorName}),'busy');
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
      officeStatusText(statusEl,t('joplix','Office was closed. Checking the last saved changes...'),'busy');
      try{
        await finalizeOfficeEdit(r.id,statusEl);
      }catch(e){
        officeStatusText(statusEl,t('joplix','Final transfer failed: ')+e.message,'error');
      }
    },1000);
  }catch(e){try{editorWindow.close();}catch(ignore){}officeStatusText(statusEl,e.message,'error');throw e;}
}
function resourceKind(r){const ext=String(r.file_extension||'').toLowerCase();const mime=String(r.mime||'').toLowerCase();if(resourceIsOffice(r))return 'office';if(resourceIsPdf(r))return 'pdf';if(r.is_image||mime.startsWith('image/'))return 'image';if(mime.startsWith('audio/')||['mp3','ogg','wav','m4a','flac'].includes(ext))return 'audio';if(mime.startsWith('video/')||['mp4','webm','mov','mkv','avi'].includes(ext))return 'video';if(['zip','7z','rar','tar','gz','bz2'].includes(ext))return 'archive';return 'file';}
function resourcePrimaryLabel(kind){return ({office:t('joplix','Edit'),pdf:t('joplix','Open'),image:t('joplix','Open'),audio:t('joplix','Play'),video:t('joplix','Play')}[kind]||'');}
function renderResources(rs){
  const box=$('jp-resources');box.innerHTML='';if(!rs||!rs.length)return;
  const head=document.createElement('div');head.className='jp-resources-head';head.textContent=t('joplix','Attachments');box.appendChild(head);
  rs.forEach(r=>{
    const kind=resourceKind(r);
    const row=document.createElement('div');row.className='jp-resource jp-resource-'+kind;
    row.appendChild(jpIconEl(kind==='image'?'image':'file'));
    const main=document.createElement('div');main.className='jp-resource-main';
    const title=document.createElement('strong');title.textContent=r.title||r.id;
    if(r.exists&&kind!=='office'&&resourcePrimaryLabel(kind)){title.className='jp-resource-open';title.tabIndex=0;title.setAttribute('role','button');title.onclick=()=>openResource(r);title.onkeydown=ev=>{if(ev.key==='Enter'||ev.key===' '){ev.preventDefault();openResource(r);}};}
    const meta=document.createElement('span');meta.textContent=[r.file_extension?String(r.file_extension).toUpperCase():(r.mime||''),formatBytes(r.size),r.exists?'':t('joplix','File missing')].filter(Boolean).join(' · ');
    main.appendChild(title);main.appendChild(meta);row.appendChild(main);box.appendChild(row);
    if(!r.exists)return;
    const actions=document.createElement('div');actions.className='jp-resource-actions';row.appendChild(actions);
    const primary=(kind==='office'&&!officeEditorsAvailable().length)?'':resourcePrimaryLabel(kind);
    if(primary){
      const b=document.createElement('button');b.type='button';b.className='jp-resource-action jp-resource-action-primary';b.textContent=primary;actions.appendChild(b);
      if(kind==='office'){const status=document.createElement('div');status.className='jp-resource-edit-status';row.appendChild(status);b.onclick=async()=>{b.disabled=true;try{await startOfficeEdit(r,status);}catch(e){jpAlert(e.message);}finally{b.disabled=false;}};}
      else b.onclick=()=>openResource(r);
    }
    const dl=document.createElement('button');dl.type='button';dl.className='jp-resource-action jp-icon-btn';dl.title='Download';dl.setAttribute('aria-label','Download');dl.appendChild(jpIconEl('download'));dl.onclick=()=>downloadResource(r).catch(e=>jpAlert(e.message));actions.appendChild(dl);
  });
}

function closeNotebookMenu(){
  const old=document.querySelector('.jp-context-menu');
  if(old)old.remove();
  // Also drop the pending outside-click listener: after an Escape close it would otherwise close the next menu
  // the moment its opening click bubbles up.
  document.removeEventListener('click',closeNotebookMenu);
  document.removeEventListener('scroll',closeNotebookMenu,true);
  window.removeEventListener('resize',closeNotebookMenu);
  document.removeEventListener('keydown',closeNotebookMenuByEscape,true);
}
function closeNotebookMenuByEscape(ev){
  if(ev && ev.key==='Escape'){ev.stopPropagation();closeNotebookMenu();}
}
function showNotebookMenu(node,row,button){
  jpShowMenu(button,[
    {act:'rename',icon:'pencil',label:t('joplix','Rename'),run:()=>renameNotebookNode(node)},
    {act:'newchild',icon:'folderPlus',label:t('joplix','New sub-notebook'),run:()=>newNotebook(node.id)},
    {act:'delete',icon:'delete',label:t('joplix','Delete'),cls:'danger',run:()=>deleteNotebookNode(node)}
  ]);
}
// Small action menu anchored to a button (notebook "⋯", attach source). items: [{act,icon,label,cls,run}].
// Only one exists at a time (.jp-context-menu); closes on outside click, scroll, resize and Escape.
function jpShowMenu(button,items){
  closeNotebookMenu();
  const menu=document.createElement('div');
  menu.className='jp-context-menu jp-menu open';
  menu.setAttribute('role','menu');
  menu.innerHTML=items.map(i=>jpMenuItem(i.act,i.icon,i.label,i.cls)).join('');
  menu.addEventListener('click',ev=>ev.stopPropagation());
  jpRoot().appendChild(menu);
  // Opens below the button like a Nextcloud action menu; flips above when there is no room.
  const rect=button.getBoundingClientRect(),margin=8;
  const left=clamp(rect.left,margin,Math.max(margin,(window.innerWidth||0)-menu.offsetWidth-margin));
  let top=rect.bottom+4;
  if(top+menu.offsetHeight>(window.innerHeight||0)-margin)top=Math.max(margin,rect.top-menu.offsetHeight-4);
  menu.style.left=Math.round(left)+'px';
  menu.style.top=Math.round(top)+'px';
  items.forEach(i=>{menu.querySelector('[data-act="'+i.act+'"]').onclick=()=>{closeNotebookMenu();i.run();};});
  setTimeout(()=>{
    document.addEventListener('click',closeNotebookMenu,{once:true});
    document.addEventListener('scroll',closeNotebookMenu,true);
    window.addEventListener('resize',closeNotebookMenu);
    document.addEventListener('keydown',closeNotebookMenuByEscape,true);
  },0);
}

async function renameNotebookNode(node){const title=await jpPrompt(t('joplix','Rename notebook'),node.title);if(!title||title===node.title)return;const nb=(state.index.notebooks||[]).find(n=>n.id===node.id);await api('/api/notebooks/'+node.id,{method:'PUT',body:{title,known_updated_time:nb?nb.updated_time:''}});await rebuildAndRestore();}
function confirmNotebookDeletion(node){
  const isSub=!!(node.parent_id||'');
  const title=String(node.title||'').trim()||t('joplix','Untitled');
  const heading=isSub?t('joplix','Move sub-notebook “{title}” to the trash?',{title}):t('joplix','Move notebook “{title}” to the trash?',{title});
  const body=t('joplix','All notes and sub-notebooks in it are moved to the trash as well. They can be restored from there.');
  return jpConfirm(body,{title:heading,confirmLabel:t('joplix','Move to trash'),cancelLabel:t('joplix','Cancel')});
}
async function deleteNotebookNode(node){
  const isSub=!!(node.parent_id||'');
  if(!(await confirmNotebookDeletion(node)))return;
  status(t('joplix','Moving to trash…'),'warning');
  try{
    await api('/api/notebooks/'+node.id,{method:'DELETE'});
    if(state.notebookId===node.id){state.notebookId='';clearNote();}
    await rebuildAndRestore(true);
    status(t('joplix','Moved to trash'),'ok');
  }catch(e){
    const msg=(isSub?t('joplix','Could not delete sub-notebook: '):t('joplix','Could not delete notebook: '))+e.message;
    status(msg,'error');
    jpAlert(msg);
  }
}
// A save answered with 409: the stored note is newer than the one in the editor. The user decides; until
// then nothing is written and the editor keeps the text. Returns the PUT result when the editor version
// was saved over the server version, otherwise null (saveNote then stops without clearing the dirty state,
// except where the editor was reloaded with the server version).
//  - overwrite: PUT again without known_updated_time, which skips the server's check.
//  - copy: the editor text becomes a new note in the same notebook, then the server version is loaded.
//  - reload: the editor text is dropped and the server version is loaded.
//  - cancel: keep editing.
async function resolveSaveConflict(note,title,body){
  const choice=await jpDialog({title:t('joplix','Note was changed in the meantime'),message:t('joplix','This note was changed somewhere else since you opened it (synchronisation or another window). Your changes are not saved yet.'),cancelValue:'cancel',buttons:[{label:t('joplix','Cancel'),value:'cancel'},{label:t('joplix','Load other version'),value:'reload'},{label:t('joplix','Overwrite with mine'),value:'overwrite',danger:true},{label:t('joplix','Save mine as a copy'),value:'copy',primary:true}]});
  if(choice==='overwrite')return api('/api/notes/'+note.id,{method:'PUT',body:{title,body}});
  if(choice!=='copy'&&choice!=='reload')return null;
  if(choice==='copy'){
    await api('/api/notes',{method:'POST',body:{title:(title||t('joplix','Untitled'))+t('joplix',' (conflict copy)'),body,parent_id:note.notebook_id||state.notebookId}});
    jpToast(t('joplix','Your version was saved as a copy'));
  }
  state.dirty=false;forgetNote(note.id);
  const fresh=await apiNote(note.id);
  if(state.noteId===note.id)renderNoteToEditor(fresh,false);
  scheduleBackgroundRefresh(300);
  return null;
}
async function saveNote(options={}){
  if(state.savePromise){
    state.saveAgain=true;
    return state.savePromise;
  }
  if(state.note&&state.note.is_deleted)return false;
  state.savePromise=(async()=>{
    if(!state.notebookId&&!state.note){jpAlert(t('joplix','Please select a notebook.'));return false;}
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
          let r;
          try{r=await api('/api/notes/'+state.note.id,{method:'PUT',body});}
          catch(e){
            if(e.status!==409)throw e;
            // The note changed on the server since it was opened (sync or another tab). Nothing is saved yet.
            const resolved=await resolveSaveConflict(state.note,titleSnapshot,bodySnapshot);
            if(!resolved)return false;
            r=resolved;
          }
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
async function newNote(){if(state.notebookId===JP_TRASH_ID)return;if(!(await handleDirtyBeforeNoteChange('__new__')))return;state.note=null;state.noteId='';setHasNote(true);setPane('note');setNoteMode('edit');$('jp-title').value=t('joplix','New note');$('jp-body').classList.remove('jp-note-loading');$('jp-body').value='';renderDisplayMode();$('jp-body').placeholder=t('joplix','Note content');$('jp-meta').textContent=t('joplix','New note in this notebook');$('jp-tags').innerHTML='';$('jp-resources').innerHTML='';updateNotePath();state.originalNote={title:'',body:''};resetEditorHistory('');state.dirty=true;updateSaveButton();}
async function deleteNote(){if(!state.note)return;if(state.note.is_deleted)return purgeTrashedNote(state.note.id,state.note.title);const id=state.note.id;if(!(await jpConfirm(state.note.title||'',{title:t('joplix','Move this note to the trash?'),confirmLabel:t('joplix','Move to trash')})))return;await api('/api/notes/'+id,{method:'DELETE'});forgetNote(id);await rebuildAndRestore(true);clearNote();status(t('joplix','Moved to trash'),'ok');}
async function newNotebook(parent){const title=await jpPrompt(parent?t('joplix','New sub-notebook'):t('joplix','New notebook'),'',{confirmLabel:t('joplix','Create')});if(!title)return;await api('/api/notebooks',{method:'POST',body:{title,parent_id:parent||''}});if(parent)jpSetCollapsed(parent,false);await rebuildAndRestore();}
function mimeFromFilename(name){const ext=String(name||'').toLowerCase().split('.').pop();const map={pdf:'application/pdf',docx:'application/vnd.openxmlformats-officedocument.wordprocessingml.document',doc:'application/msword',xlsx:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',xls:'application/vnd.ms-excel',pptx:'application/vnd.openxmlformats-officedocument.presentationml.presentation',ppt:'application/vnd.ms-powerpoint',odt:'application/vnd.oasis.opendocument.text',ods:'application/vnd.oasis.opendocument.spreadsheet',odp:'application/vnd.oasis.opendocument.presentation'};return map[ext]||'application/octet-stream';}
async function uploadResource(file){
  if(!state.note){jpAlert(t('joplix','Please open a note first.'));return;}
  const noteId=state.note.id;
  const data=await new Promise((resolve,reject)=>{
    const reader=new FileReader();
    reader.onload=()=>resolve(String(reader.result).split(',')[1]);
    reader.onerror=reject;
    reader.readAsDataURL(file);
  });
  if(state.note?.id!==noteId||state.noteLoading)throw new Error(t('joplix','The original note is no longer open.'));
  const resource=await api('/api/resources',{method:'POST',body:{filename:file.name,mime:file.type||mimeFromFilename(file.name),data_base64:data}});
  await appendResourceLink(noteId,resource);
}
// Shared tail of both attach sources: append the resource link to the note it was started from, then save.
async function appendResourceLink(noteId,resource){
  if(state.note?.id!==noteId||state.noteLoading)throw new Error(t('joplix','Attachment uploaded. The link could not be added to the original note: ')+resource.markdown);
  $('jp-body').value+='\n\n'+resource.markdown;
  markDirty();
  await saveNote();
  // The attachment list is otherwise only rendered when a note is opened; fetch the server's view of it.
  forgetNote(noteId);
  try{const n=await api('/api/notes/'+noteId);if(state.note?.id===noteId&&!state.noteLoading){state.note.resources=n.resources||[];renderResources(state.note.resources);}}catch(e){}
}
// Paperclip in the editor toolbar: choose the attach source.
function showAttachMenu(button){
  if(!button){const f=$('jp-resource-file');if(f)f.click();return;}
  jpShowMenu(button,[
    {act:'device',icon:'paperclip',label:t('joplix','Attach from device'),run:()=>{const f=$('jp-resource-file');if(f)f.click();}},
    {act:'nextcloud',icon:'cloud',label:t('joplix','Attach from Nextcloud'),run:()=>attachFromNextcloud().catch(e=>jpAlert(e.message))}
  ]);
}
// Files above this size get a warning in the picker and a confirmation: every attachment is uploaded to
// Joplin Server and downloaded by every Joplin client. Warning only, no limit.
const JP_ATTACH_WARN_BYTES=25*1024*1024;
function jpAttachFolder(){try{return localStorage.getItem('joplix.attachFolder')||'/';}catch(e){return '/';}}
function jpSetAttachFolder(path){try{localStorage.setItem('joplix.attachFolder',path);}catch(e){}}
async function attachFromNextcloud(){
  if(!state.note){jpAlert(t('joplix','Please open a note first.'));return;}
  if(state.note.is_deleted)return;
  const noteId=state.note.id;
  let entry=null;
  for(;;){
    entry=await jpPickNextcloudFile();
    if(!entry)return;
    if(entry.size<=JP_ATTACH_WARN_BYTES)break;
    const ok=await jpConfirm(entry.name+' ('+formatBytes(entry.size)+')',{title:t('joplix','File is larger than 25 MB. Attach anyway?'),confirmLabel:t('joplix','Attach')});
    if(ok)break;
  }
  if(state.note?.id!==noteId||state.noteLoading)throw new Error(t('joplix','The original note is no longer open.'));
  status(t('joplix','Copying attachment …'),'warning');
  const resource=await api('/api/resources/from-files',{method:'POST',body:{path:entry.path}});
  await appendResourceLink(noteId,resource);
  status(t('joplix','Attachment added: ')+resource.title,'ok');
}
// Picker over the user's Nextcloud files (GET /api/files). Resolves with a file entry
// {name,path,type,mime,size,mtime} or null. Opens in the folder used last.
function jpPickNextcloudFile(){
  return jpDialog({title:t('joplix','Choose a file from Nextcloud'),cancelValue:null,buttons:[{label:t('joplix','Cancel'),value:null}],mount:(modal,finish)=>{
    modal.classList.add('jp-file-picker');
    const crumbs=document.createElement('div');crumbs.className='jp-file-picker-path';
    const input=document.createElement('input');input.type='search';input.className='jp-note-picker-search';input.placeholder=t('joplix','Filter this folder');input.setAttribute('aria-label',t('joplix','Filter this folder'));
    const list=document.createElement('div');list.className='jp-note-picker-list jp-file-picker-list';list.setAttribute('role','listbox');
    modal.appendChild(crumbs);modal.appendChild(input);modal.appendChild(list);
    let data=null,shown=[],active=0,seq=0;
    const message=text=>{list.innerHTML='';const p=document.createElement('p');p.className='jp-muted';p.textContent=text;list.appendChild(p);};
    const mark=()=>list.querySelectorAll('.jp-note-picker-item').forEach((el,i)=>{el.classList.toggle('active',i===active);el.setAttribute('aria-selected',i===active?'true':'false');if(i===active)el.scrollIntoView({block:'nearest'});});
    const choose=e=>{if(!e)return;if(e.type==='up'||e.type==='dir')load(e.path);else finish(e);};
    const renderCrumbs=path=>{
      crumbs.innerHTML='';
      const parts=path.split('/').filter(Boolean);
      const add=(label,target)=>{const b=document.createElement('button');b.type='button';b.className='jp-btn-tertiary';b.textContent=label;b.onclick=()=>load(target);crumbs.appendChild(b);};
      add(t('joplix','Files'),'/');
      parts.forEach((p,i)=>{const s=document.createElement('span');s.className='jp-file-picker-sep';s.textContent='/';crumbs.appendChild(s);add(p,'/'+parts.slice(0,i+1).join('/'));});
    };
    const render=()=>{
      if(!data)return;
      const q=normalizeSearchText(input.value).trim();
      shown=data.entries.filter(e=>!q||normalizeSearchText(e.name).includes(q));
      if(data.parent!==null&&!q)shown.unshift({type:'up',name:t('joplix','Parent folder'),path:data.parent});
      active=0;list.innerHTML='';
      if(!shown.length){message(q?t('joplix','No matches'):t('joplix','Empty folder'));return;}
      shown.forEach((e,i)=>{
        const b=document.createElement('button');b.type='button';b.className='jp-note-picker-item jp-file-picker-item';b.setAttribute('role','option');b.tabIndex=-1;
        const large=e.type==='file'&&e.size>JP_ATTACH_WARN_BYTES;
        if(large)b.classList.add('jp-file-large');
        b.appendChild(jpIconEl(e.type==='up'?'arrowUp':e.type==='dir'?'folder':(String(e.mime||'').startsWith('image/')?'image':'file')));
        const name=document.createElement('strong');name.textContent=e.name;b.appendChild(name);
        if(e.type==='file'){
          const meta=document.createElement('span');meta.className='jp-file-picker-size';meta.textContent=formatBytes(e.size);
          if(large){meta.appendChild(jpIconEl('alert'));const w=document.createElement('span');w.textContent=t('joplix','Large – transferred to every synced device');meta.appendChild(w);b.title=t('joplix','File is larger than 25 MB');}
          b.appendChild(meta);
        }
        b.onclick=()=>choose(e);b.onmouseenter=()=>{active=i;mark();};
        list.appendChild(b);
      });
      mark();
    };
    const load=async path=>{
      const my=++seq;message(t('joplix','Loading …'));
      try{
        const d=await api('/api/files?path='+encodeURIComponent(path));
        if(my!==seq)return;
        data={path:d.path,parent:d.parent,entries:d.entries||[]};jpSetAttachFolder(d.path);
        input.value='';renderCrumbs(d.path);render();input.focus();
      }catch(err){
        if(my!==seq)return;
        // A remembered folder that no longer exists falls back to the root once.
        if(path!=='/'){load('/');return;}
        message(err.message);
      }
    };
    input.addEventListener('input',render);
    input.addEventListener('keydown',ev=>{
      if(ev.key==='ArrowDown'||ev.key==='ArrowUp'){ev.preventDefault();if(!shown.length)return;active=(active+(ev.key==='ArrowDown'?1:shown.length-1))%shown.length;mark();}
      else if(ev.key==='Enter'){ev.preventDefault();choose(shown[active]);}
      else if(ev.key==='Backspace'&&!input.value&&data&&data.parent!==null){ev.preventDefault();load(data.parent);}
    });
    load(jpAttachFolder());
    return input;
  }});
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
  status(t('joplix','Removing tag...'),'warning');
  try{
    await api('/api/notes/'+encodeURIComponent(noteId)+'/tags/'+encodeURIComponent(tag.id),{method:'DELETE'});
    status(t('joplix','Tag removed'),'ok');
    scheduleBackgroundRefresh(450);
  }catch(e){
    applyTagsLocally(noteId,currentTags);
    status(t('joplix','Tag could not be removed: ')+e.message,'error');
    jpAlert(t('joplix','Tag could not be removed: ')+e.message);
  }
}
async function addTag(){
  if(!state.note){jpAlert(t('joplix','Please open a note first.'));return;}
  const entered=await jpPrompt(t('joplix','New tag'),'');
  const title=String(entered||'').trim();
  if(!title)return;
  const noteId=state.note.id;
  const currentTags=normalizeLocalTags(state.note.tags||[]);
  if(currentTags.some(t=>t.title.toLocaleLowerCase()===title.toLocaleLowerCase())){
    jpAlert(t('joplix','This tag is already assigned to the note.'));
    return;
  }

  // Optimistische Anzeige: Der Benutzer sieht den Tag sofort. Die provisorische
  // ID wird nach der Serverantwort durch die echte Joplin-ID ersetzt.
  const temporaryTag={id:'pending-'+Date.now()+'-'+Math.random().toString(16).slice(2),title};
  applyTagsLocally(noteId,[...currentTags,temporaryTag]);
  status(t('joplix','Saving tag...'),'warning');

  try{
    const saved=await api('/api/notes/'+noteId+'/tags/create',{method:'POST',body:{title}});
    const realTag={id:String(saved.id||''),title:String(saved.title||title).trim()||title};
    if(!realTag.id)throw new Error(t('joplix','The saved tag did not receive a valid ID.'));
    const latest=normalizeLocalTags((state.noteId===noteId&&state.note?state.note.tags:currentTags)||[])
      .filter(t=>t.id!==temporaryTag.id&&t.id!==realTag.id);
    applyTagsLocally(noteId,[...latest,realTag]);
    status(t('joplix','Tag saved'),'ok');
    scheduleBackgroundRefresh(450);
  }catch(e){
    const rollback=normalizeLocalTags((state.noteId===noteId&&state.note?state.note.tags:currentTags)||[])
      .filter(t=>t.id!==temporaryTag.id);
    applyTagsLocally(noteId,rollback.length?rollback:currentTags);
    status(t('joplix','Tag could not be saved: ')+e.message,'error');
    jpAlert(t('joplix','Tag could not be saved: ')+e.message);
  }
}
function closeNoteContextMenu(){const m=$('jp-note-context-menu');if(m){m.classList.remove('open');m.setAttribute('aria-hidden','true');m.innerHTML='';}}
function showNoteContextMenu(ev,note){ev.preventDefault();ev.stopPropagation();closeNoteContextMenu();const m=$('jp-note-context-menu');if(!m)return;m.dataset.noteId=note.id;m.innerHTML=jpMenuItem('move','folderMove',t('joplix','Move to notebook'))+jpMenuItem('tags','tag','Tags')+jpMenuItem('delete','delete',t('joplix','Delete'),'danger');m.style.left=Math.min(ev.clientX,window.innerWidth-240)+'px';m.style.top=Math.min(ev.clientY,window.innerHeight-150)+'px';m.classList.add('open');m.setAttribute('aria-hidden','false');m.querySelector('[data-act="delete"]').onclick=()=>{closeNoteContextMenu();deleteNoteById(note.id,note.title);};m.querySelector('[data-act="move"]').onclick=()=>{closeNoteContextMenu();moveNoteByPrompt(note.id);};m.querySelector('[data-act="tags"]').onclick=()=>{closeNoteContextMenu();editTagsByPrompt(note.id);};setTimeout(()=>document.addEventListener('click',closeNoteContextMenu,{once:true}),0);}
function flatNotebooks(){return (state.index&&state.index.notebooks?state.index.notebooks:[]).slice().sort((a,b)=>String(a.title||'').localeCompare(String(b.title||'')));}
async function deleteNoteById(id,title){if(!(await jpConfirm(title||id,{title:t('joplix','Move this note to the trash?'),confirmLabel:t('joplix','Move to trash')})))return;await api('/api/notes/'+id,{method:'DELETE'});forgetNote(id);if(state.noteId===id)clearNote();await rebuildAndRestore(true);status(t('joplix','Moved to trash'),'ok');}
// ---- Trash. Mirrors Joplin's trash: the server keeps trashed notes and notebooks (deleted_time set) and
// reports them in index.trash. The trash is a pseudo notebook (JP_TRASH_ID) in the sidebar; trashed notes
// open read-only (app class jp-trash-note), trashed notebooks only offer restore / delete permanently.
const JP_TRASH_ID='__trash__';
function trashOf(idx){const tr=(idx&&idx.trash)||{};return {notes:tr.notes||[],notebooks:tr.notebooks||[]};}
function trashCount(idx){const tr=trashOf(idx);return tr.notes.length+tr.notebooks.length;}
function renderTrashRow(root){
  const n=trashCount(state.index);if(!n)return;
  const row=document.createElement('div');row.className='jp-tree-row jp-trash-row';row.dataset.id=JP_TRASH_ID;row.style.paddingLeft='4px';
  const b=document.createElement('button');b.type='button';b.className='jp-tree-item';b.dataset.id=JP_TRASH_ID;b.onclick=()=>selectNotebook(JP_TRASH_ID);
  const label=document.createElement('span');label.className='jp-tree-label';label.textContent=t('joplix','Trash')+' ('+n+')';
  const spacer=document.createElement('span');spacer.className='jp-tree-toggle';spacer.setAttribute('aria-hidden','true');b.appendChild(jpIconEl('delete'));b.appendChild(label);row.appendChild(spacer);row.appendChild(b);root.appendChild(row);
  if(state.notebookId===JP_TRASH_ID){row.classList.add('active');b.classList.add('active');}
}
function renderTrash(){
  const box=$('jp-notes');if(!box)return;box.innerHTML='';
  $('jp-breadcrumb').textContent=t('joplix','Trash');
  const tr=trashOf(state.index);
  if(!tr.notes.length&&!tr.notebooks.length){box.innerHTML='<p>'+t('joplix','The trash is empty.')+'</p>';return;}
  const byDeleted=(a,b)=>String(b.deleted_time||'').localeCompare(String(a.deleted_time||''));
  const item=(entry,kind)=>{
    const b=document.createElement('button');b.type='button';b.className='jp-note-item';b.dataset.id=entry.id;
    const title=document.createElement('strong');title.textContent=entry.title||t('joplix','Untitled');
    const sub=document.createElement('span');sub.className='jp-note-sub';
    const info=document.createElement('time');
    const where=kind==='notebook'?(entry.parent_title||''):(entry.notebook_title||'');
    info.textContent=(kind==='notebook'?t('joplix','Notebook')+(entry.note_count?' · '+entry.note_count+' '+(entry.note_count===1?t('joplix','Note'):t('joplix','notes')):''):formatJoplixDateTime(entry.updated_time))+(where?' · '+where:'');
    sub.appendChild(info);
    if(kind==='notebook'){const c=document.createElement('span');c.className='jp-note-count';c.appendChild(jpIconEl('folderMove'));sub.appendChild(c);}
    b.appendChild(title);b.appendChild(sub);
    b.onclick=kind==='notebook'?(ev=>showTrashMenu(ev,entry,kind)):(()=>openNote(entry.id));
    b.addEventListener('contextmenu',ev=>showTrashMenu(ev,entry,kind));
    box.appendChild(b);
  };
  tr.notebooks.slice().sort(byDeleted).forEach(n=>item(n,'notebook'));
  tr.notes.slice().sort(byDeleted).forEach(n=>item(n,'note'));
  ensureNoteSelected(state.noteId);
}
function showTrashMenu(ev,entry,kind){
  ev.preventDefault();ev.stopPropagation();closeNoteContextMenu();
  const m=$('jp-note-context-menu');if(!m)return;
  m.innerHTML=jpMenuItem('restore','restore',t('joplix','Restore'))+jpMenuItem('purge','delete',t('joplix','Delete permanently'),'danger');
  m.style.left=Math.max(8,Math.min(ev.clientX,window.innerWidth-240))+'px';m.style.top=Math.max(8,Math.min(ev.clientY,window.innerHeight-110))+'px';
  m.classList.add('open');m.setAttribute('aria-hidden','false');
  const run=p=>p.catch(e=>{status(e.message,'error');jpAlert(e.message);});
  m.querySelector('[data-act="restore"]').onclick=()=>{closeNoteContextMenu();run(kind==='notebook'?restoreTrashedNotebook(entry.id):restoreTrashedNote(entry.id));};
  m.querySelector('[data-act="purge"]').onclick=()=>{closeNoteContextMenu();run(kind==='notebook'?purgeTrashedNotebook(entry.id,entry.title):purgeTrashedNote(entry.id,entry.title));};
  setTimeout(()=>document.addEventListener('click',closeNoteContextMenu,{once:true}),0);
}
async function afterTrashChange(id,message){forgetNote(id);if(id&&state.noteId===id)clearNote();await rebuildAndRestore(true);status(message,'ok');}
async function restoreTrashedNote(id){
  const entry=trashOf(state.index).notes.find(n=>n.id===id)||{};
  const body={};
  if(!entry.parent_available){
    // Its notebook is in the trash too, or gone: Joplix needs a notebook to show the note in.
    const books=flatNotebooks().map(b=>({value:b.id,label:breadcrumb(b.id)})).sort((a,b)=>a.label.localeCompare(b.label));
    if(!books.length){jpAlert(t('joplix','No notebooks found.'));return;}
    const target=await jpDialog({title:t('joplix','Restore to notebook'),message:t('joplix','The original notebook is no longer available.'),select:books,value:books[0].value,cancelValue:null,buttons:[{label:t('joplix','Cancel'),value:null},{label:t('joplix','Restore'),submit:true,primary:true}]});
    if(!target)return;
    body.target_notebook_id=target;
  }
  await api('/api/notes/'+id+'/restore',{method:'POST',body});
  await afterTrashChange(id,t('joplix','Restored'));
}
async function restoreTrashedNotebook(id){
  await api('/api/notebooks/'+id+'/restore',{method:'POST',body:{}});
  (trashOf(state.index).notes||[]).forEach(n=>forgetNote(n.id));
  await afterTrashChange(state.note&&state.note.is_deleted?state.noteId:'',t('joplix','Restored'));
}
async function purgeTrashedNote(id,title){
  if(!(await jpConfirm(title||id,{title:t('joplix','Delete this note permanently?'),danger:true,confirmLabel:t('joplix','Delete permanently')})))return;
  await api('/api/notes/'+id+'?permanent=1',{method:'DELETE'});
  await afterTrashChange(id,t('joplix','Deleted permanently'));
}
async function purgeTrashedNotebook(id,title){
  if(!(await jpConfirm(t('joplix','All notes and sub-notebooks in it will be permanently deleted as well.'),{title:t('joplix','Delete this notebook permanently?')+' '+(title||''),danger:true,confirmLabel:t('joplix','Delete permanently')})))return;
  await api('/api/notebooks/'+id+'?permanent=1',{method:'DELETE'});
  await afterTrashChange(state.note&&state.note.is_deleted?state.noteId:'',t('joplix','Deleted permanently'));
}
async function emptyTrash(){
  const n=trashCount(state.index);if(!n)return;
  if(!(await jpConfirm(t('joplix','All notes and notebooks in the trash will be permanently deleted, in the Joplin apps as well.'),{title:t('joplix','Empty the trash?'),danger:true,confirmLabel:t('joplix','Empty trash')})))return;
  await api('/api/trash/empty',{method:'POST',body:{}});
  await afterTrashChange(state.note&&state.note.is_deleted?state.noteId:'',t('joplix','Trash emptied'));
}
async function moveNoteByPrompt(id){
  const books=flatNotebooks().map(b=>({value:b.id,label:breadcrumb(b.id)})).sort((a,b)=>a.label.localeCompare(b.label));
  if(!books.length){jpAlert(t('joplix','No notebooks found.'));return;}
  const target=await jpDialog({title:t('joplix','Move to notebook'),select:books,value:findNoteMeta(id).notebook_id||books[0].value,cancelValue:null,buttons:[{label:t('joplix','Cancel'),value:null},{label:t('joplix','Move'),submit:true,primary:true}]});
  if(!target||target===findNoteMeta(id).notebook_id)return;
  await api('/api/notes/'+id+'/move',{method:'POST',body:{target_notebook_id:target}});
  await rebuildAndRestore(true);
  if(state.noteId===id)await openNote(id).catch(()=>{});
}
async function editTagsByPrompt(id){
  const note=state.noteId===id&&state.note?state.note:await api('/api/notes/'+id);
  const allRaw=await api('/api/tags').catch(()=>[]);
  const all=normalizeLocalTags(allRaw);
  const currentTags=normalizeLocalTags(note.tags||[]);
  const current=currentTags.map(t=>t.title).join(', ');
  const input=await jpPrompt(t('joplix','Edit tags'),current,{message:t('joplix','Separate multiple tags with commas. Removed names are detached from this note.')+'\n\n'+t('joplix','Existing tags:')+' '+(all.map(x=>x.title).join(', ')||t('joplix','None'))});
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
  status(t('joplix','Saving tags...'),'warning');
  try{
    if(added.length)await api('/api/notes/'+id+'/tags',{method:'POST',body:{tag_ids:added.map(t=>t.id)}});
    for(const tag of removed)await api('/api/notes/'+encodeURIComponent(id)+'/tags/'+encodeURIComponent(tag.id),{method:'DELETE'});
    status(t('joplix','Tags saved'),'ok');
    scheduleBackgroundRefresh(450);
  }catch(e){
    applyTagsLocally(id,currentTags);
    status(t('joplix','Tags could not be saved: ')+e.message,'error');
    jpAlert(t('joplix','Tags could not be saved: ')+e.message);
  }
}
async function rebuildAndRestore(clear=false){status(t('joplix','Rebuilding index...'),'warning');const r=await api('/api/index/rebuild',{method:'POST'});const idx=r.index||r;if(clear){state.index=idx;rememberIndex(idx);renderTree(idx.tree||[]);if(state.notebookId)selectNotebook(state.notebookId);else restoreLocation(idx);status(t('joplix','Index updated'),'ok');}else{applyIndexPreservingFocus(idx,t('joplix','Index updated'));}if(!clear&&state.noteId&&!state.dirty)openNote(state.noteId,{silent:true,preferCache:true}).catch(()=>{});if($('jp-search')&&$('jp-search').value.trim())runFullTextSearch($('jp-search').value);}
function scheduleBackgroundRefresh(delay=1200){setTimeout(()=>backgroundRefresh().catch(()=>{}),delay);}
async function backgroundRefresh(){
  if(state.backgroundBusy)return;
  state.backgroundBusy=true;
  try{
    const st=await api('/api/index/status');
    if(!st.current){
      status(t('joplix','Changes detected. Updating index in the background...'),'warning');
      const r=await api('/api/index/rebuild',{method:'POST'});
      applyIndexPreservingFocus(r.index||r,state.dirty?t('joplix','Index updated. Open note not overwritten because of unsaved changes.'):t('joplix','Index updated in the background'));
    } else if(st.fingerprint&&state.index&&state.index.fingerprint!==st.fingerprint){
      // "current" only says the server index matches the files. Write endpoints (create/update note,
      // notebooks, tags) rebuild it on the server themselves, so it can be current and still newer than
      // the copy in this browser: fetch it, otherwise a new note is missing from the list until a reload.
      const idx=await api('/api/index');
      if(idx&&!isEmptyIndex(idx))applyIndexPreservingFocus(idx,state.dirty?t('joplix','Index updated. Open note not overwritten because of unsaved changes.'):t('joplix','Index updated in the background'));
      if($('jp-search')&&$('jp-search').value.trim())runFullTextSearch($('jp-search').value);
    } else {
      status(t('joplix','Index up to date'),'ok');
    }
  }catch(e){status(t('joplix','Index check failed: ')+e.message,'error');}
  finally{state.backgroundBusy=false;}
}
async function saveLastLocation(){const loc={notebook_id:state.notebookId||'',note_id:state.noteId||''};storage.set('lastLocation',loc);api('/api/state/last-location',{method:'POST',body:loc}).catch(()=>{});}
function setSwitch(el,on){if(!el)return;el.classList.toggle('active',!!on);el.setAttribute('aria-checked',on?'true':'false');}
function currentLocalSettings(){return Object.assign({remember_last_location:'yes',sort_order:state.sort||'updated_desc',joplin_path:'/JoplinV2',update_repo_url:JP_UPDATE_REPO_URL,update_channel:'main',office_open_mode:'window',office_editor:'auto',update_backup_enabled:'no',is_admin:false},storage.get('settings',{}),state.settings||{});}
// Saves the given settings only (server and local copy). Values not passed are left as they are.
async function persistSettings(changes={}){
  const values=Object.assign({},changes);
  if('joplin_path' in values)values.joplin_path=normalizePath(values.joplin_path);
  storage.set('settings',Object.assign(storage.get('settings',{}),values));
  state.settings=Object.assign(state.settings||{},values);
  await api('/api/settings',{method:'POST',body:values}).catch(e=>{status(t('joplix','Settings could not be saved: ')+e.message,'error');throw e;});
}
// Same rule as JoplixFolders::normalizePath (PHP): leading slash, forward slashes, no trailing slash, default /JoplinV2.
function normalizePath(p){p=String(p||'').trim().replace(/\\/g,'/').replace(/\/+/g,'/').replace(/\/+$/,'');if(!p||p==='/')return '/JoplinV2';return p.startsWith('/')?p:'/'+p;}
function openSettings(){const o=$('jp-settings-overlay');if(!o){jpAlert(t('joplix','Settings area was not found.'));return;}syncSettingsUi();loadFoldersForSettings();o.classList.add('open');o.setAttribute('aria-hidden','false');}
function closeSettings(){const o=$('jp-settings-overlay');if(!o)return;o.classList.remove('open');o.setAttribute('aria-hidden','true');}
function activateSettingsTab(tab){document.querySelectorAll('[data-settings-tab]').forEach(b=>b.classList.toggle('active',b.dataset.settingsTab===tab));document.querySelectorAll('[data-settings-section]').forEach(sec=>sec.classList.toggle('active',sec.dataset.settingsSection===tab));}
/** Fills the editor select with the installed editors; both office cards hide when there is none. */
function syncOfficeEditorUi(local){const sel=$('jp-setting-office-editor');const all=officeEditorsAvailable();document.querySelectorAll('#joplix .jp-office-setting').forEach(el=>{el.hidden=!all.length;});if(!sel)return;sel.replaceChildren();const auto=document.createElement('option');auto.value='auto';auto.textContent=all.length?t('joplix','Automatic ({editor})',{editor:officeEditorName(all[0])}):t('joplix','Automatic');sel.appendChild(auto);all.forEach(id=>{const o=document.createElement('option');o.value=id;o.textContent=officeEditorName(id);sel.appendChild(o);});sel.value=all.includes(local.office_editor)?local.office_editor:'auto';}
function syncSettingsUi(){const local=currentLocalSettings();if($('jp-setting-sort'))$('jp-setting-sort').value=local.sort_order||state.sort||'updated_desc';if($('jp-setting-joplin-path'))$('jp-setting-joplin-path').value=local.joplin_path||'/JoplinV2';if($('jp-setting-office-open-mode'))$('jp-setting-office-open-mode').value=local.office_open_mode==='tab'?'tab':'window';syncOfficeEditorUi(local);if($('jp-setting-update-backup'))$('jp-setting-update-backup').value=local.update_backup_enabled==='yes'?'yes':'no';setSwitch($('jp-setting-remember-toggle'),(local.remember_last_location||'yes')==='yes');syncUpdateUi();}
async function loadFoldersForSettings(){if(state.foldersLoaded)return;try{const data=await api('/api/folders');state.foldersLoaded=true;const list=$('jp-folder-list');if(list){list.innerHTML='';(data.folders||[]).forEach(f=>{const o=document.createElement('option');o.value=f;list.appendChild(o);});}if($('jp-setting-joplin-path')&&!$('jp-setting-joplin-path').value)$('jp-setting-joplin-path').value=data.current||'/JoplinV2';}catch(e){status(t('joplix','Folder list could not be loaded: ')+e.message,'warning');}}
async function applyJoplinPathFromInput(){const el=$('jp-setting-joplin-path');if(!el)return;const newPath=normalizePath(el.value);el.value=newPath;const oldPath=(state.settings&&state.settings.joplin_path)||'/JoplinV2';await persistSettings({joplin_path:newPath});if(newPath!==oldPath){storage.del('lastGoodIndex');state.index=null;status(t('joplix','Joplix folder changed. Rebuilding index...'),'warning');await rebuildAndRestore(true).catch(e=>status(t('joplix','Index rebuild failed: ')+e.message,'error'));}}
function showWelcomeIfNeeded(){
  const key='joplix.welcome.1.0.43.done';
  if(localStorage.getItem(key)==='yes')return;
  const o=$('jp-welcome-overlay');if(!o)return;
  o.classList.add('open');o.setAttribute('aria-hidden','false');
}
function closeWelcome(){const key='joplix.welcome.1.0.43.done';try{localStorage.setItem(key,'yes');}catch(e){}const o=$('jp-welcome-overlay');if(o){o.classList.remove('open');o.setAttribute('aria-hidden','true');}persistSettings({first_run_completed:'yes'}).catch(()=>{});}
function setupWelcome(){const done=$('jp-welcome-done');if(done)done.onclick=closeWelcome;const close=$('jp-welcome-close');if(close)close.onclick=closeWelcome;const settings=$('jp-welcome-settings');if(settings)settings.onclick=()=>{closeWelcome();openSettings();};setTimeout(showWelcomeIfNeeded,600);}


function currentNotebookTitle(){
  if(state.note&&state.note.notebook_title)return state.note.notebook_title;
  const id=state.notebookId;
  let found='';
  const walk=nodes=>{(nodes||[]).forEach(n=>{if(n.id===id)found=n.title;(n.children||[]).length&&walk(n.children);});};
  if(state.index)walk(state.index.tree||[]);
  return found||t('joplix','Notebook');
}
function updateNotePath(){
  const el=$('jp-note-path'); if(!el)return;
  const title=$('jp-title')&&$('jp-title').value?$('jp-title').value:(state.note&&state.note.title?state.note.title:t('joplix','New note'));
  if(!state.note&&!state.noteId&&!state.dirty){el.textContent=t('joplix','No note open');return;}
  el.textContent=(state.note&&state.note.is_deleted?t('joplix','Trash'):currentNotebookTitle())+' / '+title;
}
function resetEditorHistory(value){state.editorHistory={undo:[],last:String(value||''),max:80};}
function pushEditorUndo(value){const h=state.editorHistory||(state.editorHistory={undo:[],last:String(value||''),max:80});value=String(value||'');if(h.undo[h.undo.length-1]!==value&&h.last!==value){h.undo.push(value);while(h.undo.length>(h.max||80))h.undo.shift();}h.last=value;}
function applyEditorValue(value,pos){const ta=$('jp-body');if(!ta)return;ta.value=value;ta.focus();const p=typeof pos==='number'?pos:value.length;ta.setSelectionRange(p,p);state.editorHistory.last=value;markDirty();}
function editorUndo(){const ta=$('jp-body');if(!ta)return;const h=state.editorHistory||(state.editorHistory={undo:[],last:ta.value||'',max:80});if(!h.undo.length)return;const prev=h.undo.pop();applyEditorValue(prev,prev.length);}
function jpReplaceSelection(before,after='',placeholder=''){const ta=$('jp-body');if(!ta)return;const start=ta.selectionStart||0,end=ta.selectionEnd||0;const val=ta.value||'';pushEditorUndo(val);const sel=val.slice(start,end)||placeholder;const next=val.slice(0,start)+before+sel+after+val.slice(end);const pos=start+before.length+sel.length+after.length;applyEditorValue(next,pos);}
function jpInsertAtCursor(text){const ta=$('jp-body');if(!ta)return;const start=ta.selectionStart||0,end=ta.selectionEnd||0,val=ta.value||'';pushEditorUndo(val);const next=val.slice(0,start)+text+val.slice(end);const pos=start+text.length;applyEditorValue(next,pos);}
// Note picker for the "link note" tool. Resolves with an index note entry or null.
function jpPickNote(){
  const all=((state.index&&state.index.notes)||[]).filter(n=>n.id!==state.noteId);
  return jpDialog({title:t('joplix','Link to note'),cancelValue:null,buttons:[{label:t('joplix','Cancel'),value:null}],mount:(modal,finish)=>{
    const input=document.createElement('input');input.type='search';input.className='jp-note-picker-search';input.placeholder=t('joplix','Search notes');input.setAttribute('aria-label',t('joplix','Search notes'));
    const list=document.createElement('div');list.className='jp-note-picker-list';list.setAttribute('role','listbox');
    modal.appendChild(input);modal.appendChild(list);
    let shown=[],active=0;
    const mark=()=>list.querySelectorAll('.jp-note-picker-item').forEach((el,i)=>{el.classList.toggle('active',i===active);el.setAttribute('aria-selected',i===active?'true':'false');if(i===active)el.scrollIntoView({block:'nearest'});});
    const render=()=>{
      const q=normalizeSearchText(input.value).trim();
      shown=all.filter(n=>noteMatchesQuery(n,q)).sort((a,b)=>String(b.updated_time||'').localeCompare(String(a.updated_time||''))).slice(0,50);
      active=0;list.innerHTML='';
      if(!shown.length){const p=document.createElement('p');p.className='jp-muted';p.textContent=t('joplix','No matches');list.appendChild(p);return;}
      shown.forEach((n,i)=>{
        const b=document.createElement('button');b.type='button';b.className='jp-note-picker-item';b.setAttribute('role','option');b.tabIndex=-1;
        const title=document.createElement('strong');title.textContent=n.title||t('joplix','(untitled)');
        const nb=document.createElement('span');nb.textContent=n.notebook_title||'';
        b.appendChild(title);b.appendChild(nb);
        b.onclick=()=>finish(n);b.onmouseenter=()=>{active=i;mark();};
        list.appendChild(b);
      });
      mark();
    };
    input.addEventListener('input',render);
    input.addEventListener('keydown',ev=>{
      if(ev.key==='ArrowDown'||ev.key==='ArrowUp'){ev.preventDefault();if(!shown.length)return;active=(active+(ev.key==='ArrowDown'?1:shown.length-1))%shown.length;mark();}
      else if(ev.key==='Enter'){ev.preventDefault();if(shown[active])finish(shown[active]);}
    });
    render();
    return input;
  }});
}
// Same escaping as Joplin's markdownUtils.escapeTitleText, so the link text matches what Joplin writes.
function jpEscapeLinkTitle(title){return String(title||'').replace(/(\[|\])/g,'\\$1');}
async function insertNoteLink(){
  const ta=$('jp-body');if(!ta)return;
  const start=ta.selectionStart||0,end=ta.selectionEnd||0;
  const n=await jpPickNote();
  if(!n)return;
  ta.focus();ta.setSelectionRange(start,end);
  // Selected text becomes the link text; it replaces the selection like every other format tool.
  const sel=(ta.value||'').slice(start,end);
  jpInsertAtCursor('['+jpEscapeLinkTitle(sel||n.title||'')+'](:/'+String(n.id).toLowerCase()+')');
}
function jpFormatDateTime(){const d=new Date();try{return new Intl.DateTimeFormat('de-AT',{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}).format(d).replace(',', '');}catch(e){return d.toLocaleString();}}
function continueMarkdownList(ev){const ta=$('jp-body');if(!ta||ev.key!=='Enter'||ev.shiftKey||ev.altKey||ev.ctrlKey||ev.metaKey)return;const val=ta.value||'',pos=ta.selectionStart||0;if(pos!==ta.selectionEnd)return;const lineStart=val.lastIndexOf('\n',pos-1)+1;const line=val.slice(lineStart,pos);const indent=(line.match(/^\s*/)||[''])[0];let marker='';if(/^\s*- \[ \]\s*$/.test(line)||/^\s*-\s*$/.test(line)||/^\s*\d+\.\s*$/.test(line)){ev.preventDefault();pushEditorUndo(val);applyEditorValue(val.slice(0,lineStart)+val.slice(pos),lineStart);return;}if(/^\s*- \[ \] /.test(line))marker=indent+'- [ ] ';else if(/^\s*- /.test(line))marker=indent+'- ';else{const m=line.match(/^(\s*)(\d+)\. /);if(m)marker=m[1]+(parseInt(m[2],10)+1)+'. ';}if(marker){ev.preventDefault();jpInsertAtCursor('\n'+marker);}}
function handleEditorTool(action,button){const ta=$('jp-body');if(!ta)return;if(action==='undo'){editorUndo();return;}if(action==='bold')return jpReplaceSelection('**','**','Text');if(action==='italic')return jpReplaceSelection('*','*','Text');if(action==='link')return jpReplaceSelection('[','](https://)',t('joplix','link text'));if(action==='code')return jpReplaceSelection('`','`','code');if(action==='notelink'){insertNoteLink().catch(e=>jpAlert(e.message));return;}if(action==='resource'){showAttachMenu(button);return;}if(action==='ul')return jpInsertAtCursor((ta.selectionStart>0&&!ta.value.slice(0,ta.selectionStart).endsWith('\n')?'\n':'')+'- ');if(action==='ol')return jpInsertAtCursor((ta.selectionStart>0&&!ta.value.slice(0,ta.selectionStart).endsWith('\n')?'\n':'')+'1. ');if(action==='todo')return jpInsertAtCursor((ta.selectionStart>0&&!ta.value.slice(0,ta.selectionStart).endsWith('\n')?'\n':'')+'- [ ] ');if(action==='h2')return jpInsertAtCursor('\n## ');if(action==='hr')return jpInsertAtCursor('\n\n---\n\n');if(action==='datetime')return jpInsertAtCursor(jpFormatDateTime());if(action==='marker')return jpReplaceSelection('==','==',t('joplix','highlight'));}
function setupEditorFormatbar(){document.querySelectorAll('[data-jp-editor]').forEach(b=>b.addEventListener('click',ev=>{ev.preventDefault();handleEditorTool(b.dataset.jpEditor,b);}));const ta=$('jp-body');if(ta){resetEditorHistory(ta.value||'');ta.addEventListener('keydown',continueMarkdownList);ta.addEventListener('input',()=>{const h=state.editorHistory||(state.editorHistory={undo:[],last:'',max:80});if(h.last!==ta.value){h.undo.push(h.last);while(h.undo.length>(h.max||80))h.undo.shift();h.last=ta.value;}});}}

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
function setBrandUpdate(update){const el=$('jp-brand-update');if(!el)return;if(!joplixIsAdmin()){el.textContent='';el.hidden=true;el.removeAttribute('href');return;}if(update&&update.version){el.textContent=update.version.text+t('joplix',' New update!');const url=releaseUrl(update.release);if(url)el.href=url;else el.href=normalizeUpdateRepoUrlInput((currentLocalSettings().update_repo_url)||JP_UPDATE_REPO_URL);el.hidden=false;el.title=t('joplix','Open GitHub release');}else{el.textContent='';el.hidden=true;el.removeAttribute('href');}}
async function checkUpdateOnStart(){try{if(!joplixIsAdmin())return;const local=currentLocalSettings();if(!local.is_admin)return;const data=await api('/api/updates/releases');const upd=newestUpdateFromReleases(data);setBrandUpdate(upd);}catch(e){console.warn('Joplix update check at startup failed:',e.message);}}
function syncUpdateUi(){const local=currentLocalSettings();syncInstalledVersionUi();if($('jp-update-repo-url'))$('jp-update-repo-url').value=normalizeUpdateRepoUrlInput(local.update_repo_url||JP_UPDATE_REPO_URL);if($('jp-update-channel'))$('jp-update-channel').value=local.update_channel||'main';}
function updateStatus(txt,cls=''){const el=$('jp-update-status');if(el){el.textContent=txt;el.className='jp-update-status '+cls;}}
function isInstallableJoplixAsset(asset){const n=String((asset&&asset.name)||'').trim().replace(/\s+/g,'');return /^JoplixNC_v\d+\.\d+\.\d+(?:-beta)?\.zip$/i.test(n);}
function releaseIsNewer(rel,installed){return isAllowedUpdateTransition(rel,installed);}
function renderUpdateList(data){const box=$('jp-update-list');if(!box)return;box.innerHTML='';syncInstalledVersionUi();const installed=parseJoplixVersionText(getInstalledVersion());let rels=((data&&data.releases)||[]).filter(rel=>releaseIsNewer(rel,installed));rels=rels.filter(rel=>((rel.assets||[]).some(isInstallableJoplixAsset)));if(!rels.length){box.innerHTML='<p>'+t('joplix','No newer version found for this channel. Currently installed: Joplix ')+getInstalledVersion()+' ('+(installedChannel()==='beta'?'Beta':'Main')+').</p>';return;}rels.sort((a,b)=>{const c=compareJoplixVersions(releaseVersion(b),releaseVersion(a));if(c!==0)return c;return releaseChannel(a)==='main'?-1:1;});rels.forEach(rel=>{const card=document.createElement('div');card.className='jp-update-release';const h=document.createElement('div');h.className='jp-update-release-head';h.innerHTML='<strong></strong><span></span>';h.querySelector('strong').textContent=(rel.name||rel.tag||'Release')+' ['+(releaseChannel(rel)==='beta'?'Beta':'Main')+']';h.querySelector('span').textContent=rel.published_at||'';card.appendChild(h);(rel.assets||[]).filter(isInstallableJoplixAsset).forEach(asset=>{const row=document.createElement('div');row.className='jp-update-asset';const name=document.createElement('span');name.textContent=asset.name;const btn=document.createElement('button');btn.type='button';btn.textContent=t('joplix','Install');btn.onclick=()=>installUpdateAsset(asset,rel);row.appendChild(name);row.appendChild(btn);card.appendChild(row);});box.appendChild(card);});}
async function persistUpdateSettings(){
  const repo=normalizeUpdateRepoUrlInput($('jp-update-repo-url')?.value||JP_UPDATE_REPO_URL);
  const channel=$('jp-update-channel')?.value||'main';
  await api('/api/settings',{method:'POST',body:{update_source:'github',update_repo_url:repo,update_channel:channel}});
  const updates={update_source:'github',update_repo_url:repo,update_channel:channel};
  storage.set('settings',Object.assign(storage.get('settings',{}),updates));
  state.settings=Object.assign({},state.settings||{},updates);
}
async function checkUpdates(){
  try{
    if(!joplixIsAdmin())throw new Error(t('joplix','Administrator rights required.'));
    await persistUpdateSettings();
    updateStatus(t('joplix','Loading update list from GitHub...'),'warning');
    const data=await api('/api/updates/releases');
    renderUpdateList(data);
    const shown=(data.releases||[]).filter(rel=>releaseIsNewer(rel,parseJoplixVersionText(getInstalledVersion()))&&(rel.assets||[]).some(isInstallableJoplixAsset)).length;
    const update=newestUpdateFromReleases(data);
    setBrandUpdate(update);
    updateStatus(shown+t('joplix',' installable release(s) found.')+(update?t('joplix',' New update: ')+update.version.text+' ['+(releaseChannel(update.release)==='beta'?'Beta':'Main')+']':'')+t('joplix',' Currently installed: Joplix ')+getInstalledVersion()+' ('+(installedChannel()==='beta'?'Beta':'Main')+').','ok');
  }catch(e){
    updateStatus(t('joplix','Update check failed: ')+e.message,'error');
  }
}
async function installUpdateAsset(asset,rel){if(!asset?.id||!rel?.id)return;const backupEnabled=currentLocalSettings().update_backup_enabled==='yes';if(!(await jpConfirm(asset.name+'\n\n'+(backupEnabled?t('joplix','An optional app backup is created before the update.'):t('joplix','No app backup will be created. Please make sure a complete Nextcloud backup exists.')),{title:t('joplix','Install Joplix update now?'),confirmLabel:t('joplix','Install')})))return;try{updateStatus(t('joplix','Downloading and installing update...'),'warning');const r=await api('/api/updates/install',{method:'POST',body:{release_id:rel.id,asset_id:asset.id}});const ch=releaseChannel(rel||{});storage.set('installed_channel',ch);const settings=storage.get('settings',{});settings.installed_channel=ch;storage.set('settings',settings);updateStatus((r.message||t('joplix','Update installed. Please reload the page.'))+' Version: '+(r.version||''),'ok');jpAlert((r.message||t('joplix','Update installed. Please reload the page.'))+(r.backup?'\n\nBackup: '+r.backup:'')+'\nVersion: '+(r.version||''));}catch(e){updateStatus(t('joplix','Update installation failed: ')+e.message,'error');jpAlert(t('joplix','Update installation failed:')+'\n'+e.message);}}


// ---------------------------------------------------------------------------
// i18n
// English is the source language. Every string shown to the user goes through Nextcloud's translation
// function: t('joplix','Text') or, with values, t('joplix','{count} results',{count:3}). Translations live in
// l10n/<lang>.json and .js (same content; PHP reads the .json, the browser the .js, which Nextcloud loads
// with the script). The language is the one of the Nextcloud account.
// This local t() only fixes the options: results are put into the page with textContent (or escaped by the
// caller), so the HTML escaping and sanitising that window.t applies by default would show up as "&amp;".
function t(app,text,vars){return window.t(app,text,vars||{},undefined,{escape:false,sanitize:false});}


function setupSettings(){
  const local=currentLocalSettings();
  if(local.sort_order)state.sort=local.sort_order;
  const openBtn=$('jp-open-settings'); if(openBtn)openBtn.addEventListener('click',ev=>{ev.preventDefault();openSettings();});
  const closeBtn=$('jp-close-settings'); if(closeBtn)closeBtn.addEventListener('click',ev=>{ev.preventDefault();closeSettings();});
  const backdrop=$('jp-settings-backdrop'); if(backdrop)backdrop.addEventListener('click',()=>closeSettings());
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&jpSettingsOpen()&&!jpDialogOpen())closeSettings();});
  document.querySelectorAll('[data-settings-tab]').forEach(b=>b.addEventListener('click',()=>activateSettingsTab(b.dataset.settingsTab)));
  const sortEl=$('jp-setting-sort'); if(sortEl)sortEl.addEventListener('change',async e=>{state.sort=e.target.value;await persistSettings({sort_order:state.sort});if(state.notebookId)selectNotebook(state.notebookId);});
  const officeMode=$('jp-setting-office-open-mode'); if(officeMode)officeMode.addEventListener('change',async e=>{await persistSettings({office_open_mode:e.target.value==='tab'?'tab':'window'});status(t('joplix','Office opening mode saved.'),'ok');});const officeEditor=$('jp-setting-office-editor');if(officeEditor)officeEditor.addEventListener('change',async e=>{await persistSettings({office_editor:e.target.value||'auto'});status(t('joplix','Office editor saved.'),'ok');});const backupMode=$('jp-setting-update-backup');if(backupMode)backupMode.addEventListener('change',async e=>{await persistSettings({update_backup_enabled:e.target.value==='yes'?'yes':'no'});status(t('joplix','Backup setting saved.'),'ok');});
  // change fires once per edit: on Enter or when the field loses focus with a new value.
  const pathEl=$('jp-setting-joplin-path'); if(pathEl)pathEl.addEventListener('change',()=>applyJoplinPathFromInput().catch(e=>jpAlert(e.message)));
  const remember=$('jp-setting-remember-toggle'); if(remember)remember.addEventListener('click',async()=>{const next=(currentLocalSettings().remember_last_location||'yes')==='yes'?'no':'yes';setSwitch(remember,next==='yes');await persistSettings({remember_last_location:next}).then(()=>status(t('joplix','Settings applied'),'ok')).catch(()=>{});});
  const reset=$('jp-layout-reset'); if(reset)reset.addEventListener('click',()=>{storage.del('columnWidths2');applyColumnWidths();});
  const rebuild=$('jp-settings-rebuild'); if(rebuild)rebuild.addEventListener('click',()=>rebuildAndRestore().catch(e=>jpAlert(e.message)));
  const upd=$('jp-update-check'); if(upd)upd.addEventListener('click',()=>checkUpdates());
  const updChannel=$('jp-update-channel'); if(updChannel)updChannel.addEventListener('change',()=>persistUpdateSettings().then(()=>updateStatus(t('joplix','Update settings saved.'),'ok')).catch(e=>updateStatus(t('joplix','Update settings could not be saved: ')+e.message,'error')));
  const updRepo=$('jp-update-repo-url'); if(updRepo)updRepo.addEventListener('change',()=>{updRepo.value=normalizeUpdateRepoUrlInput(updRepo.value);persistUpdateSettings().then(()=>updateStatus(t('joplix','Update settings saved.'),'ok')).catch(e=>updateStatus(t('joplix','Update settings could not be saved: ')+e.message,'error'));});
  syncSettingsUi();
}
// ---------------------------------------------------------------------------
// Joplin Server synchronisation
// ---------------------------------------------------------------------------
const syncState={status:null,running:false,timer:null,again:false,lastAuto:0};
function syncPaneStatus(text,cls=''){const el=$('jp-sync-status');if(el){el.textContent=text;el.className='jp-update-status '+cls;}}
function syncBusy(on){['jp-sync-now','jp-sync-run'].forEach(id=>{const b=$(id);if(b)b.disabled=!!on;});}
function syncEnabled(){return !!(syncState.status&&syncState.status.enabled&&syncState.status.configured);}
function describeSyncCounts(r){
  const parts=[];
  if(r.pulled)parts.push(r.pulled+' '+t('joplix','received'));
  if(r.pushed)parts.push(r.pushed+' '+t('joplix','sent'));
  if(r.deleted_local)parts.push(r.deleted_local+' '+t('joplix','deleted locally'));
  if(r.deleted_remote)parts.push(r.deleted_remote+' '+t('joplix','deleted on the server'));
  if(r.conflicts)parts.push(r.conflicts+' '+t('joplix','conflict(s)'));
  if(r.rejected)parts.push(r.rejected+' '+t('joplix','rejected by the server'));
  return parts.join(', ');
}
function describeLastSync(st){
  if(!st)return t('joplix','Status not available.');
  if(!st.configured)return t('joplix','Not set up yet.');
  if(!st.enabled)return t('joplix','Synchronisation is switched off.');
  if(!st.last_run)return t('joplix','Not synchronised yet.');
  const when=formatJoplixDateTime(String(st.last_run*1000));
  if(st.last_status==='error')return t('joplix','Last attempt ')+when+': '+(st.last_message||t('joplix','error'));
  const counts=st.last_result?describeSyncCounts(st.last_result):'';
  return t('joplix','Last synchronised: ')+when+(counts?' ('+counts+')':'')+(st.last_status==='partial'?t('joplix',' – continuing'):'');
}
function fillSyncUi(){
  const st=syncState.status;
  const btn=$('jp-sync-now');if(btn)btn.hidden=!syncEnabled();
  if(!st)return;
  if($('jp-sync-url')&&document.activeElement!==$('jp-sync-url'))$('jp-sync-url').value=st.server_url||'';
  if($('jp-sync-email')&&document.activeElement!==$('jp-sync-email'))$('jp-sync-email').value=st.email||'';
  if($('jp-sync-password')){$('jp-sync-password').value='';$('jp-sync-password').placeholder=st.password_set?t('joplix','(stored)'):'';}
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
  syncPaneStatus(t('joplix','Saving settings...'),'warning');
  syncState.status=await api('/api/sync/settings',{method:'POST',body:syncFormValues()});
  fillSyncUi();
  syncPaneStatus(t('joplix','Settings saved.'),'ok');
  return syncState.status;
}
async function testSyncConnection(){
  // Save first so the test uses exactly what is in the form, but never switch sync on as a side effect of testing.
  const values=syncFormValues();
  if(!(syncState.status&&syncState.status.enabled))values.enabled='no';
  syncPaneStatus(t('joplix','Testing connection...'),'warning');
  syncState.status=await api('/api/sync/settings',{method:'POST',body:values});
  if($('jp-sync-password'))$('jp-sync-password').value='';
  const r=await api('/api/sync/test',{method:'POST',body:{}});
  if(!r.has_data)syncPaneStatus(t('joplix','Login successful, but the account holds no sync data yet. Synchronise once from a Joplin app first.'),'warning');
  else if(r.e2ee)syncPaneStatus(t('joplix','Login successful, but end-to-end encryption is enabled. Joplix cannot read encrypted notes.'),'error');
  else if(r.version!==3)syncPaneStatus(t('joplix','Login successful, but sync version ')+r.version+t('joplix',' is not supported.'),'error');
  else syncPaneStatus(t('joplix','Connection successful. The server is ready for synchronisation.'),'ok');
}
async function runSync(opts={}){
  if(!syncEnabled()){if(opts.manual)syncPaneStatus(t('joplix','Please set up and enable synchronisation first.'),'warning');return null;}
  if(syncState.running){syncState.again=true;return null;}
  syncState.running=true;syncBusy(true);
  const total={pulled:0,pushed:0,deleted_local:0,deleted_remote:0,conflicts:0,rejected:0};
  let last=null,confirmDelete=false;
  try{
    if(opts.manual){status(t('joplix','Synchronising...'),'warning');syncPaneStatus(t('joplix','Synchronising...'),'warning');}
    for(let round=0;round<2000;round++){
      last=await api('/api/sync/run',{method:'POST',body:{confirm_delete:confirmDelete?'yes':'no'}});
      Object.keys(total).forEach(k=>{total[k]+=Number(last[k]||0);});
      if(last.status==='partial'){
        const text=t('joplix','Synchronising... ')+describeSyncCounts(total);
        status(text,'warning');syncPaneStatus(text,'warning');
        continue;
      }
      if(last.status==='error'&&last.code==='massDelete'&&opts.manual&&!confirmDelete){
        if(await jpConfirm(last.message,{title:t('joplix','Delete these items on the Joplin Server as well?'),danger:true,confirmLabel:t('joplix','Delete')})){confirmDelete=true;continue;}
      }
      break;
    }
    const counts=describeSyncCounts(total);
    if(last&&last.status==='done'){
      const text=t('joplix','Synchronised')+(counts?': '+counts:'');
      if(opts.manual||counts)status(text,'ok');
      syncPaneStatus(text,'ok');
    }else if(last&&last.status==='busy'){
      if(opts.manual){status(last.message||t('joplix','A synchronisation is already running.'),'warning');syncPaneStatus(last.message||'','warning');}
    }else if(last&&last.status==='error'){
      const text=t('joplix','Synchronisation failed: ')+(last.message||'');
      status(text,'error');syncPaneStatus(text+(last.code==='massDelete'&&!opts.manual?t('joplix',' Choose "Synchronise now" to confirm.'):''),'error');
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
    const text=t('joplix','Synchronisation failed: ')+e.message;
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
  // The Office status poll is a POST too, but it only changes something when it imported a new version.
  if(/\/office\/status$/.test(path)){if(data&&data.updated)scheduleSync(2500);return;}
  if(method!=='GET'&&/^\/api\/(notes|notebooks|tags|resources|trash)(\/|$)/.test(path))scheduleSync(2500);
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
// Toolbar "more" menu, the back buttons of the single-pane layout and Ctrl/Cmd+S.
function setupChrome(){
  const moreBtn=$('jp-more-button'),moreMenu=$('jp-more-menu');
  if(moreBtn&&moreMenu){
    const closeMore=()=>{moreMenu.hidden=true;moreBtn.setAttribute('aria-expanded','false');};
    moreBtn.addEventListener('click',ev=>{ev.stopPropagation();const open=moreMenu.hidden;moreMenu.hidden=!open;moreBtn.setAttribute('aria-expanded',open?'true':'false');});
    document.addEventListener('click',closeMore);
    document.addEventListener('keydown',ev=>{if(ev.key==='Escape')closeMore();});
  }
  if($('jp-back-to-list'))$('jp-back-to-list').onclick=()=>setPane('list');
  if($('jp-nav-toggle'))$('jp-nav-toggle').onclick=()=>setNavOpen(!navIsOpen(),{focus:true});
  if($('jp-nav-backdrop'))$('jp-nav-backdrop').onclick=()=>setNavOpen(false);
  if($('jp-open-settings'))$('jp-open-settings').addEventListener('click',()=>setNavOpen(false));
  document.addEventListener('keydown',ev=>{if(ev.key!=='Escape'||!navIsOpen()||jpDialogOpen()||jpSettingsOpen())return;ev.preventDefault();setNavOpen(false,{focus:true});});
  const onWidth=()=>{if(!jpNarrow.matches)setNavOpen(false);};
  if(jpNarrow.addEventListener)jpNarrow.addEventListener('change',onWidth);else if(jpNarrow.addListener)jpNarrow.addListener(onWidth);
  document.addEventListener('keydown',ev=>{
    if(!(ev.ctrlKey||ev.metaKey)||ev.altKey||String(ev.key).toLowerCase()!=='s'||jpDialogOpen()||jpSettingsOpen())return;
    ev.preventDefault();
    if(state.dirty)saveNote({keepMode:true,skipReload:true}).then(ok=>{if(ok)jpToast(t('joplix','Note saved'));}).catch(e=>jpAlert(e.message));
  });
}
// Closing the tab, reloading or following a link out of Joplix would drop unsaved text without a word.
// The browser shows its own generic question; the text of the message cannot be set.
window.addEventListener('beforeunload',ev=>{if(state.dirty||state.savePromise){ev.preventDefault();ev.returnValue='';}});
document.addEventListener('DOMContentLoaded',()=>{
  try{hydrateIcons(jpRoot());setupChrome();}catch(e){console.error('Joplix chrome init failed',e);}
  try{setupResizableColumns();}catch(e){console.error('Joplix resizable init failed',e);} try{setupEditorFormatbar();}catch(e){console.error('Joplix editor toolbar init failed',e);} try{setupSettings();}catch(e){console.error('Joplix settings init failed',e);} try{setupWelcome();}catch(e){console.error('Joplix welcome init failed',e);} try{setupSync();}catch(e){console.error('Joplix sync init failed',e);} try{setTimeout(()=>checkUpdateOnStart(),1200);}catch(e){console.warn('Joplix update startup init failed',e);}
  if($('jp-new-root'))$('jp-new-root').onclick=()=>newNotebook('');
  if($('jp-new-note-main'))$('jp-new-note-main').onclick=newNote;
  if($('jp-save-note'))$('jp-save-note').onclick=()=>saveNote({keepMode:true,skipReload:true}).then(ok=>{if(ok)jpToast(t('joplix','Note saved'));}).catch(e=>jpAlert(e.message)); if($('jp-edit-note'))$('jp-edit-note').onclick=()=>setNoteMode('edit'); if($('jp-cancel-edit'))$('jp-cancel-edit').onclick=()=>setNoteMode('view'); if($('jp-title'))$('jp-title').addEventListener('input',()=>{markDirty();updateNotePath();}); if($('jp-body'))$('jp-body').addEventListener('input',()=>{markDirty();});
  if($('jp-delete-note'))$('jp-delete-note').onclick=()=>deleteNote().catch(e=>jpAlert(e.message));
  if($('jp-trash-restore'))$('jp-trash-restore').onclick=()=>{if(state.note&&state.note.is_deleted)restoreTrashedNote(state.note.id).catch(e=>jpAlert(e.message));};
  if($('jp-trash-purge'))$('jp-trash-purge').onclick=()=>{if(state.note&&state.note.is_deleted)purgeTrashedNote(state.note.id,state.note.title).catch(e=>jpAlert(e.message));};
  if($('jp-empty-trash'))$('jp-empty-trash').onclick=()=>emptyTrash().catch(e=>jpAlert(e.message));
  if($('jp-rebuild-index'))$('jp-rebuild-index').onclick=()=>rebuildAndRestore().catch(e=>jpAlert(e.message));
  if($('jp-resource-file'))$('jp-resource-file').onchange=e=>{if(e.target.files[0])uploadResource(e.target.files[0]).catch(err=>jpAlert(err.message));};
  if($('jp-attach-nextcloud'))$('jp-attach-nextcloud').onclick=()=>attachFromNextcloud().catch(err=>jpAlert(err.message));
  if($('jp-search'))$('jp-search').addEventListener('input',e=>runFullTextSearch(e.target.value));
  if($('jp-sort-button'))$('jp-sort-button').onclick=()=>{state.sort=state.sort==='updated_desc'?'updated_asc':'updated_desc';persistSettings({sort_order:state.sort}).catch(()=>{});if(state.notebookId)selectNotebook(state.notebookId);};
  updateSaveButton(); load().catch(e=>jpAlert(e.message)).then(()=>{if(!state.notebookId)setNavOpen(true);}).then(()=>loadSyncStatus()).then(()=>{if(syncEnabled()){syncState.lastAuto=Date.now();runSync().catch(()=>{});}});
});
})();

