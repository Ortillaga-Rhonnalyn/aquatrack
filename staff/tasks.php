<?php
declare(strict_types=1);
require __DIR__.'/../config/app.php';require __DIR__.'/../includes/staff-layout.php';
$staff=at_staff();$pdo=at_db();$error='';$flash=at_flash();
if(!in_array($staff['role'],['Staff','Manager','Delivery Staff'],true)){http_response_code(403);exit('Delivery permission required.');}
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  at_check_csrf();$action=(string)($_POST['action']??'status');
  $pdo->beginTransaction();
  if($action==='schedule'){
   if(!in_array($staff['role'],['Staff','Manager'],true))throw new RuntimeException('Only staff or managers can schedule deliveries.');
   $orderId=at_int($_POST['order_id']??null);$address=trim((string)($_POST['delivery_address']??''));$date=(string)($_POST['delivery_date']??'');$notes=trim((string)($_POST['notes']??''));
   if(strlen($address)<3||strlen($address)>255||strlen($notes)>1000)throw new RuntimeException('Provide a valid delivery address and notes.');
   if($date!==''&&(!DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$date)||DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$date)->format('Y-m-d\\TH:i')!==$date))throw new RuntimeException('Invalid delivery date/time.');
   $q=$pdo->prepare("SELECT order_status FROM orders WHERE order_id=? AND created_by=? FOR UPDATE");$q->execute([$orderId,$staff['user_id']]);$orderStatus=$q->fetchColumn();if(!in_array($orderStatus,['Confirmed','Preparing'],true))throw new RuntimeException('Only your confirmed or preparing orders can be scheduled.');
   $q=$pdo->prepare('SELECT delivery_id FROM deliveries WHERE order_id=?');$q->execute([$orderId]);if($q->fetch())throw new RuntimeException('This order already has a delivery.');
   $q=$pdo->prepare("INSERT INTO deliveries(order_id,delivery_address,delivery_date,assigned_to,delivery_status,notes,created_at,updated_at) VALUES (?,?,?,?, 'Pending',?,NOW(),NOW())");$q->execute([$orderId,$address,$date!==''?str_replace('T',' ',$date).':00':null,$staff['user_id'],$notes?:null]);$id=(int)$pdo->lastInsertId();at_log($pdo,(int)$staff['user_id'],'Staff scheduled delivery','deliveries',$id);
   $pdo->commit();at_flash('Delivery scheduled and assigned to you.');at_redirect('tasks.php');
  }
  if($action!=='status')throw new RuntimeException('Invalid delivery action.');
  $id=at_int($_POST['delivery_id']??null);$next=(string)($_POST['status']??'');
  $q=$pdo->prepare('SELECT delivery_status,order_id FROM deliveries WHERE delivery_id=? AND assigned_to=? FOR UPDATE');$q->execute([$id,$staff['user_id']]);$d=$q->fetch();
  if(!$d || !(($d['delivery_status']==='Pending' && $next==='Ongoing')||($d['delivery_status']==='Ongoing' && $next==='Completed')))throw new RuntimeException('This delivery cannot be updated.');
  $q=$pdo->prepare('SELECT order_status FROM orders WHERE order_id=? FOR UPDATE');$q->execute([$d['order_id']]);$o=$q->fetch();
  if(!$o||$o['order_status']==='Cancelled')throw new RuntimeException('Order was cancelled.');
  $q=$pdo->prepare('UPDATE deliveries SET delivery_status=?,updated_at=NOW() WHERE delivery_id=? AND assigned_to=?');$q->execute([$next,$id,$staff['user_id']]);
  $orderStatus=$next==='Completed'?'Completed':'Out for Delivery';
  $pdo->prepare('UPDATE orders SET order_status=?,updated_at=NOW() WHERE order_id=?')->execute([$orderStatus,$d['order_id']]);
  if($next==='Completed')at_ensure_sale($pdo,(int)$d['order_id'],(int)$staff['user_id']);
  at_log($pdo,(int)$staff['user_id'],'Delivery status: '.$next,'deliveries',$id);
  $pdo->commit();at_flash('Delivery updated.');at_redirect('tasks.php');
 }
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=at_failure($e,'staff-deliveries');}
$eligible=[];
if(in_array($staff['role'],['Staff','Manager'],true)){$q=$pdo->prepare("SELECT o.order_id,c.customer_name,c.address FROM orders o JOIN customers c ON c.customer_id=o.customer_id WHERE o.created_by=? AND o.order_status IN ('Confirmed','Preparing') AND NOT EXISTS(SELECT 1 FROM deliveries d WHERE d.order_id=o.order_id) ORDER BY o.order_id DESC");$q->execute([$staff['user_id']]);$eligible=$q->fetchAll();}
$q=$pdo->prepare('SELECT d.*,c.customer_name FROM deliveries d JOIN orders o ON o.order_id=d.order_id JOIN customers c ON c.customer_id=o.customer_id WHERE d.assigned_to=? ORDER BY CASE d.delivery_status WHEN \'Ongoing\' THEN 0 WHEN \'Pending\' THEN 1 ELSE 2 END,d.delivery_date DESC LIMIT 120');
$q->execute([$staff['user_id']]);$deliveries=$q->fetchAll();
at_staff_open('Assigned Deliveries','tasks',$staff,'See your deliveries and update fulfillment progress.');
if($flash):?><div class="op-notice"><?=at_h($flash)?></div><?php endif;if($error):?><div class="op-notice bad"><?=at_h($error)?></div><?php endif;?>
<?php if(in_array($staff['role'],['Staff','Manager'],true)):?><section class="op-card"><h2>Schedule my delivery</h2><p class="op-description">Your confirmed or preparing orders are assigned to you for delivery.</p><form method="post" class="op-filter"><input type="hidden" name="csrf_token" value="<?=at_h(at_csrf())?>"><input type="hidden" name="action" value="schedule"><label class="op-field">Order<select required name="order_id" id="deliveryOrder"><option value="">Select order</option><?php foreach($eligible as $o):?><option value="<?=$o['order_id']?>" data-address="<?=at_h($o['address'])?>">#<?=$o['order_id']?> — <?=at_h($o['customer_name'])?></option><?php endforeach;?></select></label><label class="op-field">Address<textarea required name="delivery_address" id="deliveryAddress" maxlength="255"></textarea></label><label class="op-field">Date & time<input type="datetime-local" name="delivery_date"></label><label class="op-field">Notes<textarea name="notes" maxlength="1000"></textarea></label><button class="op-button" type="submit" <?=!$eligible?'disabled':''?>>Schedule Delivery</button></form></section><?php endif;?>
<section class="op-card"><h2>Your delivery assignments</h2><p class="op-description">Only records assigned to your account are visible here.</p><div class="op-table-wrap"><table class="op-table"><thead><tr><th>Order / Customer</th><th>Delivery address</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($deliveries as $d):?><tr><td>Order #<?=$d['order_id']?> – <?=at_h($d['customer_name'])?></td><td><?=at_h($d['delivery_address'])?></td><td><?=at_h($d['delivery_date']??'Not scheduled')?></td><td><span class="op-pill"><?=at_h($d['delivery_status'])?></span></td><td><?php if(in_array($d['delivery_status'],['Pending','Ongoing'],true)):$next=$d['delivery_status']==='Pending'?'Ongoing':'Completed';?><form method="post" class="op-inline-form"><input type="hidden" name="csrf_token" value="<?=at_h(at_csrf())?>"><input type="hidden" name="delivery_id" value="<?=$d['delivery_id']?>"><input type="hidden" name="status" value="<?=$next?>"><button type="submit"><?= $next==='Ongoing'?'Start Delivery':'Mark Delivered' ?></button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?><?php if(!$deliveries):?><tr><td colspan="5">No assigned deliveries yet.</td></tr><?php endif;?></tbody></table></div></section>
<script>document.getElementById('deliveryOrder')?.addEventListener('change',e=>{document.getElementById('deliveryAddress').value=e.target.selectedOptions[0]?.dataset.address||'';});</script>
<?php at_staff_close();
