<?php
session_start();
require '../db_config.php';

// Проверка прав администратора на управление курсами
$admin_managment_course = 0;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT Admin_ManagmentCourse FROM Admin WHERE User_Id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin_managment_course = $stmt->fetchColumn();
}

$userName = $_SESSION['user_name'];
$userSurname = $_SESSION['user_surname'];
$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка прав перед выполнением действий
    if (!$admin_managment_course) {
        die("Доступ запрещен: недостаточно прав.");
    }

    if ($_POST['action'] === 'update_course') {
        $stmt = $pdo->prepare("UPDATE Course SET Course_Name = ?, Course_Hours = ?, Course_StartData = ?, Course_EndData = ? WHERE Course_Id = ?");
        $stmt->execute([
            $_POST['course_name'],
            $_POST['course_hours'],
            $_POST['start_date'],
            $_POST['end_date'],
            $_POST['course_id']
        ]);
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }

    if ($_POST['action'] === 'change_teacher') {
        $stmt = $pdo->prepare("UPDATE Course SET Instructor_id = ? WHERE Course_Id = ?");
        $stmt->execute([$_POST['instructor_id'], $_POST['course_id']]);
        exit('OK');
    }

    if ($_POST['action'] === 'add_student') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Course_Enrollments WHERE Course_Id = ? AND Student_Id = ?");
        $stmt->execute([$_POST['course_id'], $_POST['student_id']]);
        $exists = $stmt->fetchColumn();

        if (!$exists) {
            $stmt = $pdo->prepare("INSERT INTO Course_Enrollments (Course_Id, Student_Id) VALUES (?, ?)");
            $stmt->execute([$_POST['course_id'], $_POST['student_id']]);
        }
        exit('OK');
    }

    if ($_POST['action'] === 'remove_student') {
        $stmt = $pdo->prepare("DELETE FROM Course_Enrollments WHERE Course_Id = ? AND Student_Id = ?");
        $stmt->execute([$_POST['course_id'], $_POST['student_id']]);
        exit('OK');
    }

    if ($_POST['action'] === 'delete_course') {
        $stmt = $pdo->prepare("DELETE FROM Course WHERE Course_Id = ?");
        $stmt->execute([$_POST['course_id']]);
        header("Location: main_page.php");
        exit();
    }
}

// Получение данных курса
$course_id = (int)($_GET['course_id'] ?? 0);
if (!$course_id) {
    die("Ошибка: ID курса не указан или некорректен.");
}

$userName = $_SESSION['user_name'] ?? '';
$userSurname = $_SESSION['user_surname'] ?? '';
$userPatronymic = $_SESSION['user_patronymic'] ?? '';
$initials = ($userSurname && $userName) ? mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1) : 'Гость';

