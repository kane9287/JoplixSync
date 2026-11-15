<?php
namespace OCA\Joplix\Controller;
use OCA\Joplix\AppInfo\Application; use OCA\Joplix\Service\JoplixDevUpdateService; use OCA\Joplix\Service\JoplixFolders; use OCA\Joplix\Service\JoplixIndexService; use OCA\Joplix\Service\JoplixModelService; use OCA\Joplix\Service\JoplixWriteService; use OCA\Joplix\Service\JoplixWorkspaceService; use OCA\Joplix\Service\JoplixSyncService; use OCA\Joplix\Service\JoplixWriteLock;
use OCP\AppFramework\Controller; use OCP\AppFramework\Http\JSONResponse; use OCP\Files\Folder; use OCP\Files\IRootFolder; use OCP\IConfig; use OCP\IRequest; use OCP\IUserSession; use OCP\IGroupManager; use OCP\IL10N; use OCP\Files\AppData\IAppDataFactory; use OCA\Joplix\Service\ConflictException; use OCP\App\IAppManager;
/**
 * Every endpoint of the browser app. Writes to the Joplin folder go through write(): under the per-user
 * write lock (shared with the sync engine), on a model read inside that lock, followed by an index refresh.
 * GET endpoints read only (the cached index in app data is filled on a miss, nothing in the user's files).
 */
