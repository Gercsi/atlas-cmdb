<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/vendor/autoload.php';
$command=$argv[1]??'help';
if($command==='storage-path'){$config=Cmdb\Config::read();$storage=$config['storage']??Cmdb\Config::directory();Cmdb\Config::ensurePrivateDirectory($storage);echo $storage;exit;}
$a=new Cmdb\App;
if($command==='migrate'){Cmdb\Schema::migrate($a->db);echo "Migration complete\n";}
elseif($command==='import-preview'){$path=$argv[2]??$a->config['source']??null;if(!is_string($path)||!is_file($path))throw new RuntimeException('Add meg az importálandó XLSX fájl útvonalát.');$p=(new Cmdb\Importer($a))->preview($path,$argv[3]??'preserve');echo json_encode(array_diff_key($p,array_flip(['rows','references'])),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";}
elseif($command==='import-commit'){echo json_encode((new Cmdb\Importer($a))->commit($argv[2]),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";}
elseif($command==='check'){foreach(Cmdb\Schema::TYPES as $t)echo "$t: ".$a->one("SELECT COUNT(*) n FROM `$t`")['n']."\n";echo json_encode((new Cmdb\Graph($a))->query(['view'=>'servers'])['meta'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";}
elseif($command==='create-user'){$username=$argv[2]??'';$role=$argv[3]??'admin';$password=getenv('CMDB_NEW_PASSWORD');if(!$username||!$password||strlen($password)<12||!in_array($role,['admin','editor','viewer']))throw new RuntimeException('Username, role and CMDB_NEW_PASSWORD (12+ chars) required.');$a->run('INSERT INTO users VALUES (?,?,?,?,?,1)',[Cmdb\App::id(),$username,password_hash($password,PASSWORD_DEFAULT),$role,'[]']);echo "User created\n";}
else echo "Commands: migrate, import-preview [path] [preserve|binary], import-commit ID, check, create-user NAME ROLE (CMDB_NEW_PASSWORD env)\n";
