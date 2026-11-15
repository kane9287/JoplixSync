<?php
namespace OCA\Joplix\Service;
use OCP\Files\File; use OCP\Files\Folder; use OCP\IL10N; use OCP\L10N\IFactory;
class JoplixModelService {
 private Folder $folder; private array $items=[]; private array $notes=[]; private array $notebooks=[]; private array $resources=[]; private array $tags=[]; private array $noteTags=[]; private array $revisions=[]; private array $unknown=[];
 /** Notes and notebooks in Joplin's trash (deleted_time set): id => item. Kept in $items so the files stay addressable, but left out of $notes/$notebooks and therefore out of the index, counts and notebook cascades. */
 private array $trashed=[];
 /** Office formats an editor can open (also used by ApiController::startOfficeEdit and, as JP_OFFICE_EXTENSIONS, by js/app.js). */
 public const OFFICE_EXTENSIONS=['doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp'];
 /** MIME type by file extension, for attachments that come without one (JoplixWriteService::cleanMime, resourceMime). */
 public const MIME_BY_EXTENSION=['pdf'=>'application/pdf','txt'=>'text/plain','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml','mp3'=>'audio/mpeg','ogg'=>'audio/ogg','wav'=>'audio/wav','m4a'=>'audio/mp4','mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','doc'=>'application/msword','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','xls'=>'application/vnd.ms-excel','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','ppt'=>'application/vnd.ms-powerpoint','odt'=>'application/vnd.oasis.opendocument.text','ods'=>'application/vnd.oasis.opendocument.spreadsheet','odp'=>'application/vnd.oasis.opendocument.presentation'];
 private array $known=['id'=>1,'parent_id'=>1,'title'=>1,'created_time'=>1,'updated_time'=>1,'user_created_time'=>1,'user_updated_time'=>1,'deleted_time'=>1,'is_conflict'=>1,'latitude'=>1,'longitude'=>1,'altitude'=>1,'author'=>1,'source_url'=>1,'source'=>1,'source_application'=>1,'application_data'=>1,'order'=>1,'encryption_cipher_text'=>1,'encryption_applied'=>1,'encryption_blob_encrypted'=>1,'markup_language'=>1,'is_shared'=>1,'share_id'=>1,'conflict_original_id'=>1,'master_key_id'=>1,'type_'=>1,'mime'=>1,'filename'=>1,'file_extension'=>1,'size'=>1,'icon'=>1,'is_todo'=>1,'todo_due'=>1,'todo_completed'=>1,'item_type'=>1,'item_id'=>1,'item_updated_time'=>1,'title_diff'=>1,'body_diff'=>1,'metadata_diff'=>1,'is_locked'=>1,'note_id'=>1,'tag_id'=>1,'user_data'=>1,'file_extension'=>1,'extracted_resource_ids'=>1,'crop_rect'=>1,'ocr_status'=>1,'ocr_text'=>1,'ocr_details'=>1,'blob_updated_time'=>1,'ocr_error'=>1,'ocr_driver_id'=>1];
 /** Texts for the user (list labels, errors) in the language of the current request. */
 private IL10N $l;
 public function __construct(Folder $folder,?IL10N $l=null){$this->folder=$folder;$this->l=$l??\OCP\Server::get(IFactory::class)->get(\OCA\Joplix\AppInfo\Application::APP_ID);$this->load();}
 public function folder(): Folder{return $this->folder;} public function notesRaw(): array{return $this->notes;} public function notebooksRaw(): array{return $this->notebooks;} public function tagsRaw(): array{return $this->tags;} public function noteTagsRaw(): array{return $this->noteTags;} public function trashedRaw(): array{return $this->trashed;}
 public function counts(): array{return ['notes'=>count($this->notes),'notebooks'=>count($this->notebooks),'resources'=>count($this->resources),'tags'=>count($this->tags),'noteTags'=>count($this->noteTags),'revisions'=>count($this->revisions),'unknown'=>count($this->unknown),'trashed'=>count($this->trashed)];}
 public function fullIndex(): array { return ['version'=>3,'search_version'=>1,'count_version'=>5,'built_at'=>gmdate('c'),'fingerprint'=>$this->fingerprint(),'counts'=>$this->counts(),'tree'=>$this->tree(),'notebooks'=>array_values(array_map(fn($n)=>$this->notebookListItem($n),$this->notebooks)),'notes'=>array_values(array_map(fn($n)=>$this->noteListItem($n),$this->notes)),'resources'=>array_values(array_map(fn($r)=>$this->resourceListItem($r),$this->resources)),'tags'=>array_values(array_map(fn($t)=>$this->tagListItem($t),$this->tags)),'noteTags'=>array_values(array_filter($this->noteTags,fn($r)=>!isset($this->trashed[(string)($r['note_id']??'')]))),'orphans'=>$this->orphans(),'trash'=>$this->trashIndex()]; }
 /** Contents of Joplin's trash for the trash view. parent_available tells the client whether a note can go back to its notebook. */
 private function trashIndex(): array{ $notes=[];$books=[]; foreach($this->trashed as $id=>$i){ $p=(string)($i['parent_id']??''); if(($i['type_']??'')==='1'){ $row=$this->noteListItem($i); $row['notebook_title']=$this->anyNotebookTitle($p); $row['deleted_time']=(string)$i['deleted_time']; $row['parent_available']=isset($this->notebooks[$p]); $notes[]=$row; } else { $c=0; foreach($this->trashed as $t){ if(($t['type_']??'')==='1'&&($t['parent_id']??'')===$id)$c++; } $books[]=['id'=>$id,'title'=>$i['_title'],'parent_id'=>$p,'parent_title'=>$this->anyNotebookTitle($p),'updated_time'=>$this->time($i),'deleted_time'=>(string)$i['deleted_time'],'note_count'=>$c]; } } return ['notes'=>$notes,'notebooks'=>$books]; }
 private function anyNotebookTitle(string $id): string{ $n=$this->notebooks[$id]??$this->trashed[$id]??null; return $n&&($n['type_']??'')==='2'?(string)($n['_title']??''):''; }
 public function fingerprint(): string{return self::fingerprintOf($this->folder);}
 /** Changes whenever an item or attachment file is added, removed or rewritten. Cheap: listings only. */
 public static function fingerprintOf(Folder $folder): string { $parts=[]; foreach($folder->getDirectoryListing() as $n){ if($n instanceof File && preg_match('/^[a-f0-9]{32}\.md$/i',$n->getName()))$parts[]=$n->getName().':'.$n->getSize().':'.$n->getMTime(); } if($folder->nodeExists('.resource')){ $rf=$folder->get('.resource'); if($rf instanceof Folder){ foreach($rf->getDirectoryListing() as $n){ if($n instanceof File)$parts[]='.resource/'.$n->getName().':'.$n->getSize().':'.$n->getMTime(); } } } sort($parts); return hash('sha256',implode('|',$parts)); }
 public function note(string $id): ?array { $n=$this->notes[$id]??null; $deleted=false; if($n===null&&isset($this->trashed[$id])&&($this->trashed[$id]['type_']??'')==='1'){$n=$this->trashed[$id];$deleted=true;} if($n===null)return null; $refs=$this->resourceIds((string)($n['_body']??'')); $res=[]; foreach($refs as $rid)$res[]=$this->resourceInfo($rid); $tags=$this->tagsForNote($id); return ['id'=>$id,'title'=>$this->titleAndBody($n)[0],'body'=>$this->titleAndBody($n)[1],'full_body'=>$n['_body']??'','notebook_id'=>$n['parent_id']??'','notebook_title'=>$this->anyNotebookTitle((string)($n['parent_id']??'')),'is_deleted'=>$deleted,'breadcrumb'=>$this->breadcrumb($n['parent_id']??''),'created_time'=>$n['created_time']??'','updated_time'=>$this->time($n),'resources'=>$res,'note_links'=>$this->noteLinks((string)($n['_body']??'')),'tags'=>$tags,'raw'=>$this->publicRaw($n)]; }
 public function item(string $id): ?array{return $this->items[$id]??null;} public function fileFor(string $id): ?File { $name=$id.'.md'; if(!$this->folder->nodeExists($name))return null; $n=$this->folder->get($name); return $n instanceof File?$n:null; }
 public function resourcePayload(string $id): ?array { if(!isset($this->resources[$id]))return null; $rf=$this->resourceFolder(); if(!$rf||!$rf->nodeExists($id))return ['id'=>$id,'error'=>$this->l->t('The attachment file is missing')]; $node=$rf->get($id); if(!$node instanceof File)return ['id'=>$id,'error'=>$this->l->t('The attachment is not a file')]; if($node->getSize()>25*1024*1024)return ['id'=>$id,'error'=>$this->l->t('The attachment is too large to open in Joplix')]; $r=$this->resources[$id]; $mime=$this->resourceMime($r); return ['id'=>$id,'title'=>$r['_title']??$id,'mime'=>$mime,'file_extension'=>$r['file_extension']??'','size'=>$node->getSize(),'is_pdf'=>$this->isPdfResource($r),'data_url'=>'data:'.$mime.';base64,'.base64_encode($node->getContent())]; }
 /** Joplin's timestamp format with milliseconds, so two saves within one second differ for the conflict check. */
 public function now(): string{return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');} public function newId(): string{return bin2hex(random_bytes(16));}
 /** Serialises a new item: body, blank line, metadata in Joplin's field order. */
 public function format(array $item): string{
  $body=$item['_body']??''; $metaOrder=$item['_meta_order']??null;
  foreach(['_file','_title','_is_deleted','_body','_meta_order','_meta_raw','_meta_updates','_had_final_lf'] as $k)unset($item[$k]);
  $lines=[(string)$body,'']; $done=[];
  $order=is_array($metaOrder)&&$metaOrder!==[]?$metaOrder:self::FIELD_ORDER;
  foreach($order as $k){ if(is_string($k)&&array_key_exists($k,$item)){ $lines[]=$k.': '.$item[$k]; $done[$k]=1; } }
  foreach($item as $k=>$v){ if(!isset($done[$k]))$lines[]=$k.': '.$v; }
  return implode("\n",$lines);
 }
 private const FIELD_ORDER=['id','parent_id','created_time','updated_time','is_conflict','latitude','longitude','altitude','author','source_url','is_todo','todo_due','todo_completed','source','source_application','application_data','order','user_created_time','user_updated_time','encryption_cipher_text','encryption_applied','markup_language','is_shared','share_id','conflict_original_id','master_key_id','user_data','deleted_time','is_locked','extracted_resource_ids','mime','filename','file_extension','size','blob_updated_time','ocr_text','ocr_details','ocr_status','ocr_error','ocr_driver_id','icon','type_'];
 /**
  * Writes an existing item back with only $allowedFields changed in its original metadata block; body and
  * every other metadata line stay byte for byte. A field missing from the block is inserted before type_.
  */
 public function formatPreserving(array $item,array $allowedFields=[]): string{
  if(!isset($item['_meta_raw'])||trim((string)$item['_meta_raw'])==='')return $this->format($item);
  $body=(string)($item['_body']??'');
  $meta=trim(str_replace(["\r\n","\r"],"\n",(string)$item['_meta_raw']),"\n");
  foreach(array_fill_keys($allowedFields,true) as $field=>$_){
   if(!array_key_exists($field,$item))continue;
   $line=$field.': '.(string)$item[$field];
   $pattern='/^'.preg_quote((string)$field,'/').':.*$/m';
   if(preg_match($pattern,$meta))$meta=preg_replace_callback($pattern,fn($m)=>$line,$meta,1);
   elseif(preg_match('/^type_:.*$/m',$meta))$meta=preg_replace_callback('/^type_:.*$/m',fn($m)=>$line."\n".$m[0],$meta,1);
   else $meta=rtrim($meta,"\n")."\n".$line;
  }
  $type=(string)($item['type_']??'');
  if($type==='1'&&!preg_match('/^type_:\s*1\s*$/m',$meta))throw new \RuntimeException($this->l->t('Joplin metadata error: type_ is missing after saving'));
  if(isset($item['id'])&&!preg_match('/^id:\s*'.preg_quote((string)$item['id'],'/').'\s*$/m',$meta))throw new \RuntimeException($this->l->t('Joplin metadata error: id is missing after saving'));
  foreach(explode("\n",$meta) as $line){ if(trim($line)===''||strpos($line,':')===false)throw new \RuntimeException($this->l->t('Joplin metadata error: invalid metadata line')); }
  $out=$body."\n\n".rtrim($meta,"\n");
  if(!empty($item['_had_final_lf']))$out.="\n";
  return $out;
 }
 /**
  * Parsed item files of this request by file id: a second model in the same request (after a write) only
  * re-reads files whose etag changed. @var array<int,array{0:string,1:array}>
  */
 private static array $parsed=[];
 /** Called when the cache would grow past one large folder (the background job loads many users in one process). */
 private static function trimCache(): void{if(count(self::$parsed)>50000)self::$parsed=[];}
 private function load(): void{ self::trimCache(); foreach($this->folder->getDirectoryListing() as $node){ if(!$node instanceof File)continue; $name=$node->getName(); if(!preg_match('/^[a-f0-9]{32}\.md$/i',$name))continue; $key=(int)$node->getId(); $etag=(string)$node->getEtag(); if(isset(self::$parsed[$key])&&self::$parsed[$key][0]===$etag&&$etag!==''){$i=self::$parsed[$key][1];}else{$i=$this->parse($node->getContent()); if(empty($i['id']))$i['id']=substr($name,0,32); $i['_file']=$name; $i['_title']=$this->title((string)($i['_body']??'')); $i['_is_deleted']=$this->deleted($i); if($etag!=='')self::$parsed[$key]=[$etag,$i];} $this->items[$i['id']]=$i; $type=(string)($i['type_']??''); if($i['_is_deleted']&&($type==='1'||$type==='2')){$this->trashed[$i['id']]=$i;continue;} switch($type){case'1':$this->notes[$i['id']]=$i;break;case'2':$this->notebooks[$i['id']]=$i;break;case'4':$this->resources[$i['id']]=$i;break;case'5':$this->tags[$i['id']]=$i;break;case'6':$this->noteTags[$i['id']]=$i;break;case'13':$this->revisions[$i['id']]=$i;break;default:$this->unknown[$i['id']]=$i;break;}}}
 /** Only the fields Joplix knows are kept as item fields (so a stray line such as "_body: x" cannot overwrite internal keys); _meta_raw keeps the whole block for writing back. */
 private function parse(string $c): array{ $p=JoplinItem::parse($c); $m=[];$order=[]; foreach($p['metaLines'] as $line){ if(preg_match('/^([A-Za-z0-9_]+):/',$line,$x)&&isset($this->known[$x[1]])&&isset($p['meta'][$x[1]])){ $m[$x[1]]=$p['meta'][$x[1]]; $order[]=$x[1]; } } $m['_meta_order']=$order; $m['_meta_raw']=implode("\n",$p['metaLines']); $m['_had_final_lf']=$p['finalLf']; $m['_body']=$p['body']; return $m; }
 private function title(string $body): string{foreach(preg_split('/\n/',$body)?:[] as $l){$l=trim(strip_tags($l)); if($l!=='')return mb_substr($l,0,180);} return $this->l->t('(untitled)');}
 private function deleted(array $i): bool{if(!isset($i['deleted_time']))return false;$v=trim((string)$i['deleted_time']);return $v!==''&&$v!=='0';} private function time(array $i): string{return (string)($i['updated_time']??$i['user_updated_time']??$i['created_time']??$i['user_created_time']??'');}
 private function noteListItem(array $n): array{ $refs=$this->resourceIds($n['_body']??''); $resources=[]; foreach($refs as $rid)$resources[]=$this->resourceInfo($rid); $tags=$this->tagsForNote($n['id']); return ['id'=>$n['id'],'title'=>$n['_title'],'notebook_id'=>$n['parent_id']??'','notebook_title'=>$this->notebookTitle($n['parent_id']??''),'updated_time'=>$this->time($n),'resource_count'=>count($refs),'tag_count'=>count($tags),'tag_titles'=>array_values(array_map(fn($t)=>$t['title']??'', $tags)),'resource_titles'=>array_values(array_map(fn($r)=>trim(($r['title']??'').' '.($r['mime']??'').' '.($r['file_extension']??'')), $resources)),'search_text'=>$this->searchTextForNote($n,$tags,$resources)]; }
 private function notebookListItem(array $n): array{return ['id'=>$n['id'],'title'=>$n['_title'],'parent_id'=>$n['parent_id']??'','updated_time'=>$this->time($n),'note_count'=>$this->noteCount($n['id'])];}
 private function resourceListItem(array $r): array{return ['id'=>$r['id'],'title'=>$r['_title'],'mime'=>$r['mime']??'','file_extension'=>$r['file_extension']??'','size'=>(int)($r['size']??0),'exists'=>$this->resourceExists($r['id'])];}
 private function tagListItem(array $t): array{return ['id'=>$t['id'],'title'=>$t['_title'],'updated_time'=>$this->time($t)];}
 private function tree(): array{ $ch=[]; foreach($this->notebooks as $id=>$n){ if($this->deleted($n))continue; $p=$n['parent_id']??''; if($p!==''&&!isset($this->notebooks[$p]))$p='__orphan__'; $ch[$p][]=$id;} foreach($ch as &$ids)usort($ids,fn($a,$b)=>strcasecmp($this->notebooks[$a]['_title'],$this->notebooks[$b]['_title'])); unset($ids); $out=[]; foreach($ch['']??[] as $r)$out[]=$this->treeNode($r,$ch); if(!empty($ch['__orphan__'])){ $oo=[]; foreach($ch['__orphan__'] as $id)$oo[]=$this->treeNode($id,$ch); $out[]=['id'=>'__orphan__','title'=>$this->l->t('⚠ Orphaned / unassigned'),'note_count'=>count($this->orphans()['orphan_notes']),'children'=>$oo];} return $out; }
 private function treeNode(string $id,array $ch): array{$n=$this->notebooks[$id];$node=['id'=>$id,'title'=>$n['_title'],'parent_id'=>$n['parent_id']??'','note_count'=>$this->noteCount($id),'children'=>[]]; foreach($ch[$id]??[] as $c)$node['children'][]=$this->treeNode($c,$ch); return $node;}
 private function noteCount(string $id): int{$c=0;foreach($this->notes as $n)if(($n['parent_id']??'')===$id)$c++;return $c;} private function notebookTitle(string $id): string{return isset($this->notebooks[$id])?($this->notebooks[$id]['_title']??''):'';}
 private function breadcrumb(string $id): array{$out=[];$guard=0; while($id!==''&&isset($this->notebooks[$id])&&$guard<20){$n=$this->notebooks[$id]; array_unshift($out,['id'=>$id,'title'=>$n['_title']]); $id=$n['parent_id']??'';$guard++;} return $out;}
 /**
  * Title and body of a note exactly as Joplin splits them: the first line is the title, the line after it
  * (blank in every file Joplin writes) separates, the rest is the body. An untitled note therefore has
  * title '' and keeps its whole text as body. JoplixWriteService::compose() is the inverse; together they
  * return a note to disk byte for byte when nothing was edited. `_title` (first non-empty line, tags
  * stripped, shortened) is only the label for lists.
  * @return array{0:string,1:string}
  */
 private function titleAndBody(array $n): array{$lines=explode("\n",(string)($n['_body']??''));$title=(string)array_shift($lines);if($lines&&$lines[0]==='')array_shift($lines);return [$title,implode("\n",$lines)];}
 private function bodyWithoutDuplicatedTitle(array $n): string{return $this->titleAndBody($n)[1];}
 /** Every ":/<32 hex>" target in a body, lowercase, unique, sorted. Joplin uses this one form for attachments and for links to notes/notebooks. */
 private function linkTargetIds(string $body): array{preg_match_all('/:\/([a-f0-9]{32})/i',$body,$m);$ids=array_values(array_unique(array_map('strtolower',$m[1]??[])));sort($ids);return $ids;}
 /** Type of a known item ('1' note, '2' notebook, '4' resource, ...), '' when the id is not in the sync folder. */
 private function itemType(string $id): string{return (string)($this->items[$id]['type_']??'');}
 /** Link targets that may be attachments: everything except known notes and notebooks. Unknown ids stay, so a missing attachment still shows as missing. */
 private function resourceIds(string $body): array{return array_values(array_filter($this->linkTargetIds($body),fn($id)=>!in_array($this->itemType($id),['1','2'],true)));}
 /** Link targets that are notes (active or trashed): [{id,title,notebook_id,is_deleted}]. */
 private function noteLinks(string $body): array{$out=[];foreach($this->linkTargetIds($body) as $id){if($this->itemType($id)!=='1')continue;$n=$this->items[$id];$out[]=['id'=>$id,'title'=>$n['_title']??$id,'notebook_id'=>$n['parent_id']??'','is_deleted'=>!empty($n['_is_deleted'])];}return $out;}
 private function searchTextForNote(array $n,array $tags,array $resources): string{ $parts=[]; $parts[]=$n['_title']??''; $parts[]=$this->bodyWithoutDuplicatedTitle($n); $parts[]=$this->notebookTitle($n['parent_id']??''); foreach($tags as $t)$parts[]=$t['title']??''; foreach($resources as $r){$parts[]=$r['title']??'';$parts[]=$r['mime']??'';$parts[]=$r['file_extension']??'';} $txt=implode(' ',array_filter(array_map('strval',$parts),fn($v)=>trim($v)!=='')); $txt=preg_replace('/\s+/u',' ',strip_tags($txt)); return mb_substr(trim($txt),0,200000);}
 private function resourceInfo(string $id): array{$r=$this->resources[$id]??[];$mime=$this->resourceMime($r);return ['id'=>$id,'title'=>$r['_title']??$id,'mime'=>$mime,'file_extension'=>$r['file_extension']??'','exists'=>$this->resourceExists($id),'is_image'=>str_starts_with((string)$mime,'image/'),'is_pdf'=>$this->isPdfResource($r),'is_office'=>$this->isOfficeResource($r),'is_openable'=>$this->isOpenableResource($r)];}
 private function isPdfResource(array $r): bool{$mime=strtolower((string)($r['mime']??''));$ext=strtolower((string)($r['file_extension']??''));$title=strtolower((string)($r['_title']??$r['title']??$r['filename']??''));return $mime==='application/pdf'||$ext==='pdf'||str_ends_with($title,'.pdf');}
 private function isOfficeResource(array $r): bool{$ext=strtolower((string)($r['file_extension']??''));$title=strtolower((string)($r['_title']??$r['title']??$r['filename']??''));foreach(self::OFFICE_EXTENSIONS as $e){if($ext===$e||str_ends_with($title,'.'.$e))return true;}return false;}
 private function isOpenableResource(array $r): bool{return $this->isPdfResource($r)||$this->isOfficeResource($r);}
 private function resourceMime(array $r): string{if($this->isPdfResource($r))return 'application/pdf';$mime=(string)($r['mime']??'');if($mime!=='')return $mime;$ext=strtolower((string)($r['file_extension']??''));$title=strtolower((string)($r['_title']??$r['title']??$r['filename']??''));if($ext===''&&preg_match('/\.([a-z0-9]{1,8})$/',$title,$x))$ext=$x[1];return self::MIME_BY_EXTENSION[$ext]??'application/octet-stream';}
 private function resourceExists(string $id): bool{$rf=$this->resourceFolder();return $rf!==null&&$rf->nodeExists($id);} private function resourceFolder(): ?Folder{if(!$this->folder->nodeExists('.resource'))return null;$n=$this->folder->get('.resource');return $n instanceof Folder?$n:null;}
 private function tagsForNote(string $noteId): array{$tags=[];foreach($this->noteTags as $rel){if(($rel['note_id']??'')!==$noteId)continue;$tid=$rel['tag_id']??'';if(isset($this->tags[$tid]))$tags[]=$this->tagListItem($this->tags[$tid]);}return $tags;}
 private function publicRaw(array $n): array{return ['updated_time'=>$this->time($n),'created_time'=>$n['created_time']??'','type_'=>$n['type_']??''];}
 public function orphans(): array{ $on=[];$ob=[];$bt=[]; foreach($this->notes as $id=>$n){$p=$n['parent_id']??''; if(!$this->deleted($n)&&$p!==''&&!isset($this->notebooks[$p]))$on[]=['id'=>$id,'title'=>$n['_title'],'missing_parent_id'=>$p];} foreach($this->notebooks as $id=>$n){$p=$n['parent_id']??'';if($p!==''&&!isset($this->notebooks[$p]))$ob[]=['id'=>$id,'title'=>$n['_title'],'missing_parent_id'=>$p];} foreach($this->noteTags as $id=>$r){$nm=!isset($this->notes[$r['note_id']??'']);$tm=!isset($this->tags[$r['tag_id']??'']);if($nm||$tm)$bt[]=['id'=>$id,'missing_note'=>$nm,'missing_tag'=>$tm];} return ['orphan_notes'=>$on,'orphan_notebooks'=>$ob,'broken_note_tags'=>$bt];}
}
