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
$userPatronymic = $_SESSION['user_patronymic'] ?? '';

// Получение инициалов
$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);

try {
    // Получение всех курсов
    $sql = "SELECT c.Course_Id, c.Course_Name, c.Course_StartData, c.Course_EndData, 
                   IFNULL(COUNT(ce.Student_Id), 0) AS Student_Count
            FROM course c
            LEFT JOIN Course_Enrollments ce ON c.Course_Id = ce.Course_Id
            GROUP BY c.Course_Id"; // Группируем только по Course_Id
    $stmt = $pdo->query($sql);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Обработка ошибок базы данных
    die("Ошибка при получении данных: " . $e->getMessage());
}
?>


<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Окно Администратора</title>
    <link rel="stylesheet" href="../../css/style_Admin/main.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

</head>

<body>
    <div class="wrapper">
        <?php
        include '../components/header.php';
        ?>

        <main>
            <div class="container">
                <div class="info">
                    <div class="title">Добро пожаловать, <span><?php echo htmlspecialchars($userName) . " " . htmlspecialchars($userPatronymic); ?></span>!</div>
                    <div class="info__content">
                        <div class="btn-block">
                            <button class="btn-dec" onclick="createTeacher()">Создать уч. Преподавателя</button>
                            <button class="btn-dec" onclick="createStudent()">Создать уч. Ученика</button>
                            <button class="btn-dec" onclick="createAdmin()">Создать уч. Админа</button>
                        </div>
                        <div class="info__courses">
                            <input type="text" class="info__input" placeholder="Введите название курса">
                            <button class="btn__course" onclick="createData()">Создать курс</button>
                        </div>
                    </div>
                </div>

                <div class="courses">
                    <?php if (!empty($courses)): ?>
                        <?php foreach ($courses as $row): ?>
                            <div class='course-card'>
                                <div class='course-card__title'><?php echo htmlspecialchars($row['Course_Name'] ?? ''); ?></div>
                                <p>Количество учеников: <?php echo htmlspecialchars($row['Student_Count'] ?? '0'); ?></p>
                                <p>Дата начала: <?php echo htmlspecialchars($row['Course_StartData'] ?? 'Не запущен'); ?></p>
                                <p>Дата завершения: <?php echo htmlspecialchars($row['Course_EndData'] ?? 'Не запущен'); ?></p>
                                <!-- Формируем ссылку с course_id -->
                                <?php if (!empty($row['Course_Id'])): ?>
                                    <a href='manage_course.php?course_id=<?php echo htmlspecialchars($row['Course_Id']); ?>'>
                                        <button class='course-card__btn'>Управлять курсом</button>
                                    </a>
                                <?php else: ?>
                                    <button class='course-card__btn' disabled>Управлять курсом (ID отсутствует)</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Курсов пока нет.</p>
                    <?php endif; ?>
                </div>
        </main>
    </div>

    <?php
    include '../components/model.php';
    ?>

    <script src="../../js/JSAdmin/script.js"></script>
    <script>
        //отображение карточек с курсами по input в main_page
        const inputField = document.querySelector(".info__input"); // Поле ввода
        const courseCards = document.querySelectorAll(".course-card"); // Все карточки курсов

        inputField.addEventListener("input", function() {
            const query = inputField.value.toLowerCase(); // Текст, который введен в поле ввода

            courseCards.forEach(function(card) {
                const courseTitle = card
                    .querySelector(".course-card__title")
                    .textContent.toLowerCase(); // Название курса

                if (courseTitle.includes(query)) {
                    card.style.display = ""; // Показываем карточку, если название курса содержит запрос
                } else {
                    card.style.display = "none"; // Скрываем карточку, если название курса не соответствует запросу
                }
            });
        });
    </script>
</body>

</html>