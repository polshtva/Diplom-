<?php
session_start();
require '../db_config.php';

// Получаем данные пользователя из сессии
$userName = $_SESSION['user_name'];
$userSurname = $_SESSION['user_surname'];
$userPatronymic = $_SESSION['user_patronymic'] ?? '';

// Получение инициалов
$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);

// Функция для конвертации WMV в MP4
function convertToMp4($inputPath, $outputPath)
{
    if (!file_exists($inputPath)) {
        error_log("Input file not found: $inputPath");
        return false;
    }

    $command = "ffmpeg -i \"$inputPath\" -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 128k -movflags +faststart \"$outputPath\" 2>&1";
    exec($command, $output, $returnCode);

    if ($returnCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
        return true;
    }

    error_log("FFmpeg conversion failed. Command: $command. Output: " . implode("\n", $output));
    return false;
}

// Функция для определения MIME-типа
function getVideoMimeType($filename)
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeTypes = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
        'wmv' => 'video/x-ms-wmv',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska'
    ];
    return $mimeTypes[$extension] ?? 'video/mp4';
}

// Функция для форматирования размера файла
function formatFileSize($bytes)
{
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// Проверка авторизации студента
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: ../index.php');
    exit();
}

// Проверка наличия course_id
if (!isset($_GET['course_id']) || empty($_GET['course_id'])) {
    $_SESSION['error'] = "Не указан курс";
    header('Location: student_dashboard.php');
    exit();
}

