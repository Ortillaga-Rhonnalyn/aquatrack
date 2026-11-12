<?php
/** Offline smoke test. Does not touch production DB. */
declare(strict_types=1);
require __DIR__.'/../config/forecast.php';
$cases=[
  [10,20,30,40,50,60],
  [120,120,120,120,120,120],
  [0,0,0,0,0,0],
];
foreach($cases as $i=>$vals){
 $fit=at_linear_fit($vals);
 $expected=$i===0?10.0:($i===1?0.0:0.0);
 if(abs($fit['slope']-$expected)>0.00001)throw new RuntimeException('Unexpected slope: case '.$i);
}
echo "PASS: Native PHP linear regression unit smoke tests.\n";
