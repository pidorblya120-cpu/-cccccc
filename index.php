<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';

$page=$_GET['page'] ?? 'dashboard';
if ($page==='login' && $_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  $stmt=db()->prepare('SELECT * FROM users WHERE username=? LIMIT 1');
  $stmt->execute([trim($_POST['username'] ?? '')]); $user=$stmt->fetch();
  if ($user && password_verify($_POST['password'] ?? '',$user['password_hash'])) {
    session_regenerate_id(true); $_SESSION['user_id']=$user['id']; $_SESSION['full_name']=$user['full_name']; $_SESSION['role']=$user['role']; redirect('?');
  }
  flash('Неверный логин или пароль','error'); redirect('?page=login');
}
if ($page==='logout') { session_destroy(); redirect('?page=login'); }
if ($page==='login') { render_login(); exit; }
if ($page==='room_access') { require __DIR__.'/room_access.php'; exit; }
require_login(); verify_csrf();
$pdo=db();
$activeYear=(int)($_GET['year'] ?? ($_SESSION['year'] ?? 0));
if (!$activeYear) { $activeYear=(int)($pdo->query('SELECT id FROM academic_years WHERE is_active=1 LIMIT 1')->fetchColumn() ?: $pdo->query('SELECT id FROM academic_years ORDER BY id DESC LIMIT 1')->fetchColumn()); }
$_SESSION['year']=$activeYear;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action=$_POST['action'] ?? '';
  try {
    if ($action==='add_year') {
      $name=trim($_POST['name'] ?? '');
      if (!preg_match('/^\d{4}\s*[–-]\s*\d{4}$/u',$name)) throw new RuntimeException('Введите учебный год в формате 2026–2027.');
      $pdo->prepare('INSERT INTO academic_years(name) VALUES(?)')->execute([$name]); flash('Учебный год добавлен.');
    } elseif ($action==='edit_year') {
      $id=(int)$_POST['id']; $name=trim($_POST['name'] ?? '');
      if (!preg_match('/^\\d{4}\\s*[–-]\\s*\\d{4}$/u',$name)) throw new RuntimeException('Введите учебный год в формате 2026–2027.');
      $pdo->prepare('UPDATE academic_years SET name=? WHERE id=?')->execute([$name,$id]); flash('Название учебного года обновлено.');
    } elseif ($action==='activate_year') {
      $id=(int)$_POST['id']; $pdo->exec('UPDATE academic_years SET is_active=0'); $pdo->prepare('UPDATE academic_years SET is_active=1 WHERE id=?')->execute([$id]); $_SESSION['year']=$id; flash('Активный учебный год изменён.');
    } elseif ($action==='add_category') {
      $pdo->prepare('INSERT INTO categories(name) VALUES(?)')->execute([trim($_POST['name'])]); flash('Раздел добавлен.');
    } elseif ($action==='add_room') {
      $pdo->prepare('INSERT INTO rooms(category_id,room_number,name,notes) VALUES(?,?,?,?)')->execute([(int)$_POST['category_id'],trim($_POST['room_number']),trim($_POST['name']),trim($_POST['notes'] ?? '')]); flash('Помещение добавлено.');
    } elseif ($action==='save_equipment') {
      $id=(int)($_POST['id'] ?? 0); $data=[(int)$_POST['year_id'],(int)$_POST['room_id'],trim($_POST['item_number'] ?? ''),trim($_POST['name']),max(1,(int)$_POST['quantity']),trim($_POST['inventory_number'] ?? ''),trim($_POST['note'] ?? ''),$_POST['status'] ?? 'В наличии'];
      if ($id) { $data[]=$id; $pdo->prepare('UPDATE equipment SET academic_year_id=?,room_id=?,item_number=?,name=?,quantity=?,inventory_number=?,note=?,status=? WHERE id=?')->execute($data); flash('Оборудование обновлено.'); }
      else { $pdo->prepare('INSERT INTO equipment(academic_year_id,room_id,item_number,name,quantity,inventory_number,note,status) VALUES(?,?,?,?,?,?,?,?)')->execute($data); flash('Оборудование добавлено.'); }
    } elseif ($action==='move_equipment') {
      $id=(int)$_POST['equipment_id']; $to=(int)$_POST['to_room_id']; $date=$_POST['moved_at']; $reason=trim($_POST['reason'] ?? '');
      $q=$pdo->prepare('SELECT room_id FROM equipment WHERE id=?'); $q->execute([$id]); $from=(int)$q->fetchColumn();
      if (!$from || $from===$to) throw new RuntimeException('Выберите другое помещение для перемещения.');
      $pdo->beginTransaction();
      $pdo->prepare('INSERT INTO movements(equipment_id,from_room_id,to_room_id,moved_at,reason,created_by) VALUES(?,?,?,?,?,?)')->execute([$id,$from,$to,$date,$reason,$_SESSION['user_id']]);
      $pdo->prepare('UPDATE equipment SET room_id=? WHERE id=?')->execute([$to,$id]); $pdo->commit(); flash('Перемещение сохранено в истории.');
    } elseif ($action==='delete_equipment') {
      $id=(int)$_POST['id']; $pdo->prepare('DELETE FROM movements WHERE equipment_id=?')->execute([$id]); $pdo->prepare('DELETE FROM equipment WHERE id=?')->execute([$id]); flash('Запись удалена.','success');
    } elseif ($action==='change_password') {
      if (($_POST['new_password'] ?? '')!==($_POST['confirm_password'] ?? '') || strlen($_POST['new_password'] ?? '')<8) throw new RuntimeException('Пароли должны совпадать и содержать минимум 8 символов.');
      $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($_POST['new_password'],PASSWORD_DEFAULT),$_SESSION['user_id']]); flash('Пароль изменён.');
    }
  } catch(Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($ex instanceof PDOException ? 'Не удалось сохранить данные. Проверьте, нет ли дубликата или незаполненного поля.' : $ex->getMessage(),'error'); }
  redirect($_POST['return'] ?? '?');
}

