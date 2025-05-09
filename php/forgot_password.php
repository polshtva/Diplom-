<?php
require 'db_config.php';
require '../vendor/autoload.php';

header('Content-Type: application/json');

// ================== УЛУЧШЕННАЯ НАСТРОЙКА ЛОГИРОВАНИЯ ==================
// Создаем папку для логов с проверкой прав доступа
if (!file_exists(__DIR__ . '/logs')) {
    if (!mkdir(__DIR__ . '/logs', 0755, true)) {
        die(json_encode(['success' => false, 'message' => 'Не удалось создать папку для логов']));
    }

    // Создаем файл защиты .htaccess
    file_put_contents(__DIR__ . '/logs/.htaccess', "Deny from all\n<FilesMatch \"\.(log|txt)$\">\n    Order allow,deny\n    Deny from all\n</FilesMatch>");
}

// Настройки логирования с обработкой ошибок
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php-errors.log');
error_reporting(E_ALL);

// Улучшенная функция логирования с ротацией логов
function writeLog($message, $level = 'INFO')
{
    $logFile = __DIR__ . '/logs/app.log';

    // Ротация логов (если файл больше 5MB)
    if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
        rename($logFile, __DIR__ . '/logs/app_' . date('Y-m-d_His') . '.log');
    }

    $timestamp = date('Y-m-d H:i:s.v'); // Добавляем миллисекунды
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    $caller = $backtrace[0]['file'] . ':' . $backtrace[0]['line'];

    $logMessage = "[$timestamp] [$level] [$caller] $message" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// ================== ПРОВЕРЕННЫЕ НАСТРОЙКИ SMTP ==================
define('SMTP_HOST', 'smtp.yandex.ru');
define('SMTP_PORT', 465);
define('SMTP_USERNAME', 'it-platform@yandex.ru');
define('SMTP_PASSWORD', 'frzplfwgcgmriwtw');
define('MAIL_FROM', 'it-platform@yandex.ru');
define('MAIL_FROM_NAME', 'IT-PLATFORM');

try {
    writeLog("Начало обработки запроса на восстановление пароля");

    // Подключение к БД с таймаутом
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5 // Таймаут 5 секунд
    ]);

    $email = $_POST['email'] ?? '';
    writeLog("Получен email: " . (empty($email) ? 'не указан' : $email));

    // Валидация email
    if (empty($email)) {
        writeLog("Не указан email", 'WARNING');
        echo json_encode(['success' => false, 'message' => 'Укажите email']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        writeLog("Некорректный формат email: $email", 'WARNING');
        echo json_encode(['success' => false, 'message' => 'Укажите корректный email']);
        exit;
    }

    // Проверяем существование пользователя
    $stmt = $pdo->prepare("SELECT User_id FROM User WHERE User_Email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        writeLog("Пользователь с email $email не найден", 'NOTICE');
        echo json_encode(['success' => false, 'message' => 'Пользователь с таким email не найден']);
        exit;
    }

    // Генерируем 6-значный код
    // Устанавливаем часовой пояс для Екатеринбурга
    date_default_timezone_set('Asia/Yekaterinburg');

    // Генерируем 6-значный код
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Устанавливаем время истечения (текущее время + 1 час)
    $expiresAt = (new DateTime('now'))
        ->add(new DateInterval('PT1H'))
        ->format('Y-m-d H:i:s');

    writeLog("Сгенерирован код для пользователя ID: {$user['User_id']}. Действителен до: $expiresAt (Екатеринбург)");

    // Удаляем старые коды для этого пользователя
    $stmt = $pdo->prepare("DELETE FROM PasswordRecovery WHERE user_id = ?");
    $stmt->execute([$user['User_id']]);

    // Сохраняем новый код с хешированием
    $stmt = $pdo->prepare("INSERT INTO PasswordRecovery (user_id, recovery_code, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([
        $user['User_id'],
        password_hash($code, PASSWORD_DEFAULT),
        $expiresAt
    ]);

    // Настройка и отправка письма
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $smtpDebug = '';

    try {
        writeLog("Начало отправки письма на $email");

        // Настройки сервера
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function ($str) use (&$smtpDebug) {
            $smtpDebug .= $str . "\n";
        };

        // Отправитель и получатель
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($email);

        // Тема и содержимое письма
        $mail->isHTML(true);
        $mail->Subject = 'Код восстановления пароля';
        $mail->Body = sprintf(
            '<h2>Восстановление пароля</h2>
            <p>Ваш код подтверждения: <strong>%s</strong></p>
            <p>Код действителен до: %s</p>
            <p>Если вы не запрашивали восстановление пароля, проигнорируйте это письмо.</p>',
            $code,
            date('d.m.Y H:i', strtotime($expiresAt))
        );
        $mail->AltBody = sprintf(
            "Ваш код восстановления: %s\nКод действителен до: %s",
            $code,
            date('d.m.Y H:i', strtotime($expiresAt))
        );

        $mail->send();
        writeLog("Письмо успешно отправлено на $email");
        writeLog("SMTP Debug:\n" . trim($smtpDebug), 'DEBUG');

        echo json_encode([
            'success' => true,
            'message' => 'Письмо с кодом отправлено на вашу почту'
        ]);
    } catch (Exception $e) {
        $errorMsg = "Ошибка отправки: " . $e->getMessage() . "\nSMTP Debug:\n" . trim($smtpDebug);
        writeLog($errorMsg, 'ERROR');

        echo json_encode([
            'success' => false,
            'message' => 'Не удалось отправить письмо с кодом',
            // 'debug' => $errorMsg // Для отладки
        ]);
    }
} catch (PDOException $e) {
    writeLog("Ошибка БД: " . $e->getMessage(), 'CRITICAL');
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка сервера. Попробуйте позже'
    ]);
} catch (Exception $e) {
    writeLog("Неожиданная ошибка: " . $e->getMessage(), 'CRITICAL');
    echo json_encode([
        'success' => false,
        'message' => 'Произошла непредвиденная ошибка'
    ]);
}
