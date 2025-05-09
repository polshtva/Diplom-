<?php
session_start();
require '../db_config.php';

// Настройка логов
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__) . '/logs/download_errors.log');

// Проверка авторизации
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('HTTP/1.0 403 Forbidden');
    exit("Доступ запрещен. Пожалуйста, авторизуйтесь.");
}

if (!isset($_GET['file']) || empty($_GET['file'])) {
    header('HTTP/1.0 400 Bad Request');
    exit("Не указан файл для скачивания.");
}

// Обработка пути к файлу
$requestedFile = urldecode($_GET['file']);
$requestedFile = ltrim($requestedFile, '/'); // Удаляем начальный слеш
$filePath = dirname(dirname(__DIR__)) . '/' . $requestedFile;

// Проверка существования файла
if (!file_exists($filePath)) {
    error_log("File not found: " . $filePath);
    header('HTTP/1.0 404 Not Found');
    exit("Файл не найден: " . htmlspecialchars($requestedFile));
}

if (!is_file($filePath)) {
    error_log("Not a file: " . $filePath);
    header('HTTP/1.0 400 Bad Request');
    exit("Указанный путь не является файлом");
}

// Проверка прав доступа
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $fileName = basename($filePath);
    $stmt = $pdo->prepare("SELECT Lesson_id FROM lesson_video WHERE Video_Content = :filename");
    $stmt->execute(['filename' => $fileName]);
    $video = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$video) {
        header('HTTP/1.0 403 Forbidden');
        exit("Доступ к файлу запрещен.");
    }

    // Проверка доступа к уроку
    $stmt = $pdo->prepare("
        SELECT 1 FROM course_enrollments 
        WHERE Student_Id = :student_id 
        AND Course_Id IN (SELECT Course_id FROM Lesson WHERE Lesson_id = :lesson_id)
    ");
    $stmt->execute([
        'student_id' => $_SESSION['user_id'],
        'lesson_id' => $video['Lesson_id']
    ]);

    if (!$stmt->fetch()) {
        header('HTTP/1.0 403 Forbidden');
        exit("У вас нет доступа к этому уроку.");
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    header('HTTP/1.0 500 Internal Server Error');
    exit("Ошибка сервера. Пожалуйста, попробуйте позже.");
}

// Установка заголовков
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$fileSize = filesize($filePath);

if ($extension === 'wmv') {
    header('Content-Type: video/x-ms-wmv');
} else {
    header('Content-Type: application/octet-stream');
}

header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
header('Content-Length: ' . $fileSize);
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

// Отправка файла
if (ob_get_level()) {
    ob_end_clean();
}

readfile($filePath);
exit;
