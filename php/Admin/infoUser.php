<?php
session_start();
require '../db_config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Проверка прав администратора
$canManageUsers = false;
if (isset($_SESSION['user_id'])) {
    try {
        $adminStmt = $pdo->prepare("SELECT Admin_ManagmentUsers FROM Admin WHERE User_Id = ?");
        $adminStmt->execute([$_SESSION['user_id']]);
        $adminData = $adminStmt->fetch();
        $canManageUsers = ($adminData && $adminData['Admin_ManagmentUsers'] == 1);
    } catch (PDOException $e) {
        die("Ошибка при проверке прав администратора: " . $e->getMessage());
    }
}

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF-токена
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Неверный CSRF-токен';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Обработка разных действий
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update':
                handleUpdateUser($pdo, $canManageUsers);
                break;
            case 'delete':
                handleDeleteUser($pdo, $canManageUsers);
                break;
            case 'add_parent':
            case 'update_parent':
                handleParent($pdo, $canManageUsers, $_POST['action']);
                break;
            case 'delete_parent':
                handleDeleteParent($pdo, $canManageUsers);
                break;
            case 'generate_password':
                $_SESSION['generated_password'] = generatePassword(12);
                break;
            case 'get_parents':
                if (isset($_POST['student_id'])) {
                    $studentId = (int)$_POST['student_id'];
                    $stmt = $pdo->prepare("SELECT Student_Id FROM Student WHERE User_Id = ?");
                    $stmt->execute([$studentId]);
                    $student = $stmt->fetch();

                    if ($student) {
                        $parents = getStudentParents($pdo, $student['Student_Id']);
                        echo json_encode(['success' => true, 'parents' => $parents]);
                        exit;
                    }
                }
                echo json_encode(['success' => false, 'message' => 'Студент не найден']);
                exit;
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Функция генерации пароля
function generatePassword($length = 12)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+~';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// Обработчики действий
function handleUpdateUser($pdo, $canManageUsers)
{
    if (!$canManageUsers) {
        $_SESSION['error'] = 'У вас нет прав для редактирования пользователей';
        return;
    }

    try {
        $userId = (int)$_POST['id'];
        $roleId = (int)$_POST['role_id'];

        $updateData = [
            'User_Surname' => $_POST['surname'],
            'User_Name' => $_POST['name'],
            'User_Patronymic' => $_POST['patronymic'],
            'User_Login' => $_POST['login'],
            'User_Email' => $_POST['email'],
            'User_PhoneNumber' => $_POST['phone'],
            'User_Id' => $userId
        ];

        $sql = "UPDATE User SET 
                User_Surname = :User_Surname,
                User_Name = :User_Name,
                User_Patronymic = :User_Patronymic,
                User_Login = :User_Login,
                User_Email = :User_Email,
                User_PhoneNumber = :User_PhoneNumber";

        if (!empty($_POST['password'])) {
            $updateData['User_Password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $sql .= ", User_Password = :User_Password";
        }

        $sql .= " WHERE User_Id = :User_Id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($updateData);

        if ($roleId == 3 && isset($_POST['birthday'])) {
            $checkStmt = $pdo->prepare("SELECT * FROM Student WHERE User_Id = ?");
            $checkStmt->execute([$userId]);

            if ($checkStmt->fetch()) {
                $stmt = $pdo->prepare("UPDATE Student SET Student_Birthday = ? WHERE User_Id = ?");
            } else {
                $stmt = $pdo->prepare("INSERT INTO Student (User_Id, Student_Birthday) VALUES (?, ?)");
            }
            $stmt->execute([$_POST['birthday'], $userId]);
        }

        $_SESSION['success'] = 'Пользователь успешно обновлен';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка базы данных: ' . $e->getMessage();
    }
}

function handleDeleteUser($pdo, $canManageUsers)
{
    if (!$canManageUsers) {
        $_SESSION['error'] = 'У вас нет прав для удаления пользователей';
        return;
    }

    try {
        $userId = (int)$_POST['id'];

        // Начинаем транзакцию
        $pdo->beginTransaction();

        // Удаляем связи с родителями
        $stmt = $pdo->prepare("SELECT Student_Id FROM Student WHERE User_Id = ?");
        $stmt->execute([$userId]);
        $student = $stmt->fetch();

        if ($student) {
            $studentId = $student['Student_Id'];
            $pdo->prepare("DELETE FROM student_parents WHERE Student_id = ?")->execute([$studentId]);
        }

        // Удаляем запись администратора, если есть
        $pdo->prepare("DELETE FROM Admin WHERE User_Id = ?")->execute([$userId]);

        // Удаляем запись студента, если есть
        $pdo->prepare("DELETE FROM Student WHERE User_Id = ?")->execute([$userId]);

        // Удаляем пользователя
        $pdo->prepare("DELETE FROM User WHERE User_Id = ?")->execute([$userId]);

        // Подтверждаем транзакцию
        $pdo->commit();

        $_SESSION['success'] = 'Пользователь успешно удален';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Ошибка базы данных: ' . $e->getMessage();
    }
}

function handleParent($pdo, $canManageUsers, $action)
{
    if (!$canManageUsers) {
        $_SESSION['error'] = 'У вас нет прав для управления родителями';
        return;
    }

    try {
        $studentId = (int)$_POST['student_id'];
        $stmt = $pdo->prepare("SELECT Student_Id FROM Student WHERE User_Id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            $_SESSION['error'] = 'Студент не найден';
            return;
        }

        $studentId = $student['Student_Id'];

        if ($action === 'add_parent') {
            $stmt = $pdo->prepare("
                INSERT INTO Parents (Parents_Surname, Parents_Name, Parents_Patronymic, Parents_PhoneNumber, Parents_Email)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['surname'],
                $_POST['name'],
                $_POST['patronymic'],
                $_POST['phone'],
                $_POST['email']
            ]);

            $parentId = $pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO student_parents (Student_id, Parents_Id) VALUES (?, ?)");
            $stmt->execute([$studentId, $parentId]);
        } else {
            $parentId = (int)$_POST['parent_id'];
            $stmt = $pdo->prepare("
                UPDATE Parents SET 
                Parents_Surname = ?,
                Parents_Name = ?,
                Parents_Patronymic = ?,
                Parents_PhoneNumber = ?,
                Parents_Email = ?
                WHERE Parents_Id = ?
            ");
            $stmt->execute([
                $_POST['surname'],
                $_POST['name'],
                $_POST['patronymic'],
                $_POST['phone'],
                $_POST['email'],
                $parentId
            ]);
        }

        $_SESSION['success'] = 'Данные родителя успешно сохранены';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка базы данных: ' . $e->getMessage();
    }
}

function handleDeleteParent($pdo, $canManageUsers)
{
    if (!$canManageUsers) {
        $_SESSION['error'] = 'У вас нет прав для удаления родителей';
        return;
    }

    try {
        $parentId = (int)$_POST['parent_id'];
        $studentId = (int)$_POST['student_id'];

        $stmt = $pdo->prepare("SELECT Student_Id FROM Student WHERE User_Id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            $_SESSION['error'] = 'Студент не найден';
            return;
        }

        $studentId = $student['Student_Id'];
        $stmt = $pdo->prepare("DELETE FROM student_parents WHERE Student_id = ? AND Parents_Id = ?");
        $stmt->execute([$studentId, $parentId]);

        $stmt = $pdo->prepare("
            DELETE FROM Parents 
            WHERE Parents_id = ? 
            AND NOT EXISTS (SELECT 1 FROM student_parents WHERE Parents_Id = ?)
        ");
        $stmt->execute([$parentId, $parentId]);

        $_SESSION['success'] = 'Родитель успешно удален';
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка базы данных: ' . $e->getMessage();
    }
}

// Генерация CSRF-токена
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Получение всех пользователей
try {
    $usersStmt = $pdo->query("
        SELECT u.*, s.Student_Id, s.Student_Birthday 
        FROM User u
        LEFT JOIN Student s ON u.User_Id = s.User_Id
        ORDER BY u.Role_Id, u.User_Surname, u.User_Name
    ");
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Ошибка при загрузке пользователей: " . $e->getMessage());
}

// Группировка пользователей
$groupedUsers = [
    1 => [], // Администраторы
    2 => [], // Преподаватели
    3 => []  // Студенты
];

foreach ($users as $user) {
    $groupedUsers[$user['Role_Id']][] = $user;
}

// Функция для получения родителей студента
function getStudentParents($pdo, $studentId)
{
    try {
        $stmt = $pdo->prepare("
            SELECT p.* FROM Parents p
            JOIN student_parents sp ON p.Parents_Id = sp.Parents_Id
            WHERE sp.Student_id = ?
        ");
        $stmt->execute([$studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ошибка при получении родителей: " . $e->getMessage());
        return [];
    }
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Список пользователей</title>
    <link rel="stylesheet" href="../../css/style_Admin/section/infoUser.css">
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 0% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 50%;
            max-width: 600px;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }

        .error {
            color: red;
            margin: 10px 0;
        }

        .success {
            color: green;
            margin: 10px 0;
        }

        .password-container {
            display: flex;
            align-items: center;
        }

        .password-container input {
            flex-grow: 1;
            margin-right: 10px;
        }

        .toggle-password {
            cursor: pointer;
        }

        .show-parents-btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            margin: 10px 0;
        }

        .show-parents-btn:hover {
            background: #45a049;
        }

        .parents-container {
            margin-top: 10px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
        }

        .parent-info {
            margin-bottom: 15px;
            padding: 10px;
            border: 1px solid #eee;
            border-radius: 4px;
            background: white;
        }

        .parent-actions {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #ddd;
        }

        .parent-actions a,
        .parent-actions button {
            margin-right: 10px;
        }

        .no-parents {
            color: #666;
            font-style: italic;
        }
    </style>
</head>

<body>
    <?php include '../components/header.php'; ?>
    <div class="container">
        <h2>Список пользователей</h2>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="error"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <input type="text" id="searchInput" placeholder="Поиск по фамилии, имени или email" onkeyup="filterUsers()">

        <div id="users-container">
            <?php
            $roles = [
                1 => "Администраторы",
                2 => "Преподаватели",
                3 => "Ученики"
            ];

            foreach ($roles as $roleId => $roleName) {
                echo "<h3>$roleName</h3>";

                if (empty($groupedUsers[$roleId])) {
                    echo "<p class='no-results'>Нет пользователей в этой категории</p>";
                    continue;
                }

                echo "<table class='user-table'>";
                echo "<tr>
                        <th>Фамилия</th>
                        <th>Имя</th>
                        <th>Отчество</th>
                        <th>Логин</th>
                        <th>Email</th>
                        <th>Телефон</th>
                        <th>Время активности</th>
                        <th>Действия</th>
                      </tr>";

                foreach ($groupedUsers[$roleId] as $user) {
                    echo "<tr class='user-row' data-name='{$user['User_Surname']} {$user['User_Name']} {$user['User_Patronymic']}' data-email='{$user['User_Email']}'>";
                    echo "<td>{$user['User_Surname']}</td>";
                    echo "<td>{$user['User_Name']}</td>";
                    echo "<td>{$user['User_Patronymic']}</td>";
                    echo "<td>{$user['User_Login']}</td>";
                    echo "<td>{$user['User_Email']}</td>";
                    echo "<td>{$user['User_PhoneNumber']}</td>";
                    echo "<td>" . (!empty($user['User_Entrydate'])
                        ? date("d.m.Y H:i:s", strtotime($user['User_Entrydate']))
                        : 'Не активен')
                        . "</td>";
                    echo "<td>";
                    if ($canManageUsers) {
                        echo "<a href='?edit_user={$user['User_Id']}' class='edit-btn'>✏️</a> ";
                        echo "<form method='POST' style='display:inline;'>
                                <input type='hidden' name='action' value='delete'>
                                <input type='hidden' name='id' value='{$user['User_Id']}'>
                                <input type='hidden' name='csrf_token' value='{$_SESSION['csrf_token']}'>
                                <button type='submit' class='delete-btn' onclick='return confirm(\"Вы уверены?\")'>❌</button>
                              </form>";
                    } else {
                        echo "<span>Нет прав</span>";
                    }
                    echo "</td>";
                    echo "</tr>";
                    if ($roleId == 3 && isset($user['Student_Id'])) {
                        echo "<tr><td colspan='8'>";
                        echo "<div class='student-details'>";

                        // Добавляем информацию о дате рождения
                        if (!empty($user['Student_Birthday'])) {
                            $birthday = date('d.m.Y', strtotime($user['Student_Birthday']));
                            echo "<p><strong>Дата рождения:</strong> {$birthday}</p>";
                        }

                        // Получаем информацию о родителях
                        $parents = getStudentParents($pdo, $user['Student_Id']);

                        // Кнопка для показа родителей
                        echo "<button class='show-parents-btn' onclick='toggleParents(this)'>Показать родителей</button>";

                        // Контейнер для информации о родителях
                        echo "<div class='parents-container' style='display:none;'>";

                        if (!empty($parents)) {
                            echo "<div class='parents-list'>";
                            foreach ($parents as $parent) {
                                echo "<div class='parent-info'>";
                                echo "<p><strong>ФИО:</strong> {$parent['Parents_Surname']} {$parent['Parents_Name']} {$parent['Parents_Patronymic']}</p>";
                                echo "<p><strong>Телефон:</strong> {$parent['Parents_PhoneNumber']}</p>";
                                echo "<p><strong>Email:</strong> {$parent['Parents_Email']}</p>";

                                if ($canManageUsers) {
                                    echo "<div class='parent-actions'>";
                                    echo "<a href='?edit_parent={$parent['Parents_Id']}&student_id={$user['User_Id']}' class='edit-btn'>✏️ Редактировать</a> ";
                                    echo "<form method='POST' style='display:inline;'>";
                                    echo "<input type='hidden' name='action' value='delete_parent'>";
                                    echo "<input type='hidden' name='parent_id' value='{$parent['Parents_Id']}'>";
                                    echo "<input type='hidden' name='student_id' value='{$user['User_Id']}'>";
                                    echo "<input type='hidden' name='csrf_token' value='{$_SESSION['csrf_token']}'>";
                                    echo "<button type='submit' class='delete-btn' onclick='return confirm(\"Вы уверены?\")'>❌ Удалить</button>";
                                    echo "</form>";
                                    echo "</div>";
                                }
                                echo "</div>";
                            }
                            echo "</div>";
                        } else {
                            echo "<p class='no-parents'>Информация о родителях отсутствует</p>";
                        }

                        if ($canManageUsers) {
                            echo "<a href='?add_parent={$user['User_Id']}' class='btn btn-primary'>Добавить родителя</a>";
                        }

                        echo "</div>"; // Закрываем parents-container
                        echo "</div>"; // Закрываем student-details
                        echo "</td></tr>";
                    }
                }
                echo "</table>";
            }
            ?>
        </div>
    </div>

    <!-- Модальное окно для редактирования пользователя -->
    <?php if (isset($_GET['edit_user'])): ?>
        <?php
        $userId = (int)$_GET['edit_user'];
        $stmt = $pdo->prepare("SELECT * FROM User WHERE User_Id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $roleId = $user['Role_Id'];
        $birthday = '';

        if ($roleId == 3) {
            $stmt = $pdo->prepare("SELECT Student_Birthday FROM Student WHERE User_Id = ?");
            $stmt->execute([$userId]);
            $student = $stmt->fetch();
            $birthday = $student ? $student['Student_Birthday'] : '';
        }
        ?>
        <div id="editModal" class="modal" style="display:block;">
            <div class="modal-content">
                <span class="close" onclick="window.location.href='?'">&times;</span>
                <h3>Редактировать пользователя</h3>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= $user['User_Id'] ?>">
                    <input type="hidden" name="role_id" value="<?= $roleId ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="form-group">
                        <label>Фамилия:</label>
                        <input type="text" name="surname" value="<?= htmlspecialchars($user['User_Surname']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Имя:</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($user['User_Name']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Отчество:</label>
                        <input type="text" name="patronymic" value="<?= htmlspecialchars($user['User_Patronymic']) ?>">
                    </div>

                    <div class="form-group">
                        <label>Логин:</label>
                        <input type="text" name="login" value="<?= htmlspecialchars($user['User_Login']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Новый пароль (оставьте пустым, чтобы не менять):</label>
                        <div class="password-container">
                            <input type="password" name="password" id="editPassword" value="<?= isset($_SESSION['generated_password']) ? $_SESSION['generated_password'] : '' ?>">
                            <span class="toggle-password" onclick="togglePasswordVisibility('editPassword')">Показать пароль</span>
                        </div>
                        <button type="button" onclick="generatePassword()">Сгенерировать пароль</button>
                    </div>

                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($user['User_Email']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Телефон:</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($user['User_PhoneNumber']) ?>">
                    </div>

                    <?php if ($roleId == 3): ?>
                        <div class="form-group">
                            <label>Дата рождения:</label>
                            <input type="date" name="birthday" value="<?= $birthday ?>">
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary">Сохранить</button>
                </form>
            </div>
        </div>
        <?php unset($_SESSION['generated_password']); ?>
    <?php endif; ?>

    <!-- Модальное окно для добавления/редактирования родителя -->
    <?php if (isset($_GET['add_parent']) || isset($_GET['edit_parent'])): ?>
        <?php
        $studentId = (int)($_GET['add_parent'] ?? $_GET['edit_parent']);
        $parentId = isset($_GET['edit_parent']) ? (int)$_GET['edit_parent'] : 0;
        $parent = [];

        if ($parentId) {
            $stmt = $pdo->prepare("SELECT * FROM Parents WHERE Parents_id = ?");
            $stmt->execute([$parentId]);
            $parent = $stmt->fetch();
        }
        ?>
        <div id="parentModal" class="modal" style="display:block;">
            <div class="modal-content">
                <span class="close" onclick="window.location.href='?'">&times;</span>
                <h3><?= $parentId ? 'Редактировать' : 'Добавить' ?> родителя</h3>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="<?= $parentId ? 'update_parent' : 'add_parent' ?>">
                    <input type="hidden" name="student_id" value="<?= $studentId ?>">
                    <?php if ($parentId): ?>
                        <input type="hidden" name="parent_id" value="<?= $parentId ?>">
                    <?php endif; ?>
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="form-group">
                        <label>Фамилия:</label>
                        <input type="text" name="surname" value="<?= htmlspecialchars($parent['Parents_Surname'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Имя:</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($parent['Parents_Name'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Отчество:</label>
                        <input type="text" name="patronymic" value="<?= htmlspecialchars($parent['Parents_Patronymic'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Телефон:</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($parent['Parents_PhoneNumber'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($parent['Parents_Email'] ?? '') ?>" required>
                    </div>

                    <button type="submit" class="btn btn-primary">Сохранить</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script>
        // Функция для фильтрации пользователей
        function filterUsers() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toUpperCase();
            const rows = document.querySelectorAll('.user-row');

            rows.forEach(row => {
                const name = row.getAttribute('data-name').toUpperCase();
                const email = row.getAttribute('data-email').toUpperCase();

                if (name.includes(filter) || email.includes(filter)) {
                    row.style.display = '';
                    const nextRow = row.nextElementSibling;
                    if (nextRow && nextRow.querySelector('.student-details')) {
                        nextRow.style.display = '';
                    }
                } else {
                    row.style.display = 'none';
                    const nextRow = row.nextElementSibling;
                    if (nextRow && nextRow.querySelector('.student-details')) {
                        nextRow.style.display = 'none';
                    }
                }
            });
        }

        // Функция для переключения видимости пароля
        function togglePasswordVisibility(fieldId) {
            const field = document.getElementById(fieldId);
            field.type = field.type === 'password' ? 'text' : 'password';
        }

        function toggleParents(button) {
            const container = button.nextElementSibling;
            if (container.style.display === 'none') {
                container.style.display = 'block';
                button.textContent = 'Скрыть родителей';
            } else {
                container.style.display = 'none';
                button.textContent = 'Показать родителей';
            }
        }

        // Функция для загрузки информации о родителях
        function loadParents(userId, button) {
            const container = button.nextElementSibling;

            if (container.style.display === 'none') {
                // Показываем индикатор загрузки
                container.innerHTML = '<p>Загрузка данных...</p>';
                container.style.display = 'block';

                // Отправляем запрос на сервер
                fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'get_parents',
                            student_id: userId,
                            csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.parents.length > 0) {
                                let html = '<div class="parents-list">';
                                data.parents.forEach(parent => {
                                    html += `
                                    <div class="parent-info">
                                        <p><strong>ФИО:</strong> ${parent.Parents_Surname} ${parent.Parents_Name} ${parent.Parents_Patronymic || ''}</p>
                                        <p><strong>Телефон:</strong> ${parent.Parents_PhoneNumber}</p>
                                        <p><strong>Email:</strong> ${parent.Parents_Email}</p>
                                        <?php if ($canManageUsers): ?>
                                        <div class="parent-actions">
                                            <a href="?edit_parent=${parent.Parents_id}&student_id=${userId}" class="edit-btn">✏️ Редактировать</a>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_parent">
                                                <input type="hidden" name="parent_id" value="${parent.Parents_id}">
                                                <input type="hidden" name="student_id" value="${userId}">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <button type="submit" class="delete-btn" onclick="return confirm('Вы уверены?')">❌ Удалить</button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                `;
                                });
                                html += '</div>';

                                <?php if ($canManageUsers): ?>
                                    html += `<a href="?add_parent=${userId}" class="btn btn-primary">Добавить родителя</a>`;
                                <?php endif; ?>

                                container.innerHTML = html;
                            } else {
                                container.innerHTML = '<p class="no-parents">Информация о родителях отсутствует</p>';
                                <?php if ($canManageUsers): ?>
                                    container.innerHTML += `<a href="?add_parent=${userId}" class="btn btn-primary">Добавить родителя</a>`;
                                <?php endif; ?>
                            }
                            button.textContent = 'Скрыть родителей';
                        } else {
                            container.innerHTML = '<p class="error">' + data.message + '</p>';
                        }
                    })
                    .catch(error => {
                        container.innerHTML = '<p class="error">Ошибка при загрузке данных</p>';
                        console.error('Error:', error);
                    });
            } else {
                container.style.display = 'none';
                button.textContent = 'Показать родителей';
            }
        }

        // Функция генерации пароля (клиентская)
        function generatePassword() {
            const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+~";
            let password = "";
            for (let i = 0; i < 12; i++) {
                password += charset.charAt(Math.floor(Math.random() * charset.length));
            }
            document.getElementById('editPassword').value = password;
        }
    </script>
</body>

</html>