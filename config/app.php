<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/sales.php';
ini_set('display_errors','0');
ini_set('log_errors','1');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('aquatrack_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly'=>true,'samesite'=>'Lax']);
    ini_set('session.use_strict_mode','1');
    session_start();
}
function at_h(mixed $v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function at_peso(mixed $v): string {return '₱'.number_format((float)$v,2);}
function at_csrf(): string {return $_SESSION['at_csrf'] ??= bin2hex(random_bytes(32));}
function at_check_csrf(): void {
    if (!hash_equals(at_csrf(), (string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Session expired. Refresh and try again.');
    }
}
function at_int(mixed $v): int {return filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;}
function at_money_cents(string $s): int {
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/',$s)) throw new RuntimeException('Invalid amount.');
    $p=explode('.',$s,2);
    return ((int)$p[0])*100+(int)str_pad($p[1]??'',2,'0');
}
function at_decimal(int $c): string {return intdiv($c,100).'.'.str_pad((string)($c%100),2,'0',STR_PAD_LEFT);}
function at_admin(): array {
    if (empty($_SESSION['user_id'])) {header('Location: ../../auth/login.php');exit;}
    $pdo=at_db();
    $st=$pdo->prepare("SELECT user_id,full_name FROM users WHERE user_id=? AND role='Admin' AND status='Active'");
    $st->execute([(int)$_SESSION['user_id']]); $user=$st->fetch();
    if (!$user) {http_response_code(403);exit('Access denied.');}
    $_SESSION['logout_csrf'] ??= bin2hex(random_bytes(32));
    return $user;
}
function at_db(): PDO {static $db=null;return $db ??= require __DIR__.'/database.php';}
function at_log(PDO $pdo,int $uid,string $action,string $table,int $id): void {
    $s=$pdo->prepare('INSERT INTO activity_logs (user_id,action,table_name,record_id,log_date,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW(),NOW())');
    $s->execute([$uid,substr($action,0,100),$table,$id]);
}
function at_flash(?string $msg=null): string {
    if ($msg!==null) {$_SESSION['at_flash']=$msg;return '';}
    $v=(string)($_SESSION['at_flash']??''); unset($_SESSION['at_flash']);return $v;
}
function at_redirect(string $to='index.php'): void {header('Location: '.$to);exit;}
function at_failure(Throwable $e,string $where): string {
    if ($e instanceof RuntimeException && !($e instanceof PDOException)) return $e->getMessage();
    error_log('[AquaTrack '.$where.'] '.$e->getMessage());
    return 'Unable to save changes. Please check the server logs.';
}
