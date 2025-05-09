<?php
session_start();
require '../db_config.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Обработка обновления профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        $data = [
            'surname' => trim($_POST['surname']),
            'name' => trim($_POST['name']),
            'patronymic' => trim($_POST['patronymic']),
            'email' => trim($_POST['email']),
            'phone' => trim($_POST['phone']),
            'login' => trim($_POST['login']),
            'user_id' => $user_id
        ];

        // Обработка загрузки фото
        if (!empty($_FILES['profile_photo']['name'])) {
            $uploadDir = '../../uploads/profile_photos/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileExt = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
            $photoName = 'user_' . $user_id . '_' . time() . '.' . $fileExt;
            $uploadFile = $uploadDir . $photoName;

            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadFile)) {
                // Удаляем старое фото
                $stmt = $pdo->prepare("SELECT Profile_Photo FROM User WHERE User_Id = ?");
                $stmt->execute([$user_id]);
                $oldPhoto = $stmt->fetchColumn();

                if ($oldPhoto && file_exists($uploadDir . $oldPhoto)) {
                    unlink($uploadDir . $oldPhoto);
                }

                $data['photo'] = $photoName;
            }
        }

        $sql = "UPDATE User SET 
                User_Surname = :surname,
                User_Name = :name,
                User_Patronymic = :patronymic,
                User_Email = :email,
                User_PhoneNumber = :phone,
                User_Login = :login";

        if (isset($data['photo'])) {
            $sql .= ", Profile_Photo = :photo";
        }

        $sql .= " WHERE User_Id = :user_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);

        $success = "Профиль успешно обновлен!";
    } catch (Exception $e) {
        $error = "Ошибка: " . $e->getMessage();
    }
}

// Обработка смены пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = trim($_POST['current_password']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    try {
        // Проверка совпадения новых паролей
        if ($new_password !== $confirm_password) {
            throw new Exception('Новые пароли не совпадают');
        }

        // Проверка сложности пароля
        if (strlen($new_password) < 8) {
            throw new Exception('Пароль должен содержать минимум 8 символов');
        }

        if (!preg_match('/[A-ZА-Я]/u', $new_password)) {
            throw new Exception('Пароль должен содержать хотя бы одну заглавную букву');
        }

        if (!preg_match('/[0-9]/', $new_password)) {
            throw new Exception('Пароль должен содержать хотя бы одну цифру');
        }

        if (!preg_match('/[^A-Za-z0-9]/', $new_password)) {
            throw new Exception('Пароль должен содержать хотя бы один специальный символ');
        }

        // Получаем текущий хэш пароля
        $stmt = $pdo->prepare("SELECT User_Password FROM User WHERE User_Id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception('Пользователь не найден');
        }

        // Проверяем текущий пароль
        if (!password_verify($current_password, $user['User_Password'])) {
            throw new Exception('Текущий пароль неверен');
        }

        // Хэшируем и сохраняем новый пароль
        $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE User SET User_Password = ? WHERE User_Id = ?");
        $stmt->execute([$new_hashed_password, $user_id]);

        $success = "Пароль успешно изменен!";
    } catch (Exception $e) {
        $error = "Ошибка: " . $e->getMessage();
    }
}

