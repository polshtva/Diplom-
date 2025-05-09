<?php
// Включаем отображение всех ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Запускаем сессию
session_start();

// Подключаем конфигурацию базы данных
require '../db_config.php';

// Проверка, что пользователь авторизован
if (!isset($_SESSION['user_name']) || !isset($_SESSION['user_surname'])) {
    header('Location: login.php');
    exit();
}
// Получаем данные пользователя из сессии
$userName = $_SESSION['user_name'];
$userSurname = $_SESSION['user_surname'];
$userPatronymic = $_SESSION['user_patronymic'] ?? ''; // Используем оператор null coalescing для безопасного доступа

// Получение инициалов
$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);
// Получаем ID курса из GET-параметра
$courseId = $_GET['course_id'] ?? null;

if (!$courseId) {
    die("Курс не выбран.");
}

// Запрос для получения информации о курсе
$courseQuery = "SELECT Course_Name, Course_StartData, Course_EndData, Instructor_Id FROM Course WHERE Course_Id = ?";
$stmt = $pdo->prepare($courseQuery);
$stmt->execute([$courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Курс не найден.");
}

// Запрос для получения информации о преподавателе
$instructorQuery = "SELECT User_Id FROM Instructor WHERE Instructor_Id = ?";
$stmt = $pdo->prepare($instructorQuery);
$stmt->execute([$course['Instructor_Id']]);
$instructor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$instructor) {
    die("Преподаватель не найден.");
}

// Запрос для получения информации о пользователе (преподавателе)
$userQuery = "SELECT user_name, user_surname, user_patronymic FROM User WHERE User_Id = ?";
$stmt = $pdo->prepare($userQuery);
$stmt->execute([$instructor['User_Id']]);
$instructorInfo = $stmt->fetch(PDO::FETCH_ASSOC);

// Запрос для получения списка студентов, записанных на курс
$studentsQuery = "
    SELECT s.Student_Id, u.user_name, u.user_surname, u.user_patronymic, u.User_PhoneNumber, u.User_Email
    FROM Course_Enrollments e
    JOIN Student s ON e.Student_id = s.Student_Id
    JOIN User u ON s.User_id = u.User_Id
    WHERE e.Course_id = ?
";
$stmt = $pdo->prepare($studentsQuery);
$stmt->execute([$courseId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Информация о курсе</title>
    <link rel="stylesheet" href="../../css/style_Instructor/info_course.css">
</head>

<body>
    <?php include '../components/header.php'; ?>
    <div class="wrapper">


        <main>
            <div class="container">
                <a href="main_page.php" class="back-button">
                    &#8592; Назад
                </a>

                <h1 style="text-align: center; margin-top:50px">Информация о курсе: <span style="color: #186AF7;"><?php echo htmlspecialchars($course['Course_Name'] ?? ''); ?></span></h1>
                <div class="date">
                    <p class="info-date">Дата начала: <?php echo htmlspecialchars($course['Course_StartData'] ?? ''); ?></p>
                    <p class="info-date">Дата завершения: <?php echo htmlspecialchars($course['Course_EndData'] ?? ''); ?></p>
                </div>

                <button class="btns btns-lesson" onclick="goPage('create_lesson.php?course_id=<?php echo htmlspecialchars($courseId); ?>')">
                    Создать урок
                </button>
                <button class="btns btns-history" onclick="goPage('history_lesson.php?course_id=<?php echo htmlspecialchars($courseId); ?>')">Посмотреть историю</button>

                <h2>Преподаватель:</h2>
                <p><?php echo htmlspecialchars(($instructorInfo['user_surname'] ?? '') . ' ' . ($instructorInfo['user_name'] ?? '') . ' ' . ($instructorInfo['user_patronymic'] ?? '')); ?></p>

                <h2>Студенты:</h2>
                <?php if ($students): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Фамилия</th>
                                <th>Имя</th>
                                <th>Отчество</th>
                                <th>Телефон</th>
                                <th>Почта</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['user_surname'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($student['user_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($student['user_patronymic'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($student['User_PhoneNumber'] ?? 'Нет данных'); ?></td>
                                    <td><?php echo htmlspecialchars($student['User_Email'] ?? 'Нет данных'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>На этот курс пока никто не записался.</p>
                <?php endif; ?>
            </div>
        </main>
    </div>


    <script>
        function goPage(page) {
            window.location.href = page;
        }
    </script>
</body>

</html>