$course_id = (int)$_GET['course_id'];
$lesson_id = isset($_GET['lesson_id']) ? (int)$_GET['lesson_id'] : null;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Получаем информацию о курсе
    $course_stmt = $pdo->prepare("
        SELECT c.*, u.User_Surname, u.User_Name, u.User_Patronymic 
        FROM Course c
        JOIN Instructor i ON c.Instructor_id = i.Instructor_id
        JOIN User u ON i.User_id = u.User_id
        WHERE c.Course_id = :course_id
    ");
    $course_stmt->execute(['course_id' => $course_id]);
    $course = $course_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$course) {
        throw new Exception("Курс не найден");
    }

    // Получаем список уроков
    $lessons_stmt = $pdo->prepare("
        SELECT Lesson_id, Lesson_Number, Lesson_Name, Lesson_Status 
        FROM Lesson 
        WHERE Course_id = :course_id 
        ORDER BY Lesson_Number ASC
    ");
    $lessons_stmt->execute(['course_id' => $course_id]);
    $lessons = $lessons_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Получаем информацию о текущем уроке
    $current_lesson = null;
    $materials = [];
    $videos = [];
    $test = null;
    $questions = [];
    $answers = [];
    $test_results = null;

    if ($lesson_id) {
        // Получаем информацию об уроке
        $lesson_stmt = $pdo->prepare("
            SELECT * FROM Lesson 
            WHERE Lesson_id = :lesson_id AND Course_id = :course_id
        ");
        $lesson_stmt->execute(['lesson_id' => $lesson_id, 'course_id' => $course_id]);
        $current_lesson = $lesson_stmt->fetch(PDO::FETCH_ASSOC);

        if ($current_lesson) {
            // Получаем материалы урока
            $materials_stmt = $pdo->prepare("
                SELECT * FROM Materials 
                WHERE Lesson_id = :lesson_id
                ORDER BY Materials_Date ASC
            ");
            $materials_stmt->execute(['lesson_id' => $lesson_id]);
            $materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Получаем видео для этого урока
            $videos_stmt = $pdo->prepare("
                SELECT * FROM lesson_video 
                WHERE Lesson_id = :lesson_id
                ORDER BY Upload_Date ASC
            ");
            $videos_stmt->execute(['lesson_id' => $lesson_id]);
            $videos = $videos_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Создаем папку для кэша, если ее нет
            $cacheDir = dirname(dirname(__DIR__)) . '/uploads/videos/cache/';
            if (!file_exists($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }

            // Получаем тест для этого урока
            $test_stmt = $pdo->prepare("
                SELECT * FROM test 
                WHERE Lesson_id = :lesson_id
                LIMIT 1
            ");
            $test_stmt->execute(['lesson_id' => $lesson_id]);
            $test = $test_stmt->fetch(PDO::FETCH_ASSOC);

            if ($test) {
                // Получаем вопросы для этого теста
                $questions_stmt = $pdo->prepare("
                    SELECT * FROM Question 
                    WHERE Test_Id = :test_id
                    ORDER BY Question_id ASC
                ");
                $questions_stmt->execute(['test_id' => $test['Test_Id']]);
                $questions = $questions_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($questions)) {
                    // Получаем варианты ответов
                    $question_ids = array_column($questions, 'Question_Id');
                    $placeholders = implode(',', array_fill(0, count($question_ids), '?'));

                    $answers_stmt = $pdo->prepare("
                        SELECT * FROM VarAnswers 
                        WHERE Question_Id IN ($placeholders)
                        ORDER BY Question_Id, VarAnswers_Id ASC
                    ");
                    $answers_stmt->execute($question_ids);
                    $answers_raw = $answers_stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Группируем ответы по вопросам
                    foreach ($answers_raw as $answer) {
                        $answers[$answer['Question_Id']][] = $answer;
                    }

                    // Проверяем, проходил ли студент уже этот тест
                    $results_stmt = $pdo->prepare("
                        SELECT * FROM testresults
                        WHERE Test_Id = :test_id AND Student_Id = :student_id
                        ORDER BY DateCompleted DESC
                        LIMIT 1
                    ");
                    $results_stmt->execute([
                        'test_id' => $test['Test_Id'],
                        'student_id' => $_SESSION['user_id']
                    ]);
                    $test_results = $results_stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
        }
    }

    // Обработка отправки теста
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_test'])) {
        if (!$test || !$current_lesson) {
            throw new Exception("Тест или урок не найдены");
        }

        if ($test_results) {
            $_SESSION['message'] = "Вы уже проходили этот тест";
            header("Location: course_lessons.php?course_id=$course_id&lesson_id=$lesson_id");
            exit();
        }

        $score = 0;
        $max_score = 0;
        $correct_answers = 0;
        $total_questions = count($questions);

        foreach ($questions as $question) {
            $max_score += $question['Question_Mark'];

            if (!isset($_POST['question_' . $question['Question_Id']])) {
                continue;
            }

            $user_answer = $_POST['question_' . $question['Question_Id']];
            $correct_options = array_filter($answers[$question['Question_Id']], function ($a) {
                return $a['VarAnswers_Correct'] == 1;
            });
            $correct_option_ids = array_column($correct_options, 'VarAnswers_Id');

            if ($question['Question_Type'] === 'radio') {
                if (in_array($user_answer, $correct_option_ids)) {
                    $score += $question['Question_Mark'];
                    $correct_answers++;
                }
            } elseif ($question['Question_Type'] === 'checkbox') {
                $all_correct = true;
                foreach ($user_answer as $option_id) {
                    if (!in_array($option_id, $correct_option_ids)) {
                        $all_correct = false;
                        break;
                    }
                }

                if ($all_correct && count($user_answer) == count($correct_option_ids)) {
                    $score += $question['Question_Mark'];
                    $correct_answers++;
                }
            }
        }

        $pass_status = ($score >= $test['Test_MinMark']) ? 'Passed' : 'Failed';
        $current_test_percentage = $max_score > 0 ? round(($score / $max_score) * 100) : 0;

        $insert_stmt = $pdo->prepare("
            INSERT INTO testresults 
            (Test_Id, Student_Id, Score, MaxScore, PassStatus, DateCompleted)
            VALUES (:test_id, :student_id, :score, :max_score, :pass_status, NOW())
        ");
        $insert_stmt->execute([
            'test_id' => $test['Test_Id'],
            'student_id' => $_SESSION['user_id'],
            'score' => $score,
            'max_score' => $max_score,
            'pass_status' => $pass_status
        ]);

        $progress_stmt = $pdo->prepare("
            SELECT Progress, Tests_Completed 
            FROM course_enrollments 
            WHERE Course_Id = :course_id AND Student_Id = :student_id
            LIMIT 1
        ");
        $progress_stmt->execute([
            'course_id' => $course_id,
            'student_id' => $_SESSION['user_id']
        ]);
        $enrollment = $progress_stmt->fetch(PDO::FETCH_ASSOC);

        if ($enrollment) {
            $current_progress = (float)$enrollment['Progress'];
            $tests_completed = (int)$enrollment['Tests_Completed'] + 1;
            $new_progress = round(($current_progress * $enrollment['Tests_Completed'] + $current_test_percentage) / $tests_completed);
        } else {
            $new_progress = $current_test_percentage;
            $tests_completed = 1;
        }

        if ($enrollment) {
            $update_stmt = $pdo->prepare("
                UPDATE course_enrollments 
                SET Progress = :progress, 
                    Tests_Completed = :tests_completed,
                    Last_Updated = NOW()
                WHERE Enrollments_Id = :enrollment_id
            ");
            $update_stmt->execute([
                'progress' => $new_progress,
                'tests_completed' => $tests_completed,
                'enrollment_id' => $enrollment['Enrollments_Id']
            ]);
        } else {
            $insert_enrollment = $pdo->prepare("
                INSERT INTO course_enrollments 
                (Course_Id, Student_Id, Enrollments_date, Progress, Tests_Completed, Last_Updated) 
                VALUES (:course_id, :student_id, NOW(), :progress, 1, NOW())
            ");
            $insert_enrollment->execute([
                'course_id' => $course_id,
                'student_id' => $_SESSION['user_id'],
                'progress' => $new_progress
            ]);
        }

        $_SESSION['message'] = "Тест завершен! Результат: $score/$max_score ($pass_status). Общий прогресс: $new_progress%";
        header("Location: course_lessons.php?course_id=$course_id&lesson_id=$lesson_id");
        exit();
    }
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    $_SESSION['error'] = "Ошибка базы данных";
    header("Location: course_lessons.php?course_id=$course_id" . ($lesson_id ? "&lesson_id=$lesson_id" : ""));
    exit();
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $_SESSION['error'] = $e->getMessage();
    header("Location: course_lessons.php?course_id=$course_id" . ($lesson_id ? "&lesson_id=$lesson_id" : ""));
    exit();
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($course['Course_Name']); ?></title>
    <link rel="stylesheet" href="../../css/style_Student/course_lesson.css">
    <link rel="stylesheet" href="../../css/sections/test.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .video-container {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
            margin-bottom: 20px;
            background: #000;
            border-radius: 8px;
        }

        .video-container video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }

        .video-error {
            background: #ffebee;
            border-left: 4px solid #f44336;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }

        .video-error i {
            color: #f44336;
            margin-right: 10px;
        }

        .download-options {
            margin-top: 10px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .download-btn {
            display: inline-flex;
            align-items: center;
            background: #2196F3;
            color: white;
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            transition: background 0.3s;
        }

        .download-btn:hover {
            background: #0b7dda;
        }

        .download-btn i {
            margin-right: 8px;
        }

        .wmv-warning {
            display: inline-flex;
            align-items: center;
            color: #ff9800;
            font-size: 0.9em;
            padding: 8px 12px;
            background: #fff8e1;
            border-radius: 4px;
        }

        .wmv-warning i {
            margin-right: 8px;
        }

        .video-fallback {
            margin-top: 15px;
            padding: 15px;
            background: #fff8e1;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
        }

        .video-fallback ol {
            padding-left: 20px;
            margin-top: 10px;
        }

        .video-fallback li {
            margin-bottom: 8px;
        }

        .video-info {
            background: #f5f5f5;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .video-info div {
            margin-bottom: 5px;
        }

        .video-item {
            margin-bottom: 30px;
            border-bottom: 1px solid #eee;
            padding-bottom: 20px;
        }

        .video-item:last-child {
            border-bottom: none;
        }

        .video-item h4 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
        }

        @media (max-width: 768px) {
            .download-options {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>
    <?php include '../components/header.php'; ?>

    <button class="lesson-toggle-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i> Выбрать урок
    </button>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <div class="container">
        <aside class="sidebar">
            <h2><?php echo htmlspecialchars($course['Course_Name']); ?></h2>
            <ul class="lesson-list">
                <?php foreach ($lessons as $lesson): ?>
                    <li class="lesson-item <?php echo (isset($current_lesson) && $current_lesson['Lesson_id'] == $lesson['Lesson_id']) ? 'active' : ''; ?>">
                        <a href="course_lessons.php?course_id=<?php echo $course_id; ?>&lesson_id=<?php echo $lesson['Lesson_id']; ?>" style="display: flex; align-items: center; width: 100%; color: inherit; text-decoration: none;">
                            <span class="lesson-number"><?php echo $lesson['Lesson_Number']; ?></span>
                            <span class="lesson-name"><?php echo htmlspecialchars($lesson['Lesson_Name']); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <main class="content">
            <div class="back-button" style="margin: 0px 0 20px 0px;">
                <a href="main_page.php?user_id=<?php echo $_SESSION['user_id']; ?>" style="text-decoration: none; color: #0056d2; font-weight: bold;">
                    <i class="fas fa-arrow-left"></i> Назад к главной странице
                </a>
            </div>
            <div class="course-header">
                <h1><?php echo htmlspecialchars($course['Course_Name']); ?></h1>
                <div class="instructor-info">
                    Преподаватель: <?php echo htmlspecialchars($course['User_Surname'] . ' ' . $course['User_Name'] . ' ' . $course['User_Patronymic']); ?>
                </div>
            </div>

            <?php if (isset($current_lesson)): ?>
                <div class="lesson-content">
                    <h2 class="lesson-title">
                        <span class="lesson-title-number"><?php echo $current_lesson['Lesson_Number']; ?></span>
                        <?php echo htmlspecialchars($current_lesson['Lesson_Name']); ?>
                    </h2>

                    <?php if ($current_lesson['Lesson_Date'] || $current_lesson['Lesson_TimeStart']): ?>
                        <div class="lesson-info">
                            <?php if ($current_lesson['Lesson_date']): ?>
                                <span class="lesson-date"><?php echo date('d.m.Y', strtotime($current_lesson['Lesson_date'])); ?></span>
                            <?php endif; ?>
                            <?php if ($current_lesson['Lesson_TimeStart']): ?>
                                <span class="lesson-time"><?php echo date('H:i', strtotime($current_lesson['Lesson_TimeStart'])); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($current_lesson['Lesson_Desc']): ?>
                        <div class="lesson-description">
                            <?php echo nl2br(htmlspecialchars($current_lesson['Lesson_Desc'])); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Секция видео -->
                    <?php if (!empty($videos)): ?>
                        <div class="videos-section">
                            <h3 class="videos-title">Видеоуроки</h3>

                            <?php foreach ($videos as $video): ?>
                                <?php
                                $videoPath = '../../' . $video['Video_Path'] . $video['Video_Content'];
                                $videoExt = strtolower(pathinfo($video['Video_Content'], PATHINFO_EXTENSION));
                                $mimeType = getVideoMimeType($video['Video_Content']);
                                $cacheDir = '../../uploads/videos/cache/';
                                $cachedVideoPath = $cacheDir . pathinfo($video['Video_Content'], PATHINFO_FILENAME) . '.mp4';

                                $needsConversion = ($videoExt === 'wmv' || $videoExt === 'avi') && !file_exists($cachedVideoPath);

                                if ($needsConversion) {
                                    if (convertToMp4($videoPath, $cachedVideoPath)) {
                                        $updateStmt = $pdo->prepare("UPDATE lesson_video SET Video_Content = ? WHERE video_id = ?");
                                        $updateStmt->execute([basename($cachedVideoPath), $video['video_id']]);
                                        $videoPath = $cachedVideoPath;
                                        $mimeType = 'video/mp4';
                                    } else {
                                        echo '<div class="video-error"><i class="fas fa-exclamation-triangle"></i> Не удалось конвертировать видео. Пожалуйста, скачайте видео для просмотра.</div>';
                                    }
                                }
                                ?>

                                <div class="video-item">
                                    <h4><?php echo htmlspecialchars($video['Video_Name']); ?></h4>

                                    <div class="video-info">
                                        <div>Тип: <?php echo strtoupper($videoExt); ?></div>
                                        <div>Размер: <?php echo formatFileSize($video['Video_Size']); ?></div>
                                        <?php if ($videoExt === 'wmv'): ?>
                                            <div class="wmv-warning"><i class="fas fa-exclamation-triangle"></i> Формат WMV может не поддерживаться в некоторых браузерах</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="video-container">
                                        <?php if (in_array($videoExt, ['mp4', 'webm', 'ogg'])): ?>
                                            <video controls controlsList="nodownload" style="width: 100%;">
                                                <source src="<?php echo htmlspecialchars($videoPath); ?>" type="<?php echo $mimeType; ?>">
                                                Ваш браузер не поддерживает видео тег.
                                            </video>
                                        <?php elseif (file_exists($cachedVideoPath)): ?>
                                            <video controls controlsList="nodownload" style="width: 100%;">
                                                <source src="<?php echo htmlspecialchars($cachedVideoPath); ?>" type="video/mp4">
                                                Ваш браузер не поддерживает видео тег.
                                            </video>
                                        <?php else: ?>
                                            <div class="video-fallback">
                                                <p>Это видео не может быть воспроизведено напрямую в браузере. Пожалуйста:</p>
                                                <ol>
                                                    <li>Скачайте видео с помощью кнопки ниже</li>
                                                    <li>Откройте его с помощью медиаплеера на вашем устройстве</li>
                                                    <li>Или установите <a href="https://www.videolan.org/vlc/" target="_blank">VLC Player</a> для просмотра</li>
                                                </ol>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="download-options">
                                        <a href="<?php echo htmlspecialchars($videoPath); ?>" class="download-btn" download>
                                            <i class="fas fa-download"></i> Скачать оригинал (<?php echo strtoupper($videoExt); ?>)
                                        </a>

                                        <?php if ($videoExt !== 'mp4' && file_exists($cachedVideoPath)): ?>
                                            <a href="<?php echo htmlspecialchars($cachedVideoPath); ?>" class="download-btn" download>
                                                <i class="fas fa-download"></i> Скачать MP4 версию
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-content">Видеоуроки для этого урока отсутствуют.</p>
                    <?php endif; ?>

                    <!-- Секция материалов -->
                    <?php if (!empty($materials)): ?>
                        <div class="materials-section">
                            <h3 class="materials-title">Материалы урока</h3>
                            <?php foreach ($materials as $material): ?>
                                <div class="material-item">
                                    <div>
                                        <div class="material-name"><?php echo htmlspecialchars($material['Materials_Name']); ?></div>
                                        <div class="material-type"><?php echo htmlspecialchars($material['Materials_Type']); ?></div>
                                    </div>
                                    <a href="<?php echo htmlspecialchars($material['Materials_Content']); ?>" target="_blank" class="download-btn" download>
                                        <i class="fas fa-download"></i> Скачать
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-content">Материалы к уроку отсутствуют.</p>
                    <?php endif; ?>

                    <!-- Секция теста -->
                    <div class="test-section">
                        <?php if ($test): ?>
                            <div class="test-header">
                                <h1 class="test-title"><?php echo htmlspecialchars($test['Test_Name']); ?></h1>
                                <div class="test-info">
                                    <span><strong>Вопросов:</strong> <?php echo count($questions); ?></span>
                                    <span><strong>Минимальный балл:</strong> <?php echo htmlspecialchars($test['Test_MinMark']); ?></span>
                                </div>
                                <?php if ($test['Test_Desc']): ?>
                                    <div class="test-description">
                                        <?php echo nl2br(htmlspecialchars($test['Test_Desc'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($test_results): ?>
                                <div class="test-results">
                                    <h2 class="results-title">Ваши результаты теста</h2>
                                    <div class="results-score">
                                        Баллы: <?php echo $test_results['Score']; ?>/<?php echo $test_results['MaxScore']; ?>
                                    </div>
                                    <div>
                                        <span class="results-status status-<?php echo strtolower($test_results['PassStatus']); ?>">
                                            <?php echo $test_results['PassStatus'] === 'Passed' ? 'Молодец, ты сдал отлично!' : 'Повтори материал!'; ?>
                                        </span>
                                    </div>
                                    <div>
                                        Дата прохождения: <?php echo date('d.m.Y H:i', strtotime($test_results['DateCompleted'])); ?>
                                    </div>
                                    <h2 style="margin: 20px 0;">Подробные результаты:</h2>
                                    <div class="questions-list">
                                        <?php foreach ($questions as $question): ?>
                                            <div class="question-item">
                                                <div class="question-text">
                                                    <?php echo htmlspecialchars($question['Question_Text']); ?>
                                                    <span>(<?php echo $question['Question_Mark']; ?> баллов)</span>
                                                </div>

                                                <?php
                                                $answers_stmt = $pdo->prepare("
                                                    SELECT * FROM VarAnswers 
                                                    WHERE Question_Id = :question_id
                                                    ORDER BY VarAnswers_Id ASC
                                                ");
                                                $answers_stmt->execute(['question_id' => $question['Question_Id']]);
                                                $answers = $answers_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                ?>

                                                <ul class="answers-list">
                                                    <?php foreach ($answers as $answer): ?>
                                                        <li class="answer-item <?php echo $answer['VarAnswers_Correct'] ? 'correct' : ''; ?>">
                                                            <?php echo htmlspecialchars($answer['VarAnswers_Text']) . " "; ?>
                                                            <?php if ($answer['VarAnswers_Correct']): ?>
                                                                <span style="color: #28a745; font-weight:bold"> (правильный ответ)</span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <form method="POST" action="course_lessons.php?course_id=<?php echo $course_id; ?>&lesson_id=<?php echo $lesson_id; ?>">
                                    <?php foreach ($questions as $question): ?>
                                        <div class="question-item">
                                            <div class="question-text">
                                                <?php echo htmlspecialchars($question['Question_Text']); ?>
                                            </div>
                                            <div class="question-meta">
                                                Тип: <?php echo $question['Question_Type'] === 'radio' ? 'Один ответ' : 'Несколько ответов'; ?> |
                                                Баллы: <?php echo $question['Question_Mark']; ?>
                                            </div>

                                            <ul class="answers-list" style="margin: 20PX;">
                                                <?php foreach ($answers[$question['Question_Id']] as $answer): ?>
                                                    <li class="answer-item">
                                                        <label>
                                                            <?php if ($question['Question_Type'] === 'radio'): ?>
                                                                <input type="radio"
                                                                    name="question_<?php echo $question['Question_Id']; ?>"
                                                                    value="<?php echo $answer['VarAnswers_Id']; ?>" required>
                                                            <?php else: ?>
                                                                <input type="checkbox"
                                                                    name="question_<?php echo $question['Question_Id']; ?>[]"
                                                                    value="<?php echo $answer['VarAnswers_Id']; ?>">
                                                            <?php endif; ?>
                                                            <span><?php echo htmlspecialchars($answer['VarAnswers_Text']); ?></span>
                                                        </label>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endforeach; ?>

                                    <input type="hidden" name="submit_test" value="1">
                                    <button type="submit" class="test-submit-btn">Отправить тест</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="no-test">
                                <?php if ($current_lesson): ?>
                                    <p>Для этого урока нет доступного теста.</p>
                                <?php else: ?>
                                    <p>Выберите урок для просмотра теста.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="no-content">
                    <p>Выберите урок из списка слева, чтобы просмотреть его содержимое.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }

        // Запрет скачивания через контекстное меню
        document.addEventListener('DOMContentLoaded', function() {
            const videos = document.querySelectorAll('video');
            videos.forEach(video => {
                video.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                });

                // Предотвращение открытия в новом окне
                video.addEventListener('play', function() {
                    if (document.pictureInPictureElement === video) {
                        document.exitPictureInPicture();
                    }
                });
            });
        });
    </script>
</body>

</html>