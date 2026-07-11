<?php

$configPath = __DIR__ . '/config.local.php';

if (!file_exists($configPath)) {
    die('Не найден config.local.php. Скопируйте config.local.example.php и заполните настройки подключения.');
}

$config = require $configPath;

$DB_HOST = $config['db_host'] ?? 'localhost';
$DB_NAME = $config['db_name'] ?? '';
$DB_USER = $config['db_user'] ?? '';
$DB_PASS = $config['db_pass'] ?? '';
$DB_CHARSET = $config['db_charset'] ?? 'utf8mb4';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    die('Ошибка подключения к базе данных. Проверьте настройки config.local.php.');
}