class ApiController extends Controller{ private IRootFolder $rootFolder; private IConfig $config; private IUserSession $userSession; private IGroupManager $groupManager; private JoplixSyncService $syncService; private JoplixWriteLock $writeLock; private IAppDataFactory $appDataFactory; private IL10N $l; private IAppManager $appManager; private JoplixFolders $folders;
 public function __construct(string $AppName,IRequest $request,IRootFolder $rootFolder,IConfig $config,IUserSession $userSession,IGroupManager $groupManager,JoplixSyncService $syncService,JoplixWriteLock $writeLock,IAppDataFactory $appDataFactory,IL10N $l,IAppManager $appManager,JoplixFolders $folders){parent::__construct($AppName,$request);$this->folders=$folders;$this->appManager=$appManager;$this->l=$l;$this->appDataFactory=$appDataFactory;$this->writeLock=$writeLock;$this->syncService=$syncService;$this->rootFolder=$rootFolder;$this->config=$config;$this->userSession=$userSession;$this->groupManager=$groupManager;}
 private function uid(): string{$u=$this->userSession->getUser();if($u===null)throw new \RuntimeException($this->l->t('Not logged in'));return $u->getUID();}
 private function isAdmin(): bool{return $this->groupManager->isAdmin($this->uid());} private function setting(string $k,string $d=''): string{return $this->config->getUserValue($this->uid(),Application::APP_ID,$k,$d);} private function setSetting(string $k,string $v): void{$this->config->setUserValue($this->uid(),Application::APP_ID,$k,$v);} private function userFolder(): Folder{return $this->rootFolder->getUserFolder($this->uid());}
 private function joplinPath(): string{return $this->folders->joplinPath($this->uid());} private function joplinFolder(): Folder{return $this->folders->joplinFolder($this->uid());}
 private function workspace(): JoplixWorkspaceService{return new JoplixWorkspaceService($this->rootFolder,$this->config,$this->uid());}
 /** One parsed model per request; dropped whenever the folder may have changed (locked(), write()). */
 private ?JoplixModelService $model=null;
 private function model(): JoplixModelService{return $this->model??=new JoplixModelService($this->joplinFolder(),$this->l);}
 private function indexSvc(): JoplixIndexService{return new JoplixIndexService($this->appDataFactory->get(Application::APP_ID),$this->uid(),$this->joplinPath(),$this->userFolder());} private function devSvc(): JoplixDevUpdateService{return new JoplixDevUpdateService($this->userFolder(),$this->setting('dev_folder','/.joplix/updates'),$this->config,$this->setting('update_backup_enabled','no')==='yes');} private function writer(): JoplixWriteService{return new JoplixWriteService($this->model(),$this->l);}
 /** Every change to the Joplin folder runs inside this: the model is read, checked and written under the per-user write lock that the sync engine takes per item (JoplixWriteLock). */
 private function locked(callable $fn){return $this->writeLock->run($this->uid(),function() use ($fn){$this->model=null;try{$r=$fn();}finally{$this->model=null;}$this->maintenanceIfDue();return $r;});}
 /** A change through JoplixWriteService, then the cached index rebuilt from the files as they are now. */
 private function write(callable $change): JSONResponse{return $this->locked(function() use ($change){$r=$change($this->writer());$this->model=null;$this->storeIndex();return new JSONResponse($r);});}
 private function storeIndex(): array{$idx=$this->model()->fullIndex();$this->indexSvc()->write($idx);return $idx;}
 /** After a write, at most once a day per user: old backup copies (JoplixWriteService, "Backup retention") and leftovers of older versions (JoplixWorkspaceService::removeLegacy). */
 private function maintenanceIfDue(): void{
  try{
   if((int)$this->setting('backups_pruned_at','0')>time()-86400)return;
   $this->setSetting('backups_pruned_at',(string)time());
   JoplixWriteService::pruneBackupFolder($this->joplinFolder());
   $this->workspace()->removeLegacy();
  }catch(\Throwable $e){}
 }
 /**
  * Office editors Joplix can hand a working copy to, in preference order, with the page that opens a file by id.
  * richdocuments is Nextcloud Office; it covers both the built-in CODE server and a separate Collabora Online.
  * It has no stand-alone editor page any more: /apps/richdocuments/index needs a CSRF token (fails on navigation),
  * and its own "edit online" routes redirect to /f/<id>, i.e. the Files app, whose viewer opens Collabora. That works
  * for files in the hidden .joplix folder too (tested with richdocuments 9.2 on Nextcloud 32).
  * All of them run as a top-level page, so the browser's window-closed watcher works the same for each.
  */
 private const OFFICE_EDITORS=['eurooffice'=>'/apps/eurooffice/{id}','richdocuments'=>'/f/{id}','onlyoffice'=>'/apps/onlyoffice/{id}'];
 /** Legacy formats ONLYOFFICE only opens by converting into a new file, which the import never sees. */
 private const ONLYOFFICE_CONVERTS=['doc','xls','ppt'];
 /** @return string[] ids of the editors enabled for the current user, in preference order */
 private function officeEditors(): array{$u=$this->userSession->getUser();if($u===null)return [];$out=[];foreach(array_keys(self::OFFICE_EDITORS) as $app){try{if($this->appManager->isEnabledForUser($app,$u))$out[]=$app;}catch(\Throwable $e){}}return $out;}
 /** The editor to use: the user's choice while it is still installed, else the first available one. */
 private function officeEditor(): ?string{$all=$this->officeEditors();$pick=$this->setting('office_editor','auto');if(in_array($pick,$all,true))return $pick;return $all[0]??null;}
 /**
  * Error answer for the browser. Programming errors (\Error: type errors, undefined calls) are logged and
  * answered with a generic text; other messages are passed on with server paths (data directory, server
  * root) removed, since updater and file-system errors contain them.
  */
 private function err(\Throwable $e,?int $s=null): JSONResponse{
  if($e instanceof \Error){
   \OCP\Server::get(\Psr\Log\LoggerInterface::class)->error('Joplix: '.$e->getMessage(),['app'=>Application::APP_ID,'exception'=>$e]);
   return new JSONResponse(['error'=>$this->l->t('An internal error occurred. Details are in the Nextcloud log.')],500);
  }
  $message=$e->getMessage();
  foreach([$this->config->getSystemValueString('datadirectory',''),dirname(__DIR__,4)] as $root){if(strlen($root)>1)$message=str_replace(rtrim($root,'/'),'…',$message);}
  return new JSONResponse(['error'=>$message],$s??($e instanceof ConflictException?409:400));
 }
 /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function getSettings(): JSONResponse{try{return new JSONResponse(['joplin_path'=>$this->joplinPath(),'sort_order'=>$this->setting('sort_order','updated_desc'),'remember_last_location'=>$this->setting('remember_last_location','yes'),'last_notebook_id'=>$this->setting('last_notebook_id',''),'last_note_id'=>$this->setting('last_note_id',''),'dev_enabled'=>$this->isAdmin()?$this->setting('dev_enabled','no'):'no','dev_folder'=>$this->isAdmin()?$this->setting('dev_folder','/.joplix/updates'):'/.joplix/updates','first_run_completed'=>$this->setting('first_run_completed','no'),'update_repo_url'=>JoplixDevUpdateService::repoUrlOrDefault($this->setting('update_repo_url','')),'update_channel'=>$this->setting('update_channel','main'),'office_open_mode'=>$this->setting('office_open_mode','window'),'office_editor'=>$this->setting('office_editor','auto'),'office_editors'=>$this->officeEditors(),'update_backup_enabled'=>$this->setting('update_backup_enabled','no'),'is_admin'=>$this->isAdmin()]);}catch(\Throwable $e){return $this->err($e);}}
 /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function folders(): JSONResponse{try{$out=[];
  // Suggestions for the folder field: three levels, hidden folders skipped, at most 1000 entries, so a large
  // file tree does not make the settings slow (it used to walk five levels of everything).
  $walk=function($folder,$path,$depth) use (&$walk,&$out){if($depth>2||count($out)>=1000)return;foreach($folder->getDirectoryListing() as $node){if(count($out)>=1000)return;if($node instanceof Folder&&$node->getName()!==''&&$node->getName()[0]!=='.'){$p=$path.'/'.$node->getName();$out[]=$p;$walk($node,$p,$depth+1);}}};$walk($this->userFolder(),'',0);sort($out,SORT_NATURAL|SORT_FLAG_CASE);return new JSONResponse(['folders'=>$out,'current'=>$this->joplinPath()]);}catch(\Throwable $e){return $this->err($e);}}
 /**
  * @NoAdminRequired
  */
 public function saveSettings(): JSONResponse {
     try {
         $keys = ['joplin_path', 'sort_order', 'remember_last_location', 'first_run_completed', 'office_open_mode', 'office_editor'];
         $isAdmin = $this->isAdmin();
         if ($isAdmin) {
             $keys = array_merge($keys, ['dev_enabled', 'dev_folder', 'update_repo_url', 'update_channel', 'update_backup_enabled']);
         }
         $values = [];
         foreach ($keys as $key) {
             $value = $this->request->getParam($key);
             if ($value !== null) {
                 $values[$key] = (string)$value;
             }
         }
         if (isset($values['joplin_path'])) {
             $values['joplin_path'] = JoplixFolders::normalizePath($values['joplin_path']);
         }
         if (isset($values['update_repo_url'])) {
             $values['update_repo_url'] = JoplixDevUpdateService::normalizeRepo($values['update_repo_url'])['url'];
         }
         if (isset($values['office_editor']) && $values['office_editor'] !== 'auto' && !array_key_exists($values['office_editor'], self::OFFICE_EDITORS)) {
             throw new \RuntimeException($this->l->t('Invalid office editor.'));
         }
         if (isset($values['update_channel']) && !in_array($values['update_channel'], ['main', 'beta'], true)) {
             throw new \RuntimeException($this->l->t('Invalid update channel.'));
         }
         foreach ($values as $key => $value) {
             $this->setSetting($key, $value);
         }
         if ($isAdmin && in_array($this->setting('dev_folder', ''), ['', '/JoplixUpdates'], true)) {
             $this->setSetting('dev_folder', '/.joplix/updates');
         }
         return new JSONResponse(['ok' => true]);
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }
 /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function index(): JSONResponse{try{$idx=$this->indexSvc()->read();if(!$idx)$idx=$this->storeIndex();return new JSONResponse($idx);}catch(\Throwable $e){return $this->err($e);}}
 /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function indexStatus(): JSONResponse{try{return new JSONResponse($this->indexSvc()->status(JoplixModelService::fingerprintOf($this->joplinFolder())));}catch(\Throwable $e){return $this->err($e);}}
 /**
  * @NoAdminRequired
  */
 public function rebuildIndex(): JSONResponse{try{return new JSONResponse(['ok'=>true,'index'=>$this->storeIndex()]);}catch(\Throwable $e){return $this->err($e);}}
 /**
  * @NoAdminRequired
  */
 public function saveLastLocation(): JSONResponse{try{$this->setSetting('last_notebook_id',(string)$this->request->getParam('notebook_id',''));$this->setSetting('last_note_id',(string)$this->request->getParam('note_id',''));return new JSONResponse(['ok'=>true]);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 * @NoCSRFRequired
 */ public function getNote(string $id): JSONResponse{try{$n=$this->model()->note($id);return $n?new JSONResponse($n):new JSONResponse(['error'=>$this->l->t('Note not found')],404);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function createNote(): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$w->createNote((string)$this->request->getParam('title',$this->l->t('New note')),(string)$this->request->getParam('body',''),(string)$this->request->getParam('parent_id','')));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function updateNote(string $id): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$w->updateNote($id,(string)$this->request->getParam('title',''),(string)$this->request->getParam('body',''),(string)$this->request->getParam('known_updated_time','')));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function deleteNote(string $id): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$this->permanent()?$w->deleteNote($id):$w->trashNote($id));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function moveNote(string $id): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$w->moveNote($id,(string)$this->request->getParam('target_notebook_id','')));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function createNotebook(): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$w->createNotebook((string)$this->request->getParam('title',$this->l->t('New notebook')),(string)$this->request->getParam('parent_id','')));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function updateNotebook(string $id): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$w->updateNotebook($id,(string)$this->request->getParam('title',''),(string)$this->request->getParam('known_updated_time','')));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function deleteNotebook(string $id): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$this->permanent()?$w->deleteNotebook($id):$w->trashNotebook($id));}catch(\Throwable $e){return $this->err($e);}}
 /** DELETE moves to Joplin's trash; ?permanent=1 removes the files. */
 private function permanent(): bool{return (string)$this->request->getParam('permanent','')==='1';}
 /**
 * @NoAdminRequired
 */ public function restoreNote(string $id): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$w->restoreNote($id,(string)$this->request->getParam('target_notebook_id','')));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function restoreNotebook(string $id): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$w->restoreNotebook($id));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function emptyTrash(): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$w->emptyTrash());}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 * @NoCSRFRequired
 */ public function tags(): JSONResponse{try{return new JSONResponse(array_values($this->model()->tagsRaw()));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function createTag(): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$w->createTag((string)$this->request->getParam('title',$this->l->t('New tag'))));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function saveNoteTags(string $id): JSONResponse{try{$tags=$this->request->getParam('tag_ids',[]);if(!is_array($tags))$tags=[];return $this->write(fn(JoplixWriteService $w)=>$w->saveNoteTags($id,$tags));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function removeNoteTag(string $id,string $tagId): JSONResponse{try{return $this->write(fn(JoplixWriteService $w)=>$w->removeNoteTag($id,$tagId));}catch(\Throwable $e){return $this->err($e);}}
 /**
  * Assigns a tag by name, creating it when no tag of that name (case-insensitive) exists yet.
  * @NoAdminRequired
  */
 public function createAndAssignTag(string $id): JSONResponse{
  try{
   $title=trim((string)$this->request->getParam('title',''));
   if($title==='')throw new \RuntimeException($this->l->t('The tag name must not be empty.'));
   return $this->write(function(JoplixWriteService $writer) use ($id,$title){
    $tagId='';$canonicalTitle=$title;$created=false;
    foreach($this->model()->tagsRaw() as $tag){$existingTitle=trim((string)($tag['_title']??$tag['_body']??''));if($existingTitle!==''&&mb_strtolower($existingTitle,'UTF-8')===mb_strtolower($title,'UTF-8')){$tagId=(string)($tag['id']??'');$canonicalTitle=$existingTitle;break;}}
    if($tagId===''){
     $tagId=(string)($writer->createTag($title)['id']??'');$created=true;
     // The writer's model was read before the tag existed; assigning it needs one that knows it.
     $this->model=null;$writer=$this->writer();
    }
    if($tagId==='')throw new \RuntimeException($this->l->t('The tag could not be created.'));
    $writer->saveNoteTags($id,[$tagId]);
    return ['ok'=>true,'id'=>$tagId,'title'=>$canonicalTitle,'created'=>$created];
   });
  }catch(\Throwable $e){return $this->err($e);}
 }
 /**
 * @NoAdminRequired
 */ public function uploadResource(): JSONResponse{
  try{
   // Multipart upload (field "file"), streamed from PHP's temporary file. Until 2026.11.81 the browser sent
   // base64 inside JSON, which needed several times the file size in memory and had no limit.
   $f=$this->request->getUploadedFile('file');
   $code=is_array($f)?(int)($f['error']??UPLOAD_ERR_NO_FILE):UPLOAD_ERR_NO_FILE;
   if($code===UPLOAD_ERR_INI_SIZE||$code===UPLOAD_ERR_FORM_SIZE)throw new \RuntimeException($this->l->t('The file is larger than this server accepts for uploads (%1$s). Upload it to Nextcloud and use "Attach from Nextcloud" instead.',[(string)ini_get('upload_max_filesize')]));
   if($code!==UPLOAD_ERR_OK||!is_uploaded_file((string)($f['tmp_name']??'')))throw new \RuntimeException($this->l->t('The file could not be uploaded.'));
   $name=(string)$this->request->getParam('filename',(string)($f['name']??'upload.bin'));
   $mime=(string)$this->request->getParam('mime',(string)($f['type']??'application/octet-stream'));
   return $this->write(fn(JoplixWriteService $w)=>$w->createResourceFromStream($name,$mime,fopen((string)$f['tmp_name'],'rb'),(int)($f['size']??0)));
  }catch(\Throwable $e){return $this->err($e);}
 }

 // ---- Attach from Nextcloud. Paths are relative to the user's files root ("/Documents/a.pdf").
 // The Joplin sync folder and the .joplix workspace are not offered and cannot be attached from.
 private function cleanFilesPath(string $path): string{
  $parts=[];foreach(explode('/',str_replace('\\','/',$path)) as $p){if($p===''||$p==='.')continue;if($p==='..')throw new \RuntimeException($this->l->t('Invalid path'));$parts[]=$p;}
  return '/'.implode('/',$parts);
 }
 private function isHiddenFilesPath(string $path): bool{
  foreach([$this->joplinPath(),'/.joplix'] as $root){if($path===$root||str_starts_with($path,rtrim($root,'/').'/'))return true;}
  return false;
 }
 /** True for files of a share whose owner switched off downloading: copying one into a note would bypass that. */
 private function downloadForbidden(\OCP\Files\Node $node): bool{
  try{
   $storage=$node->getStorage();
   if(!$storage->instanceOfStorage(\OCP\Files\Storage\ISharedStorage::class)||!method_exists($storage,'getShare'))return false;
   $attributes=$storage->getShare()->getAttributes();
   return $attributes!==null&&$attributes->getAttribute('permissions','download')===false;
  }catch(\Throwable $e){return false;}
 }
 private function filesNode(string $path){$uf=$this->userFolder();if($path==='/')return $uf;try{return $uf->get(ltrim($path,'/'));}catch(\OCP\Files\NotFoundException $e){throw new \RuntimeException($this->l->t('Not found: %1$s',[$path]));}}
 /**
 * @NoAdminRequired
 * @NoCSRFRequired
 */ public function browseFiles(): JSONResponse{
  try{
   $path=$this->cleanFilesPath((string)$this->request->getParam('path','/'));
   if($this->isHiddenFilesPath($path))throw new \RuntimeException($this->l->t('This folder cannot be shown'));
   $node=$this->filesNode($path);
   if(!$node instanceof Folder)throw new \RuntimeException($this->l->t('Not a folder: %1$s',[$path]));
   $entries=[];
   foreach($node->getDirectoryListing() as $child){
    $name=$child->getName();if($name===''||$name[0]==='.')continue;
    $p=rtrim($path,'/').'/'.$name;if($this->isHiddenFilesPath($p))continue;
    $isDir=$child instanceof Folder;
    if(!$isDir&&$this->downloadForbidden($child))continue;
    $entries[]=['name'=>$name,'path'=>$p,'type'=>$isDir?'dir':'file','mime'=>$isDir?'':(string)$child->getMimeType(),'size'=>$isDir?0:(int)$child->getSize(),'mtime'=>(int)$child->getMTime()];
   }
   usort($entries,fn($a,$b)=>(($a['type']==='dir'?0:1)<=>($b['type']==='dir'?0:1))?:strnatcasecmp($a['name'],$b['name']));
   $parent=$path==='/'?null:dirname($path);
   return new JSONResponse(['path'=>$path,'parent'=>$parent,'entries'=>$entries]);
  }catch(\Throwable $e){return $this->err($e);}
 }
 /**
 * @NoAdminRequired
 */ public function attachNextcloudFile(): JSONResponse{
  try{
   $path=$this->cleanFilesPath((string)$this->request->getParam('path',''));
   if($path==='/'||$this->isHiddenFilesPath($path))throw new \RuntimeException($this->l->t('This file cannot be attached'));
   $node=$this->filesNode($path);
   if(!$node instanceof \OCP\Files\File)throw new \RuntimeException($this->l->t('Not a file: %1$s',[$path]));
   if($this->downloadForbidden($node))throw new \RuntimeException($this->l->t('The owner of this share does not allow downloading it, so it cannot be attached.'));
   return $this->write(fn(JoplixWriteService $w)=>$w->createResourceFromStream($node->getName(),(string)$node->getMimeType(),$node->fopen('r'),(int)$node->getSize()));
  }catch(\Throwable $e){return $this->err($e);}
 }


 public function updateReleases(): JSONResponse {
     try {
         if (!$this->isAdmin()) {
             return new JSONResponse(['error' => $this->l->t('Administrator rights required.')], 403);
         }
         $service = $this->devSvc();
         return new JSONResponse($service->remoteReleases(
             $this->setting('update_repo_url', ''),
             $this->setting('update_channel', 'main'),
             $service->installedVersion()
         ));
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }

 public function updateInstall(): JSONResponse {
     try {
         if (!$this->isAdmin()) {
             return new JSONResponse(['error' => $this->l->t('Administrator rights required.')], 403);
         }
         $releaseId = filter_var($this->request->getParam('release_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
         $assetId = filter_var($this->request->getParam('asset_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
         if ($releaseId === false || $assetId === false) {
             throw new \RuntimeException($this->l->t('Please reload the release list and select a package.'));
         }
         return new JSONResponse($this->devSvc()->installRemoteAsset(
             $this->setting('update_repo_url', ''),
             $this->setting('update_channel', 'main'),
             $releaseId,
             $assetId
         ));
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }

 public function devPackages(): JSONResponse {
     try {
         if (!$this->isAdmin()) {
             return new JSONResponse(['error' => $this->l->t('Administrator rights required.')], 403);
         }
         if ($this->setting('dev_enabled', 'no') !== 'yes') {
             return new JSONResponse(['enabled' => false, 'packages' => []]);
         }
         return new JSONResponse([
             'enabled' => true,
             'folder' => $this->setting('dev_folder', '/.joplix/updates'),
             'packages' => $this->devSvc()->listPackages(),
         ]);
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }

 public function devDeletePackage(): JSONResponse {
     try {
         if (!$this->isAdmin()) {
             return new JSONResponse(['error' => $this->l->t('Administrator rights required.')], 403);
         }
         if ($this->setting('dev_enabled', 'no') !== 'yes') {
             throw new \RuntimeException($this->l->t('The development environment is not enabled'));
         }
         return new JSONResponse($this->devSvc()->deletePackage((string)$this->request->getParam('name', '')));
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }

 public function devInstallPackage(): JSONResponse {
     try {
         if (!$this->isAdmin()) {
             return new JSONResponse(['error' => $this->l->t('Administrator rights required.')], 403);
         }
         if ($this->setting('dev_enabled', 'no') !== 'yes') {
             throw new \RuntimeException($this->l->t('The development environment is not enabled'));
         }
         return new JSONResponse($this->devSvc()->installPackage((string)$this->request->getParam('name', '')));
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }

/**
 * @NoAdminRequired
 * @NoCSRFRequired
 */ public function resourceData(string $id): JSONResponse{try{$p=$this->model()->resourcePayload($id);return $p?new JSONResponse($p):new JSONResponse(['error'=>$this->l->t('Attachment not found')],404);}catch(\Throwable $e){return $this->err($e);}}
 /**
  * The attachment's bytes, streamed. Always a download of type application/octet-stream with nosniff: the
  * browser builds a typed blob itself (js/app.js resourceData), and an attachment opened by URL directly
  * must never be rendered as HTML or SVG in Nextcloud's origin.
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function resourceFile(string $id): \OCP\AppFramework\Http\Response{
  try{
   $node=$this->model()->resourceFile($id);
   if($node===null)return new JSONResponse(['error'=>$this->l->t('Attachment not found')],404);
   $stream=$node->fopen('rb');
   if(!is_resource($stream))throw new \RuntimeException($this->l->t('The attachment could not be read.'));
   $r=new \OCP\AppFramework\Http\StreamResponse($stream);
   $r->addHeader('Content-Type','application/octet-stream');
   $r->addHeader('Content-Disposition','attachment; filename="'.$id.'"');
   $r->addHeader('Content-Length',(string)$node->getSize());
   $r->addHeader('X-Content-Type-Options','nosniff');
   $r->cacheFor(0);
   return $r;
  }catch(\Throwable $e){return $this->err($e);}
 }
 /**
 * @NoAdminRequired
 */ public function startOfficeEdit(string $id): JSONResponse{try{
  $payload=$this->model()->resourcePayload($id);
  if(!$payload||isset($payload['error']))throw new \RuntimeException((string)($payload['error']??$this->l->t('Attachment not found')));
  $ext=strtolower((string)($payload['file_extension']??''));
  if(!in_array($ext,JoplixModelService::OFFICE_EXTENSIONS,true))throw new \RuntimeException($this->l->t('This attachment is not a supported Office file.'));
  $editor=$this->officeEditor();
  if($editor===null)throw new \RuntimeException($this->l->t('No office editor is installed. Ask your administrator to enable Nextcloud Office, ONLYOFFICE or Euro Office.'));
  if($editor==='onlyoffice'&&in_array($ext,self::ONLYOFFICE_CONVERTS,true))throw new \RuntimeException($this->l->t('ONLYOFFICE can only edit .%1$s files by converting them to a new file, so the changes would not reach the attachment. Download the file instead, or choose another editor in the settings.',[$ext]));
  $node=$this->model()->resourceFile($id);
  $binary=$node!==null?$node->getContent():false;
  if(!is_string($binary))throw new \RuntimeException($this->l->t('The attachment could not be read.'));
  $editing=$this->workspace()->editingFolder();$this->cleanupEditingSessions($editing);
  $sessionId=bin2hex(random_bytes(16));$sessionFolder=$editing->newFolder($sessionId);
  $title=preg_replace('/[\\\/:*?"<>|]+/','_',trim((string)($payload['title']??$id)))?:$id;
  if($ext!==''&&!str_ends_with(strtolower($title),'.'.$ext))$title.='.'.$ext;
  $file=$sessionFolder->newFile($title,$binary);$hash=hash('sha256',$binary);
  $session=['session_id'=>$sessionId,'resource_id'=>$id,'file_name'=>$title,'file_id'=>$file->getId(),'working_hash'=>$hash,'resource_hash'=>$hash,'started_at'=>time(),'last_import_at'=>0];
  $sessions=json_decode($this->setting('office_sessions','{}'),true);if(!is_array($sessions))$sessions=[];$sessions[$id]=$session;
  $this->setSetting('office_sessions',json_encode($sessions,JSON_UNESCAPED_SLASHES));
  return new JSONResponse(['ok'=>true,'resource_id'=>$id,'session_id'=>$sessionId,'file_id'=>$file->getId(),'file_name'=>$title,'edit_dir'=>'/.joplix/editing/'.$sessionId,'editor'=>$editor,'editor_path'=>str_replace('{id}',(string)$file->getId(),self::OFFICE_EDITORS[$editor])]);
 }catch(\Throwable $e){return $this->err($e);}}

 /**
  * Imports the working copy when it changed. POST since it writes (CSRF-protected); the browser polls it.
 * @NoAdminRequired
 */ public function officeEditStatus(string $id): JSONResponse{try{
  $sessions=json_decode($this->setting('office_sessions','{}'),true);if(!is_array($sessions)||!isset($sessions[$id]))return new JSONResponse(['active'=>false]);
  $session=$sessions[$id];$editing=$this->workspace()->editingFolder();$this->cleanupEditingSessions($editing);
  $sessionId=(string)($session['session_id']??'');
  if($sessionId===''||!$editing->nodeExists($sessionId))return new JSONResponse(['active'=>false,'error'=>$this->l->t('The editing session is missing')]);
  $folder=$editing->get($sessionId);if(!$folder instanceof Folder||!$folder->nodeExists((string)$session['file_name']))return new JSONResponse(['active'=>false,'error'=>$this->l->t('The working file is missing')]);
  $file=$folder->get((string)$session['file_name']);if(!$file instanceof \OCP\Files\File)return new JSONResponse(['active'=>false,'error'=>$this->l->t('The working file is not valid')]);
  $binary=$file->getContent();$hash=hash('sha256',$binary);$updated=false;
  if(!hash_equals((string)$session['working_hash'],$hash)){
    $result=$this->locked(fn()=>$this->writer()->updateResourceBinary($id,$binary,(string)$session['resource_hash']));
    $session['working_hash']=$hash;$session['resource_hash']=$result['sha256'];$session['last_import_at']=time();$sessions[$id]=$session;
    $this->setSetting('office_sessions',json_encode($sessions,JSON_UNESCAPED_SLASHES));$updated=!((bool)($result['already_applied']??false));
    $alreadyApplied=(bool)($result['already_applied']??false);
  }
  return new JSONResponse(['active'=>true,'updated'=>$updated,'already_applied'=>$alreadyApplied??false,'file_name'=>$session['file_name'],'last_import_at'=>$session['last_import_at']??0,'sha256'=>$session['resource_hash'],'working_sha256'=>$hash,'working_mtime'=>$file->getMTime(),'working_size'=>$file->getSize()]);
 }catch(\Throwable $e){return $this->err($e);}}

 /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function syncStatus(): JSONResponse {
     try {
         $status = $this->syncService->status($this->uid());
         $status['is_admin'] = $this->isAdmin();
         return new JSONResponse($status);
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }

 /**
  * @NoAdminRequired
  */
 public function saveSyncSettings(): JSONResponse {
     try {
         $values = [];
         foreach (['enabled', 'server_url', 'email', 'password', 'allow_local'] as $key) {
             $values[$key] = $this->request->getParam($key);
         }
         $this->syncService->saveSettings($this->uid(), $values, $this->isAdmin());
         $status = $this->syncService->status($this->uid());
         $status['is_admin'] = $this->isAdmin();
         return new JSONResponse($status);
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }

 /**
  * @NoAdminRequired
  */
 public function testSync(): JSONResponse {
     try {
         return new JSONResponse($this->syncService->test($this->uid()));
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }

 /**
  * @NoAdminRequired
  */
 public function runSync(): JSONResponse {
     try {
         $confirm = in_array($this->request->getParam('confirm_delete'), [true, 1, '1', 'yes', 'true'], true);
         // Short budget per request; the browser repeats while the result is "partial".
         return new JSONResponse($this->syncService->run($this->uid(), 20, $confirm));
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }

 private function cleanupEditingSessions(Folder $editing): void{
  $cutoff=time()-86400;foreach($editing->getDirectoryListing() as $node){if(!$node instanceof Folder)continue;if($node->getMTime()<$cutoff){try{$node->delete();}catch(\Throwable $e){}}}
 }

}
