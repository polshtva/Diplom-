<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require '../db_config.php';

// Проверка авторизации преподавателя
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) {
    header("Location: login.php");
    exit();
}

// Получаем данные преподавателя из сессии

$userName = $_SESSION['user_name'];
$userSurname = $_SESSION['user_surname'];
$userPatronymic = $_SESSION['user_patronymic'] ?? '';

$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);

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
}

// Получаем список курсов преподавателя
$coursesQuery = "SELECT Course_id, Course_Name FROM Course WHERE Instructor_id = ? ORDER BY Course_Name";
$coursesStmt = $pdo->prepare($coursesQuery);
$coursesStmt->execute([$instructorId]);
$courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);


// Получаем выбранный курс (если есть)
$selectedCourseId = $_GET['course_id'] ?? null;
$selectedCourseName = '';

if ($selectedCourseId) {
    $courseQuery = "SELECT Course_Name FROM Course WHERE Course_Id = ? AND Instructor_id = ?";
    $courseStmt = $pdo->prepare($courseQuery);
    $courseStmt->execute([$selectedCourseId, $instructorId]);
    $selectedCourse = $courseStmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedCourse) {
        $selectedCourseName = htmlspecialchars($selectedCourse['Course_Name']);
    } else {
        $selectedCourseId = null; // Сброс, если курс не принадлежит преподавателю
    }
}

// Получаем студентов и их родителей для выбранного курса
$students = [];
if ($selectedCourseId) {
    $studentsQuery = "
    SELECT 
        s.Student_id, 
        u.User_id,
        u.User_Surname, 
        u.User_Name, 
        u.User_Patronymic,
        u.User_Email,
        u.User_PhoneNumber,
        s.Student_Birthday,
        GROUP_CONCAT(CONCAT(p.Parents_Surname, ' ', p.Parents_Name, ' ', p.Parents_Patronymic)) AS parents_names,
        GROUP_CONCAT(p.Parents_PhoneNumber SEPARATOR '|') AS parents_phones,
        GROUP_CONCAT(p.Parents_Email SEPARATOR '|') AS parents_emails
    FROM course_enrollments e
    JOIN Student s ON e.Student_Id = s.Student_id
    JOIN User u ON s.User_id = u.User_id
    LEFT JOIN Student_Parents sp ON s.Student_id = sp.Student_Id
    LEFT JOIN Parents p ON sp.Parents_Id = p.Parents_id
    WHERE e.Course_Id = ?
    GROUP BY s.Student_id
    ORDER BY u.User_Surname, u.User_Name
";

    $studentsStmt = $pdo->prepare($studentsQuery);
    $studentsStmt->execute([$selectedCourseId]);
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Студенты и родители - Преподаватель</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/style_Instructor/info_student.css">
  
</head>

<body>
    <?php include '../components/header.php'; ?>

    <div class="container">
        <div class="info-header">
            <h1>Студенты и их родители</h1>
            <div class="user-info">
                <div class="user-avatar"><?php echo mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1); ?></div>
                <div>
                    <div><?php echo htmlspecialchars("$userSurname $userName $userPatronymic"); ?></div>
                    <div style="font-size: 14px; color: #7f8c8d;">Преподаватель</div>
                </div>
            </div>
        </div>

        <div class="course-selector">
            <form method="get" action="">
                <select name="course_id" id="courseSelect">
                    <option value="">-- Выберите курс --</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?php echo $course['Course_id']; ?>"
                            <?php if ($course['Course_id'] == $selectedCourseId) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($course['Course_Name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Показать</button>
            </form>
        </div>

        <?php if ($selectedCourseId): ?>
            <h2 class="course-title">Курс: <?php echo $selectedCourseName; ?></h2>

            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Поиск по имени студента или родителя...">
            </div>

            <div id="studentsContainer">
                <?php if (count($students) > 0): ?>
                    <?php foreach ($students as $student): ?>
                        <div class="student-card">
                            <div class="student-header">
                                <div class="student-name">
                                    <?php echo htmlspecialchars("{$student['User_Surname']} {$student['User_Name']} {$student['User_Patronymic']}"); ?>
                                </div>
                            </div>

                            <div class="student-info">
                                <div class="info-item">
                                    <div class="info-label">Дата рождения</div>
                                    <div><?php echo htmlspecialchars($student['Student_Birthday'] ?? 'не указана'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Email</div>
                                    <div><?php echo htmlspecialchars($student['User_Email'] ?? 'не указан'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Телефон</div>
                                    <div><?php echo htmlspecialchars($student['User_PhoneNumber'] ?? 'не указан'); ?></div>
                                </div>
                            </div>

                            <div class="parents-section">
                                <h4>Родители:</h4>
                                <?php if ($student['parents_names']): ?>
                                    <?php
                                    $parentsNames = explode(',', $student['parents_names']);
                                    $parentsPhones = explode('|', $student['parents_phones']);
                                    $parentsEmails = explode('|', $student['parents_emails']);

                                    for ($i = 0; $i < count($parentsNames); $i++):
                                        if (empty(trim($parentsNames[$i]))) continue;
                                    ?>
                                        <div class="parent-card">
                                            <div><strong><?php echo htmlspecialchars($parentsNames[$i]); ?></strong></div>
                                            <div>Телефон: <?php echo htmlspecialchars($parentsPhones[$i] ?? '<span class="no-data">не указан</span>'); ?></div>
                                            <div>Email: <?php echo htmlspecialchars($parentsEmails[$i] ?? '<span class="no-data">не указан</span>'); ?></div>
                                        </div>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <div class="no-data">Родители не указаны</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-results">На этом курсе нет зачисленных студентов</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="no-results">Выберите курс для просмотра студентов</div>
        <?php endif; ?>
    </div>

    <script>
        // Функция для поиска студентов
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const studentCards = document.querySelectorAll('.student-card');

            let hasVisibleStudents = false;

            studentCards.forEach(card => {
                const studentText = card.textContent.toLowerCase();
                if (studentText.includes(searchTerm)) {
                    card.style.display = '';
                    hasVisibleStudents = true;
                } else {
                    card.style.display = 'none';
                }
            });

            // Показываем сообщение, если нет результатов
            const noResultsElement = document.querySelector('#studentsContainer .no-results');
            if (!hasVisibleStudents && studentCards.length > 0) {
                if (!noResultsElement) {
                    const container = document.getElementById('studentsContainer');
                    const message = document.createElement('div');
                    message.className = 'no-results';
                    message.textContent = 'Студенты не найдены';
                    container.appendChild(message);
                }
            } else if (noResultsElement && hasVisibleStudents) {
                noResultsElement.remove();
            }
        });
    </script>
</body>

</html>