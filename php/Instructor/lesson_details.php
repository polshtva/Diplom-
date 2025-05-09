<?php
require '../db_config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен");
}
                                // Получение данных пользователя
                                $userName = $_SESSION['user_name'] ?? '';
                                $userSurname = $_SESSION['user_surname'] ?? '';
                                $initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);

// Handle video deletion if video_id is present in GET parameters
if (isset($_GET['video_id']) && isset($_GET['lesson_id'])) {
    $videoId = $_GET['video_id'];
    $lessonId = $_GET['lesson_id'];

    // Получаем имя файла и путь
    $query = "SELECT Video_Content, Video_Path FROM lesson_video WHERE Video_Id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$videoId]);
    $video = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($video) {
        // Собираем путь к файлу
        $filePath = '../' . $video['Video_Content']; // добавляем ../ так как путь относительно корня

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
    header("Location: lesson__details.php?lesson_id=$lessonId");
    exit;
}

$lessonId = $_GET['lesson_id'] ?? null;
if (!$lessonId) {
    die("Ошибка: Урок не найден.");
}

// Получаем данные урока
$query = "SELECT * FROM Lesson WHERE Lesson_Id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$lessonId]);
$lesson = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lesson) {
    die("Ошибка: Урок не найден.");
}

// Обработка обновления данных урока
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_lesson'])) {
        $lessonName = $_POST['lesson_name'];
        $lessonDesc = $_POST['lesson_desc'];
        $lessonDate = $_POST['lesson_date'];
        $lessonTime = $_POST['lesson_time'];
        $lessonType = $_POST['lesson_type'];
        $lessonStatus = $_POST['lesson_status'];
        $lessonNumber = $_POST['lesson_number'];

        $updateQuery = "UPDATE Lesson SET 
                        Lesson_Name = ?, 
                        Lesson_Desc = ?, 
                        Lesson_date = ?, 
                        Lesson_TimeStart = ?, 
                        Lesson_Type = ?, 
                        Lesson_Status = ?, 
                        Lesson_Number = ? 
                        WHERE Lesson_Id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([
            $lessonName,
            $lessonDesc,
            $lessonDate,
            $lessonTime,
            $lessonType,
            $lessonStatus,
            $lessonNumber,
            $lessonId
        ]);

        // Обработка загрузки новых видео
        if (!empty($_FILES['new_videos']['name'][0])) {
            foreach ($_FILES['new_videos']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['new_videos']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = basename($_FILES['new_videos']['name'][$key]);
                    $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
                    $newFileName = 'video_' . uniqid() . '.' . $fileExt;
                    $uploadPath = '../uploads/videos/' . $newFileName;

                    if (move_uploaded_file($tmpName, $uploadPath)) {
                        $insertQuery = "INSERT INTO Video (Lesson_id, Video_Content, Upload_Date, Video_Path) 
                                       VALUES (?, ?, NOW(), ?)";
                        $insertStmt = $pdo->prepare($insertQuery);
                        $insertStmt->execute([
                            $lessonId,
                            $uploadPath,
                            'uploads/videos/' . $newFileName
                        ]);
                    }
                }
            }
        }

        // Обработка загрузки новых материалов
        if (!empty($_FILES['new_materials']['name'][0])) {
            foreach ($_FILES['new_materials']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['new_materials']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = basename($_FILES['new_materials']['name'][$key]);
                    $fileSize = $_FILES['new_materials']['size'][$key];
                    $fileType = $_FILES['new_materials']['type'][$key];
                    $newFileName = 'material_' . uniqid() . '_' . $fileName;
                    $uploadPath = '../uploads/materials/' . $newFileName;

                    if (move_uploaded_file($tmpName, $uploadPath)) {
                        $insertQuery = "INSERT INTO Materials (Lesson_id, Materials_Name, Materials_Type, Materials_Content, Materials_Size, Materials_Date) 
                                       VALUES (?, ?, ?, ?, ?, NOW())";
                        $insertStmt = $pdo->prepare($insertQuery);
                        $insertStmt->execute([
                            $lessonId,
                            $fileName,
                            $fileType,
                            'uploads/materials/' . $newFileName,
                            $fileSize
                        ]);
                    }
                }
            }
        }

        header("Location: lesson_details.php?lesson_id=$lessonId");
        exit;
    } elseif (isset($_POST['delete_lesson'])) {
        $deleteQuery = "DELETE FROM Lesson WHERE Lesson_Id = ?";
        $deleteStmt = $pdo->prepare($deleteQuery);
        if ($deleteStmt->execute([$lessonId])) {
            header("Location: history_lesson.php?course_id=" . $lesson['Course_Id']);
            exit;
        } else {
            echo "Ошибка при удалении урока.";
        }
    }
}

// Получаем видео для урока
$videoQuery = "SELECT * FROM lesson_Video WHERE Lesson_id = ?";
$videoStmt = $pdo->prepare($videoQuery);
$videoStmt->execute([$lessonId]);
$videos = $videoStmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем материалы для урока
$materialQuery = "SELECT * FROM Materials WHERE Lesson_id = ?";
$materialStmt = $pdo->prepare($materialQuery);
$materialStmt->execute([$lessonId]);
$materials = $materialStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование урока</title>
    <link rel="stylesheet" href="../../css/style_Instructor/lesson_details.css">
    
</head>

