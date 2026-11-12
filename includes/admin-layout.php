<?php
declare(strict_types=1);
function at_open(string $title, string $active, string $name, string $subtitle): void {
    $nav=['dashboard'=>'Dashboard','users'=>'Staff Accounts','customers'=>'Customers','orders'=>'Orders',
      'deliveries'=>'Deliveries','inventory'=>'Inventory','payments'=>'Sales & Payments','reports'=>'Reports & Forecasting'];
    $esc=fn($x)=>htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $avatar=strtoupper(substr($name,0,1));
    $module=ucwords($active);
    $token=$_SESSION['logout_csrf'] ??= bin2hex(random_bytes(32));
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.$esc($title).' | AquaTrack</title><link rel="stylesheet" href="../../assets/css/admin.css"><link rel="stylesheet" href="../../assets/css/operations.css"><script defer src="../../assets/js/admin.js"></script></head><body>';
    echo '<div class="app-shell"><div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div><aside class="sidebar" id="adminSidebar" aria-label="Admin navigation">';
    echo '<a class="brand" href="../dashboard.php"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 40 40" fill="none"><path d="M20 4S8 18.5 8 26a12 12 0 0 0 24 0C32 18.5 20 4 20 4Z" fill="currentColor"/><path d="M12 27c4-4 9 4 16-1" stroke="white" stroke-width="2.5" stroke-linecap="round"/></svg></span><span class="brand-name">AquaTrack<small>Water Station System</small></span></a>';
    echo '<div class="sidebar-label">WORKSPACE</div><nav class="sidebar-nav" aria-label="Modules">';
    foreach($nav as $key=>$label){
        if($key==='users')echo '<div class="sidebar-label sidebar-label-inner">MANAGEMENT</div>';
        $url=$key==='dashboard'?'../dashboard.php':'../'.$key.'/index.php';
        echo '<a class="nav-item'.($key===$active?' active':'').'" href="'.$url.'"'.($key===$active?' aria-current="page"':'').'><span class="nav-dot"></span> '.$esc($label).'</a>';
    }
    echo '</nav><div class="sidebar-bottom"><div class="station-label"><span class="station-pulse"></span> Gaeven\'s Water Station</div><form method="post" action="../../auth/logout.php"><input type="hidden" name="csrf_token" value="'.$esc($token).'"><button class="logout-btn" type="submit">Sign out →</button></form></div></aside>';
    echo '<div class="main-area"><header class="topbar"><div class="topbar-left"><button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-controls="adminSidebar" aria-expanded="false"><span></span><span></span><span></span></button><span class="breadcrumb">AquaTrack <span class="breadcrumb-separator">/</span> Admin <span class="breadcrumb-separator">/</span> '.$esc($module).'</span></div>';
    echo '<div class="profile"><span class="profile-avatar">'.$esc($avatar).'</span><span class="profile-name">'.$esc($name).'<small>Administrator</small></span></div></header>';
    echo '<main class="content operations"><div class="page-heading"><div><span class="eyebrow">AQUATRACK MANAGEMENT</span><h1>'.$esc($title).'</h1><p>'.$esc($subtitle).'</p></div><a class="op-back" href="../dashboard.php">← Dashboard</a></div>';
}
function at_close(): void {echo '</main></div></div></body></html>';}
