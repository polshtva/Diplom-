<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require '../db_config.php';

// Получаем данные пользователя из сессии
$userName = $_SESSION['user_name'];
$userSurname = $_SESSION['user_surname'];
$userPatronymic = $_SESSION['user_patronymic'] ?? '';

// Получение инициалов
$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);

// Получаем course_id
$courseId = $_POST['course_id'] ?? $_GET['course_id'] ?? null;
if (!$courseId) {
    die("Ошибка: Курс не выбран.");
}

// Получаем данные о курсе
$query = "SELECT Course_Name FROM Course WHERE Course_Id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Ошибка: Курс не найден.");
}

$courseName = htmlspecialchars($course['Course_Name']);

// Сортировка
$sort = $_GET['sort'] ?? 'Lesson_Number';
$order = $_GET['order'] ?? 'ASC';

// Валидация сортировки
$allowedSortFields = ['Lesson_Number', 'Lesson_Name', 'Lesson_Date'];
if (!in_array($sort, $allowedSortFields)) {
    $sort = 'Lesson_Number';
}

// Валидация порядка сортировки
$order = strtoupper($order);
if ($order !== 'ASC' && $order !== 'DESC') {
    $order = 'ASC';
}

// Получаем все уроки для данного курса с информацией о наличии теста
$sql = "SELECT l.Lesson_Id, l.Lesson_Number, l.Lesson_Name, l.Lesson_Desc, l.Lesson_Date, 
               (SELECT COUNT(*) FROM Test WHERE Lesson_Id = l.Lesson_Id) as has_test
        FROM Lesson l
        WHERE l.Course_Id = :courseId
        ORDER BY $sort $order";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':courseId', $courseId, PDO::PARAM_INT);
$stmt->execute();
$allLessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Преобразуем данные в JSON для использования в JavaScript
$lessonsJson = json_encode($allLessons);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>История уроков</title>
    <link rel="stylesheet" href="../../css/style_Instructor/history_lesson.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

</head>

