<?php
declare(strict_types=1);
require __DIR__.'/../../config/app.php';require __DIR__.'/../../includes/admin-layout.php';
$admin=at_admin();$pdo=at_db();$error='';$flash=at_flash();
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  at_check_csrf();
  if(($_POST['action']??'')!=='pay')throw new RuntimeException('Unknown payment action.');
  $orderId=at_int($_POST['order_id']??null);$amount=trim((string)($_POST['amount_paid']??''));$method=(string)($_POST['payment_method']??'');
  if(!$orderId||!in_array($method,['Cash','GCash','Bank Transfer','Other'],true))throw new RuntimeException('Select a valid order and payment method.');
  $cents=at_money_cents($amount);if($cents<1)throw new RuntimeException('Payment must be greater than zero.');
  $pdo->beginTransaction();
  $q=$pdo->prepare('SELECT total_amount,order_status FROM orders WHERE order_id=? FOR UPDATE');$q->execute([$orderId]);$o=$q->fetch();
  if(!$o||$o['order_status']==='Cancelled')throw new RuntimeException('Cannot record a payment for this order.');
  $q=$pdo->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE order_id=?');$q->execute([$orderId]);
  $prev=at_money_cents((string)$q->fetchColumn());$total=at_money_cents((string)$o['total_amount']);
  $due=$total-$prev;if($cents>$due)throw new RuntimeException('Payment exceeds remaining order balance of '.at_peso($due/100).'.');
  $status=$cents===$due?'Paid':'Partial';
  $q=$pdo->prepare('INSERT INTO payments (order_id,amount_paid,payment_method,payment_status,payment_date,received_by,created_at,updated_at) VALUES (?,?,?,?,NOW(),?,NOW(),NOW())');
  $q->execute([$orderId,at_decimal($cents),$method,$status,(int)$admin['user_id']]);
  $paymentId=(int)$pdo->lastInsertId();at_ensure_sale($pdo,$orderId,(int)$admin['user_id']);
  at_log($pdo,(int)$admin['user_id'],'Recorded payment','payments',$paymentId);
  $pdo->commit();at_flash('Payment recorded successfully.');at_redirect();
 }
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=at_failure($e,'payments');}
$eligible=$pdo->query("SELECT o.order_id,c.customer_name,o.total_amount,COALESCE((SELECT SUM(p.amount_paid) FROM payments p WHERE p.order_id=o.order_id),0) paid FROM orders o JOIN customers c ON c.customer_id=o.customer_id WHERE o.order_status<>'Cancelled' HAVING paid<o.total_amount ORDER BY o.order_id DESC LIMIT 200")->fetchAll();
$payments=$pdo->query('SELECT p.payment_id,p.order_id,p.amount_paid,p.payment_method,p.payment_date,c.customer_name,u.full_name FROM payments p JOIN orders o ON o.order_id=p.order_id JOIN customers c ON c.customer_id=o.customer_id JOIN users u ON u.user_id=p.received_by ORDER BY p.payment_id DESC LIMIT 100')->fetchAll();
$summary=$pdo->query("SELECT (SELECT COALESCE(SUM(amount_paid),0) FROM payments) collected,(SELECT COALESCE(SUM(o.total_amount),0) FROM orders o WHERE o.order_status='Completed') completed_sales,(SELECT COALESCE(SUM(GREATEST(0,o.total_amount-COALESCE((SELECT SUM(p.amount_paid) FROM payments p WHERE p.order_id=o.order_id),0))),0) FROM orders o WHERE o.order_status<>'Cancelled') balances")->fetch();
at_open('Sales & Payments','payments',(string)$admin['full_name'],'Record customer payments, manage balances, and review collected revenue.');
if($flash):?><div class="op-notice" role="status"><?=at_h($flash)?></div><?php endif;
if($error):?><div class="op-notice bad" role="alert"><?=at_h($error)?></div><?php endif;?>
<div class="op-summary"><article><span>Completed-order sales</span><strong><?=at_peso($summary['completed_sales'])?></strong></article><article><span>Cash collected (all orders)</span><strong><?=at_peso($summary['collected'])?></strong></article><article><span>Outstanding balances</span><strong><?=at_peso($summary['balances'])?></strong></article><article><span>Recent payments</span><strong><?=count($payments)?></strong></article></div>
<div class="op-grid"><section class="op-card"><h2>Record a payment</h2><p class="op-description">Payments cannot exceed the outstanding order balance. Record actual received amounts only.</p><form method="post">
<input type="hidden" name="csrf_token" value="<?=at_h(at_csrf())?>"><input type="hidden" name="action" value="pay">
<label class="op-field">Order with balance<select name="order_id" required id="payOrder"><option value="">Select order</option><?php foreach($eligible as $o):?><option value="<?=$o['order_id']?>" data-balance="<?=at_h(number_format((float)$o['total_amount']-(float)$o['paid'],2,'.',''))?>">#<?=$o['order_id']?> – <?=at_h($o['customer_name'])?> (Due <?=at_peso((float)$o['total_amount']-(float)$o['paid'])?>)</option><?php endforeach;?></select></label>
<label class="op-field">Amount received (PHP)<input type="number" id="payAmount" name="amount_paid" min="0.01" max="99999999.99" step="0.01" required></label>
<label class="op-field">Payment method<select name="payment_method" required><option>Cash</option><option>GCash</option><option>Bank Transfer</option><option>Other</option></select></label>
<button type="submit" class="op-button" <?=!$eligible?'disabled':''?>>Save Payment</button><p class="op-hint">Always verify customer payment before recording. No online payment gateway is connected.</p></form></section>
<section class="op-card"><h2>Payment history</h2><p class="op-description">Latest 100 payments, including receiver and transaction date.</p><div class="op-table-wrap"><table class="op-table"><thead><tr><th>Payment</th><th>Customer</th><th>Amount</th><th>Method</th><th>Date</th><th>Received by</th></tr></thead><tbody>
<?php foreach($payments as $p):?><tr><td>#<?=$p['payment_id']?> / Order #<?=$p['order_id']?></td><td><?=at_h($p['customer_name'])?></td><td><strong><?=at_peso($p['amount_paid'])?></strong></td><td><?=at_h($p['payment_method'])?></td><td><?=at_h($p['payment_date'])?></td><td><?=at_h($p['full_name'])?></td></tr><?php endforeach;?><?php if(!$payments):?><tr><td colspan="6">No payments recorded yet.</td></tr><?php endif;?></tbody></table></div></section></div>
<script>document.getElementById('payOrder')?.addEventListener('change',e=>{let n=e.target.selectedOptions[0]?.dataset.balance||'';const f=document.getElementById('payAmount');if(n){f.max=n;f.value=n;}else{f.removeAttribute('max');f.value='';}});</script>
<?php at_close();
