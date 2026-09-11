<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/vendor/autoload.php';
if(!Cmdb\Config::exists()){fwrite(STDERR,"No configuration yet. Start the local application and complete its installation wizard first.\n");exit(1);}
$app=new Cmdb\App;Cmdb\Schema::migrate($app->db);echo "Migrations complete. Open the local application to create your first admin.\n";
