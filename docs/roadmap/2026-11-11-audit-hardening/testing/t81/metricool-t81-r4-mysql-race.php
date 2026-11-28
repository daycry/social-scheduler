<?php
use App\Models\User;
use App\Models\PushSubscription;
use App\Services\Media\UrlGuard;
use App\Services\Push\PushSubscriptions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
chdir('C:/laragon/www/metricool/.cache/t81-physical');
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$app->make(Kernel::class)->bootstrap();
$storage=str_replace('\\','/',storage_path());
if(config('database.default')!=='mysql'||!str_starts_with($storage,'C:/laragon/www/metricool/.cache/t81-physical/.cache/mysql-t81-r4')||config('database.connections.mysql.database')!=='metricool_t81_r4_race')throw new RuntimeException('Owned isolated MySQL required.');
$app->instance(UrlGuard::class,new UrlGuard(fn()=>['93.184.216.34']));
$mode=$argv[1];
if($mode==='seed'){
 DB::table('push_subscriptions')->delete();
 $cfg=$storage.'/fixture.cnf';file_put_contents($cfg,"[req]\ndistinguished_name=dn\n[dn]\nCN=fixture\n");
 $k=openssl_pkey_new(['config'=>$cfg,'private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$ec=openssl_pkey_get_details($k)['ec'];unlink($cfg);
 $enc=fn($s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');
 $keys=['public'=>$enc("\x04".str_pad($ec['x'],32,"\x00",STR_PAD_LEFT).str_pad($ec['y'],32,"\x00",STR_PAD_LEFT)),'private'=>$enc(str_pad($ec['d'],32,"\x00",STR_PAD_LEFT))];
 file_put_contents($storage.'/keys.json',json_encode($keys));
 echo json_encode(['users'=>[User::factory()->create()->id,User::factory()->create()->id]]);
}elseif($mode==='worker'){
 $keys=json_decode(file_get_contents($storage.'/keys.json'),true);
 config()->set(['push.enabled'=>true,'push.vapid.subject'=>'mailto:fixture@example.test','push.vapid.public_key'=>$keys['public'],'push.vapid.private_key'=>$keys['private']]);
 $ready=$argv[3];$gate=$argv[4];foreach([$ready,$gate]as$path)if(!str_starts_with(str_replace('\\','/',$path),$storage.'/'))throw new RuntimeException('Barrier outside own storage.');
 DB::listen(function($query)use($ready,$gate){if(str_contains($query->sql,'push_subscriptions')&&str_contains(strtolower($query->sql),'for update')){file_put_contents($ready,'ready');$end=microtime(true)+15;while(!is_file($gate)){if(microtime(true)>$end)throw new RuntimeException('Barrier timeout.');usleep(10000);}}});
 try{$s=app(PushSubscriptions::class)->subscribe(User::findOrFail((int)$argv[2]),['endpoint'=>'https://push.example.test/race-'.$argv[2],'keys'=>['p256dh'=>$keys['public'],'auth'=>'AgICAgICAgICAgICAgICAg'],'device_name'=>'Race']);echo json_encode(['status'=>'created','id'=>$s->id]);}
 catch(QueryException $e){echo json_encode(['status'=>'db_error','driver_code'=>$e->errorInfo[1]??null]);}
}elseif($mode==='verify'){echo json_encode(['devices'=>PushSubscription::withoutUserScope()->count(),'owners'=>PushSubscription::withoutUserScope()->distinct()->count('user_id')]);}