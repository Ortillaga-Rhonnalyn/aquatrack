<?php
declare(strict_types=1);
function at_staff(): array {
    if(empty($_SESSION['user_id'])){header('Location: ../auth/login.php');exit;}
    $pdo=at_db();$q=$pdo->prepare("SELECT user_id,full_name,role,username,email FROM users WHERE user_id=? AND role<>'Admin' AND status='Active'");$q->execute([(int)$_SESSION['user_id']]);$staff=$q->fetch();
    if(!$staff){http_response_code(403);exit('Access denied.');}
    $_SESSION['logout_csrf'] ??= bin2hex(random_bytes(32));
    return $staff;
}
function at_staff_open(string $title,string $module,array $staff,string $description): void {
 $name=at_h($staff['full_name']);$role=at_h($staff['role']);$first=at_h(strtoupper(substr($name,0,1)));
 $nav=['dashboard'=>['Dashboard','dashboard.php','▦'],'orders'=>['My Orders','orders.php','☷'],'tasks'=>['Assigned Deliveries','tasks.php','⇢'],'inventory'=>['Inventory Stock','inventory.php','▧'],'payments'=>['Cashier Payments','payments.php','◈'],'profile'=>['My Profile','profile.php','◷']];
 echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.at_h($title).' | AquaTrack</title><link rel="stylesheet" href="../assets/css/staff.css"><link rel="stylesheet" href="../assets/css/operations.css"><script defer src="../assets/js/admin.js"></script></head><body>';
 echo '<div class="app-shell"><div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div><aside class="sidebar" id="adminSidebar" aria-label="Staff navigation"><a href="dashboard.php" class="brand"><span class="brand-drop"><svg viewBox="0 0 42 42" fill="none"><path d="M21 3S7 20 7 28a14 14 0 0 0 28 0C35 20 21 3 21 3Z" fill="currentColor"/></svg></span><span class="brand-text">AquaTrack<small>Water Station System</small></span></a><div class="station-chip"><span class="station-dot"></span> Gaeven\'s Water Station</div><div class="side-heading">WORKSPACE</div><nav class="nav-list" aria-label="Main menu">';
 foreach($nav as $key=>[$label,$href,$icon]){
   if($key==='orders' && !in_array($staff['role'],['Staff','Manager'],true))continue;
   if($key==='tasks' && !in_array($staff['role'],['Staff','Manager','Delivery Staff'],true))continue;
   if($key==='payments' && !in_array($staff['role'],['Cashier','Manager'],true))continue;
   if($key==='orders')echo '<div class="side-heading side-heading--nested">STATION MODULES</div>';
   echo '<a class="nav-item'.($key===$module?' active':'').'" href="'.$href.'"'.($key===$module?' aria-current="page"':'').'><span class="nav-icon" aria-hidden="true">'.$icon.'</span> '.$label.'</a>';
 }
 echo '</nav><div class="sidebar-bottom"><div class="sidebar-user"><span class="mini-avatar">'.$first.'</span><span><strong>'.$name.'</strong><small>'.$role.'</small></span></div><form method="post" action="../auth/logout.php"><input type="hidden" name="csrf_token" value="'.at_h($_SESSION['logout_csrf']).'"><button type="submit" class="signout-btn">Sign out →</button></form></div></aside>';
 echo '<div class="main-area"><header class="topbar"><div class="topbar-left"><button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-controls="adminSidebar" aria-expanded="false"><span></span><span></span><span></span></button><span class="breadcrumb">AquaTrack / Staff Workspace / '.at_h($title).'</span></div><div class="topbar-right"><span class="today-label">'.date('D, M j, Y').'</span><span class="topbar-avatar">'.$first.'</span></div></header><main class="content operations"><div class="page-heading"><div><span class="eyebrow">STAFF WORKSPACE</span><h1>'.at_h($title).'</h1><p>'.at_h($description).'</p></div></div>';
}
function at_staff_close(): void {echo '</main></div></div></body></html>';}
