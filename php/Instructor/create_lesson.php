<?php
ini_set('memory_limit', '1G');
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require '../db_config.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Получение данных пользователя
$userName = $_SESSION['user_name'] ?? '';
$userSurname = $_SESSION['user_surname'] ?? '';
$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);
$userId = $_SESSION['user_id'];

try {
    // Получаем Instructor_Id по User_Id
    $stmt = $pdo->prepare("SELECT Instructor_Id FROM Instructor WHERE User_Id = ?");
    $stmt->execute([$userId]);
    $instructor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$instructor) {
        die("Ошибка: Ваш аккаунт не имеет прав инструктора. Обратитесь к администратору.");
    }

    $instructorId = $instructor['Instructor_Id'];
} catch (PDOException $e) {
    die("Ошибка при получении данных инструктора: " . $e->getMessage());
}

// Получение course_id
if (isset($_GET['course_id'])) {
    $_SESSION['course_id'] = $_GET['course_id'];
}
$courseId = $_SESSION['course_id'] ?? null;

if (!$courseId) {
    die("Ошибка: Курс не выбран.");
}

// Получаем название курса
$query = "SELECT Course_Name FROM Course WHERE Course_Id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);
$courseName = $course ? htmlspecialchars($course['Course_Name']) : "Курс не найден";

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Валидация данных
        $requiredFields = ['lesson_number', 'lesson_title', 'lesson_start'];
        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Пожалуйста, заполните все обязательные поля.");
            }
        }

        // Начинаем транзакцию
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        // Подготовка данных урока
        $lessonNumber = (int)$_POST['lesson_number'];
        $lessonTitle = htmlspecialchars($_POST['lesson_title']);
        $lessonType = htmlspecialchars($_POST['lesson_type'] ?? '');
        $lessonDesc = htmlspecialchars($_POST['lesson_desc'] ?? '');

        // Обработка даты и времени
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $_POST['lesson_start'])) {
            throw new Exception("Неверный формат даты и времени.");
        }

        list($lessonDate, $lessonTime) = explode('T', $_POST['lesson_start']);

        // Вставка урока
        $query = "INSERT INTO Lesson (Course_id, Instructor_Id, Lesson_Name, Lesson_Desc, Lesson_date, Lesson_TimeStart, Lesson_Type, Lesson_Status, Lesson_Number) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $courseId,
            $instructorId,
            $lessonTitle,
            $lessonDesc,
            $lessonDate,
            $lessonTime,
            $lessonType,
            'active',
            $lessonNumber
        ]);

        $lessonId = $pdo->lastInsertId();
        $uploadedVideoPaths = [];
        $uploadedMaterialPaths = [];

        // Обработка видео (до 3 видео)
        if (!empty($_FILES['lesson_videos']['name'][0])) {
            $videoFiles = $_FILES['lesson_videos'];
            $videoCount = count($videoFiles['name']);

            if ($videoCount > 3) {
                throw new Exception("Можно загрузить максимум 3 видео.");
            }

            $allowedVideoTypes = [
                'video/mp4' => 'mp4',
                'video/quicktime' => 'mov',
                'video/x-msvideo' => 'avi',
                'video/x-ms-wmv' => 'wmv',
                'video/x-matroska' => 'mkv',
                'video/webm' => 'webm'
            ];

            // Проверяем существование таблицы lesson_video
            $tableExists = $pdo->query("SHOW TABLES LIKE 'lesson_video'")->rowCount() > 0;
            if (!$tableExists) {
                throw new Exception("Таблица для хранения видео не существует в базе данных.");
            }

            // Создаем папку для видео, если ее нет
            $videoUploadDir = '../../uploads/videos/';
            if (!file_exists($videoUploadDir)) {
                if (!mkdir($videoUploadDir, 0777, true)) {
                    throw new Exception("Не удалось создать директорию для хранения видео.");
                }
            }

            for ($i = 0; $i < $videoCount; $i++) {
                if ($videoFiles['error'][$i] !== UPLOAD_ERR_OK) {
                    throw new Exception("Ошибка загрузки видео: " . getUploadError($videoFiles['error'][$i]));
                }

                // Проверка типа файла
                $fileType = $videoFiles['type'][$i];
                if (!isset($allowedVideoTypes[$fileType])) {
                    throw new Exception("Недопустимый формат видео: " . $videoFiles['name'][$i] .
                        ". Разрешены: MP4, MOV, AVI, WMV, MKV, WebM.");
                }

                // Проверка размера (макс. 5GB)
                if ($videoFiles['size'][$i] > 5 * 1024 * 1024 * 1024) {
                    throw new Exception("Видео " . $videoFiles['name'][$i] . " слишком большое (макс. 5GB).");
                }

                // Генерируем уникальное имя файла
                $originalName = basename($videoFiles['name'][$i]);
                $fileExt = $allowedVideoTypes[$fileType];
                $fileName = uniqid('video_') . '.' . $fileExt;
                $filePath = $videoUploadDir . $fileName;

                // Перемещаем загруженный файл
                if (!move_uploaded_file($videoFiles['tmp_name'][$i], $filePath)) {
                    throw new Exception("Не удалось сохранить видео " . $originalName);
                }

                $uploadedVideoPaths[] = $filePath;

                // Сохраняем информацию о видео
                $relativePath = 'uploads/videos/';
                $query = "INSERT INTO lesson_video 
                      (Lesson_id, Video_Name, Video_Content, Video_Path, Video_Size, Video_Type, Upload_Date) 
                      VALUES (?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $pdo->prepare($query);
                $stmt->execute([
                    $lessonId,
                    $originalName,
                    $fileName,
                    $relativePath,
                    $videoFiles['size'][$i],
                    $fileType
                ]);
            }
        }

        // Обработка материалов (до 10 файлов)
        if (!empty($_FILES['material_files']['name'][0])) {
            $allowedMaterialTypes = [
                'application/pdf' => 'application/pdf',
                'application/msword' => 'application/doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'application/docx',
                'application/vnd.ms-powerpoint' => 'application/ppt',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'application/pptx',
                'application/zip' => 'application/zip',
                'application/x-rar-compressed' => 'application/rar',
                'text/plain' => 'text/plain',
                'image/jpeg' => 'image/jpeg',
                'image/png' => 'image/png'
            ];

            $materialCount = count($_FILES['material_files']['name']);

            if ($materialCount > 10) {
                throw new Exception("Можно загрузить максимум 10 материалов.");
            }

            // Проверяем существование таблицы Materials
            $tableExists = $pdo->query("SHOW TABLES LIKE 'Materials'")->rowCount() > 0;
            if (!$tableExists) {
                throw new Exception("Таблица для хранения материалов не существует в базе данных.");
            }

            // Создаем папку для материалов, если ее нет
            $materialsUploadDir = '../../uploads/materials/';
            if (!file_exists($materialsUploadDir)) {
                if (!mkdir($materialsUploadDir, 0777, true)) {
                    throw new Exception("Не удалось создать директорию для хранения материалов.");
                }
            }

            for ($i = 0; $i < $materialCount; $i++) {
                if ($_FILES['material_files']['error'][$i] !== UPLOAD_ERR_OK) {
                    throw new Exception("Ошибка загрузки материала: " . getUploadError($_FILES['material_files']['error'][$i]));
                }

                $materialName = basename($_FILES['material_files']['name'][$i]);
                $fullType = $_FILES['material_files']['type'][$i];
                $materialSize = $_FILES['material_files']['size'][$i];

                if (!isset($allowedMaterialTypes[$fullType])) {
                    throw new Exception("Файл " . $materialName . " имеет недопустимый формат.");
                }

                if ($materialSize > 20 * 1024 * 1024) {
                    throw new Exception("Файл " . $materialName . " слишком большой (макс. 20MB).");
                }

                // Генерируем уникальное имя файла
                $fileExt = pathinfo($materialName, PATHINFO_EXTENSION);
                $fileName = uniqid('material_') . '.' . $fileExt;
                $filePath = $materialsUploadDir . $fileName;

                // Перемещаем загруженный файл
                if (!move_uploaded_file($_FILES['material_files']['tmp_name'][$i], $filePath)) {
                    throw new Exception("Не удалось сохранить материал " . $materialName);
                }

                $uploadedMaterialPaths[] = $filePath;
                $materialDate = date('Y-m-d H:i:s');

                // Сохраняем информацию о материале в базе данных
                $query = "INSERT INTO Materials (Lesson_id, Materials_Name, Materials_Type, Materials_Content, Materials_Size, Materials_Date) 
                          VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($query);
                $stmt->execute([
                    $lessonId,
                    $materialName,
                    $allowedMaterialTypes[$fullType],
                    $filePath,
                    $materialSize,
                    $materialDate
                ]);
            }
        }

        // Коммитим транзакцию
        $pdo->commit();

        $_SESSION['success_message'] = "Урок успешно создан!";
        header("Location: create_lesson.php?course_id=$courseId");
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Удаляем загруженные файлы, если транзакция не удалась
        foreach ($uploadedVideoPaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        foreach ($uploadedMaterialPaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        $_SESSION['error_message'] = "Ошибка: " . $e->getMessage();
        header("Location: create_lesson.php?course_id=$courseId");
        exit();
    }
}

function getUploadError($errorCode)
{
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер, разрешенный сервером.',
        UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер, указанный в форме.',
        UPLOAD_ERR_PARTIAL => 'Файл был загружен только частично.',
        UPLOAD_ERR_NO_FILE => 'Файл не был загружен.',
        UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка.',
        UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск.',
        UPLOAD_ERR_EXTENSION => 'Загрузка файла остановлена расширением PHP.'
    ];

    return $errors[$errorCode] ?? 'Неизвестная ошибка загрузки.';
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Создание урока</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="../../css/style_Instructor/create_lesson.css">

</head>

<body>
    <?php include '../components/header.php'; ?>
    <div class="container">

        <h1 class="course-title">Создание урока для курса: <?php echo htmlspecialchars($courseName); ?></h1>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="message success"><?php echo $_SESSION['success_message'];
                                            unset($_SESSION['success_message']); ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="message error"><?php echo $_SESSION['error_message'];
                                        unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class=""><a style="margin-top: 20px;" href="info_course.php?course_id=<?php echo $courseId; ?>" class="back-button">Назад к курсу</a></div>
            <label for="lesson_number">Номер урока:</label>
            <input type="number" name="lesson_number" id="lesson_number" required min="1">

            <label for="lesson_title">Название урока:</label>
            <input type="text" name="lesson_title" id="lesson_title" required>

            <label for="lesson_start">Дата и время начала:</label>
            <input type="datetime-local" name="lesson_start" id="lesson_start" required>

            <label for="lesson_type">Тип урока:</label>
            <select name="lesson_type" id="lesson_type" required>
                <option value="lecture">Лекция</option>
                <option value="practice">Практическое занятие</option>
                <option value="seminar">Семинар</option>
                <option value="consultation">Консультация</option>
            </select>

            <label for="lesson_desc">Описание:</label>
            <textarea name="lesson_desc" id="lesson_desc"></textarea>

            <div class="video-upload-container">
                <label>Видео материалы:</label>
                <div id="video-uploads">
                    <div class="file-input-wrapper">
                        <input type="file" name="lesson_videos[]" accept="video/*">
                        <button type="button" class="remove-btn" onclick="removeFileInput(this)">Удалить</button>
                    </div>
                </div>
                <button type="button" class="add-video-btn" id="add-video-btn">Добавить видео</button>
                <div class="file-info">Максимум 3 видео. Допустимые форматы: MP4, MOV, AVI, WMV, MKV, WebM. Максимальный размер: 5GB.</div>
            </div>

            <div class="material-upload-container">
                <label>Дополнительные материалы:</label>
                <div id="material-uploads">
                    <div class="file-input-wrapper">
                        <input type="file" name="material_files[]" multiple accept="application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/zip,application/x-rar-compressed,text/plain,image/jpeg,image/png">
                        <button type="button" class="remove-btn" onclick="removeFileInput(this)">Удалить</button>
                    </div>
                </div>
                <button type="button" class="add-material-btn" id="add-material-btn">Добавить материалы</button>
                <div class="file-info">Максимум 10 файлов. Допустимые форматы: PDF, DOC, DOCX, PPT, PPTX, ZIP, RAR, TXT, JPG, PNG. Максимальный размер файла: 20MB.</div>
            </div>

            <button type="submit" class="btn-submit">Создать урок</button>
        </form>
    </div>

    <script>
        // Проверка формата времени
        function validateDateTime() {
            const dateTimeInput = document.getElementById('lesson_start');
            const lessonStart = dateTimeInput.value;

            if (!lessonStart) {
                alert('Пожалуйста, выберите дату и время.');
                return false;
            }

            return true;
        }

        // Валидация номера урока
        function validateLessonNumber() {
            const lessonNumberInput = document.getElementById('lesson_number');
            const lessonNumber = lessonNumberInput.value;

            if (!lessonNumber || lessonNumber <= 0) {
                alert('Номер урока должен быть положительным числом.');
                return false;
            }

            return true;
        }

        // Проверка файлов
        function validateFiles() {
            const videoInputs = document.querySelectorAll('input[name="lesson_videos[]"]');
            const filesInputs = document.querySelectorAll('input[name="material_files[]"]');

            const allowedVideoTypes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-ms-wmv', 'video/x-matroska', 'video/webm'];
            const allowedMaterialTypes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/zip',
                'application/x-rar-compressed',
                'text/plain',
                'image/jpeg',
                'image/png'
            ];

            // Проверка видео
            let videoCount = 0;
            videoInputs.forEach(input => {
                if (input.files.length > 0) {
                    videoCount++;
                    const videoFile = input.files[0];

                    if (!allowedVideoTypes.includes(videoFile.type)) {
                        alert('Неверный формат видео. Разрешены: MP4, MOV, AVI, WMV, MKV, WebM.');
                        return false;
                    }

                    if (videoFile.size > 5 * 1024 * 1024 * 1024) {
                        alert('Размер видео не должен превышать 5GB.');
                        return false;
                    }
                }
            });

            if (videoCount > 3) {
                alert('Можно загрузить максимум 3 видео.');
                return false;
            }

            // Проверка материалов
            let materialCount = 0;
            filesInputs.forEach(input => {
                if (input.files.length > 0) {
                    materialCount += input.files.length;

                    for (let i = 0; i < input.files.length; i++) {
                        const file = input.files[i];
                        if (!allowedMaterialTypes.includes(file.type)) {
                            alert('Файл ' + file.name + ' имеет недопустимый формат.');
                            return false;
                        }
                        if (file.size > 20 * 1024 * 1024) {
                            alert('Файл ' + file.name + ' слишком большой (макс. 20MB).');
                            return false;
                        }
                    }
                }
            });

            if (materialCount > 10) {
                alert('Можно загрузить максимум 10 материалов.');
                return false;
            }

            return true;
        }

        // Функция для отправки формы с валидацией
        function validateForm(event) {
            if (!validateLessonNumber() || !validateDateTime() || !validateFiles()) {
                event.preventDefault();
            }
        }

        // Добавление нового поля для видео
        document.getElementById('add-video-btn').addEventListener('click', function() {
            const videoUploads = document.getElementById('video-uploads');
            const videoInputs = videoUploads.querySelectorAll('input[name="lesson_videos[]"]');

            if (videoInputs.length >= 3) {
                alert('Можно добавить максимум 3 видео.');
                return;
            }

            const newInput = document.createElement('div');
            newInput.className = 'file-input-wrapper';
            newInput.innerHTML = `
                <input type="file" name="lesson_videos[]" accept="video/*">
                <button type="button" class="remove-btn" onclick="removeFileInput(this)">Удалить</button>
            `;
            videoUploads.appendChild(newInput);
        });

        // Добавление нового поля для материалов
        document.getElementById('add-material-btn').addEventListener('click', function() {
            const materialUploads = document.getElementById('material-uploads');
            const materialInputs = materialUploads.querySelectorAll('input[name="material_files[]"]');

            if (materialInputs.length >= 10) {
                alert('Можно добавить максимум 10 полей для материалов.');
                return;
            }

            const newInput = document.createElement('div');
            newInput.className = 'file-input-wrapper';
            newInput.innerHTML = `
                <input type="file" name="material_files[]" multiple accept="application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/zip,application/x-rar-compressed,text/plain,image/jpeg,image/png">
                <button type="button" class="remove-btn" onclick="removeFileInput(this)">Удалить</button>
            `;
            materialUploads.appendChild(newInput);
        });

        // Удаление поля ввода файла
        function removeFileInput(button) {
            const wrapper = button.closest('.file-input-wrapper');
            const container = wrapper.parentElement;

            // Не позволяем удалить последнее поле
            if (container.querySelectorAll('.file-input-wrapper').length > 1) {
                wrapper.remove();
            } else {
                alert('Должно остаться хотя бы одно поле для загрузки.');
            }
        }

        // Прикрепляем обработчик к форме
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            form.addEventListener('submit', validateForm);
        });
    </script>
</body>

</html>