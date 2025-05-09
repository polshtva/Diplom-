<?php
require 'db_config.php';

header('Content-Type: application/json');
session_start();

if (empty($_SESSION['password_recovery_user_id']) || empty($_SESSION['password_recovery_verified'])) {
    echo json_encode(['success' => false, 'message' => 'Сессия истекла или не пройдена верификация']);
    exit;
}

$newPassword = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// Проверка пароля
if (empty($newPassword) || strlen($newPassword) < 8) {
    echo json_encode(['success' => false, 'message' => 'Пароль должен содержать минимум 8 символов']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Пароли не совпадают']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    // Обновляем пароль
    $stmt = $pdo->prepare("UPDATE User SET User_Password = ? WHERE User_Id = ?");
    $stmt->execute([$hashedPassword, $_SESSION['password_recovery_user_id']]);

    // Удаляем использованные коды восстановления
    $stmt = $pdo->prepare("DELETE FROM PasswordRecovery WHERE user_id = ?");
    $stmt->execute([$_SESSION['password_recovery_user_id']]);

    // Очищаем сессию
    unset($_SESSION['password_recovery_user_id']);
    unset($_SESSION['password_recovery_verified']);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Ошибка при обновлении пароля: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка при обновлении пароля']);
}
