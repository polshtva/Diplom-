<?php
require '../db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен");
}

$materialId = $_GET['material_id'] ?? null;
$lessonId = $_GET['lesson_id'] ?? null;

if (!$materialId || !$lessonId) {
    die("Неверные параметры запроса");
}

// Получаем информацию о материале для удаления файла
$query = "SELECT Materials_Content FROM Materials WHERE Materials_id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$materialId]);
$material = $stmt->fetch(PDO::FETCH_ASSOC);

if ($material) {
    // Удаляем файл материала
    $filePath = '../' . $material['Materials_Content'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // Удаляем запись из БД
    $deleteQuery = "DELETE FROM Materials WHERE Materials_id = ?";
    $deleteStmt = $pdo->prepare($deleteQuery);
    $deleteStmt->execute([$materialId]);
}

header("Location: lesson_details.php?lesson_id=$lessonId");
exit;
