<?php
declare(strict_types=1);
require __DIR__.'/../config/app.php';
$message='';$error='';$_SESSION['reset_csrf'] ??= bin2hex(random_bytes(32));
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($_SESSION['reset_csrf'],(string)($_POST['csrf_token']??''))) throw new RuntimeException('Session expired.');
  if(($_SESSION['last_reset_request']??0)>time()-60)throw new RuntimeException('Please try again in a minute.');
  $_SESSION['last_reset_request']=time();
  $email=trim((string)($_POST['email']??''));
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>255)throw new RuntimeException('Enter a valid email address.');
  $pdo=at_db();$q=$pdo->prepare("SELECT user_id FROM users WHERE email=? AND status='Active' LIMIT 1");$q->execute([$email]);
  $message='If an eligible account exists, a password reset email will be sent.';
  if($q->fetch()){
   $token=bin2hex(random_bytes(32));$digest=hash('sha256',$token);$now=date('Y-m-d H:i:s');
   $q=$pdo->prepare('REPLACE INTO password_reset_tokens(email,token,created_at) VALUES (?,?,?)');$q->execute([$email,$digest,$now]);
   $base=rtrim((string)aquatrackEnv('APP_URL',''),'/');
   if(!preg_match('~^https?://[^\s]+$~',$base))throw new RuntimeException('Password reset not configured.');
   $url=$base.'/auth/reset.php?email='.rawurlencode($email).'&token='.rawurlencode($token);
   $from=(string)aquatrackEnv('MAIL_FROM','');
   if(!filter_var($from,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Email sender not configured.');
   $subject='AquaTrack password reset';
   $body="To reset your AquaTrack password, open this link within 60 minutes:\n\n".$url."\n\nIf you did not request this, ignore the email.";
   if(!@mail($email,$subject,$body,'From: '.$from."\r\nContent-Type: text/plain; charset=UTF-8"))error_log('[AquaTrack forgot] mail delivery failed. Configure a transactional email service on hosting.');
  }
 }catch(Throwable $e){$error=at_failure($e,'forgot-password');}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AquaTrack | Reset password</title><link rel="stylesheet" href="../assets/css/login.css"></head><body><div class="page"><a class="back-link" href="login.php">← Back to Login</a><main class="login-card"><section class="intro"><div class="intro-copy"><h1>Account recovery</h1><p>Enter your registered email. We'll send a one-time password reset link if the account is eligible.</p></div></section><section class="form-panel"><span class="eyebrow">SECURE ACCOUNT ACCESS</span><h2>Forgot password</h2><?php if($message):?><p><?=at_h($message)?></p><?php endif;?><?php if($error):?><div class="error" role="alert"><?=at_h($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=at_h($_SESSION['reset_csrf'])?>"><div class="field"><label for="email">Account email</label><input type="email" id="email" name="email" required maxlength="255" autocomplete="email"></div><button class="submit" type="submit">Request reset link →</button></form></section></main></div></body></html>
