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

// Получаем ID пользователя из сессии
$userId = $_SESSION['user_id'];


// Подготовка запроса для получения Instructor_Id по User_Id
$instructorIdQuery = "SELECT Instructor_Id FROM Instructor WHERE User_Id = ?";
$stmt = $pdo->prepare($instructorIdQuery);
$stmt->execute([$userId]);
$instructor = $stmt->fetch(PDO::FETCH_ASSOC);

// Проверка, найден ли Instructor_Id
if (!$instructor) {
    $instructorId = null;
    $errorMessage = "Преподаватель не найден.";
} else {
    $instructorId = $instructor['Instructor_Id'];

    // Получаем поисковый запрос из GET-параметра (если он есть)
    $searchQuery = $_GET['search'] ?? '';

    // Запрос для получения курсов для данного преподавателя с учетом поиска
    $sql = "SELECT Course_Id, Course_Name, Course_StartData, Course_EndData
     FROM Course WHERE Instructor_Id = ?";
    if (!empty($searchQuery)) {
        $sql .= " AND Course_Name LIKE :search"; // Используем именованный параметр для безопасности
    }
    $stmt = $pdo->prepare($sql);
    $params = [$instructorId]; // Начинаем с instructorId
    if (!empty($searchQuery)) {
        $params[':search'] = '%' . $searchQuery . '%'; // Добавляем параметр поиска
    }

    $stmt->execute($params);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Окно Преподавателя</title>
    <link rel="stylesheet" href="../../css/style_Admin/main.css">
    <link rel="stylesheet" href="../../css/style_Instructor/main_page.css">


</head>

<body>
    <div class="wrapper">
        <?php
        include '../components/header.php';
        ?>

        <main>
            <div class="container">
                <div class="info">
                    <div class="title">Добро пожаловать, <span><?php echo htmlspecialchars($userName) . " " . htmlspecialchars($userPatronymic);  ?></span>!</div>
                    <?php if (isset($errorMessage)): ?>
                        <p style="color: red;"><?php echo htmlspecialchars($errorMessage); ?></p>
                    <?php else: ?>
                        <div class="info__content">
                            <div class="info__courses">
                                <input type="text" class="info__input" placeholder="Поиск курса" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>">
                            </div>
                        </div>

                        <h2 >Ваши курсы:</h2>
                        <div class="courses">
                            <?php if ($courses): ?>
                                <?php foreach ($courses as $row): ?>
                                    <div class='course-card'>
                                        <div class='course-card__title'><?php echo htmlspecialchars($row['Course_Name'] ?? ''); ?></div>
                                        <p>Дата начала: <?php echo htmlspecialchars($row['Course_StartData'] ?? 'Не запущен'); ?></p>
                                        <p>Дата завершения: <?php echo htmlspecialchars($row['Course_EndData'] ?? 'Не запущен'); ?></p>
                                        <!-- Формируем ссылку с course_id -->
                                        <?php if (!empty($row['Course_Id'])): ?>
                                            <a href='info_course.php?course_id=<?php echo htmlspecialchars($row['Course_Id']); ?>'>
                                                <button class='course-card__btn'>Управлять курсом</button>
                                            </a>
                                        <?php else: ?>
                                            <button class='course-card__btn' disabled>Управлять курсом (ID отсутствует)</button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>У вас пока нет назначенных курсов.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const searchInput = document.querySelector(".info__input");
            const courseCards = document.querySelectorAll(".course-card");

            searchInput.addEventListener("input", function() {
                const searchText = searchInput.value.toLowerCase();

                courseCards.forEach(card => {
                    const courseTitle = card.querySelector(".course-card__title").textContent.toLowerCase();
                    if (courseTitle.includes(searchText)) {
                        card.style.display = "flex"; // Показываем курс
                    } else {
                        card.style.display = "none"; // Скрываем курс
                    }
                });
            });
        });
    </script>

</body>

</html>