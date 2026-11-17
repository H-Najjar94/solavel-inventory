<?php
declare(strict_types=1);
// Read-only. Signed inventory and Central registration are mandatory; there is no override.
[$script,$candidate,$descriptor,$finance,$baseline]=array_pad($argv,5,null);
foreach([$candidate,$finance,$baseline] as $path){if(!$path||realpath($path)!==$path||!str_starts_with($path,'/var/www/solavel-')||!is_dir($path))throw new RuntimeException('Immutable application roots required');}
require $finance.'/vendor/autoload.php';
use App\Spsb\ApprovalV1;
function data(string $path):array{return json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}
$d=data($descriptor);$selected=$d['products']['foundation'];$artifact=realpath($selected['artifact_realpath']);
if(!$artifact||!str_starts_with($artifact,'/var/lib/solavel/spsb/approved/'))throw new RuntimeException('Unapproved schema artifact path');
$approval=(new ApprovalV1)->verifyApproved($artifact,data('/etc/solavel/spsb/approval-keyring.json'),[]);
if(($selected['candidate_id']??null)!==$approval['candidate_id']||($selected['content_id']??null)!==$approval['content_id']||!hash_equals($selected['approval_sha256']??'',hash_file('sha256',$artifact.'/approval.json')))throw new RuntimeException('Selected descriptor identity differs from signed approval');
$inventory=data($artifact.'/payload/source-inventory.json');$signed=[];$dirs=[];
foreach($inventory['directories']as$row)if($row['app']==='inventory')$dirs[]=$row['path'];
foreach($inventory['sources']as$row)if($row['app']==='inventory')$signed[$row['path']]=$row['sha256'];
if(!$dirs||!$signed)throw new RuntimeException('Signed Stock inventory missing');
function inventory(string $root,array $dirs):array{$out=[];foreach($dirs as$dir){if(!is_dir($root.'/'.$dir))throw new RuntimeException('Missing migration directory');foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS))as$file){if($file->isLink())throw new RuntimeException('Migration symlink refused');if($file->isFile()&&$file->getExtension()==='php')$out[substr($file->getPathname(),strlen($root)+1)]=hash_file('sha256',$file->getPathname());}}ksort($out);return$out;}
$actual=inventory($candidate,$dirs);ksort($signed);if($actual!==$signed)throw new RuntimeException('STOCK_SCHEMA_PREFLIGHT_REFUSED: source inventory differs from signed schema');
$previous=inventory($baseline,$dirs);
foreach($previous as$path=>$hash)if(!isset($actual[$path])||!hash_equals($hash,$actual[$path]))throw new RuntimeException('Existing Stock migration changed or disappeared: '.$path);
$m=require '/var/www/solavel-central/current/config/tenant_manifests.php';$registered=$m['products']['inventory']['baseline_followup_paths']??[];
$new=array_keys(array_diff_key($actual,$previous));foreach($new as$path)if(!in_array($path,$registered,true))throw new RuntimeException('STOCK_SCHEMA_PREFLIGHT_REFUSED: unregistered Central Stock migration '.$path);
echo json_encode(['status'=>'PASS','foundation'=>$selected['candidate_id'],'stock_inventory_files'=>count($actual),'new_registered_paths'=>$new,'signature'=>'verified','override'=>false]).PHP_EOL;
