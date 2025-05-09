<?php
session_start();
require '../db_config.php';

$userName = $_SESSION['user_name'];
$userSurname = $_SESSION['user_surname'];
$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Получаем список преподавателей
    function getTeachersFromDB($pdo)
    {
        $stmt = $pdo->query("SELECT i.Instructor_Id, u.User_Surname, u.User_Name, u.User_Patronymic 
                             FROM User u 
                             INNER JOIN Instructor i ON u.User_Id = i.User_Id 
                             WHERE i.Is_Active = 1");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Получаем список студентов
    function getStudentsFromDB($pdo)
    {
        $stmt = $pdo->query("SELECT s.Student_Id, u.User_Surname, u.User_Name, u.User_Patronymic 
                             FROM User u 
                             INNER JOIN Student s ON u.User_Id = s.User_Id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Обработка формы
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $course_name = trim($_POST['course_name']);
        $course_hours = (int)$_POST['course_hour'];
        $start_date = $_POST['course_dateStart'] ?? null;
        $end_date = $_POST['course_dateEnd'] ?? null;
        $teacher_id = isset($_POST['teacher_id']) && is_numeric($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
        $students = isset($_POST['students']) ? json_decode($_POST['students']) : [];

        // Валидация только обязательных полей
        if (empty($course_name) || $course_hours <= 0) {
            die("Пожалуйста, укажите название курса и корректное количество часов.");
        }

        try {
            $pdo->beginTransaction();

            // Вставка курса
            $stmt = $pdo->prepare("INSERT INTO Course (Course_Name, Course_Hours, Course_StartData, Course_EndData, Instructor_Id) 
                                   VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $course_name,
                $course_hours,
                !empty($start_date) ? $start_date : null,
                !empty($end_date) ? $end_date : null,
                !empty($teacher_id) ? $teacher_id : null
            ]);

            $course_id = $pdo->lastInsertId();

            // Добавляем студентов
            if (!empty($students)) {
                $enrollment_date = date('Y-m-d H:i:s');
                $stmt = $pdo->prepare("INSERT INTO course_enrollments (Course_Id, Student_Id, Enrollments_Date) 
                                       VALUES (?, ?, ?)");

                foreach ($students as $student_id) {
                    // Проверяем наличие студента
                    $check = $pdo->prepare("SELECT 1 FROM Student WHERE Student_Id = ?");
                    $check->execute([$student_id]);

                    if ($check->fetch()) {
                        $stmt->execute([$course_id, $student_id, $enrollment_date]);
                    }
                }
            }

            $pdo->commit();

            $_SESSION['success_message'] = "Курс успешно создан!";
            header('Location: main_page.php');
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Ошибка при создании курса: " . $e->getMessage());
        }
    }

    // Получаем данные для формы
    $teachers = getTeachersFromDB($pdo);
    $students = getStudentsFromDB($pdo);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}
?>


<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Создание нового курса</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css//style_Admin/section/header.css">
    <link rel="stylesheet" href="../../css//style_Admin/section/createCourse.css">
</head>

<body>
    <?php
    include '../components/header.php';
    ?>
    <div class="container">
        <h2 class="mb-4" style="margin-top: 15px;">Создание нового курса</h2>

        <form method="POST" action="">
            <div class="form-group">
                <label>Название курса:</label>
                <input type="text" class="form-control input__data" name="course_name" required>
            </div>

            <div class="form-group">
                <label>Количество часов:</label>
                <input type="number" class="form-control input__data" name="course_hour" min="1" required>
            </div>

            <div class="form-group">
                <label>Дата начала:</label>
                <input type="date" class="form-control date-input" name="course_dateStart">
            </div>

            <div class="form-group">
                <label>Дата завершения:</label>
                <input type="date" class="form-control date-input" name="course_dateEnd">
            </div>

            <div class="form-group">
                <label>Преподаватель:</label>
                <button type="button" class="btn btn-primary" onclick="openModal('teacherModal')">Выбрать преподавателя</button>
                <input type="hidden" name="teacher_id" id="selectedTeacherId">
                <span id="selectedTeacherName" class="ms-2">Не выбран</span>
            </div>

            <div class="form-group">
                <label>Студенты:</label>
                <button type="button" class="btn btn-primary" onclick="openModal('studentModal')">Выбрать студентов</button>
                <input type="hidden" name="students" id="studentsInput" value="[]">

                <table id="selectedStudentsTable" class="table">
                    <thead>
                        <tr>
                            <th>Фамилия</th>
                            <th>Имя</th>
                            <th>Отчество</th>
                            <th>Действие</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-success mt-3">Создать курс</button>
        </form>
    </div>

    <!-- Модальное окно выбора преподавателя -->
    <div id="teacherModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('teacherModal')">&times;</span>
            <h3>Выберите преподавателя</h3>
            <div class="search-container">
                <input type="text" id="teacherSearch" class="search-input" placeholder="Поиск преподавателя..." onkeyup="searchTable('teacherSearch', 'teacherTable')">
            </div>
            <table class="table" id="teacherTable">
                <thead>
                    <tr>
                        <th>Фамилия</th>
                        <th>Имя</th>
                        <th>Отчество</th>
                        <th>Выбрать</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teachers as $teacher): ?>
                        <tr onclick="selectTeacher(<?= $teacher['Instructor_Id'] ?>, '<?= $teacher['User_Surname'] ?>', '<?= $teacher['User_Name'] ?>')">
                            <td><?= $teacher['User_Surname'] ?></td>
                            <td><?= $teacher['User_Name'] ?></td>
                            <td><?= $teacher['User_Patronymic'] ?></td>
                            <td><button class="btn btn-sm btn-primary">Выбрать</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Модальное окно выбора студентов -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('studentModal')">&times;</span>
            <h3>Выберите студентов</h3>
            <div class="search-container">
                <input type="text" id="studentSearch" class="search-input" placeholder="Поиск студентов..." onkeyup="searchTable('studentSearch', 'studentTable')">
            </div>
            <table class="table" id="studentTable">
                <thead>
                    <tr>
                        <th>Фамилия</th>
                        <th>Имя</th>
                        <th>Отчество</th>
                        <th>Выбрать</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr onclick="selectStudent(<?= $student['Student_Id'] ?>, '<?= $student['User_Surname'] ?>', '<?= $student['User_Name'] ?>', '<?= $student['User_Patronymic'] ?>')">
                            <td><?= $student['User_Surname'] ?></td>
                            <td><?= $student['User_Name'] ?></td>
                            <td><?= $student['User_Patronymic'] ?></td>
                            <td><button class="btn btn-sm btn-primary">Выбрать</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        let selectedStudents = [];

        // Открытие модального окна
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        // Закрытие модального окна
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Выбор преподавателя
        function selectTeacher(id, surname, name) {
            document.getElementById('selectedTeacherId').value = id;
            document.getElementById('selectedTeacherName').textContent = `${surname} ${name}`;
            closeModal('teacherModal');
        }

        // Выбор студента
        function selectStudent(id, surname, name, patronymic) {
            // Проверяем, не выбран ли уже этот студент
            if (selectedStudents.some(s => s.id === id)) {
                alert('Этот студент уже выбран');
                return;
            }

            // Добавляем студента в массив
            selectedStudents.push({
                id,
                surname,
                name,
                patronymic
            });

            // Обновляем скрытое поле с ID студентов
            document.getElementById('studentsInput').value = JSON.stringify(selectedStudents.map(s => s.id));

            // Обновляем таблицу выбранных студентов
            updateSelectedStudentsTable();

            closeModal('studentModal');
        }

        // Удаление студента из выбранных
        function removeStudent(id) {
            selectedStudents = selectedStudents.filter(s => s.id !== id);
            document.getElementById('studentsInput').value = JSON.stringify(selectedStudents.map(s => s.id));
            updateSelectedStudentsTable();
        }

        // Обновление таблицы выбранных студентов
        function updateSelectedStudentsTable() {
            const tbody = document.querySelector('#selectedStudentsTable tbody');
            tbody.innerHTML = '';

            if (selectedStudents.length > 0) {
                document.getElementById('selectedStudentsTable').style.display = 'table';

                selectedStudents.forEach(student => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${student.surname}</td>
                        <td>${student.name}</td>
                        <td>${student.patronymic}</td>
                        <td><button class="btn btn-sm btn-danger" onclick="removeStudent(${student.id})">Удалить</button></td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                document.getElementById('selectedStudentsTable').style.display = 'none';
            }
        }

        // Функция поиска в таблице
        function searchTable(inputId, tableId) {
            const input = document.getElementById(inputId);
            const filter = input.value.toUpperCase();
            const table = document.getElementById(tableId);
            const tr = table.getElementsByTagName("tr");

            for (let i = 1; i < tr.length; i++) { // Начинаем с 1, чтобы пропустить заголовок
                let found = false;
                const td = tr[i].getElementsByTagName("td");

                for (let j = 0; j < td.length - 1; j++) { // Пропускаем последний столбец с кнопкой
                    if (td[j]) {
                        const txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }

                tr[i].style.display = found ? "" : "none";
            }
        }
    </script>
</body>

</html>