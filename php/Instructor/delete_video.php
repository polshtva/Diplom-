<?php
require '../db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен");
}

$videoId = $_GET['video_id'] ?? null;
$lessonId = $_GET['lesson_id'] ?? null;

if (!$videoId || !$lessonId) {
    die("Недостаточно данных для удаления.");
}

// Получаем имя файла и путь
$query = "SELECT Video_Content, Video_Path FROM lesson_video WHERE Video_Id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$videoId]);
$video = $stmt->fetch(PDO::FETCH_ASSOC);

if ($video) {
    // Собираем путь к файлу
    $filePath =  $video['Video_Content']; // потому что путь уже включает uploads/videos/...

    // Удаляем файл, если существует
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // Удаляем запись из таблицы lesson_video
    $deleteQuery = "DELETE FROM lesson_video WHERE Video_Id = ?";
    $deleteStmt = $pdo->prepare($deleteQuery);
    $deleteStmt->execute([$videoId]);
}

// Возврат на страницу редактирования урока
header("Location: edit_lesson.php?lesson_id=$lessonId");
exit;
