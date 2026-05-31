<?php
/**
 * RADIUS Manager API v4
 * Complete Billing & Invoice System
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'radius');
define('DB_USER', 'radius');
define('DB_PASS', 'radius_password');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo j(['ok'=>false,'error'=>'Method not allowed']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['action'])) { echo j(['ok'=>false,'error'=>'Invalid request']); exit; }

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

    // ── AUTO CREATE TABLES ────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS radius_profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(128) NOT NULL UNIQUE,
        service_type ENUM('pppoe','hotspot') DEFAULT 'pppoe',
        upload_rate VARCHAR(32), download_rate VARCHAR(32),
        session_timeout INT DEFAULT NULL, data_limit_mb INT DEFAULT NULL,
        ip_pool VARCHAR(64) DEFAULT NULL, price DECIMAL(12,0) DEFAULT 0,
        description VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS radius_customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL UNIQUE,
        full_name VARCHAR(128) DEFAULT NULL,
        phone VARCHAR(32) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        service_number VARCHAR(64) DEFAULT NULL,
        lat DECIMAL(10,7) DEFAULT NULL,
        lng DECIMAL(10,7) DEFAULT NULL,
        registered_at DATE DEFAULT NULL,
        active_from DATE DEFAULT NULL,
        active_until DATE DEFAULT NULL,
        profile_name VARCHAR(128) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS radius_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(32) NOT NULL UNIQUE,
        username VARCHAR(64) NOT NULL,
        full_name VARCHAR(128) DEFAULT NULL,
        profile_name VARCHAR(128) NOT NULL,
        service_type ENUM('pppoe','hotspot','voucher','other') DEFAULT 'pppoe',
        amount DECIMAL(12,0) DEFAULT 0,
        discount DECIMAL(12,0) DEFAULT 0,
        total DECIMAL(12,0) DEFAULT 0,
        description VARCHAR(255) DEFAULT NULL,
        period_start DATE DEFAULT NULL,
        period_end DATE DEFAULT NULL,
        due_date DATE DEFAULT NULL,
        paid_at DATETIME DEFAULT NULL,
        status ENUM('draft','unpaid','paid','overdue','cancelled') DEFAULT 'unpaid',
        payment_method VARCHAR(64) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS radius_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        invoice_number VARCHAR(32) NOT NULL,
        username VARCHAR(64) NOT NULL,
        amount DECIMAL(12,0) DEFAULT 0,
        payment_method VARCHAR(64) DEFAULT NULL,
        paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        received_by VARCHAR(64) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        FOREIGN KEY (invoice_id) REFERENCES radius_invoices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS radius_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(64) DEFAULT 'Operasional',
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(12,0) DEFAULT 0,
        expense_date DATE DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

} catch (PDOException $e) {
    echo j(['ok'=>false,'error'=>'DB Error: '.$e->getMessage()]); exit;
}

// ── ROUTER ────────────────────────────────────────────────────
try {
    $a = $input['action'];
    $map = [
        'ping'                => fn() => ping($pdo),
        'stats'               => fn() => stats($pdo),
        'get_users'           => fn() => get_users($pdo,$input),
        'add_user'            => fn() => add_user($pdo,$input),
        'update_user'         => fn() => update_user($pdo,$input),
        'delete_user'         => fn() => delete_user($pdo,$input),
        'get_nas'             => fn() => get_nas($pdo),
        'add_nas'             => fn() => add_nas($pdo,$input),
        'delete_nas'          => fn() => delete_nas($pdo,$input),
        'get_sessions'        => fn() => get_sessions($pdo),
        'add_profile'         => fn() => add_profile($pdo,$input),
        'get_profiles'        => fn() => get_profiles($pdo,$input),
        'delete_profile'      => fn() => delete_profile($pdo,$input),
        'update_profile'      => fn() => update_profile($pdo,$input),
        'gen_vouchers'        => fn() => gen_vouchers($pdo,$input),
        'get_vouchers'        => fn() => get_vouchers($pdo,$input),
        'delete_voucher'      => fn() => delete_voucher($pdo,$input),
        'get_customer'        => fn() => get_customer($pdo,$input),
        'save_customer'       => fn() => save_customer($pdo,$input),
        'get_map_data'        => fn() => get_map_data($pdo),
        'get_online_status'   => fn() => get_online_status($pdo,$input),
        // Invoice & Billing
        'get_invoices'        => fn() => get_invoices($pdo,$input),
        'get_invoice'         => fn() => get_invoice($pdo,$input),
        'create_invoice'      => fn() => create_invoice($pdo,$input),
        'update_invoice'      => fn() => update_invoice($pdo,$input),
        'delete_invoice'      => fn() => delete_invoice($pdo,$input),
        'pay_invoice'         => fn() => pay_invoice($pdo,$input),
        'bulk_create_invoices'=> fn() => bulk_create_invoices($pdo,$input),
        'get_payments'        => fn() => get_payments($pdo,$input),
        // Expenses
        'get_expenses'        => fn() => get_expenses($pdo,$input),
        'add_expense'         => fn() => add_expense($pdo,$input),
        'delete_expense'      => fn() => delete_expense($pdo,$input),
        // Reports
        'billing_summary'     => fn() => billing_summary($pdo,$input),
        'monthly_report'      => fn() => monthly_report($pdo,$input),
    ];
    if (isset($map[$a])) echo j($map[$a]());
    else echo j(['ok'=>false,'error'=>'Unknown action: '.$a]);
} catch (Exception $e) {
    echo j(['ok'=>false,'error'=>$e->getMessage()]);
}

function j($d) { return json_encode($d, JSON_UNESCAPED_UNICODE); }

// ── PING ──────────────────────────────────────────────────────
function ping($p) { $p->query("SELECT 1"); return ['ok'=>true,'time'=>date('Y-m-d H:i:s')]; }

// ── STATS ─────────────────────────────────────────────────────
function stats($pdo) {
    $total   = (int)$pdo->query("SELECT COUNT(DISTINCT username) FROM radcheck WHERE attribute='Cleartext-Password'")->fetchColumn();
    $online  = (int)$pdo->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL")->fetchColumn();
    $mapped  = (int)$pdo->query("SELECT COUNT(*) FROM radius_customers WHERE lat IS NOT NULL")->fetchColumn();
    $voucher = (int)$pdo->query("SELECT COUNT(DISTINCT username) FROM radcheck WHERE attribute='X-Voucher'")->fetchColumn();
    $nas     = (int)$pdo->query("SELECT COUNT(*) FROM nas")->fetchColumn();
    $unpaid  = (int)$pdo->query("SELECT COUNT(*) FROM radius_invoices WHERE status IN ('unpaid','overdue')")->fetchColumn();
    $overdue = (int)$pdo->query("SELECT COUNT(*) FROM radius_invoices WHERE status='unpaid' AND due_date < CURDATE()")->fetchColumn();
    // Auto-mark overdue
    $pdo->exec("UPDATE radius_invoices SET status='overdue' WHERE status='unpaid' AND due_date < CURDATE()");
    $month_income = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM radius_payments WHERE MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn();
    $month_expense= (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM radius_expenses WHERE MONTH(expense_date)=MONTH(NOW()) AND YEAR(expense_date)=YEAR(NOW())")->fetchColumn();

    $recent = $pdo->query(
        "SELECT rc.username, COALESCE(rg.groupname,'') as groupname,
         CASE WHEN rg.groupname LIKE '%hotspot%' THEN 'hotspot' ELSE 'pppoe' END as service_type, rc.id
         FROM radcheck rc LEFT JOIN radusergroup rg ON rc.username=rg.username
         WHERE rc.attribute='Cleartext-Password' GROUP BY rc.username ORDER BY rc.id DESC LIMIT 6"
    )->fetchAll();

    return ['ok'=>true,'total'=>$total,'online'=>$online,'mapped'=>$mapped,'voucher'=>$voucher,
            'nas'=>$nas,'unpaid'=>$unpaid,'overdue'=>$overdue,'month_income'=>$month_income,
            'month_expense'=>$month_expense,'recent'=>$recent];
}

// ── USERS ─────────────────────────────────────────────────────
function get_users($pdo,$in) {
    $type=$in['type']??'';
    $cond="WHERE rc.attribute='Cleartext-Password'";
    if ($type==='pppoe')   $cond.=" AND (rg.groupname IS NULL OR rg.groupname NOT LIKE '%hotspot%') AND rc.username NOT IN (SELECT username FROM radcheck WHERE attribute='X-Voucher')";
    if ($type==='hotspot') $cond.=" AND rg.groupname LIKE '%hotspot%' AND rc.username NOT IN (SELECT username FROM radcheck WHERE attribute='X-Voucher')";
    $rows=$pdo->query("SELECT rc.username, COALESCE(rg.groupname,'') as groupname,
        CASE WHEN rg.groupname LIKE '%hotspot%' THEN 'hotspot' ELSE 'pppoe' END as service_type,
        (SELECT value FROM radcheck r2 WHERE r2.username=rc.username AND r2.attribute='Expiration' LIMIT 1) as expiry
        FROM radcheck rc LEFT JOIN radusergroup rg ON rc.username=rg.username
        $cond GROUP BY rc.username ORDER BY rc.username LIMIT 500")->fetchAll();
    return ['ok'=>true,'users'=>$rows];
}

function add_user($pdo,$in) {
    $u=trim($in['username']??''); $pw=$in['password']??''; $g=trim($in['groupname']??''); $ex=$in['expiry']??'';
    if (!$u||!$pw) return ['ok'=>false,'error'=>'Username & password wajib'];
    $e=$pdo->prepare("SELECT COUNT(*) FROM radcheck WHERE username=? AND attribute='Cleartext-Password'"); $e->execute([$u]);
    if ($e->fetchColumn()) return ['ok'=>false,'error'=>'Username sudah ada'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,'Cleartext-Password',':=',?)")->execute([$u,$pw]);
        if ($g) $pdo->prepare("INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)")->execute([$u,$g]);
        if ($ex) $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,'Expiration',':=',?)")->execute([$u,(new DateTime($ex))->format('d M Y')]);
        $pdo->prepare("INSERT IGNORE INTO radius_customers (username,profile_name) VALUES (?,?)")->execute([$u,$g?:null]);
        $pdo->commit(); return ['ok'=>true];
    } catch(Exception $e) { $pdo->rollBack(); return ['ok'=>false,'error'=>$e->getMessage()]; }
}

function update_user($pdo,$in) {
    $u=$in['username']??''; $pw=$in['password']??''; $g=$in['groupname']??''; $ex=$in['expiry']??'';
    if (!$u) return ['ok'=>false,'error'=>'Username wajib'];
    $pdo->beginTransaction();
    try {
        if ($pw) $pdo->prepare("UPDATE radcheck SET value=? WHERE username=? AND attribute='Cleartext-Password'")->execute([$pw,$u]);
        if ($g) {
            $c=$pdo->prepare("SELECT COUNT(*) FROM radusergroup WHERE username=?"); $c->execute([$u]);
            if ($c->fetchColumn()) $pdo->prepare("UPDATE radusergroup SET groupname=? WHERE username=?")->execute([$g,$u]);
            else $pdo->prepare("INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)")->execute([$u,$g]);
        }
        if ($ex) {
            $expStr=(new DateTime($ex))->format('d M Y');
            $c=$pdo->prepare("SELECT COUNT(*) FROM radcheck WHERE username=? AND attribute='Expiration'"); $c->execute([$u]);
            if ($c->fetchColumn()) $pdo->prepare("UPDATE radcheck SET value=? WHERE username=? AND attribute='Expiration'")->execute([$expStr,$u]);
            else $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,'Expiration',':=',?)")->execute([$u,$expStr]);
        }
        $pdo->commit(); return ['ok'=>true];
    } catch(Exception $e) { $pdo->rollBack(); return ['ok'=>false,'error'=>$e->getMessage()]; }
}

function delete_user($pdo,$in) {
    $u=$in['username']??''; if (!$u) return ['ok'=>false,'error'=>'Username wajib'];
    foreach(['radcheck','radreply','radusergroup'] as $t) $pdo->prepare("DELETE FROM $t WHERE username=?")->execute([$u]);
    return ['ok'=>true];
}

// ── NAS ───────────────────────────────────────────────────────
function get_nas($p) { return ['ok'=>true,'nas'=>$p->query("SELECT id,nasname,shortname,type,ports FROM nas ORDER BY id DESC")->fetchAll()]; }
function add_nas($pdo,$in) {
    $n=trim($in['name']??''); $ip=trim($in['ip']??''); $s=trim($in['secret']??'');
    if (!$n||!$ip||!$s) return ['ok'=>false,'error'=>'Nama, IP, Secret wajib'];
    $pdo->prepare("INSERT INTO nas (nasname,shortname,type,ports,secret) VALUES (?,?,?,?,?)")->execute([$ip,$n,$in['type']??'other',(int)($in['ports']??0),$s]);
    return ['ok'=>true];
}
function delete_nas($pdo,$in) {
    $id=(int)($in['id']??0); if (!$id) return ['ok'=>false,'error'=>'ID invalid'];
    $pdo->prepare("DELETE FROM nas WHERE id=?")->execute([$id]); return ['ok'=>true];
}

// ── SESSIONS ──────────────────────────────────────────────────
function get_sessions($p) {
    return ['ok'=>true,'sessions'=>$p->query(
        "SELECT username,nasipaddress,framedipaddress,acctstarttime,acctstoptime,
         TIMESTAMPDIFF(MINUTE,acctstarttime,COALESCE(acctstoptime,NOW())) as duration_min
         FROM radacct ORDER BY acctstarttime DESC LIMIT 50")->fetchAll()];
}

// ── PROFILES ──────────────────────────────────────────────────
function add_profile($pdo,$in) {
    $name=trim($in['name']??''); $type=$in['type']??'pppoe'; $up=trim($in['upload']??''); $dl=trim($in['download']??'');
    $price=(int)($in['price']??0);
    if (!$name||!$up||!$dl) return ['ok'=>false,'error'=>'Nama, upload, download wajib'];
    $e=$pdo->prepare("SELECT COUNT(*) FROM radius_profiles WHERE name=?"); $e->execute([$name]);
    if ($e->fetchColumn()) return ['ok'=>false,'error'=>'Profile sudah ada'];
    $pdo->prepare("INSERT INTO radius_profiles (name,service_type,upload_rate,download_rate,session_timeout,data_limit_mb,ip_pool,price,description) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$name,$type,$up,$dl,$in['timeout']?:null,$in['datalimit']?:null,$in['pool']?:null,$price,$in['description']??'']);
    $pdo->prepare("DELETE FROM radgroupreply WHERE groupname=?")->execute([$name]);
    $pdo->prepare("INSERT INTO radgroupreply (groupname,attribute,op,value) VALUES (?,'Mikrotik-Rate-Limit',':=',?)")->execute([$name,$up.'/'.$dl]);
    if ($in['timeout']??null) $pdo->prepare("INSERT INTO radgroupreply (groupname,attribute,op,value) VALUES (?,'Session-Timeout',':=',?)")->execute([$name,$in['timeout']]);
    if ($in['pool']??null) $pdo->prepare("INSERT INTO radgroupreply (groupname,attribute,op,value) VALUES (?,'Framed-Pool',':=',?)")->execute([$name,$in['pool']]);
    return ['ok'=>true];
}
function get_profiles($pdo,$in) {
    $type=$in['type']??'pppoe';
    $s=$pdo->prepare("SELECT * FROM radius_profiles WHERE service_type=? ORDER BY name"); $s->execute([$type]);
    return ['ok'=>true,'profiles'=>$s->fetchAll()];
}
function delete_profile($pdo,$in) {
    $name=trim($in['name']??''); if (!$name) return ['ok'=>false,'error'=>'Nama wajib'];
    $pdo->prepare("DELETE FROM radius_profiles WHERE name=?")->execute([$name]);
    $pdo->prepare("DELETE FROM radgroupreply WHERE groupname=?")->execute([$name]);
    return ['ok'=>true];
}

function update_profile($pdo,$in) {
    $name=trim($in['name']??''); if (!$name) return ['ok'=>false,'error'=>'Nama wajib'];
    $fields=['upload_rate'=>$in['upload']??null,'download_rate'=>$in['download']??null,
             'session_timeout'=>$in['timeout']?:null,'data_limit_mb'=>$in['datalimit']?:null,
             'ip_pool'=>$in['pool']?:null,'price'=>(int)($in['price']??0),
             'description'=>$in['description']??''];
    $set=implode(',',array_map(fn($k)=>"$k=:$k",array_keys($fields)));
    $fields[':name']=$name;
    $pdo->prepare("UPDATE radius_profiles SET $set WHERE name=:name")->execute($fields);
    // Sync ke radgroupreply
    $pdo->prepare("DELETE FROM radgroupreply WHERE groupname=?")->execute([$name]);
    if ($in['upload']&&$in['download'])
        $pdo->prepare("INSERT INTO radgroupreply (groupname,attribute,op,value) VALUES (?,'Mikrotik-Rate-Limit',':=',?)")->execute([$name,$in['upload'].'/'.$in['download']]);
    if ($in['timeout']??null)
        $pdo->prepare("INSERT INTO radgroupreply (groupname,attribute,op,value) VALUES (?,'Session-Timeout',':=',?)")->execute([$name,$in['timeout']]);
    if ($in['pool']??null)
        $pdo->prepare("INSERT INTO radgroupreply (groupname,attribute,op,value) VALUES (?,'Framed-Pool',':=',?)")->execute([$name,$in['pool']]);
    return ['ok'=>true];
}


// ── VOUCHER ───────────────────────────────────────────────────
function gen_vouchers($pdo,$in) {
    $count=min((int)($in['count']??10),200); $profile=trim($in['profile']??'');
    $dur=(int)($in['duration']??24); $prefix=strtoupper(trim($in['prefix']??'VOC')); $price=(int)($in['price']??0);
    if (!$profile) return ['ok'=>false,'error'=>'Profile wajib'];
    $pdo->beginTransaction();
    try {
        for ($i=0;$i<$count;$i++) {
            $code=$prefix.'-'.strtoupper(substr(md5(uniqid(mt_rand(),true)),0,6));
            $pass=substr(md5(uniqid(mt_rand(),true)),0,8);
            $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,'Cleartext-Password',':=',?)")->execute([$code,$pass]);
            $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,'X-Voucher',':=','1')")->execute([$code]);
            $pdo->prepare("INSERT INTO radcheck (username,attribute,op,value) VALUES (?,'Session-Timeout',':=',?)")->execute([$code,$dur*3600]);
            $pdo->prepare("INSERT INTO radusergroup (username,groupname,priority) VALUES (?,?,1)")->execute([$code,$profile]);
            $pdo->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,'X-Voucher-Duration',':=',?)")->execute([$code,$dur]);
            $pdo->prepare("INSERT INTO radreply (username,attribute,op,value) VALUES (?,'X-Voucher-Price',':=',?)")->execute([$code,$price]);
        }
        $pdo->commit(); return ['ok'=>true,'count'=>$count];
    } catch(Exception $e) { $pdo->rollBack(); return ['ok'=>false,'error'=>$e->getMessage()]; }
}
function get_vouchers($pdo,$in) {
    $filter=$in['filter']??'';
    $rows=$pdo->query("SELECT rc.username as code, rg.groupname as profile,
        (SELECT value FROM radreply WHERE username=rc.username AND attribute='X-Voucher-Duration' LIMIT 1) as duration,
        (SELECT value FROM radreply WHERE username=rc.username AND attribute='X-Voucher-Price' LIMIT 1) as price,
        (SELECT COUNT(*) FROM radacct WHERE username=rc.username) as used_count
        FROM radcheck rc LEFT JOIN radusergroup rg ON rc.username=rg.username
        WHERE rc.attribute='X-Voucher' GROUP BY rc.username ORDER BY rc.id DESC LIMIT 300")->fetchAll();
    if ($filter==='unused') $rows=array_values(array_filter($rows,fn($v)=>!$v['used_count']));
    if ($filter==='used')   $rows=array_values(array_filter($rows,fn($v)=> $v['used_count']));
    return ['ok'=>true,'vouchers'=>$rows];
}
function delete_voucher($pdo,$in) {
    $code=trim($in['code']??''); if (!$code) return ['ok'=>false,'error'=>'Code wajib'];
    foreach(['radcheck','radreply','radusergroup'] as $t) $pdo->prepare("DELETE FROM $t WHERE username=?")->execute([$code]);
    return ['ok'=>true];
}

// ── CUSTOMER / MAP ────────────────────────────────────────────
function get_customer($pdo,$in) {
    $u=trim($in['username']??''); if (!$u) return ['ok'=>false,'error'=>'Username wajib'];
    $s=$pdo->prepare("SELECT * FROM radius_customers WHERE username=?"); $s->execute([$u]);
    $row=$s->fetch();
    if (!$row) {
        $pdo->prepare("INSERT IGNORE INTO radius_customers (username) VALUES (?)")->execute([$u]);
        $row=['username'=>$u,'full_name'=>'','phone'=>'','address'=>'','service_number'=>'','lat'=>null,'lng'=>null,'registered_at'=>null,'active_from'=>null,'active_until'=>null,'profile_name'=>null,'notes'=>''];
    }
    // Get unpaid invoices count
    $unpaid=$pdo->prepare("SELECT COUNT(*) FROM radius_invoices WHERE username=? AND status IN ('unpaid','overdue')"); $unpaid->execute([$u]);
    $row['unpaid_invoices']=(int)$unpaid->fetchColumn();
    return ['ok'=>true,'customer'=>$row];
}

function save_customer($pdo,$in) {
    $u=trim($in['username']??''); if (!$u) return ['ok'=>false,'error'=>'Username wajib'];
    $ex=$pdo->prepare("SELECT COUNT(*) FROM radius_customers WHERE username=?"); $ex->execute([$u]);
    $fields=['full_name','phone','address','service_number','lat','lng','registered_at','active_from','active_until','profile_name','notes'];
    $data=[];
    foreach($fields as $f) if (array_key_exists($f,$in)) $data[$f]=$in[$f]===''?null:$in[$f];
    if ($ex->fetchColumn()) {
        if (empty($data)) return ['ok'=>true];
        $set=implode(',',array_map(fn($k)=>"$k=:$k",array_keys($data)));
        $data[':username']=$u;
        $pdo->prepare("UPDATE radius_customers SET $set WHERE username=:username")->execute($data);
    } else {
        $data['username']=$u;
        $cols=implode(',',array_keys($data)); $vals=implode(',',array_map(fn($k)=>":$k",array_keys($data)));
        $pdo->prepare("INSERT INTO radius_customers ($cols) VALUES ($vals)")->execute($data);
    }
    return ['ok'=>true];
}

function get_map_data($pdo) {
    $customers=$pdo->query("SELECT c.*,rg.groupname as profile,
        EXISTS(SELECT 1 FROM radacct ra WHERE ra.username=c.username AND ra.acctstoptime IS NULL) as is_online,
        (SELECT framedipaddress FROM radacct ra WHERE ra.username=c.username ORDER BY ra.acctstarttime DESC LIMIT 1) as ip_address,
        (SELECT COUNT(*) FROM radius_invoices WHERE username=c.username AND status IN ('unpaid','overdue')) as unpaid_count
        FROM radius_customers c LEFT JOIN radusergroup rg ON c.username=rg.username
        WHERE c.lat IS NOT NULL AND c.lng IS NOT NULL GROUP BY c.username")->fetchAll();
    $online=(int)$pdo->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL")->fetchColumn();
    return ['ok'=>true,'customers'=>$customers,'online'=>$online,'total_mapped'=>count($customers)];
}

function get_online_status($pdo,$in) {
    $usernames=$in['usernames']??[]; if (empty($usernames)) return ['ok'=>true,'status'=>[]];
    $ph=implode(',',array_fill(0,count($usernames),'?'));
    $rows=$pdo->prepare("SELECT DISTINCT username FROM radacct WHERE username IN ($ph) AND acctstoptime IS NULL");
    $rows->execute($usernames);
    $online=array_column($rows->fetchAll(),'username');
    $status=[]; foreach($usernames as $u) $status[$u]=in_array($u,$online);
    return ['ok'=>true,'status'=>$status];
}

// ══ INVOICE SYSTEM ═══════════════════════════════════════════

function gen_invoice_number($pdo) {
    $year=date('Y'); $month=date('m');
    $count=$pdo->query("SELECT COUNT(*) FROM radius_invoices WHERE YEAR(created_at)=$year AND MONTH(created_at)=$month")->fetchColumn();
    return 'INV-'.$year.$month.'-'.str_pad($count+1,4,'0',STR_PAD_LEFT);
}

function get_invoices($pdo,$in) {
    $status=$in['status']??'';
    $username=$in['username']??'';
    $month=$in['month']??'';
    $where="WHERE 1=1";
    $p=[];
    if ($status) { $where.=" AND i.status=:s"; $p[':s']=$status; }
    if ($username) { $where.=" AND i.username=:u"; $p[':u']=$username; }
    if ($month) { $where.=" AND DATE_FORMAT(i.created_at,'%Y-%m')=:m"; $p[':m']=$month; }
    // Auto-mark overdue
    $pdo->exec("UPDATE radius_invoices SET status='overdue' WHERE status='unpaid' AND due_date < CURDATE()");
    $stmt=$pdo->prepare("SELECT i.*, c.full_name, c.phone
        FROM radius_invoices i
        LEFT JOIN radius_customers c ON i.username=c.username
        $where ORDER BY i.created_at DESC LIMIT 300");
    $stmt->execute($p);
    return ['ok'=>true,'invoices'=>$stmt->fetchAll()];
}

function get_invoice($pdo,$in) {
    $id=(int)($in['id']??0); if (!$id) return ['ok'=>false,'error'=>'ID wajib'];
    $s=$pdo->prepare("SELECT i.*,c.full_name,c.phone,c.address,c.service_number
        FROM radius_invoices i LEFT JOIN radius_customers c ON i.username=c.username WHERE i.id=?");
    $s->execute([$id]); $inv=$s->fetch();
    if (!$inv) return ['ok'=>false,'error'=>'Invoice tidak ditemukan'];
    $ps=$pdo->prepare("SELECT * FROM radius_payments WHERE invoice_id=? ORDER BY paid_at DESC"); $ps->execute([$id]);
    $inv['payments']=$ps->fetchAll();
    return ['ok'=>true,'invoice'=>$inv];
}

function create_invoice($pdo,$in) {
    $username=trim($in['username']??''); $profile=trim($in['profile']??'');
    $amount=(int)($in['amount']??0); $discount=(int)($in['discount']??0);
    if (!$username||!$profile||!$amount) return ['ok'=>false,'error'=>'Username, profile, amount wajib'];

    // Get customer name
    $cn=$pdo->prepare("SELECT full_name FROM radius_customers WHERE username=?"); $cn->execute([$username]);
    $full_name=$cn->fetchColumn()?:'';

    $inv_no=gen_invoice_number($pdo);
    $total=max(0,$amount-$discount);
    $due_date=$in['due_date']??date('Y-m-d',strtotime('+7 days'));
    $period_start=$in['period_start']??date('Y-m-01');
    $period_end=$in['period_end']??date('Y-m-t');
    $status=$in['status']??'unpaid';

    $pdo->prepare("INSERT INTO radius_invoices
        (invoice_number,username,full_name,profile_name,service_type,amount,discount,total,description,period_start,period_end,due_date,status,notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$inv_no,$username,$full_name,$profile,$in['service_type']??'pppoe',$amount,$discount,$total,
                   $in['description']??'',$period_start,$period_end,$due_date,$status,$in['notes']??'']);

    $inv_id=(int)$pdo->lastInsertId();

    // If status paid, record payment immediately
    if ($status==='paid') {
        $pdo->prepare("UPDATE radius_invoices SET paid_at=NOW() WHERE id=?")->execute([$inv_id]);
        $pdo->prepare("INSERT INTO radius_payments (invoice_id,invoice_number,username,amount,payment_method,paid_at,notes) VALUES (?,?,?,?,?,NOW(),?)")
            ->execute([$inv_id,$inv_no,$username,$total,$in['payment_method']??'Cash',$in['notes']??'']);
    }

    return ['ok'=>true,'invoice_number'=>$inv_no,'id'=>$inv_id];
}

function update_invoice($pdo,$in) {
    $id=(int)($in['id']??0); if (!$id) return ['ok'=>false,'error'=>'ID wajib'];
    $fields=['profile_name','service_type','amount','discount','total','description','period_start','period_end','due_date','status','notes','payment_method'];
    $data=[];
    foreach($fields as $f) if (isset($in[$f])) $data[$f]=$in[$f];
    if (isset($data['amount'])||isset($data['discount'])) {
        $amt=$in['amount']??0; $disc=$in['discount']??0;
        $data['total']=max(0,$amt-$disc);
    }
    if (empty($data)) return ['ok'=>true];
    $set=implode(',',array_map(fn($k)=>"$k=:$k",array_keys($data)));
    $data[':id']=$id;
    $pdo->prepare("UPDATE radius_invoices SET $set WHERE id=:id")->execute($data);
    return ['ok'=>true];
}

function delete_invoice($pdo,$in) {
    $id=(int)($in['id']??0); if (!$id) return ['ok'=>false,'error'=>'ID wajib'];
    $pdo->prepare("DELETE FROM radius_invoices WHERE id=?")->execute([$id]);
    return ['ok'=>true];
}

function pay_invoice($pdo,$in) {
    $id=(int)($in['id']??0); if (!$id) return ['ok'=>false,'error'=>'ID wajib'];
    $s=$pdo->prepare("SELECT * FROM radius_invoices WHERE id=?"); $s->execute([$id]); $inv=$s->fetch();
    if (!$inv) return ['ok'=>false,'error'=>'Invoice tidak ditemukan'];
    if ($inv['status']==='paid') return ['ok'=>false,'error'=>'Invoice sudah lunas'];

    $amount=(int)($in['amount']??$inv['total']);
    $method=$in['payment_method']??'Cash';

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE radius_invoices SET status='paid',paid_at=NOW(),payment_method=? WHERE id=?")->execute([$method,$id]);
        $pdo->prepare("INSERT INTO radius_payments (invoice_id,invoice_number,username,amount,payment_method,paid_at,received_by,notes) VALUES (?,?,?,?,?,NOW(),?,?)")
            ->execute([$id,$inv['invoice_number'],$inv['username'],$amount,$method,$in['received_by']??'',$in['notes']??'']);
        $pdo->commit();
        return ['ok'=>true,'invoice_number'=>$inv['invoice_number'],'amount'=>$amount];
    } catch(Exception $e) { $pdo->rollBack(); return ['ok'=>false,'error'=>$e->getMessage()]; }
}

function bulk_create_invoices($pdo,$in) {
    // Generate invoice untuk semua PPPoE user aktif sekaligus
    $profile=$in['profile']??''; $month=$in['month']??date('Y-m');
    $due_date=$in['due_date']??date('Y-m-d',strtotime('+7 days'));
    if (!$profile) return ['ok'=>false,'error'=>'Profile wajib'];

    $pr=$pdo->prepare("SELECT price FROM radius_profiles WHERE name=?"); $pr->execute([$profile]);
    $price=(int)($pr->fetchColumn()?:0);
    if (!$price) return ['ok'=>false,'error'=>'Harga profile 0, set harga dulu'];

    // Ambil semua user dengan profile ini yang belum punya invoice bulan ini
    $users=$pdo->prepare("SELECT DISTINCT rc.username FROM radcheck rc
        JOIN radusergroup rg ON rc.username=rg.username
        WHERE rc.attribute='Cleartext-Password' AND rg.groupname=?
        AND rc.username NOT IN (SELECT username FROM radius_invoices WHERE profile_name=? AND DATE_FORMAT(created_at,'%Y-%m')=?)");
    $users->execute([$profile,$profile,$month]); $userlist=$users->fetchAll(PDO::FETCH_COLUMN);

    $created=0;
    $pdo->beginTransaction();
    try {
        foreach($userlist as $u) {
            $cn=$pdo->prepare("SELECT full_name FROM radius_customers WHERE username=?"); $cn->execute([$u]);
            $full_name=$cn->fetchColumn()?:'';
            $inv_no=gen_invoice_number($pdo);
            $period_start=$month.'-01';
            $period_end=date('Y-m-t',strtotime($period_start));
            $pdo->prepare("INSERT INTO radius_invoices (invoice_number,username,full_name,profile_name,service_type,amount,discount,total,description,period_start,period_end,due_date,status)
                VALUES (?,?,?,?,'pppoe',?,0,?,?,?,?,?,'unpaid')")
                ->execute([$inv_no,$u,$full_name,$profile,$price,$price,"Tagihan $profile bulan ".date('F Y',strtotime($period_start)),$period_start,$period_end,$due_date]);
            $created++;
        }
        $pdo->commit();
        return ['ok'=>true,'created'=>$created,'skipped'=>count($userlist)-$created];
    } catch(Exception $e) { $pdo->rollBack(); return ['ok'=>false,'error'=>$e->getMessage()]; }
}

function get_payments($pdo,$in) {
    $month=$in['month']??date('Y-m');
    $rows=$pdo->prepare("SELECT p.*,i.profile_name,i.period_start,i.period_end
        FROM radius_payments p LEFT JOIN radius_invoices i ON p.invoice_id=i.id
        WHERE DATE_FORMAT(p.paid_at,'%Y-%m')=? ORDER BY p.paid_at DESC LIMIT 200");
    $rows->execute([$month]);
    return ['ok'=>true,'payments'=>$rows->fetchAll()];
}

// ── EXPENSES ──────────────────────────────────────────────────
function get_expenses($pdo,$in) {
    $month=$in['month']??date('Y-m');
    $rows=$pdo->prepare("SELECT * FROM radius_expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=? ORDER BY expense_date DESC");
    $rows->execute([$month]);
    $total=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM radius_expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=?");
    $total->execute([$month]);
    return ['ok'=>true,'expenses'=>$rows->fetchAll(),'total'=>(int)$total->fetchColumn()];
}

function add_expense($pdo,$in) {
    $desc=trim($in['description']??''); $amount=(int)($in['amount']??0);
    if (!$desc||!$amount) return ['ok'=>false,'error'=>'Deskripsi & amount wajib'];
    $pdo->prepare("INSERT INTO radius_expenses (category,description,amount,expense_date,notes) VALUES (?,?,?,?,?)")
        ->execute([$in['category']??'Operasional',$desc,$amount,$in['expense_date']??date('Y-m-d'),$in['notes']??'']);
    return ['ok'=>true,'id'=>$pdo->lastInsertId()];
}

function delete_expense($pdo,$in) {
    $id=(int)($in['id']??0); if (!$id) return ['ok'=>false,'error'=>'ID wajib'];
    $pdo->prepare("DELETE FROM radius_expenses WHERE id=?")->execute([$id]);
    return ['ok'=>true];
}

// ── REPORTS ───────────────────────────────────────────────────
function billing_summary($pdo,$in) {
    $month=$in['month']??date('Y-m');
    $year=substr($month,0,4);

    $income=(int)$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM radius_payments WHERE DATE_FORMAT(paid_at,'%Y-%m')=?")->execute([$month]) && false ?: (function() use($pdo,$month){ $s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM radius_payments WHERE DATE_FORMAT(paid_at,'%Y-%m')=?"); $s->execute([$month]); return (int)$s->fetchColumn(); })();
    $expense=(int)(function() use($pdo,$month){ $s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM radius_expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=?"); $s->execute([$month]); return (int)$s->fetchColumn(); })();
    $unpaid_count=(int)(function() use($pdo,$month){ $s=$pdo->prepare("SELECT COUNT(*) FROM radius_invoices WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND status IN ('unpaid','overdue')"); $s->execute([$month]); return (int)$s->fetchColumn(); })();
    $unpaid_total=(int)(function() use($pdo,$month){ $s=$pdo->prepare("SELECT COALESCE(SUM(total),0) FROM radius_invoices WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND status IN ('unpaid','overdue')"); $s->execute([$month]); return (int)$s->fetchColumn(); })();
    $paid_count=(int)(function() use($pdo,$month){ $s=$pdo->prepare("SELECT COUNT(*) FROM radius_invoices WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND status='paid'"); $s->execute([$month]); return (int)$s->fetchColumn(); })();
    $total_invoices=(int)(function() use($pdo,$month){ $s=$pdo->prepare("SELECT COUNT(*) FROM radius_invoices WHERE DATE_FORMAT(created_at,'%Y-%m')=?"); $s->execute([$month]); return (int)$s->fetchColumn(); })();

    // Monthly chart (12 months)
    $monthly=[]; for($m=1;$m<=12;$m++) {
        $ym=$year.'-'.str_pad($m,2,'0',STR_PAD_LEFT);
        $si=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM radius_payments WHERE DATE_FORMAT(paid_at,'%Y-%m')=?"); $si->execute([$ym]);
        $se=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM radius_expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=?"); $se->execute([$ym]);
        $monthly[]=[ 'month'=>$ym,'income'=>(int)$si->fetchColumn(),'expense'=>(int)$se->fetchColumn() ];
    }

    // Top profiles
    $top=$pdo->prepare("SELECT profile_name, COUNT(*) as count, SUM(total) as total FROM radius_invoices WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND status='paid' GROUP BY profile_name ORDER BY total DESC LIMIT 5");
    $top->execute([$month]);

    // Payment methods
    $methods=$pdo->prepare("SELECT payment_method, COUNT(*) as count, SUM(amount) as total FROM radius_payments WHERE DATE_FORMAT(paid_at,'%Y-%m')=? GROUP BY payment_method ORDER BY total DESC");
    $methods->execute([$month]);

    return ['ok'=>true,'income'=>$income,'expense'=>$expense,'profit'=>$income-$expense,
            'unpaid_count'=>$unpaid_count,'unpaid_total'=>$unpaid_total,
            'paid_count'=>$paid_count,'total_invoices'=>$total_invoices,
            'monthly'=>$monthly,'top_profiles'=>$top->fetchAll(),'payment_methods'=>$methods->fetchAll()];
}

function monthly_report($pdo,$in) {
    $month=$in['month']??date('Y-m');
    // Per-user summary
    $rows=$pdo->prepare("SELECT i.username, c.full_name, i.profile_name,
        COUNT(*) as invoice_count, SUM(i.total) as total_billed,
        SUM(CASE WHEN i.status='paid' THEN i.total ELSE 0 END) as total_paid,
        SUM(CASE WHEN i.status IN ('unpaid','overdue') THEN i.total ELSE 0 END) as total_unpaid,
        MAX(CASE WHEN i.status IN ('unpaid','overdue') THEN 1 ELSE 0 END) as has_unpaid
        FROM radius_invoices i LEFT JOIN radius_customers c ON i.username=c.username
        WHERE DATE_FORMAT(i.created_at,'%Y-%m')=?
        GROUP BY i.username ORDER BY has_unpaid DESC, i.username ASC");
    $rows->execute([$month]);
    return ['ok'=>true,'report'=>$rows->fetchAll()];
}
