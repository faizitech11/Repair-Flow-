<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
start_secure_session();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

$action = $_GET['action'] ?? '';
$data = request_data();
$pdo = db();

function shop_row(PDO $pdo, int $shopId): array {
    $s = $pdo->prepare('SELECT id, username, shop_name, phone, logo_path, created_at FROM shops WHERE id = ?');
    $s->execute([$shopId]);
    $row = $s->fetch();
    if (!$row) json_response(['ok'=>false,'message'=>'Shop account not found.'], 404);
    return $row;
}
function upload_image(string $field, int $shopId, string $folder): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > MAX_UPLOAD_BYTES) throw new RuntimeException('Image upload failed or file exceeds 3 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $exts = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($exts[$mime])) throw new RuntimeException('Use JPG, PNG, or WEBP images only.');
    $dir = __DIR__ . '/uploads/' . $folder . '/' . $shopId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Could not create upload directory.');
    $name = bin2hex(random_bytes(16)) . '.' . $exts[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Could not save uploaded image.');
    return 'uploads/' . $folder . '/' . $shopId . '/' . $name;
}
function require_post_csrf(): int {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['ok'=>false,'message'=>'POST required.'], 405);
    require_csrf();
    return require_auth();
}

try {
    if ($action === 'csrf') {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        json_response(['ok'=>true,'csrf'=>$_SESSION['csrf'],'loggedIn'=>!empty($_SESSION['shop_id'])]);
    }
    if ($action === 'register') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'message'=>'POST required.'],405);
        require_csrf();
        $username = strtolower(clean_string($data['username'] ?? '', 60));
        $password = (string)($data['password'] ?? '');
        $shopName = clean_string($data['shop_name'] ?? '', 150);
        $phone = clean_string($data['phone'] ?? '', 40);
        if (!preg_match('/^[a-z0-9_.-]{3,60}$/', $username)) json_response(['ok'=>false,'message'=>'Username must be 3–60 characters using letters, numbers, dot, dash or underscore.'],422);
        if (strlen($password) < 10) json_response(['ok'=>false,'message'=>'Password must be at least 10 characters.'],422);
        if ($shopName === '' || $phone === '') json_response(['ok'=>false,'message'=>'Shop name and phone are required.'],422);
        $check = $pdo->prepare('SELECT id FROM shops WHERE username=?'); $check->execute([$username]);
        if ($check->fetch()) json_response(['ok'=>false,'message'=>'Username is already registered.'],409);
        $logo = upload_image('logo', 0, 'logos');
        $st = $pdo->prepare('INSERT INTO shops(username,password_hash,shop_name,phone,logo_path) VALUES(?,?,?,?,?)');
        $st->execute([$username,password_hash($password,PASSWORD_DEFAULT),$shopName,$phone,$logo]);
        $shopId = (int)$pdo->lastInsertId();
        if ($logo && str_contains($logo, '/0/')) {
            $old = __DIR__ . '/' . $logo; $dir = __DIR__ . '/uploads/logos/' . $shopId;
            if (!is_dir($dir)) mkdir($dir,0755,true); $new = $dir . '/' . basename($old);
            if (is_file($old) && rename($old,$new)) { $logo = 'uploads/logos/'.$shopId.'/'.basename($new); $pdo->prepare('UPDATE shops SET logo_path=? WHERE id=?')->execute([$logo,$shopId]); }
        }
        $pdo->prepare('INSERT INTO categories(shop_id,name) VALUES(?,?)')->execute([$shopId,'General']);
        session_regenerate_id(true); $_SESSION['shop_id']=$shopId; $_SESSION['username']=$username; $_SESSION['csrf']=bin2hex(random_bytes(32));
        json_response(['ok'=>true,'message'=>'Shop account created.','csrf'=>$_SESSION['csrf'],'shop'=>shop_row($pdo,$shopId)]);
    }
    if ($action === 'login') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'message'=>'POST required.'],405);
        require_csrf();
        $username = strtolower(clean_string($data['username'] ?? '',60)); $password=(string)($data['password']??'');
        $st=$pdo->prepare('SELECT id,password_hash FROM shops WHERE username=?'); $st->execute([$username]); $u=$st->fetch();
        if (!$u || !password_verify($password,$u['password_hash'])) json_response(['ok'=>false,'message'=>'Invalid username or password.'],401);
        if (password_needs_rehash($u['password_hash'],PASSWORD_DEFAULT)) $pdo->prepare('UPDATE shops SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$u['id']]);
        session_regenerate_id(true); $_SESSION['shop_id']=(int)$u['id']; $_SESSION['username']=$username; $_SESSION['csrf']=bin2hex(random_bytes(32));
        json_response(['ok'=>true,'message'=>'Login successful.','csrf'=>$_SESSION['csrf'],'shop'=>shop_row($pdo,(int)$u['id'])]);
    }
    if ($action === 'logout') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'message'=>'POST required.'],405);
        require_csrf(); $_SESSION=[]; if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',$p['secure'],$p['httponly']); }
        session_destroy(); json_response(['ok'=>true,'message'=>'Logged out.']);
    }

    $shopId = require_auth();
    if ($action === 'bootstrap') {
        $shop=shop_row($pdo,$shopId);
        $c=$pdo->prepare('SELECT id,name FROM categories WHERE shop_id=? ORDER BY name'); $c->execute([$shopId]);
        $p=$pdo->prepare('SELECT p.*,c.name AS category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.shop_id=? ORDER BY p.title'); $p->execute([$shopId]);
        $r=$pdo->prepare('SELECT * FROM repairs WHERE shop_id=? ORDER BY created_at DESC LIMIT 300'); $r->execute([$shopId]);
        $s=$pdo->prepare('SELECT * FROM sales WHERE shop_id=? ORDER BY created_at DESC LIMIT 500'); $s->execute([$shopId]);
        $pay=$pdo->prepare('SELECT rp.*,r.customer_name,r.device_model FROM repair_payments rp JOIN repairs r ON r.id=rp.repair_id AND r.shop_id=rp.shop_id WHERE rp.shop_id=? ORDER BY rp.created_at DESC LIMIT 500'); $pay->execute([$shopId]);
        json_response(['ok'=>true,'shop'=>$shop,'categories'=>$c->fetchAll(),'products'=>$p->fetchAll(),'repairs'=>$r->fetchAll(),'sales'=>$s->fetchAll(),'payments'=>$pay->fetchAll()]);
    }
    if ($action === 'settings') {
        $id=require_post_csrf(); $name=clean_string($data['shop_name']??'',150); $phone=clean_string($data['phone']??'',40);
        if (!$name || !$phone) json_response(['ok'=>false,'message'=>'Shop name and phone are required.'],422);
        $logo=upload_image('logo',$id,'logos');
        if ($logo) $pdo->prepare('UPDATE shops SET shop_name=?,phone=?,logo_path=? WHERE id=?')->execute([$name,$phone,$logo,$id]);
        else $pdo->prepare('UPDATE shops SET shop_name=?,phone=? WHERE id=?')->execute([$name,$phone,$id]);
        json_response(['ok'=>true,'shop'=>shop_row($pdo,$id),'message'=>'Shop settings saved.']);
    }
    if ($action === 'category') {
        $id=require_post_csrf(); $name=clean_string($data['name']??'',100); if(!$name) json_response(['ok'=>false,'message'=>'Category name is required.'],422);
        $st=$pdo->prepare('INSERT INTO categories(shop_id,name) VALUES(?,?)'); $st->execute([$id,$name]); json_response(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
    }
    if ($action === 'product_save') {
        $id=require_post_csrf(); $pid=(int)($data['id']??0); $title=clean_string($data['title']??'',180); $cat=(int)($data['category_id']??0); $price=money_value($data['price']??0); $cost=money_value($data['purchase_cost']??0); $qty=(int)($data['qty']??0); $threshold=max(0,(int)($data['low_stock_threshold']??2));
        if(!$title || $qty<0) json_response(['ok'=>false,'message'=>'Product title and valid stock quantity are required.'],422);
        $cs=$pdo->prepare('SELECT id FROM categories WHERE id=? AND shop_id=?'); $cs->execute([$cat,$id]); if(!$cs->fetch()) $cat=0;
        $img=upload_image('image',$id,'products');
        if($pid){
            $check=$pdo->prepare('SELECT id,image_path,qty FROM products WHERE id=? AND shop_id=?'); $check->execute([$pid,$id]); $old=$check->fetch(); if(!$old) json_response(['ok'=>false,'message'=>'Product not found.'],404);
            $pdo->prepare('UPDATE products SET category_id=?,title=?,price=?,purchase_cost=?,qty=?,low_stock_threshold=?,image_path=COALESCE(?,image_path) WHERE id=? AND shop_id=?')->execute([$cat?:null,$title,$price,$cost,$qty,$threshold,$img,$pid,$id]);
            $diff=$qty-(int)$old['qty']; if($diff!==0) $pdo->prepare('INSERT INTO stock_movements(shop_id,product_id,movement_type,quantity_change,purchase_cost,note) VALUES(?,?,?,?,?,?)')->execute([$id,$pid,'ADJUSTMENT',$diff,$cost,'Product stock edited']);
        } else {
            $pdo->prepare('INSERT INTO products(shop_id,category_id,title,price,purchase_cost,qty,low_stock_threshold,image_path) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$cat?:null,$title,$price,$cost,$qty,$threshold,$img]); $pid=(int)$pdo->lastInsertId();
            if($qty>0) $pdo->prepare('INSERT INTO stock_movements(shop_id,product_id,movement_type,quantity_change,purchase_cost,note) VALUES(?,?,?,?,?,?)')->execute([$id,$pid,'RESTOCK',$qty,$cost,'Opening stock']);
        }
        json_response(['ok'=>true,'message'=>'Product saved.']);
    }
    if ($action === 'product_delete') {
        $id=require_post_csrf(); $pid=(int)($data['id']??0); $st=$pdo->prepare('DELETE FROM products WHERE id=? AND shop_id=?'); $st->execute([$pid,$id]); if(!$st->rowCount()) json_response(['ok'=>false,'message'=>'Product not found.'],404); json_response(['ok'=>true,'message'=>'Product deleted. Previous sales remain in sales history.']);
    }
    if ($action === 'restock') {
        $id=require_post_csrf(); $pid=(int)($data['id']??0); $qty=(int)($data['qty']??0); $cost=money_value($data['purchase_cost']??0); $note=clean_string($data['note']??'Stock received',255);
        if($qty<1) json_response(['ok'=>false,'message'=>'Restock quantity must be at least 1.'],422);
        $pdo->beginTransaction(); $st=$pdo->prepare('SELECT id,qty FROM products WHERE id=? AND shop_id=? FOR UPDATE'); $st->execute([$pid,$id]); $prod=$st->fetch(); if(!$prod){$pdo->rollBack();json_response(['ok'=>false,'message'=>'Product not found.'],404);}
        $pdo->prepare('UPDATE products SET qty=qty+?,purchase_cost=? WHERE id=? AND shop_id=?')->execute([$qty,$cost,$pid,$id]);
        $pdo->prepare('INSERT INTO stock_movements(shop_id,product_id,movement_type,quantity_change,purchase_cost,note) VALUES(?,?,?,?,?,?)')->execute([$id,$pid,'RESTOCK',$qty,$cost,$note]); $pdo->commit(); json_response(['ok'=>true,'message'=>'Stock updated.']);
    }
    if ($action === 'sell') {
        $id=require_post_csrf(); $pid=(int)($data['id']??0); $qty=max(1,(int)($data['qty']??1));
        $pdo->beginTransaction(); $st=$pdo->prepare('SELECT p.*,c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.shop_id=? FOR UPDATE'); $st->execute([$pid,$id]); $p=$st->fetch();
        if(!$p || (int)$p['qty']<$qty){$pdo->rollBack();json_response(['ok'=>false,'message'=>'Product not found or insufficient stock.'],422);}
        $total=(float)$p['price']*$qty; $pdo->prepare('UPDATE products SET qty=qty-? WHERE id=? AND shop_id=?')->execute([$qty,$pid,$id]);
        $pdo->prepare('INSERT INTO sales(shop_id,product_id,product_title,category_name,quantity,unit_price,total) VALUES(?,?,?,?,?,?,?)')->execute([$id,$pid,$p['title'],$p['category_name']??'Uncategorized',$qty,$p['price'],$total]);
        $pdo->prepare('INSERT INTO stock_movements(shop_id,product_id,movement_type,quantity_change,purchase_cost,note) VALUES(?,?,?,?,?,?)')->execute([$id,$pid,'SALE',-$qty,$p['purchase_cost'],'Quick sale']); $pdo->commit(); json_response(['ok'=>true,'message'=>'Sale recorded.']);
    }
    if ($action === 'repair_save') {
        $id=require_post_csrf(); $rid=(int)($data['id']??0); $name=clean_string($data['customer_name']??'',150); $phone=clean_string($data['customer_phone']??'',40); $model=clean_string($data['device_model']??'',180); $desc=clean_string($data['description']??'',5000); $est=money_value($data['estimated_cost']??0); $final=money_value($data['final_cost']??$est); $due=clean_string($data['due_date']??'',10); $photo=upload_image('photo',$id,'repairs');
        if(!$name||!$phone||!$model||!$desc) json_response(['ok'=>false,'message'=>'Customer, phone, device and repair description are required.'],422);
        if($due && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)) json_response(['ok'=>false,'message'=>'Invalid due date.'],422);
        if($rid){$st=$pdo->prepare('UPDATE repairs SET customer_name=?,customer_phone=?,device_model=?,description=?,estimated_cost=?,final_cost=?,due_date=?,device_photo=COALESCE(?,device_photo) WHERE id=? AND shop_id=?');$st->execute([$name,$phone,$model,$desc,$est,$final,$due?:null,$photo,$rid,$id]); if(!$st->rowCount()) { $q=$pdo->prepare('SELECT id FROM repairs WHERE id=? AND shop_id=?');$q->execute([$rid,$id]);if(!$q->fetch())json_response(['ok'=>false,'message'=>'Repair not found.'],404); }}
        else {$pdo->prepare('INSERT INTO repairs(shop_id,customer_name,customer_phone,device_model,description,estimated_cost,final_cost,due_date,device_photo) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$name,$phone,$model,$desc,$est,$final,$due?:null,$photo]);$rid=(int)$pdo->lastInsertId();}
        json_response(['ok'=>true,'id'=>$rid,'message'=>'Repair ticket saved.']);
    }
    if ($action === 'repair_status') {
        $id=require_post_csrf(); $rid=(int)($data['id']??0); $status=(string)($data['status']??''); if(!in_array($status,['Pending','In-Progress','Completed','Cancelled'],true)) json_response(['ok'=>false,'message'=>'Invalid status.'],422);
        $st=$pdo->prepare("UPDATE repairs SET status=?,completed_at=CASE WHEN ?='Completed' THEN COALESCE(completed_at,NOW()) ELSE completed_at END WHERE id=? AND shop_id=?");$st->execute([$status,$status,$rid,$id]);if(!$st->rowCount()){ $q=$pdo->prepare('SELECT id FROM repairs WHERE id=? AND shop_id=?');$q->execute([$rid,$id]);if(!$q->fetch())json_response(['ok'=>false,'message'=>'Repair not found.'],404);}json_response(['ok'=>true,'message'=>'Repair status updated.']);
    }
    if ($action === 'repair_payment') {
        $id=require_post_csrf(); $rid=(int)($data['repair_id']??0); $amount=money_value($data['amount']??0); $method=clean_string($data['method']??'Cash',40); $note=clean_string($data['note']??'',255); if($amount<=0)json_response(['ok'=>false,'message'=>'Payment must be greater than zero.'],422);
        $pdo->beginTransaction(); $st=$pdo->prepare('SELECT id,final_cost FROM repairs WHERE id=? AND shop_id=? FOR UPDATE');$st->execute([$rid,$id]);$r=$st->fetch();if(!$r){$pdo->rollBack();json_response(['ok'=>false,'message'=>'Repair not found.'],404);}
        $pdo->prepare('INSERT INTO repair_payments(shop_id,repair_id,amount,method,note) VALUES(?,?,?,?,?)')->execute([$id,$rid,$amount,$method,$note]);
        $q=$pdo->prepare('SELECT COALESCE(SUM(amount),0) paid FROM repair_payments WHERE repair_id=? AND shop_id=?');$q->execute([$rid,$id]);$paid=(float)$q->fetch()['paid'];$state=$paid<=0?'Unpaid':($paid+0.001 >= (float)$r['final_cost']?'Paid':'Partial');$pdo->prepare('UPDATE repairs SET payment_status=? WHERE id=? AND shop_id=?')->execute([$state,$rid,$id]);$pdo->commit();json_response(['ok'=>true,'message'=>'Payment recorded.','payment_status'=>$state]);
    }
    if ($action === 'report') {
        $id=require_auth(); $period=(string)($_GET['period']??'month'); $date=(string)($_GET['date']??date('Y-m-d')); if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$date=date('Y-m-d');
        if($period==='day'){$start=$date.' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($date.' +1 day'));$label=$date;}
        elseif($period==='year'){$year=substr($date,0,4);$start=$year.'-01-01 00:00:00';$end=((int)$year+1).'-01-01 00:00:00';$label=$year;}
        else{$month=substr($date,0,7);$start=$month.'-01 00:00:00';$end=date('Y-m-d H:i:s',strtotime($start.' +1 month'));$label=$month;}
        $shop=shop_row($pdo,$id);$st=$pdo->prepare('SELECT COUNT(*) n,COALESCE(SUM(final_cost),0) total FROM repairs WHERE shop_id=? AND created_at>=? AND created_at<?');$st->execute([$id,$start,$end]);$repair=$st->fetch();
        $st=$pdo->prepare('SELECT COUNT(*) n,COALESCE(SUM(total),0) total FROM sales WHERE shop_id=? AND created_at>=? AND created_at<?');$st->execute([$id,$start,$end]);$sales=$st->fetch();
        $st=$pdo->prepare('SELECT COALESCE(SUM(amount),0) total FROM repair_payments WHERE shop_id=? AND created_at>=? AND created_at<?');$st->execute([$id,$start,$end]);$payments=$st->fetch();
        $st=$pdo->prepare('SELECT product_title,category_name,quantity,unit_price,total,created_at FROM sales WHERE shop_id=? AND created_at>=? AND created_at<? ORDER BY created_at DESC');$st->execute([$id,$start,$end]);$saleRows=$st->fetchAll();
        $st=$pdo->prepare('SELECT customer_name,customer_phone,device_model,description,status,payment_status,final_cost,created_at FROM repairs WHERE shop_id=? AND created_at>=? AND created_at<? ORDER BY created_at DESC');$st->execute([$id,$start,$end]);$repairRows=$st->fetchAll();
        json_response(['ok'=>true,'period'=>$period,'label'=>$label,'shop'=>$shop,'summary'=>['repair_count'=>(int)$repair['n'],'repair_billed'=>(float)$repair['total'],'sales_count'=>(int)$sales['n'],'sales_total'=>(float)$sales['total'],'payments_received'=>(float)$payments['total']],'sales'=>$saleRows,'repairs'=>$repairRows]);
    }
    if ($action === 'repair_delete') {
        $id=require_post_csrf();$rid=(int)($data['id']??0);$st=$pdo->prepare('DELETE FROM repairs WHERE id=? AND shop_id=?');$st->execute([$rid,$id]);if(!$st->rowCount())json_response(['ok'=>false,'message'=>'Repair not found.'],404);json_response(['ok'=>true,'message'=>'Repair deleted.']);
    }
    json_response(['ok'=>false,'message'=>'Unknown action.'],404);
} catch (InvalidArgumentException $e) {
    json_response(['ok'=>false,'message'=>$e->getMessage()],422);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') json_response(['ok'=>false,'message'=>'That value already exists or is linked to another record.'],409);
    error_log('RepairFlow DB error: '.$e->getMessage()); json_response(['ok'=>false,'message'=>'A database error occurred. Check the PHP error log.'],500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack(); error_log('RepairFlow error: '.$e->getMessage()); json_response(['ok'=>false,'message'=>$e->getMessage()],500);
}
