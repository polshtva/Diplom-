<?php
session_start();
require '../db_config.php';

// Проверка авторизации
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: ../index.php');
    exit();
}


// Получаем данные пользователя из сессии
$userName = $_SESSION['user_name'];
$userSurname = $_SESSION['user_surname'];
$userPatronymic = $_SESSION['user_patronymic'] ?? '';

// Получение инициалов
$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Получаем данные студента
    $student_id = $pdo->prepare("SELECT Student_Id FROM Student WHERE User_Id = :user_id");
    $student_id->execute(['user_id' => $_SESSION['user_id']]);
    $student = $student_id->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new Exception("Студент не найден");
    }

    // Получаем курсы студента
    $stmt = $pdo->prepare("
        SELECT c.Course_id, c.Course_Name, c.Course_Hours, c.Course_StartData, c.Course_EndData, 
               c.Instructor_id, u.User_Surname, u.User_Name, u.User_Patronymic
        FROM course_enrollments e
        JOIN Course c ON e.Course_Id = c.Course_id
        JOIN Instructor i ON c.Instructor_id = i.Instructor_id
        JOIN User u ON i.User_id = u.User_id
        WHERE e.Student_Id = :student_id
    ");
    $stmt->execute(['student_id' => $student['Student_Id']]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Определяем приветствие по времени суток
    $hour = date('H');
    if ($hour >= 5 && $hour < 12) {
        $greeting = "Доброе утро";
    } elseif ($hour >= 12 && $hour < 18) {
        $greeting = "Добрый день";
    } else {
        $greeting = "Добрый вечер";
    }
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
} catch (Exception $e) {
    die($e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Главная страница студента</title>
    <link rel="stylesheet" href="../../css/style_Student/main_page.css">
</head>

<body>

    <?php
    include '../components/header.php';
    ?>
    <div class="container">
        <div class="user-header">
            <h1><?php echo $greeting . ', ' . htmlspecialchars($_SESSION['user_name']) . ' ' . htmlspecialchars($_SESSION['user_surname']); ?>!</h1>
            <p>Ваши текущие курсы</p>
        </div>

        <?php if (empty($courses)): ?>
            <p>Вы пока не записаны ни на один курс.</p>
        <?php else: ?>
            <?php foreach ($courses as $course): ?>
                <div class="course-card">
                    <h2 class="course-title"><?php echo htmlspecialchars($course['Course_Name']); ?></h2>
                    <p><strong>Преподаватель:</strong> <?php echo htmlspecialchars($course['User_Surname'] . ' ' . $course['User_Name'] . ' ' . $course['User_Patronymic']); ?></p>
                    <p><strong>Даты курса:</strong> <?php echo date('d.m.Y', strtotime($course['Course_StartData'])) . ' - ' . date('d.m.Y', strtotime($course['Course_EndData'])); ?></p>
                    <p><strong>Часов:</strong> <?php echo $course['Course_Hours']; ?></p>

                    <a href="course_lessons.php?course_id=<?php echo $course['Course_id']; ?>" class="btn">Посмотреть уроки</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>

</html>