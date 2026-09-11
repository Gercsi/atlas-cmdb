<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/vendor/autoload.php';$a=new Cmdb\App;
// Explicit maintenance command; not scheduled automatically. Dry run by default.
$apply=in_array('--apply',$argv,true);$hours=max(1,(int)($a->config['export_retention_hours']??24));$jobs=$a->all("SELECT id,path FROM jobs WHERE created_at < UTC_TIMESTAMP()-INTERVAL $hours HOUR AND status IN ('completed','cancelled','failed')");
foreach($jobs as $j){$path=$j['path'];if(!$path||!is_file($path))continue;$real=realpath($path);$root=realpath($a->config['storage']);if(!str_starts_with(strtolower($real),strtolower($root).DIRECTORY_SEPARATOR))throw new RuntimeException('Storage boundary violation.');echo ($apply?'Remove: ':'Would remove: ').basename($path)."\n";if($apply){unlink($real);$a->run("UPDATE jobs SET status='expired',path=NULL WHERE id=?",[$j['id']]);}}
// Import raw rows and audit history remain retained until a separate approved retention policy is implemented.
