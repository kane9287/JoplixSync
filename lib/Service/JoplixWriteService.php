<?php
namespace OCA\Joplix\Service;
use OCP\Files\Folder;

class JoplixWriteService {
 private JoplixModelService $model; private Folder $folder;
 public function __construct(JoplixModelService $model){$this->model=$model;$this->folder=$model->folder();}
 public function createNotebook(string $title,string $parentId=''): array{$id=$this->model->newId();$now=$this->model->now();$item=['_body'=>trim($title)?:'Neues Notizbuch','id'=>$id,'created_time'=>$now,'updated_time'=>$now,'user_created_time'=>$now,'user_updated_time'=>$now,'encryption_cipher_text'=>'','encryption_applied'=>'0','parent_id'=>$parentId,'is_shared'=>'0','share_id'=>'','master_key_id'=>'','icon'=>'','type_'=>'2'];$this->writeNew($id,$item);return ['id'=>$id];}
 public function updateNotebook(string $id,string $title,string $known=''): array{$item=$this->must($id,'2');$this->conflict($item,$known,'Notizbuch');$this->backup($id);$item['_body']=trim($title)?:($item['_title']??'Notizbuch');$item['updated_time']=$this->model->now();$item['user_updated_time']=$item['updated_time'];$this->writeExisting($id,$item,['updated_time','user_updated_time']);return ['id'=>$id,'updated_time'=>$item['updated_time']];}
 public function deleteNotebook(string $id): array{$deleted=$this->deleteNotebookRecursive($id);return ['id'=>$id,'deleted'=>true,'deleted_items'=>$deleted];}
 public function createNote(string $title,string $body,string $parentId): array{$id=$this->model->newId();$now=$this->model->now();$item=['_body'=>$this->compose($title,$body),'id'=>$id,'parent_id'=>$parentId,'created_time'=>$now,'updated_time'=>$now,'is_conflict'=>'0','latitude'=>'0.00000000','longitude'=>'0.00000000','altitude'=>'0.0000','author'=>'','source_url'=>'','is_todo'=>'0','todo_due'=>'0','todo_completed'=>'0','source'=>'joplix','source_application'=>'joplix','application_data'=>'','order'=>(string)time(),'user_created_time'=>$now,'user_updated_time'=>$now,'encryption_cipher_text'=>'','encryption_applied'=>'0','markup_language'=>'1','is_shared'=>'0','share_id'=>'','conflict_original_id'=>'','master_key_id'=>'','user_data'=>'','type_'=>'1'];$this->writeNew($id,$item);return ['id'=>$id];}
 public function updateNote(string $id,string $title,string $body,string $known=''): array{$item=$this->must($id,'1');$this->conflict($item,$known,'Notiz');$this->backup($id);$item['_body']=$this->compose($title,$body);$item['updated_time']=$this->model->now();$item['user_updated_time']=$item['updated_time'];$this->writeExisting($id,$item,['updated_time','user_updated_time']);return ['id'=>$id,'updated_time'=>$item['updated_time']];}
 public function moveNote(string $id,string $targetNotebookId): array{$item=$this->must($id,'1');$this->backup($id);$item['parent_id']=$targetNotebookId;$item['updated_time']=$this->model->now();$item['user_updated_time']=$item['updated_time'];$this->writeExisting($id,$item,['updated_time','user_updated_time','parent_id']);return ['id'=>$id];}
 public function deleteNote(string $id): array{$this->must($id,'1');$relations=$this->deleteNoteRelations($id);$this->deleteSyncItem($id);return ['id'=>$id,'deleted'=>true,'deleted_relations'=>$relations];}
 public function createTag(string $title): array{$id=$this->model->newId();$now=$this->model->now();$item=['_body'=>trim($title)?:'Neuer Tag','id'=>$id,'created_time'=>$now,'updated_time'=>$now,'user_created_time'=>$now,'user_updated_time'=>$now,'encryption_cipher_text'=>'','encryption_applied'=>'0','is_shared'=>'0','parent_id'=>'','type_'=>'5'];$this->writeNew($id,$item);return ['id'=>$id];}
 public function saveNoteTags(string $noteId,array $tagIds): array{ foreach($tagIds as $tagId){$exists=false;foreach($this->model->noteTagsRaw() as $rel){if(($rel['note_id']??'')===$noteId&&($rel['tag_id']??'')===$tagId)$exists=true;} if(!$exists){$id=$this->model->newId();$now=$this->model->now();$item=['_body'=>'','id'=>$id,'note_id'=>$noteId,'tag_id'=>$tagId,'created_time'=>$now,'updated_time'=>$now,'user_created_time'=>$now,'user_updated_time'=>$now,'encryption_cipher_text'=>'','encryption_applied'=>'0','is_shared'=>'0','type_'=>'6'];$this->writeNew($id,$item);}} return ['ok'=>true];}
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
  if($bin===false)throw new \RuntimeException('Ungueltige Base64-Daten');
  if($bin==='')throw new \RuntimeException('Leere Ressource kann nicht angehaengt werden');
  $safeName=$this->cleanResourceFileName($filename);
  $mime=$this->cleanMime($mime,$safeName);
  $id=$this->model->newId();
  $now=$this->model->now();
  $ext=strtolower(pathinfo($safeName,PATHINFO_EXTENSION));
  $blobMs=(string)round(microtime(true)*1000);
  $item=['_body'=>$safeName,'id'=>$id,'mime'=>$mime,'filename'=>'','created_time'=>$now,'updated_time'=>$now,'user_created_time'=>$now,'user_updated_time'=>$now,'file_extension'=>$ext,'encryption_cipher_text'=>'','encryption_applied'=>'0','encryption_blob_encrypted'=>'0','size'=>(string)strlen($bin),'is_shared'=>'0','share_id'=>'','master_key_id'=>'','user_data'=>'','blob_updated_time'=>$blobMs,'ocr_text'=>'','ocr_details'=>'','ocr_status'=>'0','ocr_error'=>'','ocr_driver_id'=>'1','is_locked'=>'0','type_'=>'4'];
  if(!$this->folder->nodeExists('.resource'))$this->folder->newFolder('.resource');
  $rf=$this->folder->get('.resource');
  if(!$rf instanceof Folder)throw new \RuntimeException('.resource ist kein Ordner');
  $this->writeNew($id,$item);
  if($rf->nodeExists($id))$rf->get($id)->delete();
  $rf->newFile($id,$bin);
  if(!$this->folder->nodeExists($id.'.md')||!$rf->nodeExists($id))throw new \RuntimeException('Ressource konnte nicht vollstaendig angelegt werden');
  return ['id'=>$id,'title'=>$safeName,'mime'=>$mime,'file_extension'=>$ext,'size'=>strlen($bin),'markdown'=>'['.$safeName.'](:/'.$id.')'];
 }
 public function updateResourceBinary(string $id,string $binary,string $knownHash=''): array{
  if($binary==='')throw new \RuntimeException('Leere Ressource kann nicht gespeichert werden');
  $item=$this->must($id,'4');
  if(!$this->folder->nodeExists('.resource'))throw new \RuntimeException('Ressourcenordner fehlt');
  $rf=$this->folder->get('.resource');
  if(!$rf instanceof Folder||!$rf->nodeExists($id))throw new \RuntimeException('Ressourcendatei fehlt');
  $node=$rf->get($id);
  if(!$node instanceof \OCP\Files\File)throw new \RuntimeException('Ressource ist keine Datei');
  $current=$node->getContent();
  $currentHash=hash('sha256',$current);
  $uploadedHash=hash('sha256',$binary);
  if($knownHash!==''&&!hash_equals($knownHash,$currentHash)){
    if(hash_equals($uploadedHash,$currentHash)){
      return ['id'=>$id,'updated_time'=>(string)($item['updated_time']??''),'size'=>strlen($binary),'sha256'=>$currentHash,'already_applied'=>true];
    }
    throw new \RuntimeException('Konflikt: Die Ressource wurde zwischenzeitlich auf einem anderen Gerät geändert.');
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
 private function cleanMime(string $mime,string $filename): string{$mime=trim(strtolower($mime));if($mime===''||$mime==='application/octet-stream'){$ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));$map=['pdf'=>'application/pdf','txt'=>'text/plain','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','ppt'=>'application/vnd.ms-powerpoint','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','odt'=>'application/vnd.oasis.opendocument.text','ods'=>'application/vnd.oasis.opendocument.spreadsheet','odp'=>'application/vnd.oasis.opendocument.presentation'];return $map[$ext]??'application/octet-stream';}return preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i',$mime)?$mime:'application/octet-stream';}
 private function compose(string $title,string $body): string{$title=trim($title)?:'Neue Notiz';$body=rtrim($body);return $body===''?$title:$title."\n\n".$body;}
 private function deleteNotebookRecursive(string $id): array{
  $deleted=['notebooks'=>0,'notes'=>0];
  $children=[];
  foreach($this->model->notebooksRaw() as $childId=>$nb){ if(($nb['parent_id']??'')===$id)$children[]=$childId; }
  foreach($children as $childId){ $sub=$this->deleteNotebookRecursive($childId); $deleted['notebooks']+=$sub['notebooks']; $deleted['notes']+=$sub['notes']; }
  foreach($this->model->notesRaw() as $noteId=>$note){ if(($note['parent_id']??'')===$id){ $this->deleteNoteRelations((string)$noteId); $this->deleteSyncItem((string)$noteId); $deleted['notes']++; } }
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
  if(!$file)throw new \RuntimeException('Datei nicht gefunden: '.$id);
  $this->backup($id);
  // Joplin represents a remote deletion by removing the sync-target item. A
  // deleted_time field belongs to the local database model and is not a valid
  // tombstone in a WebDAV/filesystem sync item.
  $file->delete();
 }

 private function must(string $id,string $type): array{$item=$this->model->item($id);if(!$item||($item['type_']??'')!==$type)throw new \RuntimeException('Objekt nicht gefunden');return $item;}
 private function conflict(array $item,string $known,string $name): void{if($known!==''&&($item['updated_time']??'')!==$known)throw new \RuntimeException('Konflikt: '.$name.' wurde zwischenzeitlich geaendert');}
 private function writeNew(string $id,array $item): void{$this->folder->newFile($id.'.md',$this->model->format($item));}
 private function writeExisting(string $id,array $item,array $allowedFields=['updated_time','user_updated_time']): void{if(($item['type_']??'')==='13')throw new \RuntimeException('Revisionen duerfen nicht durch Joplix geschrieben werden');$f=$this->model->fileFor($id);if(!$f)throw new \RuntimeException('Datei nicht gefunden');$content=$this->model->formatPreserving($item,$allowedFields);if(($item['type_']??'')==='1'&&!preg_match('/\n\nid:\s*'.preg_quote($id,'/').'/',$content))throw new \RuntimeException('Speichern abgebrochen: Joplin-Trenner vor Metadaten fehlt');$f->putContent($content);}
 private function backup(string $id): void{$f=$this->model->fileFor($id);if(!$f)return;if(!$this->folder->nodeExists('.joplix_backups'))$this->folder->newFolder('.joplix_backups');$b=$this->folder->get('.joplix_backups');if($b instanceof Folder)$b->newFile(gmdate('Ymd_His').'_'.$id.'.md',$f->getContent());}
}
