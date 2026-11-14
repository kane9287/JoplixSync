<?php
namespace OCA\Joplix\Service;
use OCP\Files\Folder;

class JoplixIndexService {
 private Folder $userFolder; private string $indexName;
 public function __construct(Folder $userFolder,string $joplinPath){$this->userFolder=$userFolder;$this->indexName='index_'.substr(hash('sha256',$joplinPath),0,16).'.json';}
 private function indexFolder(): Folder{ if(!$this->userFolder->nodeExists('.joplix'))$this->userFolder->newFolder('.joplix'); $n=$this->userFolder->get('.joplix'); if(!$n instanceof Folder)throw new \RuntimeException('.joplix ist kein Ordner'); return $n; }
 public function read(): ?array{ $f=$this->indexFolder(); if(!$f->nodeExists($this->indexName))return null; $n=$f->get($this->indexName); $data=json_decode($n->getContent(),true); return is_array($data)?$data:null; }
 public function write(array $index): void{ $f=$this->indexFolder(); $json=json_encode($index,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); if($f->nodeExists($this->indexName))$f->get($this->indexName)->putContent($json); else $f->newFile($this->indexName,$json); }
 public function status(string $currentFingerprint): array{ $idx=$this->read(); if(!$idx)return ['exists'=>false,'current'=>false,'built_at'=>'','fingerprint'=>'']; return ['exists'=>true,'current'=>(($idx['fingerprint']??'')===$currentFingerprint),'built_at'=>$idx['built_at']??'','fingerprint'=>$idx['fingerprint']??'']; }
 public function clear(): void{ $f=$this->indexFolder(); if($f->nodeExists($this->indexName))$f->get($this->indexName)->delete(); }
}
