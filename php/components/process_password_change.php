<?php
session_start();
require '../db_config.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Пользователь не авторизован']));
}

$user_id = $_SESSION['user_id'];
$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';

if (empty($current_password) || empty($new_password)) {
    die(json_encode(['error' => 'Заполните все поля']));
}

try {
    // Получение пользователя по user_id
    $stmt = $pdo->prepare("SELECT * FROM User WHERE User_Id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        die(json_encode(['error' => 'Пользователь не найден']));
    }

    // Проверка текущего пароля
    if (!password_verify($current_password, $user['User_Password'])) {
        die(json_encode(['error' => 'Неверный текущий пароль']));
    }

    // Хеширование нового пароля
    $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);

    // Обновление пароля
    $update = $pdo->prepare("UPDATE User SET User_Password = :new_password WHERE User_Id = :user_id");
    $update->execute([
        'new_password' => $hashedPassword,
        'user_id' => $user_id
    ]);

    echo json_encode(['success' => 'Пароль успешно изменён']);
} catch (PDOException $e) {
    die(json_encode(['error' => 'Ошибка базы данных: ' . $e->getMessage()]));
}