try {
    $stmt = $pdo->prepare("SELECT * FROM Course WHERE Course_Id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) {
        die("Курс не найден.");
    }

    $stmt = $pdo->prepare("SELECT u.User_Surname, u.User_Name, u.User_Patronymic FROM Instructor i JOIN User u ON i.User_Id = u.User_Id WHERE i.Instructor_Id = ?");
    $stmt->execute([$course['Instructor_id']]);
    $instructor = $stmt->fetch(PDO::FETCH_ASSOC);

    $all_teachers = $pdo->query("SELECT i.Instructor_Id, u.User_Surname, u.User_Name, u.User_Patronymic FROM Instructor i JOIN User u ON i.User_Id = u.User_Id WHERE i.Is_Active = 1")->fetchAll();

    $stmt = $pdo->prepare("SELECT s.Student_Id, u.User_Surname, u.User_Name, u.User_Patronymic, u.User_PhoneNumber FROM Course_Enrollments ce JOIN Student s ON ce.Student_Id = s.Student_Id JOIN User u ON s.User_Id = u.User_Id WHERE ce.Course_Id = ?");
    $stmt->execute([$course_id]);
    $students = $stmt->fetchAll();

    $all_students = $pdo->query("SELECT s.Student_Id, u.User_Surname, u.User_Name, u.User_Patronymic, u.User_PhoneNumber FROM Student s JOIN User u ON s.User_Id = u.User_Id")->fetchAll();
} catch (PDOException $e) {
    die("Ошибка базы данных: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Управление курсом</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/style_Admin/section/header.css">
    <link rel="stylesheet" href="../../css/style_Admin/section/manage_course.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

</head>

<body class="bg-light">

    <?php
    include '../components/header.php';
    ?>
    <div class="container mt-5">
        <h2>Управление курсом: <?= htmlspecialchars($course['Course_Name']) ?></h2>

        <!-- Текущий преподаватель -->
        <div class="mb-3">
            <h5>Текущий преподаватель:</h5>
            <p><?= $instructor ? htmlspecialchars("{$instructor['User_Surname']} {$instructor['User_Name']} {$instructor['User_Patronymic']}") : 'Не назначен' ?></p>
        </div>

        <!-- Форма редактирования -->
        <form method="POST" class="mb-4 <?= !$admin_managment_course ? 'no-access' : '' ?>">
            <input type="hidden" name="action" value="update_course">
            <input type="hidden" name="course_id" value="<?= $course_id ?>">
            <div class="mb-2">
                <label>Название курса:</label>
                <input class="form-control" name="course_name" value="<?= htmlspecialchars($course['Course_Name']) ?>" <?= !$admin_managment_course ? 'readonly' : '' ?> required>
            </div>
            <div class="mb-2">
                <label>Часы:</label>
                <input type="number" class="form-control" name="course_hours" value="<?= $course['Course_Hours'] ?>" <?= !$admin_managment_course ? 'readonly' : '' ?> required>
            </div>
            <div class="mb-2">
                <label>Дата начала:</label>
                <input type="date" class="form-control" name="start_date" value="<?= $course['Course_StartData'] ?>" <?= !$admin_managment_course ? 'readonly' : '' ?> required>
            </div>
            <div class="mb-2">
                <label>Дата окончания:</label>
                <input type="date" class="form-control" name="end_date" value="<?= $course['Course_EndData'] ?>" <?= !$admin_managment_course ? 'readonly' : '' ?> required>
            </div>
            <button class="btn btn-primary" <?= !$admin_managment_course ? 'disabled' : '' ?>>Обновить курс</button>
        </form>

        <!-- Студенты -->
        <h5>Студенты курса:</h5>
        <?php if ($students): ?>
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Фамилия</th>
                        <th>Имя</th>
                        <th>Отчество</th>
                        <th>Телефон</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $s): ?>
                        <tr>
                            <td><?= htmlspecialchars($s['User_Surname']) ?></td>
                            <td><?= htmlspecialchars($s['User_Name']) ?></td>
                            <td><?= htmlspecialchars($s['User_Patronymic']) ?></td>
                            <td class="phone-number"><?= htmlspecialchars($s['User_PhoneNumber']) ?></td>
                            <td class="action-buttons">
                                <button class="btn btn-sm btn-danger" <?= !$admin_managment_course ? 'disabled' : '' ?>
                                    onclick="<?= $admin_managment_course ? "removeStudent({$s['Student_Id']})" : '' ?>">
                                    <i class="fas fa-trash-alt"></i> Удалить
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Нет студентов.</p>
        <?php endif; ?>

        <!-- Управление -->
        <div class="mt-3 d-flex gap-2">
            <button onclick="<?= $admin_managment_course ? "openModal('teacherModal')" : '' ?>" class="btn btn-primary" <?= !$admin_managment_course ? 'disabled' : '' ?>>
                <i class="fas fa-chalkboard-teacher"></i> Изменить преподавателя
            </button>
            <button onclick="<?= $admin_managment_course ? "openModal('studentModal')" : '' ?>" class="btn btn-success" <?= !$admin_managment_course ? 'disabled' : '' ?>>
                <i class="fas fa-user-plus"></i> Добавить студентов
            </button>
            <form method="POST" onsubmit="return <?= $admin_managment_course ? "confirm('Удалить курс?')" : 'false' ?>">
                <input type="hidden" name="action" value="delete_course">
                <input type="hidden" name="course_id" value="<?= $course_id ?>">
                <button class="btn btn-danger" <?= !$admin_managment_course ? 'disabled' : '' ?>>
                    <i class="fas fa-trash"></i> Удалить курс
                </button>
            </form>
        </div>
    </div>

    <!-- Модальное окно преподавателя -->
    <div id="teacherModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('teacherModal')">&times;</span>
            <h5>Выберите преподавателя:</h5>
            <div class="search-box">
                <input type="text" id="teacherSearch" class="form-control" placeholder="Поиск преподавателя..." onkeyup="filterTeachers()">
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Фамилия</th>
                        <th>Имя</th>
                        <th>Отчество</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="teacherTableBody">
                    <?php foreach ($all_teachers as $t): ?>
                        <tr class="teacher-row">
                            <td><?= htmlspecialchars($t['User_Surname']) ?></td>
                            <td><?= htmlspecialchars($t['User_Name']) ?></td>
                            <td><?= htmlspecialchars($t['User_Patronymic']) ?></td>
                            <td><button class="btn btn-sm btn-outline-primary"
                                    onclick="changeTeacher(<?= $t['Instructor_Id'] ?>)">Выбрать</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Модальное окно студентов -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('studentModal')">&times;</span>
            <h5>Добавить студентов:</h5>
            <div class="search-box">
                <input type="text" id="studentSearch" class="form-control" placeholder="Поиск студента..." onkeyup="filterStudents()">
            </div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Фамилия</th>
                        <th>Имя</th>
                        <th>Отчество</th>
                        <th>Телефон</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="studentTableBody">
                    <?php foreach ($all_students as $s): ?>
                        <tr class="student-row">
                            <td><?= htmlspecialchars($s['User_Surname']) ?></td>
                            <td><?= htmlspecialchars($s['User_Name']) ?></td>
                            <td><?= htmlspecialchars($s['User_Patronymic']) ?></td>
                            <td class="phone-number"><?= htmlspecialchars($s['User_PhoneNumber']) ?></td>
                            <td><button class="btn btn-sm btn-success"
                                    onclick="addStudent(<?= $s['Student_Id'] ?>)">Добавить</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).style.display = 'block';
            if (id === 'teacherModal') {
                document.getElementById('teacherSearch').value = '';
                filterTeachers();
            } else if (id === 'studentModal') {
                document.getElementById('studentSearch').value = '';
                filterStudents();
            }
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function filterTeachers() {
            const input = document.getElementById('teacherSearch');
            const filter = input.value.toUpperCase();
            const rows = document.querySelectorAll('.teacher-row');

            rows.forEach(row => {
                const surname = row.cells[0].textContent.toUpperCase();
                const name = row.cells[1].textContent.toUpperCase();
                const patronymic = row.cells[2].textContent.toUpperCase();

                if (surname.includes(filter) || name.includes(filter) || patronymic.includes(filter)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function filterStudents() {
            const input = document.getElementById('studentSearch');
            const filter = input.value.toUpperCase();
            const rows = document.querySelectorAll('.student-row');

            rows.forEach(row => {
                const surname = row.cells[0].textContent.toUpperCase();
                const name = row.cells[1].textContent.toUpperCase();
                const patronymic = row.cells[2].textContent.toUpperCase();
                const phone = row.cells[3].textContent.toUpperCase();

                if (surname.includes(filter) || name.includes(filter) || patronymic.includes(filter) || phone.includes(filter)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function changeTeacher(instructorId) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'change_teacher',
                    instructor_id: instructorId,
                    course_id: <?= $course_id ?>
                })
            }).then(() => location.reload());
        }

        function addStudent(studentId) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'add_student',
                    student_id: studentId,
                    course_id: <?= $course_id ?>
                })
            }).then(() => location.reload());
        }

        function removeStudent(studentId) {
            if (confirm('Вы уверены, что хотите удалить этого студента с курса?')) {
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        action: 'remove_student',
                        student_id: studentId,
                        course_id: <?= $course_id ?>
                    })
                }).then(() => location.reload());
            }
        }
    </script>
</body>

</html>