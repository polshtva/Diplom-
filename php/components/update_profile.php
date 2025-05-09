<?php
session_start();
require '../db_config.php';

$user_id = $_POST['user_id'];
$surname = $_POST['surname'];
$name = $_POST['name'];
$patronymic = $_POST['patronymic'];
$email = $_POST['email'];
$phone = $_POST['phone'];
$login = $_POST['login'];

try {
    // Проверка уникальности логина и email
    $stmt = $pdo->prepare("SELECT * FROM User WHERE (User_Login = :login OR User_Email = :email) AND User_Id != :user_id");
    $stmt->execute(['login' => $login, 'email' => $email, 'user_id' => $user_id]);

    if ($stmt->fetch()) {
        die("Пользователь с таким логином или email уже существует.");
    }

    // Загрузка изображения
    $profile_photo = null;
    $photo_type = null;

    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $profile_photo = file_get_contents($_FILES['profile_photo']['tmp_name']);
        $photo_type = $_FILES['profile_photo']['type'];
    }

    // Обновление данных пользователя
    $query = "UPDATE User SET 
        User_Surname = :surname,
        User_Name = :name,
        User_Patronymic = :patronymic,
        User_Email = :email,
        User_PhoneNumber = :phone,
        User_Login = :login";

    if ($profile_photo) {
        $query .= ", Profile_Photo = :photo, Profile_Photo_Type = :photo_type";
    }

    $query .= " WHERE User_Id = :user_id";

    $stmt = $pdo->prepare($query);
    $params = [
        'surname' => $surname,
        'name' => $name,
        'patronymic' => $patronymic,
        'email' => $email,
        'phone' => $phone,
        'login' => $login,
        'user_id' => $user_id
    ];

    if ($profile_photo) {
        $params['photo'] = $profile_photo;
        $params['photo_type'] = $photo_type;
    }

    $stmt->execute($params);

    header("Location: settings.php?success=1");
} catch (PDOException $e) {
    die("Ошибка при обновлении: " . htmlspecialchars($e->getMessage()));
}