if ($page==='export') {
  $stmt=$pdo->prepare('SELECT e.item_number,e.name,e.quantity,e.inventory_number,e.status,e.note,c.name category,r.room_number,r.name room,y.name year FROM equipment e JOIN rooms r ON r.id=e.room_id JOIN categories c ON c.id=r.category_id JOIN academic_years y ON y.id=e.academic_year_id WHERE e.academic_year_id=? ORDER BY c.name,r.room_number,e.name');
  $stmt->execute([$activeYear]); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="kmk-inventory.csv"'); echo "\xEF\xBB\xBF"; $out=fopen('php://output','w'); fputcsv($out,['№','Наименование','Количество','Инвентарный номер','Статус','Примечание','Раздел','Кабинет','Помещение','Учебный год'],';'); while($row=$stmt->fetch()) fputcsv($out,$row,';'); fclose($out); exit;
}

$years=$pdo->query('SELECT * FROM academic_years ORDER BY name DESC')->fetchAll();
$categories=$pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$rooms=$pdo->query('SELECT r.*,c.name category FROM rooms r JOIN categories c ON c.id=r.category_id ORDER BY c.name,r.room_number')->fetchAll();
$flash=$_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$stats=$pdo->prepare("SELECT COUNT(*) records,COALESCE(SUM(quantity),0) units,COUNT(DISTINCT room_id) room_count FROM equipment WHERE academic_year_id=?"); $stats->execute([$activeYear]); $stats=$stats->fetch();
$roomId=(int)($_GET['room'] ?? 0); $search=trim($_GET['q'] ?? '');
$sql="SELECT e.*,r.room_number,r.name room_name,c.name category FROM equipment e JOIN rooms r ON r.id=e.room_id JOIN categories c ON c.id=r.category_id WHERE e.academic_year_id=?";
$args=[$activeYear];
if($roomId){$sql.=" AND e.room_id=?";$args[]=$roomId;}
if($search!==''){$sql.=" AND (e.name LIKE ? OR e.inventory_number LIKE ? OR e.item_number LIKE ?)";array_push($args,"%$search%","%$search%","%$search%");}
$sql.=" ORDER BY c.name,r.room_number,e.name";
$stmt=$pdo->prepare($sql);$stmt->execute($args);$equipment=$stmt->fetchAll();
$movements=$pdo->query("SELECT m.*,e.name equipment_name,e.inventory_number,rf.room_number from_number,rf.name from_name,rt.room_number to_number,rt.name to_name FROM movements m JOIN equipment e ON e.id=m.equipment_id JOIN rooms rf ON rf.id=m.from_room_id JOIN rooms rt ON rt.id=m.to_room_id ORDER BY m.moved_at DESC,m.id DESC LIMIT 30")->fetchAll();
$edit=null;if(isset($_GET['edit'])){$q=$pdo->prepare('SELECT * FROM equipment WHERE id=?');$q->execute([(int)$_GET['edit']]);$edit=$q->fetch();}
function render_login(): void { $f=$_SESSION['flash']??null;unset($_SESSION['flash']); ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Вход — КМК</title><link rel="stylesheet" href="assets/style.css"></head><body class="login-page"><main class="login-card"><div class="brand-mark">КМК</div><h1>Инвентаризация</h1><p class="muted">Система учёта имущества колледжа</p><?php if($f):?><div class="alert <?=$f['type']?>"><?=e($f['message'])?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><label>Логин<input name="username" required autofocus></label><label>Пароль<input type="password" name="password" required></label><button class="btn full">Войти в систему</button></form></main></body></html><?php }
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Инвентаризация КМК</title><link rel="stylesheet" href="assets/style.css"></head><body>
<aside class="sidebar"><div class="brand"><img class="sidebar-logo" src="assets/kmk-logo.png" alt="Логотип КМК"><div><b>КМК</b><small>Инвентаризация</small></div></div><nav><a class="<?= $page==='dashboard'?'active':''?>" href="?">▦ Обзор</a><a class="<?= $page==='rooms'?'active':''?>" href="?page=rooms">▤ Помещения</a><a href="?page=movements">↔ Перемещения</a><a href="?page=room_access">⌘ Ключи кабинетов</a><a href="?page=room_access&mode=login">⌂ Вход кабинета</a><a href="?page=settings">⚙ Настройки</a></nav><div class="side-bottom"><small>Пользователь</small><b><?=e($_SESSION['full_name'])?></b><a href="?page=logout">Выйти ↗</a></div></aside>
<main class="main"><header class="topbar"><div><span class="eyebrow">КОНАЕВСКИЙ МНОГОПРОФИЛЬНЫЙ КОЛЛЕДЖ</span><h1><?= $page==='rooms'?'Помещения':($page==='movements'?'История перемещений':($page==='settings'?'Настройки':'Панель управления'))?></h1></div><div class="top-actions"><button type="button" class="lang-toggle" onclick="toggleLanguage()" id="langToggle">ҚАЗ</button><form method="get" class="year-select"><input type="hidden" name="page" value="<?=e($page)?>"><label>Учебный год<select name="year" onchange="this.form.submit()"><?php foreach($years as $y):?><option value="<?=$y['id']?>" <?=$activeYear==$y['id']?'selected':''?>><?=e($y['name'])?></option><?php endforeach;?></select></label></form></div></header>
<?php if($flash):?><div class="alert <?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endif;?>
<?php if($page==='dashboard'||$page==='rooms'):?>
<section class="stats"><article><span>Позиций оборудования</span><strong><?=$stats['records']?></strong><small>Записей в выбранном году</small></article><article><span>Всего единиц</span><strong><?=$stats['units']?></strong><small>Суммарное количество</small></article><article><span>Помещений с имуществом</span><strong><?=$stats['room_count']?></strong><small>В выбранном году</small></article><article><span>Разделов</span><strong><?=count($categories)?></strong><small>Категории колледжа</small></article></section>
<div class="section-head"><div><h2>Оборудование</h2><p class="muted">Список имущества по выбранному учебному году</p></div><button class="btn" onclick="openModal('equipmentModal')">＋ Добавить оборудование</button></div>
<div class="toolbar"><form method="get" class="search"><input type="hidden" name="year" value="<?=$activeYear?>"><input placeholder="Поиск по названию или инвентарному номеру" name="q" value="<?=e($search)?>"><button class="btn secondary">Найти</button></form><a class="btn secondary" href="?page=export&year=<?=$activeYear?>">↓ Экспорт CSV / Excel</a></div>
<div class="table-wrap"><table><thead><tr><th>№</th><th>Оборудование</th><th>Раздел / помещение</th><th>Кол-во</th><th>Инвентарный №</th><th>Статус</th><th></th></tr></thead><tbody><?php if(!$equipment):?><tr><td colspan="7" class="empty">Записей пока нет. Добавьте первое оборудование.</td></tr><?php endif;?><?php foreach($equipment as $i=>$item):?><tr><td><?=e($item['item_number'] ?: $i+1)?></td><td><b><?=e($item['name'])?></b><small class="cell-note"><?=e($item['note'])?></small></td><td><?=e($item['category'])?><small class="cell-note">№<?=e($item['room_number'])?> · <?=e($item['room_name'])?></small></td><td><?=$item['quantity']?></td><td><?=e($item['inventory_number'] ?: '—')?></td><td><span class="status"><?=e($item['status'])?></span></td><td class="actions"><a href="?edit=<?=$item['id']?>&year=<?=$activeYear?>">Изменить</a><button class="link-button" onclick="moveItem(<?=$item['id']?>,'<?=e(addslashes($item['name']))?>')">Переместить</button><form method="post" onsubmit="return confirm('Удалить запись оборудования?')"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="delete_equipment"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="link-button danger">Удалить</button></form></td></tr><?php endforeach;?></tbody></table></div>
<?php if($edit):?><script>window.addEventListener('DOMContentLoaded',()=>openModal('equipmentModal'));</script><?php endif;?>
<div class="section-head lower"><div><h2>Помещения колледжа</h2><p class="muted">Разделы и кабинеты</p></div><button class="btn secondary" onclick="openModal('roomModal')">＋ Добавить помещение</button></div><div class="room-grid"><?php foreach($categories as $cat):?><article class="room-card"><div class="room-card-head"><b><?=e($cat['name'])?></b><span><?=count(array_filter($rooms,fn($r)=>(int)$r['category_id']===(int)$cat['id']))?></span></div><?php $found=false;foreach($rooms as $r)if((int)$r['category_id']===(int)$cat['id']){$found=true;?><a href="?room=<?=$r['id']?>&year=<?=$activeYear?>" class="room-line"><span>№<?=e($r['room_number'])?> · <?=e($r['name'])?></span><span>→</span></a><?php }if(!$found):?><p class="muted small">Пока нет помещений</p><?php endif;?></article><?php endforeach;?></div>
<?php elseif($page==='movements'):?><div class="section-head"><div><h2>Последние перемещения</h2><p class="muted">История перемещений оборудования между помещениями</p></div></div><div class="table-wrap"><table><thead><tr><th>Дата</th><th>Оборудование</th><th>Откуда</th><th>Куда</th><th>Причина / примечание</th></tr></thead><tbody><?php foreach($movements as $m):?><tr><td><?=e(date('d.m.Y',strtotime($m['moved_at'])))?></td><td><b><?=e($m['equipment_name'])?></b><small class="cell-note"><?=e($m['inventory_number'])?></small></td><td>№<?=e($m['from_number'])?> · <?=e($m['from_name'])?></td><td>№<?=e($m['to_number'])?> · <?=e($m['to_name'])?></td><td><?=e($m['reason'])?></td></tr><?php endforeach;?><?php if(!$movements):?><tr><td colspan="5" class="empty">История пока пуста.</td></tr><?php endif;?></tbody></table></div>
<?php elseif($page==='settings'):?><div class="settings-grid"><section class="panel"><h2>Учебные годы</h2><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="add_year"><input name="name" placeholder="2027–2028" required><button class="btn">Добавить год</button></form><?php foreach($years as $y):?><div class="setting-row"><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="edit_year"><input type="hidden" name="id" value="<?=$y['id']?>"><input name="name" value="<?=e($y['name'])?>" required aria-label="Название учебного года"><button class="link-button">Сохранить</button></form><span><?=$y['is_active']?'<span class="status">Активный</span>':''?><?php if(!$y['is_active']):?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="activate_year"><input type="hidden" name="id" value="<?=$y['id']?>"><button class="link-button">Сделать активным</button></form><?php endif;?></span></div><?php endforeach;?></section><section class="panel"><h2>Разделы помещений</h2><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="add_category"><input name="name" placeholder="Например, библиотека" required><button class="btn">Добавить раздел</button></form><?php foreach($categories as $c):?><div class="setting-row"><?=e($c['name'])?></div><?php endforeach;?></section><section class="panel"><h2>Изменить пароль</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="change_password"><label>Новый пароль<input type="password" name="new_password" minlength="8" required></label><label>Повторите пароль<input type="password" name="confirm_password" minlength="8" required></label><button class="btn">Сохранить пароль</button></form></section></div>
<?php endif;?>
</main>
<!-- Equipment modal -->
<div class="modal" id="equipmentModal"><div class="modal-card"><button class="close" onclick="closeModal('equipmentModal')">×</button><h2><?=$edit?'Редактировать оборудование':'Новое оборудование'?></h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="save_equipment"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><input type="hidden" name="year_id" value="<?=$activeYear?>"><label>Номер записи<input name="item_number" value="<?=e($edit['item_number']??'')?>"></label><label>Наименование оборудования<input name="name" required value="<?=e($edit['name']??'')?>"></label><label>Количество<input type="number" min="1" name="quantity" required value="<?=e($edit['quantity']??1)?>"></label><label>Инвентарный номер<input name="inventory_number" value="<?=e($edit['inventory_number']??'')?>"></label><label class="wide">Помещение<select name="room_id" required><?php foreach($rooms as $r):?><option value="<?=$r['id']?>" <?=isset($edit['room_id'])&&(int)$edit['room_id']===(int)$r['id']?'selected':''?>><?=e($r['category'].' · №'.$r['room_number'].' · '.$r['name'])?></option><?php endforeach;?></select></label><label>Состояние<select name="status"><?php foreach(['В наличии','Требует ремонта','Списано'] as $s):?><option <?=($edit['status']??'В наличии')===$s?'selected':''?>><?=e($s)?></option><?php endforeach;?></select></label><label class="wide">Примечание<textarea name="note" rows="3"><?=e($edit['note']??'')?></textarea></label><div class="wide form-actions"><button class="btn">Сохранить</button><button type="button" class="btn secondary" onclick="closeModal('equipmentModal')">Отмена</button></div></form></div></div>
<div class="modal" id="roomModal"><div class="modal-card"><button class="close" onclick="closeModal('roomModal')">×</button><h2>Добавить помещение</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="add_room"><label>Раздел<select name="category_id"><?php foreach($categories as $c):?><option value="<?=$c['id']?>"><?=e($c['name'])?></option><?php endforeach;?></select></label><label>Номер помещения<input name="room_number" required></label><label class="wide">Наименование<input name="name" placeholder="Например, Кабинет информатики" required></label><label class="wide">Примечание<textarea name="notes"></textarea></label><div class="wide form-actions"><button class="btn">Добавить</button></div></form></div></div>
<div class="modal" id="moveModal"><div class="modal-card"><button class="close" onclick="closeModal('moveModal')">×</button><h2>Перемещение оборудования</h2><p id="moveName" class="muted"></p><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="move_equipment"><input type="hidden" name="equipment_id" id="moveId"><label>Куда переместить<select name="to_room_id" required><?php foreach($rooms as $r):?><option value="<?=$r['id']?>"><?=e($r['category'].' · №'.$r['room_number'].' · '.$r['name'])?></option><?php endforeach;?></select></label><label>Дата перемещения<input type="date" name="moved_at" value="<?=date('Y-m-d')?>" required></label><label class="wide">Причина / примечание<textarea name="reason"></textarea></label><div class="wide form-actions"><button class="btn">Сохранить перемещение</button></div></form></div></div>
<script src="assets/lang.js"></script><script src="assets/app.js"></script></body></html>
