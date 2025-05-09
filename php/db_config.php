<?php
$host = 'localhost'; // Адрес сервера базы данных
$db = 'edDelfa'; // Имя базы данных
$user = 'root'; // Имя пользователя базы данных
$pass = 'shtva@2025!SecBd'; // Пароль пользователя базы данных


try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}


if (!file_exists(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// 2. Защищаем папку logs от доступа извне
if (!file_exists(__DIR__ . '/logs/.htaccess')) {
    file_put_contents(
        __DIR__ . '/logs/.htaccess',
        "Deny from all\n<FilesMatch \"\.(log|txt)$\">\n    Order allow,deny\n    Deny from all\n</FilesMatch>"
    );
}

// 3. Настройки отображения ошибок
ini_set('display_errors', 0); // Не показывать ошибки пользователям
ini_set('log_errors', 1);     // Сохранять ошибки в лог
ini_set('error_log', __DIR__ . '/logs/php-errors.log'); // Путь к лог-файлу
error_reporting(E_ALL);       // Ловить ВСЕ ошибки

// 4. Функция для удобного логирования
function logMessage($message, $level = 'INFO', $file = 'app.log')
{
    $logFile = __DIR__ . '/logs/' . $file;
    $time = date('Y-m-d H:i:s');
    $logMessage = "[$time] [$level] $message" . PHP_EOL;

    // Ротация логов (если файл больше 10 МБ)
    if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
        rename($logFile, __DIR__ . '/logs/' . date('Y-m-d_His') . '_' . $file);
    }

    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// 5. Логируем запуск скрипта
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        logMessage("Фатальная ошибка: {$error['message']} в файле {$error['file']} на строке {$error['line']}", 'ERROR');
    }
});

logMessage('====== Запуск приложения ======', 'INFO');
