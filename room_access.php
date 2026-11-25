<?php
require_once __DIR__.'/config.php';
function ra_redirect($u){header('Location: '.$u);exit;}
$pdo=db();
if (!empty($_GET['logout'])) { unset($_SESSION['room_access_id']); ra_redirect('?page=room_access&mode=login'); }
if (!empty($_SESSION['user_id']) && (($_GET['mode'] ?? 'admin') !== 'login')) {
  if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf(); $id=(int)($_POST['room_id']??0);
    $key='KMK-'.strtoupper(bin2hex(random_bytes(4)));
    $st=$pdo->prepare('UPDATE rooms SET access_key_hash=?,access_key_updated_at=NOW() WHERE id=?');
    $st->execute([password_hash($key,PASSWORD_DEFAULT),$id]);
    $_SESSION['generated_room_key']=$key;
    ra_redirect('?page=room_access');
  }
  $rooms=$pdo->query('SELECT r.*,c.name category FROM rooms r JOIN categories c ON c.id=r.category_id ORDER BY c.name,r.room_number')->fetchAll();
  $generated=$_SESSION['generated_room_key']??null; unset($_SESSION['generated_room_key']);
  ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ключи кабинетов</title><link rel="stylesheet" href="assets/style.css"><script defer src="assets/lang.js"></script></head><body><main class="main standalone"><button type="button" class="lang-toggle" onclick="toggleLanguage()" id="langToggle">ҚАЗ</button><a href="?" class="back-link">← Назад в панель</a><h1>Ключи доступа кабинетов</h1><p class="muted">Создавайте индивидуальный ключ для каждого помещения. При повторной генерации старый ключ перестанет работать.</p><?php if($generated):?><div class="key-reveal"><b>Новый ключ — сохраните его сейчас:</b><code id="newKey"><?=e($generated)?></code><button class="btn secondary" onclick="navigator.clipboard.writeText(document.getElementById('newKey').textContent)">Скопировать</button></div><?php endif;?><div class="table-wrap"><table><thead><tr><th>Раздел</th><th>Помещение</th><th>Ключ</th><th>Действие</th></tr></thead><tbody><?php foreach($rooms as $r):?><tr><td><?=e($r['category'])?></td><td>№<?=e($r['room_number'])?> · <?=e($r['name'])?></td><td><?=$r['access_key_hash']?'<span class="status">Создан</span>':'<span class="muted">Не создан</span>'?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="room_id" value="<?=$r['id']?>"><button class="btn secondary">Создать / сменить ключ</button></form></td></tr><?php endforeach;?></tbody></table></div><p class="muted small">Ссылка для сотрудников: <b>?page=room_access</b>. Ключи в базе хранятся в виде хеша.</p></main></body></html><?php exit;
}
// Room login and room-only entry mode
if (empty($_SESSION['room_access_id'])) {
  $rooms=$pdo->query('SELECT id,room_number,name FROM rooms ORDER BY room_number')->fetchAll();
  $err='';
  if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf(); $id=(int)($_POST['room_id']??0); $q=$pdo->prepare('SELECT id,access_key_hash FROM rooms WHERE id=?');$q->execute([$id]);$r=$q->fetch();
    if($r && $r['access_key_hash'] && password_verify($_POST['access_key']??'',$r['access_key_hash'])){session_regenerate_id(true);$_SESSION['room_access_id']=$id;ra_redirect('?page=room_access&mode=login');}
    $err='Неверный ключ доступа или ключ ещё не создан администратором.';
  }
  ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Вход кабинета — КМК</title><link rel="stylesheet" href="assets/style.css"><script defer src="assets/lang.js"></script></head><body class="login-page"><main class="login-card"><button type="button" class="lang-toggle login-lang" onclick="toggleLanguage()" id="langToggle">ҚАЗ</button><img class="login-logo" src="assets/kmk-logo.png" alt="Логотип КМК"><h1>Вход кабинета</h1><p class="muted">Выберите помещение и введите персональный ключ.</p><?php if($err):?><div class="alert error"><?=e($err)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><label>Кабинет<select name="room_id"><?php foreach($rooms as $r):?><option value="<?=$r['id']?>">№<?=e($r['room_number'])?> · <?=e($r['name'])?></option><?php endforeach;?></select></label><label>Ключ доступа<input name="access_key" required></label><button class="btn full">Войти</button></form><a class="muted small" href="?page=login">Вход администратора</a></main></body></html><?php exit;
}
$rid=(int)$_SESSION['room_access_id'];$q=$pdo->prepare('SELECT r.*,c.name category FROM rooms r JOIN categories c ON c.id=r.category_id WHERE r.id=?');$q->execute([$rid]);$room=$q->fetch();
if(!$room){unset($_SESSION['room_access_id']);ra_redirect('?page=room_access');}
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();$year=(int)$pdo->query('SELECT id FROM academic_years WHERE is_active=1 LIMIT 1')->fetchColumn();
  if($year){$pdo->prepare('INSERT INTO equipment(academic_year_id,room_id,item_number,name,quantity,inventory_number,note,status) VALUES(?,?,?,?,?,?,?,?)')->execute([$year,$rid,trim($_POST['item_number']??''),trim($_POST['name']??''),max(1,(int)($_POST['quantity']??1)),trim($_POST['inventory_number']??''),trim($_POST['note']??''),'В наличии']);}
  ra_redirect('?page=room_access');
}
$year=(int)($pdo->query('SELECT id FROM academic_years WHERE is_active=1 LIMIT 1')->fetchColumn()?:0);$q=$pdo->prepare('SELECT * FROM equipment WHERE room_id=? AND academic_year_id=? ORDER BY id DESC');$q->execute([$rid,$year]);$items=$q->fetchAll();
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Кабинет КМК</title><link rel="stylesheet" href="assets/style.css"><script defer src="assets/lang.js"></script></head><body class="room-page"><main class="room-shell"><header class="room-top"><button type="button" class="lang-toggle" onclick="toggleLanguage()" id="langToggle">ҚАЗ</button><img src="assets/kmk-logo.png" alt="КМК"><div><b>Инвентаризация КМК</b><small><?=e($room['category'])?> · №<?=e($room['room_number'])?> · <?=e($room['name'])?></small></div><a href="?page=room_access&logout=1">Выйти</a></header><section class="room-welcome"><span class="eyebrow">КАБИНЕТНЫЙ ДОСТУП</span><h1>Учёт оборудования</h1><p>Добавление доступно только для выбранного помещения. Редактирование общих данных и перемещения выполняет администратор.</p></section><section class="panel"><h2>Добавить оборудование</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><label>Номер записи<input name="item_number"></label><label>Наименование оборудования<input name="name" required></label><label>Количество<input type="number" name="quantity" min="1" value="1"></label><label>Инвентарный номер<input name="inventory_number"></label><label class="wide">Примечание<textarea name="note"></textarea></label><div class="wide"><button class="btn">＋ Сохранить оборудование</button></div></form></section><section class="panel" style="margin-top:18px"><h2>Оборудование кабинета</h2><div class="table-wrap"><table><thead><tr><th>№</th><th>Наименование</th><th>Кол-во</th><th>Инвентарный номер</th><th>Примечание</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><?=e($it['item_number'])?></td><td><?=e($it['name'])?></td><td><?=$it['quantity']?></td><td><?=e($it['inventory_number'])?></td><td><?=e($it['note'])?></td></tr><?php endforeach;?><?php if(!$items):?><tr><td colspan="5" class="empty">Оборудование пока не внесено.</td></tr><?php endif;?></tbody></table></div></section></main></body></html>
