<?php
declare(strict_types=1);
/** One-time CLI bootstrap for a fresh EMPTY database; cannot be called from a browser. */
if (PHP_SAPI !== 'cli') {http_response_code(403);exit('CLI only.');}
require __DIR__.'/../config/env.php';
$fields=['ADMIN_NAME','ADMIN_USERNAME','ADMIN_EMAIL','ADMIN_PASSWORD'];$values=[];
foreach($fields as $field){$values[$field]=getenv('AQUATRACK_'.$field)?:'';}
if(in_array('',$values,true)){fwrite(STDERR,"Set AQUATRACK_ADMIN_NAME, _USERNAME, _EMAIL and _PASSWORD environment variables.\n");exit(1);}
if(strlen($values['ADMIN_PASSWORD'])<12||strlen($values['ADMIN_PASSWORD'])>72||!filter_var($values['ADMIN_EMAIL'],FILTER_VALIDATE_EMAIL)||!preg_match('/^[A-Za-z0-9_.-]{3,50}$/',$values['ADMIN_USERNAME'])){fwrite(STDERR,"Invalid admin credentials: 12-72 character password, valid email, and 3-50 character username required.\n");exit(1);}
try{
 $pdo=require __DIR__.'/../config/database.php';
 $q=$pdo->query("SELECT COUNT(*) FROM users WHERE role='Admin'");
 if((int)$q->fetchColumn()>0){fwrite(STDERR,"Admin already exists. No changes made.\n");exit(1);}
 $stmt=$pdo->prepare("INSERT INTO users(full_name,username,password,role,status,email,created_at,updated_at) VALUES (?,? ,?,'Admin','Active',?,NOW(),NOW())");
 $stmt->execute([$values['ADMIN_NAME'],$values['ADMIN_USERNAME'],password_hash($values['ADMIN_PASSWORD'],PASSWORD_BCRYPT,['cost'=>12]),$values['ADMIN_EMAIL']]);
 fwrite(STDOUT,"Admin created. Immediately unset credentials from shell environment.\n");
}catch(Throwable $e){fwrite(STDERR,"Could not create admin; check DB configuration.\n");error_log('[AquaTrack admin bootstrap] '.$e->getMessage());exit(1);}
