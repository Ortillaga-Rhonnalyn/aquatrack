<?php
declare(strict_types=1);
/** Invoke within a transaction while holding SELECT ... FOR UPDATE on the order row. */
function at_ensure_sale(PDO $pdo,int $orderId,int $actorId): void {
    $q=$pdo->prepare('SELECT customer_id,total_amount FROM orders WHERE order_id=?');$q->execute([$orderId]);$o=$q->fetch();
    if(!$o)throw new RuntimeException('Order not found.');
    $q=$pdo->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE order_id=?');$q->execute([$orderId]);$paid=(float)$q->fetchColumn();
    $total=(float)$o['total_amount'];$balance=max(0,round($total-$paid,2));
    $status=$balance<=0?'Paid':($paid>0?'Partial':'Unpaid');
    $q=$pdo->prepare('SELECT sale_id FROM sales WHERE order_id=? ORDER BY sale_id LIMIT 1');$q->execute([$orderId]);$existing=$q->fetchColumn();
    if($existing){
        $q=$pdo->prepare('UPDATE sales SET total_amount=?,amount_paid=?,balance=?,payment_status=?,updated_at=NOW() WHERE sale_id=?');
        $q->execute([$o['total_amount'],number_format($paid,2,'.',''),number_format($balance,2,'.',''),$status,$existing]);
    }else{
        $q=$pdo->prepare('INSERT INTO sales (order_id,customer_id,sale_date,total_amount,amount_paid,balance,payment_status,recorded_by,created_at,updated_at) VALUES (?,?,NOW(),?,?,?,?,?,NOW(),NOW())');
        $q->execute([$orderId,$o['customer_id'],$o['total_amount'],number_format($paid,2,'.',''),number_format($balance,2,'.',''),$status,$actorId]);
    }
}
