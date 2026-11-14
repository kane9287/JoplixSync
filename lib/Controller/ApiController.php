<?php
namespace OCA\Joplix\Controller;
use OCA\Joplix\AppInfo\Application; use OCA\Joplix\Service\JoplixDevUpdateService; use OCA\Joplix\Service\JoplixIndexService; use OCA\Joplix\Service\JoplixModelService; use OCA\Joplix\Service\JoplixWriteService; use OCA\Joplix\Service\JoplixWorkspaceService;
use OCP\AppFramework\Controller; use OCP\AppFramework\Http\JSONResponse; use OCP\Files\Folder; use OCP\Files\IRootFolder; use OCP\IConfig; use OCP\IRequest; use OCP\IUserSession; use OCP\IGroupManager;
class ApiController extends Controller{ private IRootFolder $rootFolder; private IConfig $config; private IUserSession $userSession; private IGroupManager $groupManager;
 public function __construct(string $AppName,IRequest $request,IRootFolder $rootFolder,IConfig $config,IUserSession $userSession,IGroupManager $groupManager){parent::__construct($AppName,$request);$this->rootFolder=$rootFolder;$this->config=$config;$this->userSession=$userSession;$this->groupManager=$groupManager;}
 private function uid(): string{$u=$this->userSession->getUser();if($u===null)throw new \RuntimeException('Nicht angemeldet');return $u->getUID();}
 private function isAdmin(): bool{return $this->groupManager->isAdmin($this->uid());} private function setting(string $k,string $d=''): string{return $this->config->getUserValue($this->uid(),Application::APP_ID,$k,$d);} private function setSetting(string $k,string $v): void{$this->config->setUserValue($this->uid(),Application::APP_ID,$k,$v);} private function userFolder(): Folder{return $this->rootFolder->getUserFolder($this->uid());}
 private function joplinPath(): string{$p=$this->setting('joplin_path','/JoplinV2');$p='/'.trim($p,'/');return $p==='/'?'/JoplinV2':$p;} private function joplinFolder(): Folder{$rel=trim($this->joplinPath(),'/');$uf=$this->userFolder();$n=$rel===''?$uf:$uf->get($rel);if(!$n instanceof Folder)throw new \RuntimeException('Joplix-Pfad ist kein Ordner: '.$this->joplinPath());return $n;}
 private function workspace(): JoplixWorkspaceService{return new JoplixWorkspaceService($this->rootFolder,$this->config,$this->uid());} private function model(): JoplixModelService{return new JoplixModelService($this->joplinFolder());} private function indexSvc(): JoplixIndexService{return new JoplixIndexService($this->userFolder(),$this->joplinPath());} private function devSvc(): JoplixDevUpdateService{return new JoplixDevUpdateService($this->userFolder(),$this->setting('dev_folder','/.joplix/updates'),$this->config,$this->setting('update_backup_enabled','no')==='yes');} private function writer(): JoplixWriteService{return new JoplixWriteService($this->model());} private function err(\Throwable $e,int $s=400): JSONResponse{return new JSONResponse(['error'=>$e->getMessage()],$s);}
 /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function getSettings(): JSONResponse{$this->workspace()->ensureWorkspace();return new JSONResponse(['joplin_path'=>$this->joplinPath(),'sort_order'=>$this->setting('sort_order','updated_desc'),'remember_last_location'=>$this->setting('remember_last_location','yes'),'last_notebook_id'=>$this->setting('last_notebook_id',''),'last_note_id'=>$this->setting('last_note_id',''),'dev_enabled'=>$this->isAdmin()?$this->setting('dev_enabled','no'):'no','dev_folder'=>$this->isAdmin()?$this->setting('dev_folder','/.joplix/updates'):'/.joplix/updates','first_run_completed'=>$this->setting('first_run_completed','no'),'update_source'=>$this->setting('update_source','codeberg'),'update_repo_url'=>$this->setting('update_repo_url','https://codeberg.org/Stoneubi/joplix/releases'),'update_channel'=>$this->setting('update_channel','main'),'office_open_mode'=>$this->setting('office_open_mode','window'),'update_backup_enabled'=>$this->setting('update_backup_enabled','no'),'language'=>$this->setting('language','system'),'is_admin'=>$this->isAdmin()]);}
 /**
  * @NoAdminRequired
  */

