<?php
namespace OCA\Joplix\Service;
use OCP\Files\Folder;
use OCP\IL10N;
use OCP\L10N\IFactory;

class JoplixWriteService {
 private JoplixModelService $model; private Folder $folder; private IL10N $l;
 public function __construct(JoplixModelService $model,?IL10N $l=null){$this->model=$model;$this->folder=$model->folder();$this->l=$l??\OCP\Server::get(IFactory::class)->get(\OCA\Joplix\AppInfo\Application::APP_ID);}
 public function createNotebook(string $title,string $parentId=''): array{if($parentId!=='')$this->notebookId($parentId);$id=$this->model->newId();$now=$this->model->now();$item=['_body'=>trim($title)?:$this->l->t('New notebook'),'id'=>$id,'created_time'=>$now,'updated_time'=>$now,'user_created_time'=>$now,'user_updated_time'=>$now,'encryption_cipher_text'=>'','encryption_applied'=>'0','parent_id'=>$parentId,'is_shared'=>'0','share_id'=>'','master_key_id'=>'','icon'=>'','type_'=>'2'];$this->writeNew($id,$item);return ['id'=>$id];}
 public function updateNotebook(string $id,string $title,string $known=''): array{$item=$this->must($id,'2');$this->conflict($item,$known,$this->l->t('Conflict: the notebook was changed in the meantime'));$this->backup($id);$item['_body']=trim($title)?:($item['_title']??$this->l->t('Notebook'));$item['updated_time']=$this->model->now();$item['user_updated_time']=$item['updated_time'];$this->writeExisting($id,$item,['updated_time','user_updated_time']);return ['id'=>$id,'updated_time'=>$item['updated_time']];}
 public function deleteNotebook(string $id): array{$deleted=$this->deleteNotebookRecursive($id);return ['id'=>$id,'deleted'=>true,'deleted_items'=>$deleted];}

