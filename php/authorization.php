<?php
session_start();
require 'db_config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $login = trim($_POST['login']);
        $password = trim($_POST['password']);

        if (empty($login) || empty($password)) {
            header('Location: ../index.php?error=empty_fields');
            exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM User WHERE User_Login = :login");
        $stmt->execute(['login' => $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (password_verify($password, $user['User_Password'])) {
                // Простое обновление времени (MySQL автоматически конвертирует в UTC)
                $updateStmt = $pdo->prepare("UPDATE User SET User_Entrydate = CURRENT_TIMESTAMP WHERE User_Id = :user_id");
                $updateStmt->execute(['user_id' => $user['User_Id']]);

                // Сессионные переменные
                $_SESSION = [
                    'user_id' => $user['User_Id'],
                    'user_name' => $user['User_Name'],
                    'user_surname' => $user['User_Surname'],
                    'user_patronymic' => $user['User_Patronymic'] ?? '',
                    'role_id' => $user['Role_Id'],
                    'last_login' => date('d.m.Y H:i:s') // Локальное время для отображения
                ];

                // Перенаправление по роли
                $roles = [
                    1 => 'Admin/main_page.php',
                    2 => 'Instructor/main_page.php',
                    3 => 'Student/main_page.php'
                ];
                header('Location: ' . ($roles[$user['Role_Id']] ?? '../index.php?error=unknown_role'));
                exit();
            }
            header('Location: ../index.php?error=invalid_password');
            exit();
        }
        header('Location: ../index.php?error=user_not_found');
        exit();
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    header('Location: ../index.php?error=db_error');
    exit();
}
