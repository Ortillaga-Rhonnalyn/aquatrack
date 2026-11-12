<?php
declare(strict_types=1);
require __DIR__.'/../../config/app.php';
require __DIR__.'/../../includes/admin-layout.php';
$admin=at_admin();$pdo=at_db();$error='';$flash=at_flash();
try {
 if($_SERVER['REQUEST_METHOD']==='POST'){
  at_check_csrf();$action=(string)($_POST['action']??'');$id=at_int($_POST['delivery_id']??null);
  $pdo->beginTransaction();
  if($action==='create'){
   $orderId=at_int($_POST['order_id']??null);$staffId=at_int($_POST['assigned_to']??null);
   $date=(string)($_POST['delivery_date']??'');$address=trim((string)($_POST['delivery_address']??''));
   $notes=trim((string)($_POST['notes']??''));
   if(!$orderId||strlen($address)<3||strlen($address)>255||strlen($notes)>1000)throw new RuntimeException('Provide a valid order, address, and optional notes.');
   if($date!=='' && (!DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date) || DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date)->format('Y-m-d\TH:i')!==$date))throw new RuntimeException('Invalid delivery date/time.');
   $q=$pdo->prepare('SELECT order_status FROM orders WHERE order_id=? FOR UPDATE');$q->execute([$orderId]);$order=$q->fetch();
   if(!$order || !in_array($order['order_status'],['Confirmed','Preparing','Out for Delivery'],true))throw new RuntimeException('Only confirmed or preparing delivery orders can be scheduled.');
   $exists=$pdo->prepare('SELECT delivery_id FROM deliveries WHERE order_id=? LIMIT 1');$exists->execute([$orderId]);
   if($exists->fetch())throw new RuntimeException('This order already has a delivery record.');
   if($staffId){$q=$pdo->prepare("SELECT user_id FROM users WHERE user_id=? AND status='Active' AND role IN ('Staff','Delivery Staff','Manager')");$q->execute([$staffId]);if(!$q->fetch())throw new RuntimeException('Select an active delivery staff member.');}
   $q=$pdo->prepare("INSERT INTO deliveries (order_id,delivery_address,delivery_date,assigned_to,delivery_status,notes,created_at,updated_at) VALUES (?,?,?,?, 'Pending',?,NOW(),NOW())");
   $q->execute([$orderId,$address,$date!==''?str_replace('T',' ',$date).':00':null,$staffId?:null,$notes?:null]);
   $id=(int)$pdo->lastInsertId();at_log($pdo,(int)$admin['user_id'],'Scheduled delivery','deliveries',$id);
  }elseif($action==='status'){
   $new=(string)($_POST['status']??'');
   $q=$pdo->prepare('SELECT d.*,o.order_status FROM deliveries d JOIN orders o ON o.order_id=d.order_id WHERE d.delivery_id=? FOR UPDATE');$q->execute([$id]);$delivery=$q->fetch();
   $trans=['Pending'=>['Ongoing','Cancelled'],'Ongoing'=>['Completed','Cancelled'],'Completed'=>[],'Cancelled'=>[]];
   if(!$delivery || !in_array($new,$trans[$delivery['delivery_status']]??[],true))throw new RuntimeException('Invalid delivery status transition.');
   if($new==='Ongoing' && !$delivery['assigned_to'])throw new RuntimeException('Assign a delivery staff member first.');
   if($new==='Completed' && $delivery['order_status']==='Cancelled')throw new RuntimeException('Cannot complete a cancelled order.');
   $q=$pdo->prepare('UPDATE deliveries SET delivery_status=?,updated_at=NOW() WHERE delivery_id=?');$q->execute([$new,$id]);
   if($new==='Ongoing' && in_array($delivery['order_status'],['Confirmed','Preparing'],true)){
      $pdo->prepare("UPDATE orders SET order_status='Out for Delivery',updated_at=NOW() WHERE order_id=?")->execute([$delivery['order_id']]);
   }
   if($new==='Completed'){
      $pdo->prepare("UPDATE orders SET order_status='Completed',updated_at=NOW() WHERE order_id=? AND order_status<>'Completed'")->execute([$delivery['order_id']]);
      at_ensure_sale($pdo,(int)$delivery['order_id'],(int)$admin['user_id']);
   }
   at_log($pdo,(int)$admin['user_id'],'Delivery status: '.$new,'deliveries',$id);
  }else throw new RuntimeException('Unknown request.');
  $pdo->commit();at_flash('Delivery saved successfully.');at_redirect();
 }
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=at_failure($e,'deliveries');}
$orders=$pdo->query("SELECT o.order_id,c.customer_name,c.address,o.order_status FROM orders o JOIN customers c ON c.customer_id=o.customer_id WHERE o.order_status IN ('Confirmed','Preparing','Out for Delivery') AND NOT EXISTS(SELECT 1 FROM deliveries d WHERE d.order_id=o.order_id) ORDER BY o.order_id DESC LIMIT 150")->fetchAll();
$staff=$pdo->query("SELECT user_id,full_name FROM users WHERE role IN ('Staff','Delivery Staff','Manager') AND status='Active' ORDER BY full_name")->fetchAll();
$rows=$pdo->query('SELECT d.*,c.customer_name,u.full_name FROM deliveries d JOIN orders o ON o.order_id=d.order_id JOIN customers c ON c.customer_id=o.customer_id LEFT JOIN users u ON u.user_id=d.assigned_to ORDER BY d.delivery_id DESC LIMIT 100')->fetchAll();
$counts=['total'=>count($rows),'pending'=>0,'ongoing'=>0,'completed'=>0];foreach($rows as $r){$x=strtolower($r['delivery_status']);if(isset($counts[$x]))$counts[$x]++;}
at_open('Delivery Management','deliveries',(string)$admin['full_name'],'Schedule deliveries, assign staff, and record fulfillment.');
if($flash):?><div class="op-notice" role="status"><?=at_h($flash)?></div><?php endif;
if($error):?><div class="op-notice bad" role="alert"><?=at_h($error)?></div><?php endif;?>
<div class="op-summary"><article><span>Recent deliveries</span><strong><?=count($rows)?></strong></article><article><span>Pending</span><strong><?=$counts['pending']?></strong></article><article><span>Ongoing</span><strong><?=$counts['ongoing']?></strong></article><article><span>Completed</span><strong><?=$counts['completed']?></strong></article></div>
<div class="op-grid"><section class="op-card"><h2>Schedule a delivery</h2><p class="op-description">Choose a confirmed order that does not yet have a delivery.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?=at_h(at_csrf())?>"><input type="hidden" name="action" value="create">
<label class="op-field">Order *<select required name="order_id" id="deliveryOrder"><option value="">Select an eligible order</option><?php foreach($orders as $o):?><option value="<?=$o['order_id']?>" data-address="<?=at_h($o['address'])?>">#<?=$o['order_id']?> — <?=at_h($o['customer_name'])?> (<?=at_h($o['order_status'])?>)</option><?php endforeach;?></select></label>
<label class="op-field">Delivery address *<textarea id="deliveryAddress" name="delivery_address" maxlength="255" required></textarea></label>
<label class="op-field">Scheduled date & time<input type="datetime-local" name="delivery_date"></label>
<label class="op-field">Assign staff<select name="assigned_to"><option value="">Unassigned</option><?php foreach($staff as $u):?><option value="<?=$u['user_id']?>"><?=at_h($u['full_name'])?></option><?php endforeach;?></select></label>
<label class="op-field">Notes<textarea name="notes" maxlength="1000"></textarea></label><button class="op-button" type="submit" <?=!$orders?'disabled':''?>>Schedule Delivery</button>
</form></section><section class="op-card"><h2>Delivery history</h2><p class="op-description">Latest 100 records. Changes are logged by the system.</p>
<div class="op-table-wrap"><table class="op-table"><thead><tr><th>Delivery / Customer</th><th>Schedule</th><th>Assigned staff</th><th>Status</th><th>Change status</th></tr></thead><tbody>
<?php foreach($rows as $d):?><tr><td><strong>#<?=$d['delivery_id']?> / Order #<?=$d['order_id']?></strong><?=at_h($d['customer_name'])?><div class="op-muted"><?=at_h($d['delivery_address'])?></div></td><td><?=at_h($d['delivery_date']?:'Not scheduled')?></td><td><?=at_h($d['full_name']?:'Unassigned')?></td><td><span class="op-pill <?= $d['delivery_status']==='Completed'?'good':($d['delivery_status']==='Cancelled'?'bad':'warn') ?>"><?=at_h($d['delivery_status'])?></span></td><td><?php $next=match($d['delivery_status']){'Pending'=>['Ongoing','Cancelled'],'Ongoing'=>['Completed','Cancelled'],default=>[]};if($next):?><form method="post" class="op-inline-form"><input type="hidden" name="csrf_token" value="<?=at_h(at_csrf())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="delivery_id" value="<?=$d['delivery_id']?>"><select name="status" aria-label="New delivery status"><?php foreach($next as $status):?><option><?=at_h($status)?></option><?php endforeach;?></select><button type="submit">Update</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="5">No deliveries yet.</td></tr><?php endif;?></tbody></table></div></section></div>
<script>document.getElementById('deliveryOrder')?.addEventListener('change',e=>{const a=e.target.selectedOptions[0]?.dataset.address||'';document.getElementById('deliveryAddress').value=a;});</script>
<?php at_close();