 // ---- Trash. Same representation as Joplin 3.x: the item file stays and carries
 // deleted_time (milliseconds) until it is restored (deleted_time: 0) or purged
 // (file removed). Only that line, the two update times and, on a re-parented
 // restore, parent_id are rewritten; everything else stays byte-exact.
 public function trashNote(string $id): array{
  $item=$this->must($id,'1');
  if(!isset($this->model->trashedRaw()[$id]))$this->mark($id,$item,$this->nowMs());
  return ['id'=>$id,'trashed'=>true];
 }
 /** Marks the notebook, every sub-notebook and every note in them, with one shared timestamp. */
 public function trashNotebook(string $id): array{
  $item=$this->must($id,'2');
  if(isset($this->model->trashedRaw()[$id]))return ['id'=>$id,'trashed'=>true,'trashed_items'=>['notebooks'=>0,'notes'=>0]];
  $books=$this->model->notebooksRaw(); $ids=$this->withDescendants($id,$books); $when=$this->nowMs(); $count=['notebooks'=>0,'notes'=>0];
  foreach($this->model->notesRaw() as $noteId=>$note){ if(isset($ids[(string)($note['parent_id']??'')])){ $this->mark((string)$noteId,$note,$when); $count['notes']++; } }
  foreach($ids as $bookId=>$_){ $this->mark((string)$bookId,$bookId===$id?$item:$books[$bookId],$when); $count['notebooks']++; }
  return ['id'=>$id,'trashed'=>true,'trashed_items'=>$count];
 }
 /** Back to its notebook, or to $targetNotebookId when that notebook is itself trashed or gone. */
 public function restoreNote(string $id,string $targetNotebookId=''): array{
  $item=$this->trashedItem($id,'1'); $books=$this->model->notebooksRaw(); $parent=(string)($item['parent_id']??'');
  if($targetNotebookId!==''){ if(!isset($books[$targetNotebookId]))throw new \RuntimeException($this->l->t('Target notebook not found')); $parent=$targetNotebookId; }
  elseif(!isset($books[$parent]))throw new \RuntimeException($this->l->t('The notebook of this note no longer exists. Please choose a target notebook.'));
  $this->mark($id,$item,'0',$parent===(string)($item['parent_id']??'')?null:$parent);
  return ['id'=>$id,'restored'=>true,'notebook_id'=>$parent];
 }
 /** Restores the notebook with everything in the trash below it; top level when its parent is not available. */
 public function restoreNotebook(string $id): array{
  $item=$this->trashedItem($id,'2'); $trashed=$this->model->trashedRaw(); $parent=(string)($item['parent_id']??''); $count=['notebooks'=>0,'notes'=>0];
  $trashedBooks=array_filter($trashed,fn($t)=>($t['type_']??'')==='2'); $ids=$this->withDescendants($id,$trashedBooks);
  foreach($ids as $bookId=>$_){ $reparent=($bookId===$id&&$parent!==''&&!isset($this->model->notebooksRaw()[$parent]))?'':null; $this->mark((string)$bookId,$trashed[$bookId],'0',$reparent); $count['notebooks']++; }
  foreach($trashed as $noteId=>$t){ if(($t['type_']??'')==='1'&&isset($ids[(string)($t['parent_id']??'')])){ $this->mark((string)$noteId,$t,'0'); $count['notes']++; } }
  return ['id'=>$id,'restored'=>true,'restored_items'=>$count];
 }
 /** Permanently removes everything in the trash. */
 public function emptyTrash(): array{
  $count=['notebooks'=>0,'notes'=>0];
  foreach($this->model->trashedRaw() as $id=>$t){ if(($t['type_']??'')==='1'){ $this->deleteNoteRelations((string)$id); $this->deleteSyncItem((string)$id); $count['notes']++; } }
  foreach($this->model->trashedRaw() as $id=>$t){ if(($t['type_']??'')==='2'){ $this->deleteSyncItem((string)$id); $count['notebooks']++; } }
  return ['ok'=>true,'deleted_items'=>$count];
 }
 private function nowMs(): string{return (string)(int)round(microtime(true)*1000);}
 private function trashedItem(string $id,string $type): array{$t=$this->model->trashedRaw()[$id]??null;if(!$t||($t['type_']??'')!==$type)throw new \RuntimeException($this->l->t('Item not found in the trash'));return $t;}
 /** @return array<string,true> $id plus all notebooks of $books below it */
 private function withDescendants(string $id,array $books): array{ $ids=[$id=>true]; do{ $grew=false; foreach($books as $bookId=>$nb){ if(!isset($ids[$bookId])&&isset($ids[(string)($nb['parent_id']??'')])){$ids[$bookId]=true;$grew=true;} } }while($grew); return $ids; }
 private function mark(string $id,array $item,string $deletedTime,?string $parentId=null): void{
  $this->backup($id); $now=$this->model->now(); $item['deleted_time']=$deletedTime; $item['updated_time']=$now; $item['user_updated_time']=$now;
  $fields=['updated_time','user_updated_time','deleted_time']; if($parentId!==null){$item['parent_id']=$parentId;$fields[]='parent_id';}
  $this->writeExisting($id,$item,$fields);
 }
 public function createNote(string $title,string $body,string $parentId): array{$this->notebookId($parentId);$id=$this->model->newId();$now=$this->model->now();$item=['_body'=>$this->compose($title,$body),'id'=>$id,'parent_id'=>$parentId,'created_time'=>$now,'updated_time'=>$now,'is_conflict'=>'0','latitude'=>'0.00000000','longitude'=>'0.00000000','altitude'=>'0.0000','author'=>'','source_url'=>'','is_todo'=>'0','todo_due'=>'0','todo_completed'=>'0','source'=>'joplix','source_application'=>'joplix','application_data'=>'','order'=>(string)time(),'user_created_time'=>$now,'user_updated_time'=>$now,'encryption_cipher_text'=>'','encryption_applied'=>'0','markup_language'=>'1','is_shared'=>'0','share_id'=>'','conflict_original_id'=>'','master_key_id'=>'','user_data'=>'','type_'=>'1'];$this->writeNew($id,$item);return ['id'=>$id];}
 public function updateNote(string $id,string $title,string $body,string $known=''): array{$item=$this->must($id,'1');$this->conflict($item,$known,$this->l->t('Conflict: the note was changed in the meantime'));$this->backup($id);$item['_body']=$this->compose($title,$body);$item['updated_time']=$this->model->now();$item['user_updated_time']=$item['updated_time'];$this->writeExisting($id,$item,['updated_time','user_updated_time']);return ['id'=>$id,'updated_time'=>$item['updated_time']];}
 public function moveNote(string $id,string $targetNotebookId): array{$item=$this->must($id,'1');$this->notebookId($targetNotebookId);$this->backup($id);$item['parent_id']=$targetNotebookId;$item['updated_time']=$this->model->now();$item['user_updated_time']=$item['updated_time'];$this->writeExisting($id,$item,['updated_time','user_updated_time','parent_id']);return ['id'=>$id];}
 public function deleteNote(string $id): array{$this->must($id,'1');$relations=$this->deleteNoteRelations($id);$this->deleteSyncItem($id);return ['id'=>$id,'deleted'=>true,'deleted_relations'=>$relations];}
 public function createTag(string $title): array{$id=$this->model->newId();$now=$this->model->now();$item=['_body'=>trim($title)?:$this->l->t('New tag'),'id'=>$id,'created_time'=>$now,'updated_time'=>$now,'user_created_time'=>$now,'user_updated_time'=>$now,'encryption_cipher_text'=>'','encryption_applied'=>'0','is_shared'=>'0','parent_id'=>'','type_'=>'5'];$this->writeNew($id,$item);return ['id'=>$id];}
 public function saveNoteTags(string $noteId,array $tagIds): array{ $this->must($noteId,'1'); foreach($tagIds as $tagId){ if(!is_string($tagId)||!self::isId($tagId)||!isset($this->model->tagsRaw()[$tagId]))throw new \RuntimeException($this->l->t('Tag not found'));$exists=false;foreach($this->model->noteTagsRaw() as $rel){if(($rel['note_id']??'')===$noteId&&($rel['tag_id']??'')===$tagId)$exists=true;} if(!$exists){$id=$this->model->newId();$now=$this->model->now();$item=['_body'=>'','id'=>$id,'note_id'=>$noteId,'tag_id'=>$tagId,'created_time'=>$now,'updated_time'=>$now,'user_created_time'=>$now,'user_updated_time'=>$now,'encryption_cipher_text'=>'','encryption_applied'=>'0','is_shared'=>'0','type_'=>'6'];$this->writeNew($id,$item);}} return ['ok'=>true];}
 public function removeNoteTag(string $noteId,string $tagId): array{
  $removed=0;
  foreach($this->model->noteTagsRaw() as $relationId=>$rel){
   if((string)($rel['note_id']??'')!==$noteId||(string)($rel['tag_id']??'')!==$tagId)continue;
   $file=$this->model->fileFor((string)$relationId);
   if($file){$this->backup((string)$relationId);$file->delete();$removed++;}
  }
  return ['ok'=>true,'note_id'=>$noteId,'tag_id'=>$tagId,'removed'=>$removed];
 }
 public function uploadResource(string $filename,string $mime,string $dataBase64): array{
  $bin=base64_decode($dataBase64,true);
  if($bin===false)throw new \RuntimeException($this->l->t('Invalid Base64 data'));
  return $this->createResource($filename,$mime,$bin,strlen($bin));
 }
 // Attach a file from the user's Nextcloud: the content is streamed straight into .resource/<id>,
 // so large files never pass through the browser or PHP's upload limits.
 /** @param resource $stream */
 public function createResourceFromStream(string $filename,string $mime,$stream,int $size): array{
  if(!is_resource($stream))throw new \RuntimeException($this->l->t('The file could not be read'));
  try{return $this->createResource($filename,$mime,$stream,$size);}finally{if(is_resource($stream))fclose($stream);}
 }
 /** @param string|resource $content */
 private function createResource(string $filename,string $mime,$content,int $size): array{
  if($size<=0)throw new \RuntimeException($this->l->t('An empty file cannot be attached'));
  $safeName=$this->cleanResourceFileName($filename);
  $mime=$this->cleanMime($mime,$safeName);
  $id=$this->model->newId();
  $now=$this->model->now();
  $ext=strtolower(pathinfo($safeName,PATHINFO_EXTENSION));
  $blobMs=(string)round(microtime(true)*1000);
  $item=['_body'=>$safeName,'id'=>$id,'mime'=>$mime,'filename'=>'','created_time'=>$now,'updated_time'=>$now,'user_created_time'=>$now,'user_updated_time'=>$now,'file_extension'=>$ext,'encryption_cipher_text'=>'','encryption_applied'=>'0','encryption_blob_encrypted'=>'0','size'=>(string)$size,'is_shared'=>'0','share_id'=>'','master_key_id'=>'','user_data'=>'','blob_updated_time'=>$blobMs,'ocr_text'=>'','ocr_details'=>'','ocr_status'=>'0','ocr_error'=>'','ocr_driver_id'=>'1','is_locked'=>'0','type_'=>'4'];
  if(!$this->folder->nodeExists('.resource'))$this->folder->newFolder('.resource');
  $rf=$this->folder->get('.resource');
  if(!$rf instanceof Folder)throw new \RuntimeException($this->l->t('.resource is not a folder'));
  $this->writeNew($id,$item);
  if($rf->nodeExists($id))$rf->get($id)->delete();
  $rf->newFile($id,$content);
  if(!$this->folder->nodeExists($id.'.md')||!$rf->nodeExists($id))throw new \RuntimeException($this->l->t('The attachment could not be created completely'));
  return ['id'=>$id,'title'=>$safeName,'mime'=>$mime,'file_extension'=>$ext,'size'=>$size,'markdown'=>(str_starts_with(strtolower($mime),'image/')?'!':'').'['.$safeName.'](:/'.$id.')'];
 }
 public function updateResourceBinary(string $id,string $binary,string $knownHash=''): array{
  if($binary==='')throw new \RuntimeException($this->l->t('An empty attachment cannot be saved'));
  $item=$this->must($id,'4');
  if(!$this->folder->nodeExists('.resource'))throw new \RuntimeException($this->l->t('The attachment folder is missing'));
  $rf=$this->folder->get('.resource');
  if(!$rf instanceof Folder||!$rf->nodeExists($id))throw new \RuntimeException($this->l->t('The attachment file is missing'));
  $node=$rf->get($id);
  if(!$node instanceof \OCP\Files\File)throw new \RuntimeException($this->l->t('The attachment is not a file'));
  $current=$node->getContent();
  $currentHash=hash('sha256',$current);
  $uploadedHash=hash('sha256',$binary);
  if($knownHash!==''&&!hash_equals($knownHash,$currentHash)){
    if(hash_equals($uploadedHash,$currentHash)){
      return ['id'=>$id,'updated_time'=>(string)($item['updated_time']??''),'size'=>strlen($binary),'sha256'=>$currentHash,'already_applied'=>true];
    }
    throw new \RuntimeException($this->l->t('Conflict: the attachment was changed on another device in the meantime.'));
  }
  if(hash_equals($uploadedHash,$currentHash)){
    return ['id'=>$id,'updated_time'=>(string)($item['updated_time']??''),'size'=>strlen($binary),'sha256'=>$currentHash,'already_applied'=>true];
  }
  if(!$this->folder->nodeExists('.joplix_backups'))$this->folder->newFolder('.joplix_backups');
  $backupRoot=$this->folder->get('.joplix_backups');
  if($backupRoot instanceof Folder){
    if(!$backupRoot->nodeExists('resources'))$backupRoot->newFolder('resources');
    $br=$backupRoot->get('resources');
    if($br instanceof Folder)$br->newFile(gmdate('Ymd_His').'_'.$id,$current);
  }
  $node->putContent($binary);
  $now=$this->model->now();
  $item['updated_time']=$now;
  $item['user_updated_time']=$now;
  $item['blob_updated_time']=(string)round(microtime(true)*1000);
  $item['size']=(string)strlen($binary);
  $this->writeExisting($id,$item,['updated_time','user_updated_time','blob_updated_time','size']);
  return ['id'=>$id,'updated_time'=>$now,'size'=>strlen($binary),'sha256'=>$uploadedHash,'already_applied'=>false];
 }
 private function cleanResourceFileName(string $filename): string{
  // Robust gegen leere, ungueltig kodierte oder vom Browser fehlerhaft gelieferte Dateinamen.
  // preg_replace kann bei Fehlern null liefern. Wegen des string-Return-Types darf diese
  // Methode niemals null zurueckgeben, sonst bricht der Upload mit einem TypeError ab.
  $filename=(string)$filename;
  $filename=str_replace(["\r","\n","\0"],'',$filename);
  $filename=trim($filename);
  $filename=basename(str_replace('\\','/',$filename));
  $clean=preg_replace('/[^A-Za-z0-9._ -]+/','_',$filename);
  if(!is_string($clean))$clean='';
  $clean=trim($clean," ._-");
  if($clean==='')$clean='upload.bin';
  if(strpos($clean,'.')===false)$clean.='.bin';
  if(strlen($clean)>180){
    $ext=pathinfo($clean,PATHINFO_EXTENSION);
    $base=pathinfo($clean,PATHINFO_FILENAME);
    $clean=substr($base,0,160).($ext!==''?'.'.$ext:'');
  }
  return $clean!==''?$clean:'upload.bin';
 }
 private function cleanMime(string $mime,string $filename): string{$mime=trim(strtolower($mime));if($mime===''||$mime==='application/octet-stream'){$ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));return JoplixModelService::MIME_BY_EXTENSION[$ext]??'application/octet-stream';}return preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i',$mime)?$mime:'application/octet-stream';}
 // Inverse of JoplixModelService::titleAndBody(): nothing is trimmed and no default title is invented, so
 // an unedited note is written back unchanged. The title is one line by definition.
 private function compose(string $title,string $body): string{$title=str_replace(["\r\n","\r","\n"],' ',$title);$body=str_replace("\r\n","\n",$body);return $body===''?$title:$title."\n\n".$body;}
 private function deleteNotebookRecursive(string $id): array{
  $deleted=['notebooks'=>0,'notes'=>0];
  $children=[];
  // Live and trashed children alike, so purging a notebook never leaves items behind in the trash.
  $trashed=$this->model->trashedRaw();
  foreach($this->model->notebooksRaw()+array_filter($trashed,fn($t)=>($t['type_']??'')==='2') as $childId=>$nb){ if(($nb['parent_id']??'')===$id)$children[]=(string)$childId; }
  foreach($children as $childId){ $sub=$this->deleteNotebookRecursive($childId); $deleted['notebooks']+=$sub['notebooks']; $deleted['notes']+=$sub['notes']; }
  foreach($this->model->notesRaw()+array_filter($trashed,fn($t)=>($t['type_']??'')==='1') as $noteId=>$note){ if(($note['parent_id']??'')===$id){ $this->deleteNoteRelations((string)$noteId); $this->deleteSyncItem((string)$noteId); $deleted['notes']++; } }
  $this->must($id,'2'); $this->deleteSyncItem($id); $deleted['notebooks']++;
  return $deleted;
 }
 private function deleteNoteRelations(string $noteId): int{
  $removed=0;
  foreach($this->model->noteTagsRaw() as $relationId=>$relation){
   if((string)($relation['note_id']??'')!==$noteId)continue;
   $this->deleteSyncItem((string)$relationId);$removed++;
  }
  return $removed;
 }
 private function deleteSyncItem(string $id): void{
  $file=$this->model->fileFor($id);
  if(!$file)throw new \RuntimeException($this->l->t('File not found: %1$s',[$id]));
  // Permanent deletion: the sync-target item is removed and so is every backup copy of it, otherwise
  // "delete permanently" would leave the text readable in .joplix_backups. (Joplin's own "move to
  // trash" instead keeps the item with deleted_time set; the model hides those.)
  $file->delete();
  $this->dropBackups($id);
 }

 // Ids taken from a request end up as "key: value" lines in item files. Only a 32-digit hex id that names
 // an existing item is ever written, so a request cannot add lines to the metadata block or leave an item
 // pointing at something that does not exist.
 private static function isId(string $id): bool{return preg_match('/^[a-f0-9]{32}$/D',$id)===1;}
 private function notebookId(string $id): string{if(!self::isId($id)||!isset($this->model->notebooksRaw()[$id]))throw new \RuntimeException($this->l->t('Notebook not found'));return $id;}
 private function must(string $id,string $type): array{$item=$this->model->item($id);if(!$item||($item['type_']??'')!==$type)throw new \RuntimeException($this->l->t('Item not found'));return $item;}
 /** $message is the translated text for the user; the controller answers a ConflictException with HTTP 409. */
 private function conflict(array $item,string $known,string $message): void{if($known!==''&&($item['updated_time']??'')!==$known)throw new ConflictException($message);}
 private function writeNew(string $id,array $item): void{$this->folder->newFile($id.'.md',$this->model->format($item));}
 private function writeExisting(string $id,array $item,array $allowedFields=['updated_time','user_updated_time']): void{if(($item['type_']??'')==='13')throw new \RuntimeException($this->l->t('Revisions must not be written by Joplix'));$f=$this->model->fileFor($id);if(!$f)throw new \RuntimeException($this->l->t('File not found'));$content=$this->model->formatPreserving($item,$allowedFields);if(($item['type_']??'')==='1'&&!preg_match('/\n\nid:\s*'.preg_quote($id,'/').'/',$content))throw new \RuntimeException($this->l->t('Saving aborted: the Joplin separator before the metadata is missing'));$f->putContent($content);}
 private function backup(string $id): void{$f=$this->model->fileFor($id);if(!$f)return;if(!$this->folder->nodeExists('.joplix_backups'))$this->folder->newFolder('.joplix_backups');$b=$this->folder->get('.joplix_backups');if($b instanceof Folder)$b->newFile(gmdate('Ymd_His').'_'.$id.'.md',$f->getContent());}
 // ---- Backup retention. backup() copies an item into <Joplin folder>/.joplix_backups before it is
 // changed ("<Ymd_His>_<id>.md"; replaced attachments go to resources/"<Ymd_His>_<id>"). Copies are kept
 // for BACKUP_DAYS and then removed by pruneBackupFolder(), which ApiController runs at most once a day.
 // Copies of an item that is deleted permanently go at once (dropBackups).
 public const BACKUP_DAYS=30;
 /** @var array<string,\OCP\Files\Node[]>|null backup files by item id, listed once per request */
 private ?array $backupsById=null;
 private function dropBackups(string $id): void{
  if($this->backupsById===null){
   $this->backupsById=[];
   foreach(self::backupNodes($this->folder) as $node){ if(preg_match('/^\d{8}_\d{6}_([a-f0-9]{32})(?:\.md)?$/D',$node->getName(),$m))$this->backupsById[$m[1]][]=$node; }
  }
  foreach($this->backupsById[$id]??[] as $node){ try{$node->delete();}catch(\Throwable $e){} }
  unset($this->backupsById[$id]);
 }
 /** @return \OCP\Files\Node[] the backup files of items and of attachments */
 private static function backupNodes(Folder $joplinFolder): array{
  if(!$joplinFolder->nodeExists('.joplix_backups'))return [];
  $root=$joplinFolder->get('.joplix_backups'); if(!$root instanceof Folder)return [];
  $out=[];
  foreach($root->getDirectoryListing() as $node){
   if($node instanceof Folder){ if($node->getName()==='resources')foreach($node->getDirectoryListing() as $r){ if(!$r instanceof Folder)$out[]=$r; } }
   else $out[]=$node;
  }
  return $out;
 }
 /** Removes backup copies older than $days. Returns how many were removed. */
 public static function pruneBackupFolder(Folder $joplinFolder,int $days=self::BACKUP_DAYS): int{
  $cutoff=time()-$days*86400; $removed=0;
  foreach(self::backupNodes($joplinFolder) as $node){ if($node->getMTime()<$cutoff){ try{$node->delete();$removed++;}catch(\Throwable $e){} } }
  return $removed;
 }
}
