<?php
require '../db_config.php';

if (!isset($_GET['video_id']) || !isset($_GET['filename'])) {
    die(json_encode(['success' => false, 'error' => 'Не указаны параметры']));
}

$video_id = $_GET['video_id'];
$filename = $_GET['filename'];
$input_path = "uploads/videos/" . $filename;
$output_path = "uploads/videos/" . pathinfo($filename, PATHINFO_FILENAME) . ".mp4";

// Команда для конвертации с помощью FFmpeg
$command = "ffmpeg -i $input_path -c:v libx264 -c:a aac -strict experimental $output_path 2>&1";

exec($command, $output, $return_var);

if ($return_var === 0) {
    // Обновляем запись в базе данных
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
        $stmt = $pdo->prepare("UPDATE lesson_video SET Video_Content = :new_name, Video_Type = 'video/mp4' WHERE Video_id = :video_id");
        $stmt->execute([
            'new_name' => pathinfo($filename, PATHINFO_FILENAME) . ".mp4",
            'video_id' => $video_id
        ]);

        // Удаляем старый файл WMV
        unlink($input_path);

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Ошибка базы данных: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Ошибка конвертации: ' . implode("\n", $output)]);
}
