<?php
declare(strict_types=1);
require __DIR__.'/../config/app.php';require __DIR__.'/../includes/staff-layout.php';
$staff=at_staff();$pdo=at_db();$items=$pdo->query('SELECT item_name,item_type,quantity,reorder_level,unit,status FROM inventory ORDER BY quantity ASC,item_name ASC')->fetchAll();
at_staff_open('Inventory Stock','inventory',$staff,'Read-only inventory visibility for station personnel.');
?><section class="op-card"><h2>Current supply levels</h2><p class="op-description">Request stock adjustments from an administrator. This page does not change inventory.</p><div class="op-table-wrap"><table class="op-table"><thead><tr><th>Item</th><th>Type</th><th>Quantity</th><th>Reorder level</th><th>Status</th></tr></thead><tbody><?php foreach($items as $x):?><tr><td><?=at_h($x['item_name'])?></td><td><?=at_h($x['item_type'])?></td><td><?=$x['quantity']?> <?=at_h($x['unit']??'')?></td><td><?=$x['reorder_level']?></td><td><span class="op-pill <?=($x['status']==='Available'?'good':'warn')?>"><?=at_h($x['status'])?></span></td></tr><?php endforeach;?><?php if(!$items):?><tr><td colspan="5">No items recorded.</td></tr><?php endif;?></tbody></table></div></section>
<?php at_staff_close();
