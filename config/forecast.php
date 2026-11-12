<?php
declare(strict_types=1);
/** Forecast next calendar month from historical completed months (one-month gap).
 *  Walk-forward MAE uses the same two-step horizon, allowing for the current partial month. Amounts in PHP cents to keep outputs stable.
 *  Zero-revenue gaps are included *after first recorded sale*; historical gaps may represent missing data.
 */
function at_linear_fit(array $y): array {
    $n=count($y);$sx=($n-1)*$n/2;$sxx=($n-1)*$n*(2*$n-1)/6;
    $sy=array_sum($y);$sxy=0;foreach($y as $x=>$v)$sxy+=$x*$v;
    $den=$n*$sxx-$sx*$sx;
    $slope=$den>0?($n*$sxy-$sx*$sy)/$den:0.0;
    return ['intercept'=>($sy-$slope*$sx)/$n,'slope'=>$slope];
}
function at_sales_forecast(PDO $pdo): array {
    $now=new DateTimeImmutable('first day of this month 00:00:00');
    $end=$now->format('Y-m-d');
    $q=$pdo->prepare("SELECT DATE_FORMAT(s.sale_date,'%Y-%m') month, SUM(s.total_amount) revenue
      FROM sales s JOIN orders o ON o.order_id=s.order_id
      WHERE o.order_status='Completed' AND s.sale_date<?
      GROUP BY DATE_FORMAT(s.sale_date,'%Y-%m') ORDER BY month");
    $q->execute([$end]);$raw=$q->fetchAll();
    $output=['sufficient'=>false,'forecast'=>null,'mae'=>null,'baseline_mae'=>null,'mape'=>null,'history'=>[],'next_month'=>$now->modify('+1 month')->format('F Y'),'method'=>'Linear Regression'];
    if(!$raw)return $output;
    $mapping=[];foreach($raw as $r)$mapping[$r['month']]=round((float)$r['revenue'],2);
    $start=DateTimeImmutable::createFromFormat('!Y-m',$raw[0]['month']);if(!$start)return $output;
    $dates=[];$values=[];$cursor=$start;
    // Avoid unbounded processing when data contains bad dates.
    for($i=0;$i<240 && $cursor<$now;$i++,$cursor=$cursor->modify('+1 month')){
        $month=$cursor->format('Y-m');$dates[]=$month;$values[]=(float)($mapping[$month]??0);
    }
    $output['history']=array_map(fn($m,$v)=>['month'=>$m,'revenue'=>$v],$dates,$values);
    // Six full months is a minimum for an early (not validated) forecast; 12+ strongly preferred.
    if(count($values)<6)return $output;
    $fit=at_linear_fit($values);$n=count($values);
    $output['sufficient']=true;$output['forecast']=round(max(0.0,$fit['intercept']+$fit['slope']*($n+1)),2);
    $errors=[];$baseline=[];$percentErrors=[];
    for($i=max(4,$n-3);$i<$n;$i++){
      $past=array_slice($values,0,$i-1);
      $f=at_linear_fit($past);
      $pred=max(0.0,$f['intercept']+$f['slope']*$i);
      $errors[]=abs($pred-$values[$i]);
      if($values[$i]>0)$percentErrors[]=100*abs($pred-$values[$i])/$values[$i];
      $baseline[]=abs(array_sum(array_slice($past,-3))/3-$values[$i]);
    }
    $output['mae']=round(array_sum($errors)/count($errors),2);
    $output['mape']=$percentErrors?round(array_sum($percentErrors)/count($percentErrors),1):null;
    $output['baseline_mae']=round(array_sum($baseline)/count($baseline),2);
    $output['months']=$n;
    $output['last_month_sales']=end($values);
    $output['change_pct']=$output['last_month_sales']>0?round(($output['forecast']/$output['last_month_sales']-1)*100,1):null;
    return $output;
}
