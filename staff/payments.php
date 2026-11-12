<?php
declare(strict_types=1);
require __DIR__.'/../config/app.php';require __DIR__.'/../includes/staff-layout.php';
$staff=at_staff();
if(!in_array($staff['role'],['Cashier','Manager'],true)){http_response_code(403);exit('Cashier or Manager permission required.');}
$pdo=at_db();$error='';$flash=at_flash();
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  at_check_csrf();$orderId=at_int($_POST['order_id']??null);
  $amount=trim((string)($_POST['amount_paid']??''));$method=(string)($_POST['payment_method']??'');
  if(!$orderId||!in_array($method,['Cash','GCash','Bank Transfer','Other'],true))throw new RuntimeException('Select an order and valid payment method.');
  $cents=at_money_cents($amount);
  if($cents<1)throw new RuntimeException('Amount must be positive.');
  $pdo->beginTransaction();
  $q=$pdo->prepare('SELECT total_amount,order_status FROM orders WHERE order_id=? FOR UPDATE');$q->execute([$orderId]);$o=$q->fetch();
  if(!$o||$o['order_status']==='Cancelled')throw new RuntimeException('Payment not allowed.');
  $q=$pdo->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE order_id=?');$q->execute([$orderId]);
  $paid=at_money_cents((string)$q->fetchColumn());$due=at_money_cents((string)$o['total_amount'])-$paid;
  if($cents>$due)throw new RuntimeException('Payment exceeds remaining balance.');
  $status=$cents===$due?'Paid':'Partial';
  $q=$pdo->prepare('INSERT INTO payments(order_id,amount_paid,payment_method,payment_status,payment_date,received_by,created_at,updated_at) VALUES (?,?,?,?,NOW(),?,NOW(),NOW())');
  $q->execute([$orderId,at_decimal($cents),$method,$status,$staff['user_id']]);
  $paymentId=(int)$pdo->lastInsertId();at_ensure_sale($pdo,$orderId,(int)$staff['user_id']);
  at_log($pdo,(int)$staff['user_id'],'Cashier payment recorded','payments',$paymentId);
  $pdo->commit();at_flash('Payment saved.');at_redirect('payments.php');
 }
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=at_failure($e,'cashier');}
$orders=$pdo->query("SELECT o.order_id,c.customer_name,o.total_amount,COALESCE((SELECT SUM(p.amount_paid) FROM payments p WHERE p.order_id=o.order_id),0) paid FROM orders o JOIN customers c ON c.customer_id=o.customer_id WHERE o.order_status<>'Cancelled' HAVING paid<o.total_amount ORDER BY o.order_id DESC LIMIT 200")->fetchAll();
$q=$pdo->prepare('SELECT p.payment_date,p.amount_paid,p.order_id FROM payments p WHERE p.received_by=? ORDER BY p.payment_id DESC LIMIT 100');$q->execute([$staff['user_id']]);$history=$q->fetchAll();
at_staff_open('Cashier Payments','payments',$staff,'Record verified customer payments for station orders.');
if($flash):?><div class="op-notice"><?=at_h($flash)?></div><?php endif;if($error):?><div class="op-notice bad"><?=at_h($error)?></div><?php endif;?>
<div class="op-grid"><section class="op-card"><h2>New payment</h2><form method="post"><input type="hidden" name="csrf_token" value="<?=at_h(at_csrf())?>"><label class="op-field">Order<select name="order_id" required><option value="">Select order</option><?php foreach($orders as $o):?><option value="<?=$o['order_id']?>">#<?=$o['order_id']?> – <?=at_h($o['customer_name'])?> (<?=at_peso((float)$o['total_amount']-(float)$o['paid'])?> due)</option><?php endforeach;?></select></label><label class="op-field">Amount received<input type="number" name="amount_paid" required min="0.01" max="99999999.99" step="0.01"></label><label class="op-field">Payment method<select name="payment_method"><option>Cash</option><option>GCash</option><option>Bank Transfer</option><option>Other</option></select></label><button type="submit" class="op-button" <?=!$orders?'disabled':''?>>Record Payment</button></form></section>
<section class="op-card"><h2>My recorded payments</h2><div class="op-table-wrap"><table class="op-table"><thead><tr><th>Date</th><th>Order</th><th>Amount</th></tr></thead><tbody><?php foreach($history as $r):?><tr><td><?=at_h($r['payment_date'])?></td><td>#<?=$r['order_id']?></td><td><?=at_peso($r['amount_paid'])?></td></tr><?php endforeach;?><?php if(!$history):?><tr><td colspan="3">No payments recorded by your account yet.</td></tr><?php endif;?></tbody></table></div></section></div>
<?php at_staff_close();
