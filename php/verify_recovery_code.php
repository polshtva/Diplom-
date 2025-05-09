<?php
require 'db_config.php';

header('Content-Type: application/json');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $code = $_POST['code'] ?? '';

    if (empty($code)) {
        echo json_encode(['success' => false, 'message' => 'Код не указан']);
        exit;
    }

    // Ищем неиспользованный и неистекший код
    $stmt = $pdo->prepare("SELECT * FROM PasswordRecovery 
                          WHERE expires_at > NOW() AND is_used = FALSE");
    $stmt->execute();

    $found = false;
    $userId = null;

    while ($row = $stmt->fetch()) {
        if (password_verify($code, $row['recovery_code'])) {
            $found = true;
            $userId = $row['user_id'];
            break;
        }
    }

    if ($found) {
        // Помечаем код как использованный
        $stmt = $pdo->prepare("UPDATE PasswordRecovery SET is_used = TRUE 
                              WHERE user_id = ?");
        $stmt->execute([$userId]);

        // Сохраняем user_id в сессии для следующего шага
        session_start();
        $_SESSION['password_recovery_user_id'] = $userId;
        $_SESSION['password_recovery_verified'] = true;

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Неверный или просроченный код']);
    }
} catch (PDOException $e) {
    error_log("Ошибка базы данных: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка базы данных']);
}