// Получение данных пользователя
try {
    $stmt = $pdo->prepare("SELECT * FROM User WHERE User_Id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Пользователь не найден");
    }

    $initials = mb_substr($user['User_Surname'], 0, 1, 'UTF-8') .
        mb_substr($user['User_Name'], 0, 1, 'UTF-8');

    $avatarPath = !empty($user['Profile_Photo']) ?
        '../../uploads/profile_photos/' . $user['Profile_Photo'] : '';
} catch (Exception $e) {
    $error = "Ошибка: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки профиля</title>
    <link rel="stylesheet" href="../../css/basic.css">
    <style>
        .settings-container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 30px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .avatar-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 20px;
        }

        .avatar-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            margin-bottom: 15px;
            border: 3px solid #3498db;
        }

        .avatar-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-preview .initials {
            font-size: 36px;
            color: #555;
            font-weight: bold;
        }

        .avatar-upload label {
            background-color: #3498db;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .avatar-upload label:hover {
            background-color: #2980b9;
        }

        .avatar-upload input[type="file"] {
            display: none;
        }

        .form-group {
            margin-bottom: 15px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }

        .btn-primary {
            background-color: #3498db;
            color: white;
        }

        .btn-warning {
            background-color: #f39c12;
            color: white;
            margin-top: 10px;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            width: 100%;
            max-width: 400px;
        }

        .error {
            color: #e74c3c;
            margin-bottom: 15px;
        }

        .success {
            color: #2ecc71;
            margin-bottom: 15px;
        }

        .password-requirements {
            margin: 10px 0;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
            font-size: 14px;
        }

        .password-requirements ul {
            margin: 5px 0 0 20px;
            padding: 0;
        }

        .password-requirements li {
            margin-bottom: 5px;
        }

        .requirement-met {
            color: #2ecc71;
        }

        .requirement-not-met {
            color: #e74c3c;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 35px;
            cursor: pointer;
            color: #3498db;
            font-size: 14px;
        }

        .password-strength {
            height: 5px;
            background: #eee;
            margin-top: 5px;
            border-radius: 3px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s;
        }

        .strength-0 {
            width: 20%;
            background: #e74c3c;
        }

        .strength-1 {
            width: 40%;
            background: #e67e22;
        }

        .strength-2 {
            width: 60%;
            background: #f1c40f;
        }

        .strength-3 {
            width: 80%;
            background: #2ecc71;
        }

        .strength-4 {
            width: 100%;
            background: #27ae60;
        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>

    <div class="settings-container">
        <h1>Редактирование профиля</h1>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="update_profile" value="1">

            <div class="avatar-container">
                <div class="avatar-preview" id="avatarPreview">
                    <?php if (!empty($avatarPath)): ?>
                        <img src="<?= htmlspecialchars($avatarPath) ?>" alt="Аватар" id="avatarImage">
                    <?php else: ?>
                        <div class="initials"><?= htmlspecialchars($initials) ?></div>
                    <?php endif; ?>
                </div>
                <div class="avatar-upload">
                    <label for="profile_photo">Выбрать фото</label>
                    <input type="file" id="profile_photo" name="profile_photo" accept="image/*">
                </div>
            </div>

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
                <label>Email:</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['User_Email']) ?>" required>
            </div>

            <div class="form-group">
                <label>Телефон:</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($user['User_PhoneNumber']) ?>">
            </div>

            <div class="form-group">
                <label>Логин:</label>
                <input type="text" name="login" value="<?= htmlspecialchars($user['User_Login']) ?>" required>
            </div>

            <button type="submit" class="btn btn-primary">Сохранить изменения</button>
            <button type="button" class="btn btn-warning" onclick="document.getElementById('passwordModal').style.display='flex'">
                Сменить пароль
            </button>
        </form>
    </div>

    <!-- Модальное окно смены пароля -->
    <div class="modal" id="passwordModal">
        <div class="modal-content">
            <h2>Смена пароля</h2>
            <form method="POST" id="passwordForm">
                <input type="hidden" name="change_password" value="1">

                <div class="form-group">
                    <label>Текущий пароль:</label>
                    <input type="password" name="current_password" id="current_password" required>
                    <span class="toggle-password" onclick="togglePassword('current_password')">Показать</span>
                </div>

                <div class="form-group">
                    <label>Новый пароль:</label>
                    <input type="password" name="new_password" id="new_password" required oninput="checkPasswordStrength()">
                    <span class="toggle-password" onclick="togglePassword('new_password')">Показать</span>
                    <div class="password-strength">
                        <div class="strength-bar" id="strengthBar"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Повторите новый пароль:</label>
                    <input type="password" name="confirm_password" id="confirm_password" required oninput="checkPasswordMatch()">
                    <span class="toggle-password" onclick="togglePassword('confirm_password')">Показать</span>
                    <div id="passwordMatch" style="font-size: 14px; margin-top: 5px;"></div>
                </div>

                <div class="password-requirements">
                    <strong>Требования к паролю:</strong>
                    <ul>
                        <li id="req-length">Минимум 8 символов</li>
                        <li id="req-upper">Хотя бы одна заглавная буква</li>
                        <li id="req-number">Хотя бы одна цифра</li>
                        <li id="req-special">Хотя бы один специальный символ</li>
                    </ul>
                </div>

                <button type="submit" class="btn btn-primary">Изменить пароль</button>
                <button type="button" class="btn" onclick="document.getElementById('passwordModal').style.display='none'">
                    Отмена
                </button>
            </form>
        </div>
    </div>

    <script>
        // Превью аватарки
        document.getElementById('profile_photo').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const avatarPreview = document.getElementById('avatarPreview');
                    avatarPreview.innerHTML = `<img src="${event.target.result}" alt="Предпросмотр">`;
                }
                reader.readAsDataURL(file);
            }
        });

        // Закрытие модалки по клику вне окна
        window.addEventListener('click', function(e) {
            if (e.target === document.getElementById('passwordModal')) {
                document.getElementById('passwordModal').style.display = 'none';
            }
        });

        // Показать/скрыть пароль
        function togglePassword(id) {
            const input = document.getElementById(id);
            const toggle = input.nextElementSibling;

            if (input.type === 'password') {
                input.type = 'text';
                toggle.textContent = 'Скрыть';
            } else {
                input.type = 'password';
                toggle.textContent = 'Показать';
            }
        }

        // Проверка сложности пароля
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthBar = document.getElementById('strengthBar');
            const reqLength = document.getElementById('req-length');
            const reqUpper = document.getElementById('req-upper');
            const reqNumber = document.getElementById('req-number');
            const reqSpecial = document.getElementById('req-special');

            let strength = 0;

            // Проверка длины
            if (password.length >= 8) {
                strength++;
                reqLength.classList.add('requirement-met');
                reqLength.classList.remove('requirement-not-met');
            } else {
                reqLength.classList.add('requirement-not-met');
                reqLength.classList.remove('requirement-met');
            }

            // Проверка заглавных букв
            if (/[A-ZА-Я]/.test(password)) {
                strength++;
                reqUpper.classList.add('requirement-met');
                reqUpper.classList.remove('requirement-not-met');
            } else {
                reqUpper.classList.add('requirement-not-met');
                reqUpper.classList.remove('requirement-met');
            }

            // Проверка цифр
            if (/[0-9]/.test(password)) {
                strength++;
                reqNumber.classList.add('requirement-met');
                reqNumber.classList.remove('requirement-not-met');
            } else {
                reqNumber.classList.add('requirement-not-met');
                reqNumber.classList.remove('requirement-met');
            }

            // Проверка специальных символов
            if (/[^A-Za-z0-9]/.test(password)) {
                strength++;
                reqSpecial.classList.add('requirement-met');
                reqSpecial.classList.remove('requirement-not-met');
            } else {
                reqSpecial.classList.add('requirement-not-met');
                reqSpecial.classList.remove('requirement-met');
            }

            // Обновление индикатора силы
            strengthBar.className = 'strength-bar';
            if (password.length > 0) {
                strengthBar.classList.add(`strength-${strength}`);
            }
        }

        // Проверка совпадения паролей
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchIndicator = document.getElementById('passwordMatch');

            if (confirmPassword.length === 0) {
                matchIndicator.textContent = '';
                matchIndicator.style.color = '';
            } else if (password === confirmPassword) {
                matchIndicator.textContent = 'Пароли совпадают';
                matchIndicator.style.color = '#2ecc71';
            } else {
                matchIndicator.textContent = 'Пароли не совпадают';
                matchIndicator.style.color = '#e74c3c';
            }
        }
    </script>
</body>

</html>