 /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function folders(): JSONResponse{try{$out=[];$walk=function($folder,$path,$depth) use (&$walk,&$out){if($depth>4)return;foreach($folder->getDirectoryListing() as $node){if($node instanceof Folder){$p=$path.'/'.$node->getName();$out[]=$p;$walk($node,$p,$depth+1);}}};$walk($this->userFolder(),'',0);sort($out,SORT_NATURAL|SORT_FLAG_CASE);return new JSONResponse(['folders'=>$out,'current'=>$this->joplinPath()]);}catch(\Throwable $e){return $this->err($e);}}
 /**
  * @NoAdminRequired
  */
 public function saveSettings(): JSONResponse {
     try {
         $keys = ['joplin_path', 'sort_order', 'remember_last_location', 'first_run_completed', 'office_open_mode', 'language'];
         $isAdmin = $this->isAdmin();
         if ($isAdmin) {
             $keys = array_merge($keys, ['dev_enabled', 'dev_folder', 'update_source', 'update_repo_url', 'update_channel', 'update_backup_enabled']);
         }
         $values = [];
         foreach ($keys as $key) {
             $value = $this->request->getParam($key);
             if ($value !== null) {
                 $values[$key] = (string)$value;
             }
         }
         if (isset($values['update_repo_url'])) {
             $values['update_repo_url'] = JoplixDevUpdateService::normalizeRepo($values['update_repo_url'])['url'];
         }
         if (isset($values['update_channel']) && !in_array($values['update_channel'], ['main', 'beta'], true)) {
             throw new \RuntimeException('Ungueltiger Update-Kanal.');
         }
         foreach ($values as $key => $value) {
             $this->setSetting($key, $value);
         }
         if ($isAdmin && in_array($this->setting('dev_folder', ''), ['', '/JoplixUpdates'], true)) {
             $this->setSetting('dev_folder', '/.joplix/updates');
         }
         $this->workspace()->ensureWorkspace();
         return new JSONResponse(['ok' => true]);
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }
 /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function index(): JSONResponse{try{$this->workspace()->ensureWorkspace();$idx=$this->indexSvc()->read(); if(!$idx){$m=$this->model();$idx=$m->fullIndex($this->setting('sort_order','updated_desc'));$this->indexSvc()->write($idx);$idx['built_now']=true;$idx['stale']=false;} else {$idx['stale']=false;$idx['fast_loaded']=true;} return new JSONResponse($idx);}catch(\Throwable $e){return $this->err($e);}}
 /**
  * @NoAdminRequired
  * @NoCSRFRequired
  */
 public function indexStatus(): JSONResponse{try{$m=$this->model();return new JSONResponse($this->indexSvc()->status($m->fingerprint()));}catch(\Throwable $e){return $this->err($e);}}
 /**
  * @NoAdminRequired
  */
 public function rebuildIndex(): JSONResponse{try{$m=$this->model();$idx=$m->fullIndex($this->setting('sort_order','updated_desc'));$this->indexSvc()->write($idx);return new JSONResponse(['ok'=>true,'index'=>$idx]);}catch(\Throwable $e){return $this->err($e);}}
 /**
  * @NoAdminRequired
  */
 public function saveLastLocation(): JSONResponse{try{$this->setSetting('last_notebook_id',(string)$this->request->getParam('notebook_id',''));$this->setSetting('last_note_id',(string)$this->request->getParam('note_id',''));return new JSONResponse(['ok'=>true]);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 * @NoCSRFRequired
 */ public function getNote(string $id): JSONResponse{try{$n=$this->model()->note($id);return $n?new JSONResponse($n):new JSONResponse(['error'=>'Notiz nicht gefunden'],404);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function createNote(): JSONResponse{try{$r=$this->writer()->createNote((string)$this->request->getParam('title','Neue Notiz'),(string)$this->request->getParam('body',''),(string)$this->request->getParam('parent_id',''));$this->rebuildIndex();return new JSONResponse($r);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function updateNote(string $id): JSONResponse{try{$r=$this->writer()->updateNote($id,(string)$this->request->getParam('title',''),(string)$this->request->getParam('body',''),(string)$this->request->getParam('known_updated_time',''));$this->rebuildIndex();return new JSONResponse($r);}catch(\Throwable $e){return $this->err($e,str_starts_with($e->getMessage(),'Konflikt')?409:400);}}
 /**
 * @NoAdminRequired
 */ public function deleteNote(string $id): JSONResponse{try{$r=$this->writer()->deleteNote($id);$this->rebuildIndex();return new JSONResponse($r);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function moveNote(string $id): JSONResponse{try{$r=$this->writer()->moveNote($id,(string)$this->request->getParam('target_notebook_id',''));$this->rebuildIndex();return new JSONResponse($r);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function createNotebook(): JSONResponse{try{$r=$this->writer()->createNotebook((string)$this->request->getParam('title','Neues Notizbuch'),(string)$this->request->getParam('parent_id',''));$this->rebuildIndex();return new JSONResponse($r);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function updateNotebook(string $id): JSONResponse{try{$r=$this->writer()->updateNotebook($id,(string)$this->request->getParam('title',''),(string)$this->request->getParam('known_updated_time',''));$this->rebuildIndex();return new JSONResponse($r);}catch(\Throwable $e){return $this->err($e,str_starts_with($e->getMessage(),'Konflikt')?409:400);}}
 /**
 * @NoAdminRequired
 */ public function deleteNotebook(string $id): JSONResponse{try{$r=$this->writer()->deleteNotebook($id);$this->rebuildIndex();return new JSONResponse($r);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 * @NoCSRFRequired
 */ public function tags(): JSONResponse{try{return new JSONResponse(array_values($this->model()->tagsRaw()));}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function createTag(): JSONResponse{try{$r=$this->writer()->createTag((string)$this->request->getParam('title','Neuer Tag'));return new JSONResponse($r);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function saveNoteTags(string $id): JSONResponse{try{$tags=$this->request->getParam('tag_ids',[]); if(!is_array($tags))$tags=[]; $r=$this->writer()->saveNoteTags($id,$tags);return new JSONResponse($r);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function removeNoteTag(string $id,string $tagId): JSONResponse{try{$r=$this->writer()->removeNoteTag($id,$tagId);return new JSONResponse($r);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function createAndAssignTag(string $id): JSONResponse{try{$title=trim((string)$this->request->getParam('title',''));if($title==='')throw new \RuntimeException('Tag-Name darf nicht leer sein.');$model=$this->model();$writer=new JoplixWriteService($model);$tagId='';$canonicalTitle=$title;$created=false;foreach($model->tagsRaw() as $tag){$existingTitle=trim((string)($tag['_title']??$tag['_body']??''));if($existingTitle!==''&&mb_strtolower($existingTitle,'UTF-8')===mb_strtolower($title,'UTF-8')){$tagId=(string)($tag['id']??'');$canonicalTitle=$existingTitle;break;}}if($tagId===''){$createdTag=$writer->createTag($title);$tagId=(string)($createdTag['id']??'');$created=true;}if($tagId==='')throw new \RuntimeException('Tag konnte nicht erstellt werden.');$writer->saveNoteTags($id,[$tagId]);return new JSONResponse(['ok'=>true,'id'=>$tagId,'title'=>$canonicalTitle,'created'=>$created]);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function uploadResource(): JSONResponse{try{$r=$this->writer()->uploadResource((string)$this->request->getParam('filename','upload.bin'),(string)$this->request->getParam('mime','application/octet-stream'),(string)$this->request->getParam('data_base64',''));$this->rebuildIndex();return new JSONResponse($r);}catch(\Throwable $e){return $this->err($e);}}
 


 public function updateReleases(): JSONResponse {
     try {
         if (!$this->isAdmin()) {
             return new JSONResponse(['error' => 'Administratorrechte erforderlich.'], 403);
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
             return new JSONResponse(['error' => 'Administratorrechte erforderlich.'], 403);
         }
         if (!in_array($this->setting('update_source', 'codeberg'), ['', 'codeberg'], true)) {
             throw new \RuntimeException('Aktuell wird nur Codeberg als Update-Quelle unterstuetzt');
         }
         $releaseId = filter_var($this->request->getParam('release_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
         $assetId = filter_var($this->request->getParam('asset_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
         if ($releaseId === false || $assetId === false) {
             throw new \RuntimeException('Bitte die Release-Liste neu laden und ein Paket auswaehlen.');
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
             return new JSONResponse(['error' => 'Administratorrechte erforderlich.'], 403);
         }
         if ($this->setting('dev_enabled', 'no') !== 'yes') {
             return new JSONResponse(['enabled' => false, 'packages' => []]);
         }
         $this->workspace()->ensureWorkspace();
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
             return new JSONResponse(['error' => 'Administratorrechte erforderlich.'], 403);
         }
         if ($this->setting('dev_enabled', 'no') !== 'yes') {
             throw new \RuntimeException('Entwicklungsumgebung ist nicht aktiviert');
         }
         $this->workspace()->ensureWorkspace();
         return new JSONResponse($this->devSvc()->deletePackage((string)$this->request->getParam('name', '')));
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }

 public function devInstallPackage(): JSONResponse {
     try {
         if (!$this->isAdmin()) {
             return new JSONResponse(['error' => 'Administratorrechte erforderlich.'], 403);
         }
         if ($this->setting('dev_enabled', 'no') !== 'yes') {
             throw new \RuntimeException('Entwicklungsumgebung ist nicht aktiviert');
         }
         $this->workspace()->ensureWorkspace();
         return new JSONResponse($this->devSvc()->installPackage((string)$this->request->getParam('name', '')));
     } catch (\Throwable $e) {
         return $this->err($e);
     }
 }

/**
 * @NoAdminRequired
 * @NoCSRFRequired
 */ public function resourceData(string $id): JSONResponse{try{$p=$this->model()->resourcePayload($id);return $p?new JSONResponse($p):new JSONResponse(['error'=>'Ressource nicht gefunden'],404);}catch(\Throwable $e){return $this->err($e);}}
 /**
 * @NoAdminRequired
 */ public function startOfficeEdit(string $id): JSONResponse{try{
  
  $payload=$this->model()->resourcePayload($id);
  if(!$payload||isset($payload['error']))throw new \RuntimeException((string)($payload['error']??'Ressource nicht gefunden'));
  $ext=strtolower((string)($payload['file_extension']??''));
  if(!in_array($ext,['doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp'],true))throw new \RuntimeException('Diese Ressource ist keine unterstützte Office-Datei.');
  $dataUrl=(string)($payload['data_url']??'');
  $comma=strpos($dataUrl,',');
  $binary=$comma===false?false:base64_decode(substr($dataUrl,$comma+1),true);
  if($binary===false)throw new \RuntimeException('Ressource konnte nicht gelesen werden.');
  $editing=$this->editingFolder();$this->cleanupEditingSessions($editing);
  $sessionId=bin2hex(random_bytes(16));$sessionFolder=$editing->newFolder($sessionId);
  $title=preg_replace('/[\\\/:*?"<>|]+/','_',trim((string)($payload['title']??$id)))?:$id;
  if($ext!==''&&!str_ends_with(strtolower($title),'.'.$ext))$title.='.'.$ext;
  $file=$sessionFolder->newFile($title,$binary);$hash=hash('sha256',$binary);
  $session=['session_id'=>$sessionId,'resource_id'=>$id,'file_name'=>$title,'file_id'=>$file->getId(),'working_hash'=>$hash,'resource_hash'=>$hash,'started_at'=>time(),'last_import_at'=>0];
  $sessionFolder->newFile('session.json',json_encode($session,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  $sessionFolder->newFile('.lock',json_encode(['created_at'=>time(),'resource_id'=>$id,'sha256'=>$hash],JSON_UNESCAPED_SLASHES));
  $sessions=json_decode($this->setting('office_sessions','{}'),true);if(!is_array($sessions))$sessions=[];$sessions[$id]=$session;
  $this->setSetting('office_sessions',json_encode($sessions,JSON_UNESCAPED_SLASHES));
  return new JSONResponse(['ok'=>true,'resource_id'=>$id,'session_id'=>$sessionId,'file_id'=>$file->getId(),'file_name'=>$title,'edit_dir'=>'/.joplix/editing/'.$sessionId,'editor_path'=>'/apps/eurooffice/'.$file->getId()]);
 }catch(\Throwable $e){return $this->err($e);}}

 /**
 * @NoAdminRequired
 * @NoCSRFRequired
 */ public function officeEditStatus(string $id): JSONResponse{try{
  $sessions=json_decode($this->setting('office_sessions','{}'),true);if(!is_array($sessions)||!isset($sessions[$id]))return new JSONResponse(['active'=>false]);
  $session=$sessions[$id];$editing=$this->editingFolder();$this->cleanupEditingSessions($editing);
  $sessionId=(string)($session['session_id']??'');
  if($sessionId===''||!$editing->nodeExists($sessionId))return new JSONResponse(['active'=>false,'error'=>'Arbeitssitzung fehlt']);
  $folder=$editing->get($sessionId);if(!$folder instanceof Folder||!$folder->nodeExists((string)$session['file_name']))return new JSONResponse(['active'=>false,'error'=>'Arbeitsdatei fehlt']);
  $file=$folder->get((string)$session['file_name']);if(!$file instanceof \OCP\Files\File)return new JSONResponse(['active'=>false,'error'=>'Arbeitsdatei ist ungültig']);
  $binary=$file->getContent();$hash=hash('sha256',$binary);$updated=false;
  if(!hash_equals((string)$session['working_hash'],$hash)){
    $result=$this->writer()->updateResourceBinary($id,$binary,(string)$session['resource_hash']);
    $session['working_hash']=$hash;$session['resource_hash']=$result['sha256'];$session['last_import_at']=time();$sessions[$id]=$session;
    $this->setSetting('office_sessions',json_encode($sessions,JSON_UNESCAPED_SLASHES));$updated=!((bool)($result['already_applied']??false));
    $alreadyApplied=(bool)($result['already_applied']??false);
  }
  return new JSONResponse(['active'=>true,'updated'=>$updated,'already_applied'=>$alreadyApplied??false,'file_name'=>$session['file_name'],'last_import_at'=>$session['last_import_at']??0,'sha256'=>$session['resource_hash'],'working_sha256'=>$hash,'working_mtime'=>$file->getMTime(),'working_size'=>$file->getSize()]);
 }catch(\Throwable $e){return $this->err($e);}}

 private function editingFolder(): Folder{
  $uf=$this->userFolder();if(!$uf->nodeExists('.joplix'))$uf->newFolder('.joplix');$base=$uf->get('.joplix');if(!$base instanceof Folder)throw new \RuntimeException('.joplix ist kein Ordner');
  foreach(['editing','backups','cache','logs','updates','updater'] as $name)if(!$base->nodeExists($name))$base->newFolder($name);
  $editing=$base->get('editing');if(!$editing instanceof Folder)throw new \RuntimeException('Joplix-Arbeitsordner ist ungültig');return $editing;
 }
 private function cleanupEditingSessions(Folder $editing): void{
  $cutoff=time()-86400;foreach($editing->getDirectoryListing() as $node){if(!$node instanceof Folder)continue;if($node->getMTime()<$cutoff){try{$node->delete();}catch(\Throwable $e){}}}
 }

}