<body>
    <div class="wrapper">
        <?php include '../components/header.php'; ?>

        <main class="main">
            <div class="container">
                <h2 style="margin-top: 30px; text-align:center">История уроков курса: <?php echo $courseName; ?></h2>

                <!-- Форма поиска -->
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Поиск по названию или описанию урока">
                </div>

                <!-- Сортировка -->
                <div class="sort-links">
                    <p>Сортировать по:
                        <a href="?course_id=<?php echo $courseId; ?>&sort=Lesson_Number&order=<?php echo ($sort == 'Lesson_Number' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>">Номеру урока</a> |
                        <a href="?course_id=<?php echo $courseId; ?>&sort=Lesson_Name&order=<?php echo ($sort == 'Lesson_Name' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>">Названию урока</a> |
                        <a href="?course_id=<?php echo $courseId; ?>&sort=Lesson_Date&order=<?php echo ($sort == 'Lesson_Date' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>">Дате урока</a>
                    </p>
                </div>

                <!-- Контейнер для уроков -->
                <div class="lesson-container" id="lessonsContainer">
                    <!-- Уроки будут загружаться здесь через JavaScript -->
                </div>
            </div>
        </main>
    </div>

    <!-- Модальное окно для мониторинга теста -->
    <div id="testMonitoringModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <div class="modal-header">
                <h3 class="modal-title">Результаты тестирования</h3>
            </div>
            <div class="search-student">
                <input type="text" id="studentSearch" placeholder="Поиск студента">
            </div>
            <div id="testResultsContainer">
                <div class="loading">
                    <i class="fas fa-spinner fa-spin"></i> Загрузка данных...
                </div>
            </div>
        </div>
    </div>

    <script>
        // Получаем данные уроков из PHP
        const allLessons = <?php echo $lessonsJson; ?>;
        const container = document.getElementById('lessonsContainer');
        const searchInput = document.getElementById('searchInput');

        // Элементы модального окна
        const modal = document.getElementById('testMonitoringModal');
        const closeBtn = document.querySelector('.close');
        const studentSearch = document.getElementById('studentSearch');
        const testResultsContainer = document.getElementById('testResultsContainer');

        // Текущий lesson_id для мониторинга
        let currentLessonId = null;

        // Функция для экранирования HTML
        function escapeHtml(unsafe) {
            if (!unsafe) return '';
            return unsafe
                .toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Функция для сокращения текста до 100 слов
        function truncateText(text, maxWords = 100) {
            if (!text) return '';

            const words = text.trim().split(/\s+/);
            if (words.length <= maxWords) {
                return escapeHtml(text);
            }

            const truncated = words.slice(0, maxWords).join(' ');
            return escapeHtml(truncated);
        }

        // Функция для отображения уроков
        function displayLessons(lessons) {
            if (lessons.length === 0) {
                container.innerHTML = '<div class="no-results">Нет уроков для отображения.</div>';
                return;
            }

            let html = '';
            lessons.forEach(lesson => {
                const fullDesc = lesson.Lesson_Desc ? escapeHtml(lesson.Lesson_Desc) : '';
                const shortDesc = truncateText(lesson.Lesson_Desc);
                const wordsCount = lesson.Lesson_Desc ? lesson.Lesson_Desc.split(/\s+/).length : 0;
                const isTruncated = wordsCount > 100;

                // Определяем кнопки в зависимости от наличия теста
                const testButtons = lesson.has_test ?
                    `
                        <a href="edit_test.php?lesson_id=${lesson.Lesson_Id}" class="btn-edit-test" target="_blank">Редактировать тест</a>
                        <a href="#" class="btn-monitoring" data-lesson-id="${lesson.Lesson_Id}">Мониторинг теста</a>
                      ` :
                    `<a href="create_test.php?lesson_id=${lesson.Lesson_Id}" class="btn-create-test" target="_blank">Создать тест</a>`;

                html += `
                    <div class="lesson-card">
                        <div class="lesson-card__title"><strong>Урок ${escapeHtml(lesson.Lesson_Number)}:</strong> ${escapeHtml(lesson.Lesson_Name)}</div>
                        <div class="lesson-card__desc">
                            ${shortDesc}${isTruncated ? '...' : ''}
                            ${isTruncated ? `<button class="show-more-btn" data-full="${fullDesc}" data-lesson="${lesson.Lesson_Id}" >Показать больше</button>` : ''}
                        </div>
                        <div class="lesson-card__date"><span style="font-weight: 600;">Дата создания:</span> ${escapeHtml(lesson.Lesson_Date)}</div>
                        <div class="lesson-actions">
                            <a href="lesson_details.php?lesson_id=${lesson.Lesson_Id}" class="btn-details" target="_blank">Подробнее</a>
                            ${testButtons}
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;

            // Добавляем обработчики для кнопок "Показать больше"
            document.querySelectorAll('.show-more-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const descElement = this.parentElement;
                    descElement.innerHTML = this.getAttribute('data-full');
                });
            });

            // Добавляем обработчики для кнопок мониторинга теста
            document.querySelectorAll('.btn-monitoring').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    currentLessonId = this.getAttribute('data-lesson-id');
                    openTestMonitoringModal(currentLessonId);
                });
            });
        }

        // Функция для фильтрации уроков
        function filterLessons(searchTerm) {
            if (!searchTerm) {
                return allLessons;
            }

            const term = searchTerm.toLowerCase();
            return allLessons.filter(lesson => {
                return lesson.Lesson_Name.toLowerCase().includes(term) ||
                    (lesson.Lesson_Desc && lesson.Lesson_Desc.toLowerCase().includes(term));
            });
        }

        // Функция для открытия модального окна мониторинга теста
        function openTestMonitoringModal(lessonId) {
            modal.style.display = 'block';
            loadTestResults(lessonId);
        }

        // Функция для загрузки результатов теста
        function loadTestResults(lessonId, searchTerm = '') {
            testResultsContainer.innerHTML = `
                <div class="loading">
                    <i class="fas fa-spinner fa-spin"></i> Загрузка данных...
                </div>
            `;

            // AJAX запрос для получения результатов теста
            fetch(`get_test_results.php?lesson_id=${lessonId}&search=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        testResultsContainer.innerHTML = `
                            <div class="no-results-message">
                                ${data.error}
                            </div>
                        `;
                        return;
                    }

                    if (data.length === 0) {
                        testResultsContainer.innerHTML = `
                            <div class="no-results-message">
                                Нет результатов тестирования для отображения.
                            </div>
                        `;
                        return;
                    }

                    let html = `
                        <table class="results-table">
                            <thead>
                                <tr>
                                    <th>ФИО студента</th>
                                    <th>Баллы</th>
                                    <th>Макс. балл</th>
                                    <th>Статус</th>
                                    <th>Дата прохождения</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    data.forEach(result => {
                        html += `
                            <tr>
                                <td>${escapeHtml(result.User_Surname)} ${escapeHtml(result.User_Name)} ${escapeHtml(result.User_Patronymic || '')}</td>
                                <td>${result.Score}</td>
                                <td>${result.MaxScore}</td>
                                <td class="${result.PassStatus === 'passed' ? 'passed' : 'failed'}">
                                    ${result.PassStatus === 'passed' ? 'Сдал' : 'Плохо сдал'}
                                </td>
                                <td>${result.DateCompleted}</td>
                            </tr>
                        `;
                    });

                    html += `
                            </tbody>
                        </table>
                    `;

                    testResultsContainer.innerHTML = html;
                })
                .catch(error => {
                    console.error('Ошибка:', error);
                    testResultsContainer.innerHTML = `
                        <div class="no-results-message">
                            Произошла ошибка при загрузке данных.
                        </div>
                    `;
                });
        }

        // Обработчик ввода в поле поиска
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            const filteredLessons = filterLessons(searchTerm);
            displayLessons(filteredLessons);
        });

        // Обработчик ввода в поле поиска студентов
        studentSearch.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            if (currentLessonId) {
                loadTestResults(currentLessonId, searchTerm);
            }
        });

        // Обработчик закрытия модального окна
        closeBtn.addEventListener('click', function() {
            modal.style.display = 'none';
        });

        // Закрытие модального окна при клике вне его
        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Инициализация - отображаем все уроки при загрузке
        displayLessons(allLessons);
    </script>
</body>

</html>