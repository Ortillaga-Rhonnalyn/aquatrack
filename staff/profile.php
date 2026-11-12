<?php
declare(strict_types=1);
require __DIR__.'/../config/app.php';require __DIR__.'/../includes/staff-layout.php';
$staff=at_staff();$pdo=at_db();$error='';$flash=at_flash();
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  at_check_csrf();$old=(string)($_POST['current']??'');$new=(string)($_POST['new']??'');
  if(strlen($new)<12||strlen($new)>72||$new!==(string)($_POST['confirm']??''))throw new RuntimeException('New passwords must match and be 12–72 characters.');
  $q=$pdo->prepare('SELECT password FROM users WHERE user_id=?');$q->execute([$staff['user_id']]);
  if(!password_verify($old,(string)$q->fetchColumn()))throw new RuntimeException('Current password is incorrect.');
  $pdo->prepare('UPDATE users SET password=?,updated_at=NOW() WHERE user_id=?')->execute([password_hash($new,PASSWORD_BCRYPT,['cost'=>12]),$staff['user_id']]);
  at_flash('Password changed.');at_redirect('profile.php');
 }
}catch(Throwable $e){$error=at_failure($e,'staff-profile');}
at_staff_open('My Profile','profile',$staff,'Your account information and password settings.');
if($flash):?><div class="op-notice"><?=at_h($flash)?></div><?php endif;if($error):?><div class="op-notice bad"><?=at_h($error)?></div><?php endif;?>
<div class="op-grid"><section class="op-card"><h2>Account details</h2><p><strong>Name:</strong> <?=at_h($staff['full_name'])?></p><p><strong>Username:</strong> <?=at_h($staff['username'])?></p><p><strong>Role:</strong> <?=at_h($staff['role'])?></p><p><strong>Email:</strong> <?=at_h($staff['email']??'Not set')?></p><p class="op-hint">Ask an administrator to update these account details.</p></section><section class="op-card"><h2>Change password</h2><form method="post"><input type="hidden" name="csrf_token" value="<?=at_h(at_csrf())?>"><label class="op-field">Current password<input type="password" name="current" required autocomplete="current-password"></label><label class="op-field">New password<input type="password" name="new" minlength="12" maxlength="72" autocomplete="new-password" required></label><label class="op-field">Confirm new password<input type="password" name="confirm" minlength="12" maxlength="72" autocomplete="new-password" required></label><button type="submit" class="op-button">Change Password</button></form></section></div>
<?php at_staff_close();
