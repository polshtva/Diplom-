<?php
require '../db_config.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Не указан ID пользователя']);
    exit;
}

$userId = $_GET['id'];

try {
    // Получаем основную информацию о пользователе
    $userStmt = $pdo->prepare("SELECT * FROM User WHERE User_Id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['error' => 'Пользователь не найден']);
        exit;
    }

    // Дополнительная информация для студентов
    if ($user['Role_Id'] == 3) {
        $studentStmt = $pdo->prepare("SELECT * FROM Student WHERE User_Id = ?");
        $studentStmt->execute([$userId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

        if ($student) {
            $user['Student_Id'] = $student['Student_Id'];
            $user['Student_Birthday'] = $student['Student_Birthday'];
        }
    }

    echo json_encode($user);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Ошибка базы данных: ' . $e->getMessage()]);
}
