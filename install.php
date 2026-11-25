<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
$message='';
try {
  // Create database if it does not exist, then import schema.
  $server = new PDO('mysql:host='.DB_HOST.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $server->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $sql=file_get_contents(__DIR__.'/schema.sql');
  $sql=preg_replace('/^CREATE DATABASE.*?;\\s*/mi','',$sql);
  $sql=preg_replace('/^USE .*?;\\s*/mi','',$sql);
  foreach (array_filter(array_map('trim', explode(';',$sql))) as $statement) $server->exec($statement);
  $pdo=db();
  try { $pdo->exec("ALTER TABLE rooms ADD COLUMN access_key_hash VARCHAR(255) NULL"); } catch(Throwable $ignore) {}
  try { $pdo->exec("ALTER TABLE rooms ADD COLUMN access_key_updated_at TIMESTAMP NULL"); } catch(Throwable $ignore) {}
  $count=(int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
  if (!$count) {
    $stmt=$pdo->prepare("INSERT INTO users(username,password_hash,full_name,role) VALUES(?,?,?,'admin')");
    $stmt->execute(['admin',password_hash('Kmk2026!',PASSWORD_DEFAULT),'Администратор КМК']);
  }
  $message='Установка завершена. База данных создана, начальные разделы добавлены.';
} catch(Throwable $ex) { $message='Ошибка установки: '.$ex->getMessage(); }
?>
<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка КМК</title><link rel="stylesheet" href="assets/style.css"><body class="install"><main class="login-card"><div class="brand-mark">КМК</div><h1>Установка системы</h1><p><?=e($message)?></p><p><b>Вход по умолчанию:</b><br>Логин: admin<br>Пароль: Kmk2026!</p><a class="btn" href="index.php?page=login">Перейти ко входу</a><p class="muted small">После первого входа измените пароль. Удалите install.php после успешной установки.</p></main></body></html>
