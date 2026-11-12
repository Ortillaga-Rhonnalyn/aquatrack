<?php
declare(strict_types=1);
require __DIR__.'/../config/app.php';
$_SESSION['reset_form_csrf'] ??=bin2hex(random_bytes(32));$error='';$done=false;
$email=(string)($_POST['email']??$_GET['email']??'');$token=(string)($_POST['token']??$_GET['token']??'');
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!preg_match('/^[a-f0-9]{64}$/D',$token)){$error='Invalid or expired reset link.';}
elseif($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($_SESSION['reset_form_csrf'],(string)($_POST['csrf_token']??'')))throw new RuntimeException('Session expired.');
  $password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm']??'');
  if(strlen($password)<12||strlen($password)>72)throw new RuntimeException('Password must be 12–72 characters.');
  if($password!==$confirm)throw new RuntimeException('Passwords do not match.');
  $pdo=at_db();$pdo->beginTransaction();
  $q=$pdo->prepare('SELECT token,created_at FROM password_reset_tokens WHERE email=? FOR UPDATE');$q->execute([$email]);$record=$q->fetch();
  if(!$record||!hash_equals((string)$record['token'],hash('sha256',$token))||strtotime((string)$record['created_at'])<time()-3600)throw new RuntimeException('Invalid or expired reset link.');
  $q=$pdo->prepare("UPDATE users SET password=?,updated_at=NOW() WHERE email=? AND status='Active'");$q->execute([password_hash($password,PASSWORD_BCRYPT,['cost'=>12]),$email]);
  if($q->rowCount()<1)throw new RuntimeException('Account unavailable.');
  $q=$pdo->prepare('DELETE FROM password_reset_tokens WHERE email=?');$q->execute([$email]);$pdo->commit();$done=true;
  if(isset($_SESSION['user_id'])){$_SESSION=[];session_regenerate_id(true);}
 }catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$error=at_failure($e,'reset-password');}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AquaTrack | New password</title><link rel="stylesheet" href="../assets/css/login.css"></head><body><div class="page"><a class="back-link" href="login.php">← Login</a><main class="login-card"><section class="intro"><div class="intro-copy"><h1>Secure your account</h1><p>Create a new password for your AquaTrack login.</p></div></section><section class="form-panel"><h2>Reset password</h2><?php if($done):?><p>Password updated. <a href="login.php">Sign in now</a>.</p><?php elseif($error && $_SERVER['REQUEST_METHOD']!=='POST'):?><div class="error"><?=at_h($error)?></div><?php else:?><?php if($error):?><div class="error" role="alert"><?=at_h($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=at_h($_SESSION['reset_form_csrf'])?>"><input type="hidden" name="email" value="<?=at_h($email)?>"><input type="hidden" name="token" value="<?=at_h($token)?>"><div class="field"><label>New password<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label></div><div class="field"><label>Confirm password<input type="password" name="confirm" minlength="12" maxlength="72" autocomplete="new-password" required></label></div><button class="submit" type="submit">Set new password</button></form><?php endif;?></section></main></div></body></html>
