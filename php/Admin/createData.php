<?php
session_start();
if (!isset($_SESSION['user_name']) || !isset($_SESSION['user_surname'])) {
    header('Location: login.php');
    exit();
}

$userName = $_SESSION['user_name'];
$userSurname = $_SESSION['user_surname'];
$type = isset($_GET['type']) ? $_GET['type'] : 'teacher';
$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);

require '../db_config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $surname = $_POST['surname'];
        $name = $_POST['name'];
        $patronymic = $_POST['patronymic'];
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $username = $_POST['username'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $createdData = date('Y-m-d');
        $qualification = $_POST['qualification'];
        $isActive = true;


        $role_id = ($type === 'admin') ? 1 : (($type === 'teacher') ? 2 : 3);

        $pdo->beginTransaction();

        $sqlUser = "INSERT INTO User (User_Surname, User_Name, User_Patronymic, User_PhoneNumber, User_Login, User_Password, Role_Id, User_Email, User_DataCreate) 
                    VALUES (:surname, :name, :patronymic, :phone, :username, :password, :role_id, :email, :createdData)";
        $stmtUser = $pdo->prepare($sqlUser);
        $stmtUser->execute([
            ':surname' => $surname,
            ':name' => $name,
            ':patronymic' => $patronymic,
            ':phone' => $phone,
            ':username' => $username,
            ':password' => $password,
            ':role_id' => $role_id,
            ':email' => $email,
            ':createdData' => $createdData
        ]);

        $userId = $pdo->lastInsertId();

        if ($type === 'admin') {
            $canManageUsers = isset($_POST['canCreateUser']) ? 1 : 0;
            $canManageCourses = isset($_POST['canCreateCourse']) ? 1 : 0;

            $sqlAdmin = "INSERT INTO Admin (User_Id, Admin_ManagmentUsers, Admin_ManagmentCourse) VALUES (:userId, :canManageUsers, :canManageCourses)";
            $stmtAdmin = $pdo->prepare($sqlAdmin);
            $stmtAdmin->execute([
                ':userId' => $userId,
                ':canManageUsers' => $canManageUsers,
                ':canManageCourses' => $canManageCourses
            ]);
        }

        if ($type === 'teacher') {
            $qualification = $_POST['qualification'];
            $isActive = 1; // По умолчанию активен

            $sqlInstructor = "INSERT INTO Instructor (User_Id, Instructor_Qualification, Is_Active) 
                      VALUES (:userId, :qualification, :isActive)";
            $stmtInstructor = $pdo->prepare($sqlInstructor);
            $stmtInstructor->execute([
                ':userId' => $userId,
                ':qualification' => $qualification,
                ':isActive' => $isActive
            ]);
        }


        if ($type === 'student') {
            $birthday = $_POST['birthday'];

            // Добавляем студента в таблицу Student
            $sqlStudent = "INSERT INTO Student (User_Id, Student_Birthday) VALUES (:userId, :birthday)";
            $stmtStudent = $pdo->prepare($sqlStudent);
            $stmtStudent->execute([
                ':userId' => $userId,
                ':birthday' => $birthday
            ]);

            $studentId = $pdo->lastInsertId();

            // Обрабатываем данные о родителях
            if (!empty($_POST['parent_surname']) && is_array($_POST['parent_surname'])) {
                foreach ($_POST['parent_surname'] as $index => $parentSurname) {
                    $parentName = $_POST['parent_name'][$index];
                    $parentPatronymic = $_POST['parent_patronymic'][$index];
                    $parentPhone = $_POST['parent_phone'][$index];
                    $parentEmail = $_POST['parent_email'][$index];

                    $sqlParent = "INSERT INTO Parents (Parents_Surname, Parents_Name, Parents_Patronymic, Parents_PhoneNumber, Parents_Email) 
                          VALUES (:surname, :name, :patronymic, :phone, :email)";
                    $stmtParent = $pdo->prepare($sqlParent);
                    $stmtParent->execute([
                        ':surname' => $parentSurname,
                        ':name' => $parentName,
                        ':patronymic' => $parentPatronymic,
                        ':phone' => $parentPhone,
                        ':email' => $parentEmail
                    ]);

                    $parentId = $pdo->lastInsertId();

                    // Привязываем родителя к студенту
                    $sqlStudentParent = "INSERT INTO Student_Parents (Student_Id, Parents_Id) VALUES (:studentId, :parentId)";
                    $stmtStudentParent = $pdo->prepare($sqlStudentParent);
                    $stmtStudentParent->execute([
                        ':studentId' => $studentId,
                        ':parentId' => $parentId
                    ]);
                }
            }
        }

        $pdo->commit();
    }
} catch (PDOException $e) {
    $pdo->rollBack();
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}