<body>
    <?php include '../components/header.php'; ?>
    <div class="container">
        <h2>Редактирование урока: <?php echo htmlspecialchars($lesson['Lesson_Name']); ?></h2>

        <form action="" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="lesson_id" value="<?php echo $lesson['Lesson_Id']; ?>">

            <div class="form-group">
                <label for="lesson_name">Название урока</label>
                <input type="text" id="lesson_name" name="lesson_name" value="<?php echo htmlspecialchars($lesson['Lesson_Name']); ?>" required>
            </div>

            <div class="form-group">
                <label for="lesson_desc">Описание урока</label>
                <textarea id="lesson_desc" name="lesson_desc"><?php echo htmlspecialchars($lesson['Lesson_Desc']); ?></textarea>
            </div>

            <div class="form-group">
                <label for="lesson_date">Дата урока</label>
                <input type="date" id="lesson_date" name="lesson_date" value="<?php echo date('Y-m-d', strtotime($lesson['Lesson_date'])); ?>" required>
            </div>

            <div class="form-group">
                <label for="lesson_time">Время начала</label>
                <input type="time" id="lesson_time" name="lesson_time" value="<?php echo date('H:i', strtotime($lesson['Lesson_TimeStart'])); ?>" required>
            </div>

            <div class="form-group">
                <label for="lesson_type">Тип урока</label>
                <select id="lesson_type" name="lesson_type" required>
                    <option value="lecture" <?php echo $lesson['Lesson_Type'] == 'lecture' ? 'selected' : ''; ?>>Лекция</option>
                    <option value="practice" <?php echo $lesson['Lesson_Type'] == 'practice' ? 'selected' : ''; ?>>Практика</option>
                    <option value="seminar" <?php echo $lesson['Lesson_Type'] == 'seminar' ? 'selected' : ''; ?>>Семинар</option>
                </select>
            </div>

            <div class="form-group">
                <label for="lesson_status">Статус урока</label>
                <select id="lesson_status" name="lesson_status" required>
                    <option value="active" <?php echo $lesson['Lesson_Status'] == 'active' ? 'selected' : ''; ?>>Активный</option>
                    <option value="inactive" <?php echo $lesson['Lesson_Status'] == 'inactive' ? 'selected' : ''; ?>>Неактивный</option>
                    <option value="completed" <?php echo $lesson['Lesson_Status'] == 'completed' ? 'selected' : ''; ?>>Завершен</option>
                </select>
            </div>

            <div class="form-group">
                <label for="lesson_number">Номер урока в курсе</label>
                <input type="number" id="lesson_number" name="lesson_number" value="<?php echo $lesson['Lesson_Number']; ?>" min="1" required>
            </div>

            <div class="file-section">
                <h3>Видео материалы</h3>
                <div class="file-list">
                    <?php if (!empty($videos)): ?>
                        <?php foreach ($videos as $video): ?>
                            <div class="file-item">
                                <a href="<?php echo $video['Video_Path']; ?>" target="_blank">
                                    Видео <?php echo basename($video['Video_Path']); ?>
                                </a>
                                <button type="button" class="remove-btn" onclick="deleteVideo(<?php echo $video['Video_id']; ?>)">Удалить</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Нет загруженных видео</p>
                    <?php endif; ?>
                </div>
                <label>Добавить новые видео:</label>
                <input type="file" name="new_videos[]" accept="video/*" multiple>
            </div>

            <div class="file-section">
                <h3>Дополнительные материалы</h3>
                <div class="file-list">
                    <?php if (!empty($materials)): ?>
                        <?php foreach ($materials as $material): ?>
                            <div class="file-item">
                                <a href="../<?php echo $material['Materials_Content']; ?>" target="_blank">
                                    <?php echo htmlspecialchars($material['Materials_Name']); ?> (<?php echo round($material['Materials_Size'] / 1024); ?> KB)
                                </a>
                                <button type="button" class="remove-btn" onclick="deleteMaterial(<?php echo $material['Materials_id']; ?>)">Удалить</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Нет загруженных материалов</p>
                    <?php endif; ?>
                </div>
                <label>Добавить новые материалы:</label>
                <input type="file" name="new_materials[]" multiple>
                <button type="button" class="btn btn-add" onclick="addMaterialField()">+ Добавить еще поле</button>
                <div id="additional-materials"></div>
            </div>

            <div class="buttons">
                <button type="submit" name="update_lesson" class="btn btn-update">Обновить данные</button>
                <button type="button" class="btn btn-delete" onclick="confirmDelete()">Удалить урок</button>
            </div>
        </form>
    </div>

    <script>
        function addMaterialField() {
            const container = document.getElementById('additional-materials');
            const div = document.createElement('div');
            div.className = 'form-group';
            div.innerHTML = '<input type="file" name="new_materials[]">';
            container.appendChild(div);
        }

        function deleteVideo(videoId) {
            if (confirm('Вы уверены, что хотите удалить это видео?')) {
                window.location.href = 'edit_lesson.php?video_id=' + videoId + '&lesson_id=<?php echo $lessonId; ?>';
            }
        }

        function deleteMaterial(materialId) {
            if (confirm('Вы уверены, что хотите удалить этот материал?')) {
                window.location.href = 'delete_material.php?material_id=' + materialId + '&lesson_id=<?php echo $lessonId; ?>';
            }
        }

        function confirmDelete() {
            if (confirm('Вы уверены, что хотите удалить этот урок? Это действие нельзя отменить.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const input1 = document.createElement('input');
                input1.type = 'hidden';
                input1.name = 'delete_lesson';
                input1.value = '1';

                const input2 = document.createElement('input');
                input2.type = 'hidden';
                input2.name = 'lesson_id';
                input2.value = '<?php echo $lessonId; ?>';

                form.appendChild(input1);
                form.appendChild(input2);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>

</html>