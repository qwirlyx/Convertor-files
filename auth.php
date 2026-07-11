<?php
session_start();
require_once __DIR__ . '/db.php';

// === SSO БЛОК ===
$appConfig = file_exists(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];
define('SSO_SECRET', $appConfig['sso_secret'] ?? 'change_me');

$sso_allowed_users = $appConfig['sso_allowed_users'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['sso_sign'])) {
    $sso_user    = $_POST['sso_user'] ?? '';
    $sso_time    = $_POST['sso_time'] ?? '0';
    $sso_service = $_POST['sso_service'] ?? '';
    $sso_sign    = $_POST['sso_sign'];

    if (abs(time() - (int)($sso_time / 1000)) > 60) {
        die('Ошибка SSO: Срок действия токена перехода истек.');
    }

    $dataToSign = $sso_user . '|' . $sso_time . '|' . $sso_service;
    $expectedSign = hash_hmac('sha256', $dataToSign, SSO_SECRET);

    if (hash_equals($expectedSign, $sso_sign)) {
        if (isset($sso_allowed_users[$sso_user])) {
            $_SESSION['role'] = $sso_allowed_users[$sso_user];
            session_regenerate_id(true);
            header('Location: index.php');
            exit;
        } else {
            $error = 'Доступ в данный сервис через корпоративное зеркало запрещён для сотрудника: ' . htmlspecialchars($sso_user);
        }
    } else {
        die('Ошибка SSO: Недействительная или подделанная подпись.');
    }
}
// === КОНЕЦ SSO БЛОКА ===

if (isset($_SESSION['role'])) {
    header('Location: index.php');
    exit;
}

$error = $error ?? '';

$stmt = $pdo->query('SELECT COUNT(*) FROM users');
if ((int)$stmt->fetchColumn() === 0) {
    $insert = $pdo->prepare('INSERT INTO users (login, password, role) VALUES (?, ?, ?)');
    $insert->execute(['admin', password_hash('admin', PASSWORD_DEFAULT), 'admin']);
    $insert->execute(['user', password_hash('user', PASSWORD_DEFAULT), 'viewer']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['sso_sign'])) {
    $login = trim($_POST['login'] ?? '');
    $pass  = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM users WHERE login = ?');
    $stmt->execute([$login]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password'])) {
        $_SESSION['role'] = $user['role'];
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    } else {
        $error = 'Неверный логин или пароль';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Авторизация</title>
    <link rel="shortcut icon" href="icon.ico?v=2" type="image/x-icon">
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-page">
    <div class="page-glow page-glow-left"></div>
    <div class="page-glow page-glow-right"></div>

    <div class="auth-layout">
        <section class="auth-card">
            <h2>Вход в систему</h2>

            <?php if ($error): ?>
                <div class="auth-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <div class="auth-field">
                    <label class="auth-label" for="login">Логин</label>
                    <input class="auth-input" type="text" id="login" name="login" placeholder="Введите логин" required autofocus>
                </div>

                <div class="auth-field">
                    <label class="auth-label" for="password">Пароль</label>
                    <input class="auth-input" type="password" id="password" name="password" placeholder="Введите пароль" required>
                </div>

                <button class="auth-btn" type="submit">Войти</button>
            </form>

        </section>
    </div>
</body>
</html>