?>


<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добавление нового пользователя</title>
    <link rel="stylesheet" href="style_Admin/main.css">
    <link rel="stylesheet" href="../../css/style_Admin/main.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body>
    <?php
    include '../components/header.php';
    ?>

    <main>
        <div class="data">
            <div class="container">
                <?php if ($type === 'teacher'): ?>
                    <div class="form-title">Добавление нового Преподавателя</div>
                <?php elseif ($type === 'student'): ?>
                    <div class="form-title">Добавление нового Ученика</div>
                <?php else: ?>
                    <div class="form-title">Добавление нового Администратора</div>
                <?php endif; ?>

                <form method="post" action="">
                    <div class="data__extra">Основные данные <?php
                                                                echo ($type == "admin") ? "Администратора" : ($type === "teacher" ? "Преподавателя" : "Ученика");
                                                                ?></div>

                    <div class="form-group">
                        <label for="surname">Введите фамилию</label>
                        <input type="text" class="input__data" id="surname" name="surname" placeholder="Фамилия" required>
                    </div>
                    <div class="form-group">
                        <label for="name">Введите имя</label>
                        <input type="text" class="input__data" id="name" name="name" placeholder="Имя" required>
                    </div>
                    <div class="form-group">
                        <label for="patronymic">Введите отчество</label>
                        <input type="text" class="input__data" id="patronymic" name="patronymic" placeholder="Отчество" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">Номер телефона</label>
                        <input type="text" class="input__data" id="phone" name="phone" placeholder="Номер телефона" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Введите email пользователя</label>
                        <input type="email" class="input__data" id="email" name="email" placeholder="Email" required>
                    </div>


                    <?php if ($type === 'teacher'): ?>
                        <div class="form-group">
                            <label for="email">Введите квалификацию преподавателя</label>
                            <input type="text" class="input__data" id="qualification" name="qualification" placeholder="Введите квалификацию" required>
                        </div>
                    <?php endif; ?>

                    <?php if ($type === 'admin'): ?>
                        <div class="admin__permision">
                            <label>
                                <input type="checkbox" name="canCreateUser" id="" value="true"><span>Разрешить <span>создавать/редактировать/удалять</span> пользователей</span>
                            </label>
                            <label>
                                <input type="checkbox" name="canCreateCourse" id="" value="true"><span>Разрешить <span>создавать создавать/редактировать/удалят</span>ь курсы</span>
                            </label>
                        </div>
                    <?php endif; ?>

                    <?php if ($type === 'student'): ?>
                        <div class="form-group">
                            <label>Введите день рождение ученика</label>
                            <input type="date" class="input__data" id="birthday" name="birthday" required>
                        </div>

                        <div id="parents-container">
                            <div class="parents">
                                <div class="data__extra">Данные о родителях</div>
                                <div class="form-group">
                                    <label for="surname">Введите фамилию</label>
                                    <input type="text" class="input__data" id="surname" name="parent_surname[]" placeholder="Фамилия" required>
                                </div>
                                <div class="form-group">
                                    <label for="name">Введите имя</label>
                                    <input type="text" class="input__data" id="name" name="parent_name[]" placeholder="Имя" required>
                                </div>
                                <div class="form-group">
                                    <label for="patronymic">Введите отчество</label>
                                    <input type="text" class="input__data" id="patronymic" name="parent_patronymic[]" placeholder="Отчество" required>
                                </div>
                                <div class="form-group">
                                    <label for="phone">Номер телефона</label>
                                    <input type="text" class="input__data" id="phone" name="parent_phone[]" placeholder="Номер телефона" required>
                                </div>
                                <div class="form-group">
                                    <label for="email">Почта</label>
                                    <input type="email" class="input__data" id="email" name="parent_email[]" placeholder="Почта" required>
                                </div>
                            </div>
                        </div>
                        <div class="parents__add">Добавить данные о родителях</div>
                    <?php endif; ?>

                    <div class="data__extra">Данные для авторизации пользователя</div>
                    <div class="form-group">
                        <label for="username">Введите логин пользователя</label>
                        <div class="input-group">
                            <div class="block">
                                <button type="button" class="btn-generate" id="generateUsername">Сгенерировать логин</button>
                            </div>
                            <input type="text" class="input__data" id="username" name="username" placeholder="Логин" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password">Введите пароль пользователя</label>
                        <div class="input-group">
                            <div class="block">
                                <button type="button" class="btn-generate" id="generatePassword">Сгенерировать пароль</button>
                            </div>
                            <input type="password" class="input__data" id="password" name="password" placeholder="Пароль" required>
                            <label class="check-pass"><input type="checkbox" class="btn-outline-secondary" id="togglePassword"><span>Показать пароль</span></label>
                        </div>
                    </div>
                    <div class="btn-addUser"><button type="submit" class="btn btn-primary">Добавить</button></div>
                </form>
            </div>
        </div>
    </main>

    <?php
    include '../components/model.php';
    ?>

    <script>
        // Показать/скрыть пароль
        document.getElementById('togglePassword').addEventListener('change', function() {
            const passwordField = document.getElementById('password');
            passwordField.type = this.checked ? 'text' : 'password';
        });
        // Генерация сложного пароля
        $("#generatePassword").on("click", function() {
            const passwordField = $("#password");
            const generatedPassword = generatePassword(12); // Генерация пароля длиной 12 символов
            passwordField.val(generatedPassword);
        });

        // Функция генерации сложного пароля
        function generatePassword(length) {
            const charset =
                "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+~";
            let password = "";
            for (let i = 0; i < length; i++) {
                const randomIndex = Math.floor(Math.random() * charset.length);
                password += charset[randomIndex];
            }
            return password;
        }

        // Генерация логина на основе фамилии, имени и отчества
        $("#generateUsername").on("click", function() {
            const surname = $("#surname").val();
            const name = $("#name").val();
            const patronymic = $("#patronymic").val();

            const usernameField = $("#username");

            if (surname && name) {
                let username = surname.toLowerCase(); // Фамилия полностью (в нижнем регистре)
                username += name.charAt(0).toLowerCase(); // Первая буква имени (в нижнем регистре)

                if (patronymic) {
                    username += patronymic.charAt(0).toLowerCase(); // Первая буква отчества (в нижнем регистре)
                }

                // Добавим случайный идентификатор для уникальности (например, 3 цифры)
                const randomSuffix = Math.floor(Math.random() * 900) + 100; // от 100 до 999
                username += randomSuffix;

                usernameField.val(username); // Устанавливаем сгенерированный логин в поле
            } else {
                alert("Пожалуйста, введите фамилию и имя для генерации логина");
            }
        });

        document.addEventListener("DOMContentLoaded", function() {
            const parentsContainer = document.getElementById("parents-container");
            const addParentsButton = document.querySelector(".parents__add");
            let parentsCount = 1;
            const maxParents = 3;

            addParentsButton.addEventListener("click", function() {
                if (parentsCount < maxParents) {
                    const newParentsDiv = document.createElement("div");
                    newParentsDiv.classList.add("parents");
                    newParentsDiv.innerHTML = `
                        <div class="data__extra">Данные о родителях</div>
                        <div class="form-group">
                            <label for="surname">Введите фамилию</label>
                            <input type="text" class="input__data" id="surname" name="parent_surname[]" placeholder="Фамилия" required>
                        </div>
                        <div class="form-group">
                            <label for="name">Введите имя</label>
                            <input type="text" class="input__data" id="name" name="parent_name[]" placeholder="Имя" required>
                        </div>
                        <div class="form-group">
                            <label for="patronymic">Введите отчество</label>
                            <input type="text" class="input__data" id="patronymic" name="parent_patronymic[]" placeholder="Отчество" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Номер телефона</label>
                            <input type="text" class="input__data" id="phone" name="parent_phone[]" placeholder="Номер телефона" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Почта</label>
                            <input type="email" class="input__data" id="email" name="parent_email[]" placeholder="Почта" required>
                        </div>
                        <button type="button" class="remove-parents">Удалить</button>
                    `;

                    parentsContainer.appendChild(newParentsDiv);
                    parentsCount++;

                    const removeButton = newParentsDiv.querySelector(".remove-parents");
                    removeButton.addEventListener("click", function() {
                        parentsContainer.removeChild(newParentsDiv);
                        parentsCount--;

                        if (parentsCount < maxParents) {
                            addParentsButton.style.display = "block";
                        }
                    });

                    if (parentsCount === maxParents) {
                        addParentsButton.style.display = "none";
                    }
                }
            });
        });
    </script>

    <script src="../../js/JSAdmin/script.js"></script>
</body>

</html>