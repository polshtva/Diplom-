<?php
session_start();
require '../db_config.php';

// Включение отображения ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM User WHERE User_Id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$role = $user['Role_Id'];

// Обработка удаления группы
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_group'])) {
    $group_id = (int)$_POST['group_id'];

    // Проверяем, является ли пользователь создателем группы
    $stmt = $pdo->prepare("SELECT Created_By FROM Chat_Groups WHERE Group_Id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($group && ($group['Created_By'] == $user_id || $role == 1)) {
        // Удаляем группу и все связанные данные
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM Messages WHERE Group_Id = ?")->execute([$group_id]);
            $pdo->prepare("DELETE FROM Group_Members WHERE Group_Id = ?")->execute([$group_id]);
            $pdo->prepare("DELETE FROM Chat_Groups WHERE Group_Id = ?")->execute([$group_id]);
            $pdo->commit();
            header("Location: chat.php");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Ошибка при удалении группы: " . $e->getMessage();
        }
    } else {
        $error = "Вы не можете удалить эту группу";
    }
}

// Обработка отправки сообщения
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    // Перед вставкой в базу данных
    $message = mb_convert_encoding(trim($_POST['message']), 'UTF-8', 'UTF-8');

    // Или заменять эмодзи на текстовые аналоги

    $receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : null;
    $group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : null;

    // Для групповых сообщений устанавливаем receiver_id в NULL
    if ($group_id) {
        $receiver_id = null;
    }

    // Проверяем, есть ли текст сообщения или прикрепленный файл
    if (!empty($message) || (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK)) {
        $attachment_path = null;

        // Обработка загрузки файла
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = '../../uploads/chat_attachments/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            // Проверка размера файла (максимум 5MB)
            $max_file_size = 5 * 1024 * 1024;
            if ($_FILES['attachment']['size'] > $max_file_size) {
                die("Файл слишком большой. Максимальный размер: 5MB");
            }

            // Генерируем уникальное имя файла
            $file_ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
            $file_name = uniqid() . '.' . $file_ext;
            $target_path = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
                $attachment_path = $file_name;
            } else {
                die("Ошибка при загрузке файла");
            }
        }

        try {
            // Вставляем сообщение в базу данных
            $stmt = $pdo->prepare("
                INSERT INTO Messages (Sender_Id, Receiver_Id, Group_Id, Message_Text, Attachment_Path, Is_Read, Created_At)
                VALUES (?, ?, ?, ?, ?, FALSE, NOW())
            ");
            $stmt->execute([$user_id, $receiver_id, $group_id, $message, $attachment_path]);

            // Перенаправление после успешной отправки
            $redirect_url = "chat.php?";
            if ($group_id) {
                $redirect_url .= "dialog=$group_id&group=1";
            } elseif ($receiver_id) {
                $redirect_url .= "dialog=$receiver_id";
            }
            header("Location: $redirect_url");
            exit();
        } catch (PDOException $e) {
            die("Ошибка при отправке сообщения: " . $e->getMessage());
        }
    }
}

// Обработка создания группы
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_group'])) {
    $group_name = trim($_POST['group_name']);
    $members = isset($_POST['group_members']) ? $_POST['group_members'] : [];

    if (!empty($group_name) && !empty($members)) {
        try {
            $pdo->beginTransaction();

            // Создаем группу
            $stmt = $pdo->prepare("INSERT INTO Chat_Groups (Group_Name, Created_By) VALUES (?, ?)");
            $stmt->execute([$group_name, $user_id]);
            $group_id = $pdo->lastInsertId();

            // Добавляем участников (включая создателя)
            $members[] = $user_id;
            $members = array_unique($members);

            $stmt = $pdo->prepare("INSERT INTO Group_Members (Group_Id, User_Id) VALUES (?, ?)");
            foreach ($members as $member_id) {
                $stmt->execute([$group_id, $member_id]);
            }

            $pdo->commit();
            header("Location: chat.php?dialog=$group_id&group=1");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Ошибка при создании группы: " . $e->getMessage());
        }
    }
}

// Получаем список диалогов
$dialogs = [];

// Личные сообщения
$stmt = $pdo->prepare("
    SELECT DISTINCT u.User_Id, u.User_Surname, u.User_Name, u.Profile_Photo, u.Role_Id,
           (SELECT COUNT(*) FROM Messages m WHERE m.Sender_Id = u.User_Id AND m.Receiver_Id = ? AND m.Is_Read = FALSE) AS Unread_Count
    FROM User u
    JOIN Messages m ON (m.Sender_Id = u.User_Id OR m.Receiver_Id = u.User_Id)
    WHERE (m.Sender_Id = ? OR m.Receiver_Id = ?) AND u.User_Id != ?
    ORDER BY (SELECT MAX(Created_At) FROM Messages WHERE (Sender_Id = u.User_Id AND Receiver_Id = ?) OR (Sender_Id = ? AND Receiver_Id = u.User_Id)) DESC
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
$personal_dialogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Групповые чаты
$stmt = $pdo->prepare("
    SELECT g.Group_Id, g.Group_Name, g.Created_By,
           (SELECT COUNT(*) FROM Messages m WHERE m.Group_Id = g.Group_Id AND m.Sender_Id != ? AND m.Is_Read = FALSE) AS Unread_Count
    FROM Chat_Groups g
    JOIN Group_Members gm ON g.Group_Id = gm.Group_Id
    WHERE gm.User_Id = ?
    ORDER BY (SELECT MAX(Created_At) FROM Messages WHERE Group_Id = g.Group_Id) DESC
");
$stmt->execute([$user_id, $user_id]);
$group_dialogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dialogs = array_merge($personal_dialogs, $group_dialogs);

// Текущий диалог
$current_dialog = null;
$current_messages = [];
$group_members = [];
$is_group_chat = false;

if (isset($_GET['dialog'])) {
    $dialog_id = (int)$_GET['dialog'];

    if (isset($_GET['group'])) {
        $is_group_chat = true;

        // Получаем информацию о группе
        $stmt = $pdo->prepare("
            SELECT g.* FROM Chat_Groups g
            JOIN Group_Members gm ON g.Group_Id = gm.Group_Id
            WHERE g.Group_Id = ? AND gm.User_Id = ?
        ");
        $stmt->execute([$dialog_id, $user_id]);
        $current_dialog = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($current_dialog) {
            // Получаем участников группы
            $stmt = $pdo->prepare("
                SELECT u.User_Id, u.User_Surname, u.User_Name, u.Profile_Photo, u.Role_Id
                FROM User u
                JOIN Group_Members gm ON u.User_Id = gm.User_Id
                WHERE gm.Group_Id = ?
                ORDER BY u.User_Surname, u.User_Name
            ");
            $stmt->execute([$dialog_id]);
            $group_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Получаем сообщения группы
            $stmt = $pdo->prepare("
                SELECT m.*, u.User_Surname, u.User_Name, u.Profile_Photo
                FROM Messages m
                JOIN User u ON m.Sender_Id = u.User_Id
                WHERE m.Group_Id = ?
                ORDER BY m.Created_At ASC
            ");
            $stmt->execute([$dialog_id]);
            $current_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Помечаем сообщения как прочитанные
            $pdo->prepare("
                UPDATE Messages SET Is_Read = TRUE 
                WHERE Group_Id = ? AND Sender_Id != ? AND Is_Read = FALSE
            ")->execute([$dialog_id, $user_id]);
        }
    } else {
        // Личный чат
        $stmt = $pdo->prepare("
            SELECT u.User_Id, u.User_Surname, u.User_Name, u.Profile_Photo, u.Role_Id
            FROM User u
            WHERE u.User_Id = ?
        ");
        $stmt->execute([$dialog_id]);
        $current_dialog = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($current_dialog) {
            // Проверяем, может ли текущий пользователь писать этому пользователю
            $can_message = false;

            // Админ может писать всем
            if ($role == 1) {
                $can_message = true;
            }
            // Преподаватель может писать студентам, другим преподавателям и админам
            elseif ($role == 2 && ($current_dialog['Role_Id'] == 1 || $current_dialog['Role_Id'] == 2 || $current_dialog['Role_Id'] == 3)) {
                $can_message = true;
            }
            // Студент может писать преподавателям и другим студентам
            elseif ($role == 3 && ($current_dialog['Role_Id'] == 2 || $current_dialog['Role_Id'] == 3)) {
                $can_message = true;
            }

            if ($can_message) {
                // Получаем сообщения
                $stmt = $pdo->prepare("
                    SELECT m.*, u.User_Surname, u.User_Name, u.Profile_Photo
                    FROM Messages m
                    JOIN User u ON m.Sender_Id = u.User_Id
                    WHERE (m.Sender_Id = ? AND m.Receiver_Id = ?) OR (m.Sender_Id = ? AND m.Receiver_Id = ?)
                    ORDER BY m.Created_At ASC
                ");
                $stmt->execute([$user_id, $dialog_id, $dialog_id, $user_id]);
                $current_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Помечаем сообщения как прочитанные
                $pdo->prepare("
                    UPDATE Messages SET Is_Read = TRUE 
                    WHERE Sender_Id = ? AND Receiver_Id = ? AND Is_Read = FALSE
                ")->execute([$dialog_id, $user_id]);
            } else {
                $current_dialog = null; // Запрещаем доступ к чату
            }
        }
    }
}

// Получаем список всех пользователей для создания чатов
$all_users = [];
$query = "SELECT User_Id, User_Surname, User_Name, Role_Id FROM User WHERE User_Id != ? ORDER BY User_Surname, User_Name";

// Для админа - все пользователи
if ($role == 1) {
    $stmt = $pdo->prepare($query);
    $stmt->execute([$user_id]);
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Для преподавателя - студенты, другие преподаватели и админы
elseif ($role == 2) {
    $query .= " AND Role_Id IN (1, 2, 3)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$user_id]);
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Для студента - преподаватели и другие студенты
elseif ($role == 3) {
    $query .= " AND Role_Id IN (2, 3)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$user_id]);
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Проверяем, можно ли писать в текущий диалог
$can_send_message = false;
if (isset($current_dialog)) {
    if ($is_group_chat) {
        $can_send_message = true;
    } else {
        if ($role == 1) {
            $can_send_message = true;
        } elseif ($role == 2 && ($current_dialog['Role_Id'] == 1 || $current_dialog['Role_Id'] == 2 || $current_dialog['Role_Id'] == 3)) {
            $can_send_message = true;
        } elseif ($role == 3 && ($current_dialog['Role_Id'] == 2 || $current_dialog['Role_Id'] == 3)) {
            $can_send_message = true;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Образовательная платформа - Чат</title>
    <link rel="stylesheet" href="../../css/sections/chat.css">
    <style>
        .toggle-members-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            margin: 10px 0;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .toggle-members-btn svg {
            width: 16px;
            height: 16px;
        }

        .group-members {
            padding: 10px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            margin-bottom: 10px;
        }

        .group-members.visible {
            max-height: 500px;
        }

        .members-count-badge {
            background: white;
            color: var(--primary-color);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-left: 5px;
        }

        .member-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .member-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--bg-white);
            border-radius: 20px;
            font-size: 14px;
        }

        .member-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-dark);
            font-weight: bold;
            background-size: cover;
            background-position: center;
        }

        .member-role {
            font-size: 12px;
            color: var(--text-light);
        }

        .group-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        /* Стили для предпросмотра изображений */
        .attachment-preview {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .attachment-item {
            position: relative;
            width: 100px;
            height: 100px;
            border-radius: 5px;
            overflow: hidden;
        }

        .attachment-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .remove-attachment {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        /* Стили для смайликов */
        .emoji-picker-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 20px;
            margin-right: 10px;
        }

        .emoji-picker {
            position: absolute;
            bottom: 60px;
            left: 10px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            width: 300px;
            height: 200px;
            overflow-y: auto;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: none;
            z-index: 1000;
        }

        .emoji-picker.visible {
            display: block;
        }

        .emoji-item {
            display: inline-block;
            font-size: 24px;
            padding: 5px;
            cursor: pointer;
        }

        .emoji-item:hover {
            transform: scale(1.2);
        }


        .delete-group-btn {
            background: #ff4d4d;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
        }

        .member-item.clickable {
            cursor: pointer;
            transition: background 0.2s;
        }

        .member-item.clickable:hover {
            background: var(--primary-light);
        }

        .user-role-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
            background: var(--primary-light);
            color: var(--primary-dark);
            margin-left: 5px;
        }

        .chat-input {
            display: flex;
            flex-direction: column;
            padding: 15px;
            background: var(--bg-white);
            border-top: 1px solid var(--border-color);
        }

        .chat-input-wrapper {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .chat-input textarea {
            width: 100%;
            min-height: 50px;
            max-height: 150px;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            resize: none;
            font-family: inherit;
            font-size: 14px;
            box-sizing: border-box;
            /* Важно для правильного расчета ширины */
        }

        .chat-input-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Остальные стили остаются без изменений */
        .attachment-preview {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .emoji-picker-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 20px;
            padding: 5px;
        }

        /* Адаптация для мобильных устройств */
        @media (max-width: 768px) {
            .chat-input {
                padding: 10px;
            }

            .chat-input textarea {
                min-height: 40px;
                padding: 10px 12px;
            }

        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>

    <div class="chat-container">
        <!-- Сайдбар с диалогами -->
        <div class="chat-sidebar">
            <div class="chat-header">
                <span>Мои диалоги</span>
                <?php if ($role == 1 || $role == 2): ?>
                    <button id="createGroupBtn" style="background: none; border: none; color: white; cursor: pointer;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                    </button>
                <?php endif; ?>
            </div>

            <div class="chat-search">
                <input type="text" placeholder="Поиск диалогов..." id="searchDialogs">
            </div>

            <div class="dialog-list">
                <?php if (empty($dialogs)): ?>
                    <div style="padding: 20px; text-align: center; color: var(--text-light);">
                        У вас пока нет диалогов
                    </div>
                <?php else: ?>
                    <?php foreach ($dialogs as $dialog): ?>
                        <?php
                        $is_active = isset($current_dialog) &&
                            ((!$is_group_chat && isset($dialog['User_Id']) && $current_dialog['User_Id'] == $dialog['User_Id']) ||
                                ($is_group_chat && isset($dialog['Group_Id']) && $current_dialog['Group_Id'] == $dialog['Group_Id']));
                        $unread = isset($dialog['Unread_Count']) && $dialog['Unread_Count'] > 0;
                        ?>
                        <div class="dialog-item <?= $is_active ? 'active' : '' ?>"
                            onclick="location.href='<?=
                                                    isset($dialog['Group_Id']) ?
                                                        "chat.php?dialog={$dialog['Group_Id']}&group=1" :
                                                        "chat.php?dialog={$dialog['User_Id']}"
                                                    ?>'">
                            <div class="dialog-avatar" style="<?=
                                                                isset($dialog['Profile_Photo']) && !empty($dialog['Profile_Photo']) ?
                                                                    "background-image: url('../../uploads/profile_photos/{$dialog['Profile_Photo']}')" : ''
                                                                ?>">
                                <?php if (!isset($dialog['Profile_Photo']) || empty($dialog['Profile_Photo'])): ?>
                                    <?= mb_substr($dialog['User_Surname'] ?? $dialog['Group_Name'], 0, 1, 'UTF-8') ?>
                                <?php endif; ?>
                            </div>
                            <div class="dialog-info">
                                <div class="dialog-name">
                                    <?= htmlspecialchars($dialog['User_Surname'] ?? $dialog['Group_Name']) ?>
                                    <?= isset($dialog['User_Name']) ? ' ' . htmlspecialchars($dialog['User_Name']) : '' ?>
                                    <?php if (isset($dialog['Role_Id'])): ?>
                                        <span class="user-role-badge">
                                            <?= $dialog['Role_Id'] == 1 ? 'Админ' : ($dialog['Role_Id'] == 2 ? 'Преподаватель' : 'Студент') ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="dialog-last-message">
                                    <?= isset($dialog['Group_Id']) ? 'Групповой чат' : 'Личный чат' ?>
                                </div>
                            </div>
                            <?php if ($unread): ?>
                                <div class="unread-count"><?= $dialog['Unread_Count'] ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Основная область чата -->
        <div class="chat-main">
            <?php if (isset($current_dialog)): ?>
                <div class="chat-header">
                    <?php if ($is_group_chat): ?>
                        <span><?= htmlspecialchars($current_dialog['Group_Name']) ?></span>
                    <?php else: ?>
                        <span><?= htmlspecialchars($current_dialog['User_Surname']) ?> <?= htmlspecialchars($current_dialog['User_Name']) ?>
                            <span class="user-role-badge">
                                <?= $current_dialog['Role_Id'] == 1 ? 'Админ' : ($current_dialog['Role_Id'] == 2 ? 'Преподаватель' : 'Студент') ?>
                            </span>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($is_group_chat): ?>
                    <button class="toggle-members-btn" id="toggleMembersBtn">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        Участники
                        <span class="members-count-badge" id="membersCountBadge">
                            <?= count($group_members) ?>
                        </span>
                    </button>

                    <div class="group-members" id="groupMembers">
                        <div class="member-list">
                            <?php foreach ($group_members as $member): ?>
                                <div class="member-item clickable" onclick="startPrivateChat(<?= $member['User_Id'] ?>)">
                                    <div class="member-avatar" style="<?= !empty($member['Profile_Photo']) ? "background-image: url('../../uploads/profile_photos/{$member['Profile_Photo']}')" : '' ?>">
                                        <?php if (empty($member['Profile_Photo'])): ?>
                                            <?= mb_substr($member['User_Surname'], 0, 1, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?= htmlspecialchars($member['User_Surname']) ?> <?= htmlspecialchars($member['User_Name']) ?>
                                        <div class="member-role">
                                            <?= $member['Role_Id'] == 1 ? 'Админ' : ($member['Role_Id'] == 2 ? 'Преподаватель' : 'Студент') ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (($current_dialog['Created_By'] == $user_id || $role == 1) && $is_group_chat): ?>
                            <div class="group-actions">
                                <form method="post" onsubmit="return confirmGroupDelete()">
                                    <input type="hidden" name="group_id" value="<?= $current_dialog['Group_Id'] ?>">
                                    <button type="submit" name="delete_group" class="delete-group-btn">Удалить группу</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="chat-messages" id="chatMessages">
                    <?php foreach ($current_messages as $message): ?>
                        <div class="message <?= $message['Sender_Id'] == $user_id ? 'my-message' : 'other-message' ?>">
                            <div class="message-header">
                                <?php if ($message['Sender_Id'] != $user_id): ?>
                                    <div class="message-avatar" style="<?=
                                                                        !empty($message['Profile_Photo']) ?
                                                                            "background-image: url('../../uploads/profile_photos/{$message['Profile_Photo']}')" : ''
                                                                        ?>">
                                        <?php if (empty($message['Profile_Photo'])): ?>
                                            <?= mb_substr($message['User_Surname'], 0, 1, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="message-sender">
                                        <?= htmlspecialchars($message['User_Surname']) ?> <?= htmlspecialchars($message['User_Name']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="message-time">
                                    <?= date('H:i', strtotime($message['Created_At'])) ?>
                                </div>
                            </div>

                            <?php if (!empty(trim($message['Message_Text']))): ?>
                                <div class="message-content">
                                    <?= nl2br(htmlspecialchars($message['Message_Text'])) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($message['Attachment_Path'])): ?>
                                <div class="message-attachment">
                                    <?php
                                    $ext = pathinfo($message['Attachment_Path'], PATHINFO_EXTENSION);
                                    $image_exts = ['jpg', 'jpeg', 'png', 'gif'];
                                    if (in_array(strtolower($ext), $image_exts)):
                                    ?>
                                        <img src="../../uploads/chat_attachments/<?= htmlspecialchars($message['Attachment_Path']) ?>" alt="Вложение">
                                    <?php else: ?>
                                        <a href="../../uploads/chat_attachments/<?= htmlspecialchars($message['Attachment_Path']) ?>" download>
                                            Скачать файл: <?= htmlspecialchars($message['Attachment_Path']) ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($can_send_message): ?>
                    <form class="chat-input" method="post" enctype="multipart/form-data" onsubmit="return validateMessageForm(this)">
                        <input type="hidden" name="receiver_id" value="<?= !$is_group_chat ? $current_dialog['User_Id'] : '' ?>">
                        <input type="hidden" name="group_id" value="<?= $is_group_chat ? $current_dialog['Group_Id'] : '' ?>">

                        <!-- Поле для предпросмотра вложений -->
                        <div class="attachment-preview" id="attachmentPreview"></div>

                        <div class="chat-input-wrapper">
                            <textarea name="message" placeholder="Введите сообщение..." id="messageInput"></textarea>

                            <div class="chat-input-actions">
                                <!-- Кнопка смайликов -->
                                <button type="button" class="emoji-picker-btn" id="emojiPickerBtn">😊</button>
                                <div class="emoji-picker" id="emojiPicker">
                                    <!-- Смайлики будут добавлены через JavaScript -->
                                </div>

                                <label for="fileInput" class="chat-input-btn" title="Прикрепить файл">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                                    </svg>
                                </label>
                                <input type="file" id="fileInput" class="file-input" name="attachment" accept="" multiple>

                                <button type="submit" class="chat-input-btn" title="Отправить">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="22" y1="2" x2="11" y2="13"></line>
                                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="chat-input-disabled">
                        У вас нет прав отправлять сообщения в этот чат
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-chat">
                    <div class="empty-chat-content">
                        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                        <h3>Выберите диалог</h3>
                        <p>Выберите существующий диалог или создайте новый, чтобы начать общение</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Модальное окно создания группы -->
    <div class="modal" id="createGroupModal">
        <div class="modal-content">
            <div class="modal-header">
                <span>Создать групповой чат</span>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            <form method="post" action="chat.php">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="groupName">Название группы</label>
                        <input type="text" id="groupName" name="group_name" required>
                    </div>

                    <div class="form-group">
                        <label for="userSearch">Поиск участников</label>
                        <input type="text" id="userSearch" placeholder="Начните вводить имя..." class="search-input">
                    </div>

                    <div class="members-container" id="membersContainer">
                        <div class="form-group">
                            <label>Выберите участников</label>
                            <div class="user-select" id="userSelect">
                                <?php foreach ($all_users as $user_item): ?>
                                    <div class="user-option">
                                        <input type="checkbox" name="group_members[]" value="<?= $user_item['User_Id'] ?>" id="user_<?= $user_item['User_Id'] ?>" class="member-checkbox">
                                        <label for="user_<?= $user_item['User_Id'] ?>">
                                            <?= htmlspecialchars($user_item['User_Surname']) ?> <?= htmlspecialchars($user_item['User_Name']) ?>
                                            <span class="user-role-badge">
                                                <?= $user_item['Role_Id'] == 1 ? 'Админ' : ($user_item['Role_Id'] == 2 ? 'Преподаватель' : 'Студент') ?>
                                            </span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary" name="create_group">Создать</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        // Поиск пользователей при создании группы
        const userSearch = document.getElementById('userSearch');
        if (userSearch) {
            userSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const userOptions = document.querySelectorAll('.user-option');

                userOptions.forEach(option => {
                    const text = option.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        option.style.display = 'flex';
                    } else {
                        option.style.display = 'none';
                    }
                });
            });
        }

        // Подсчет выбранных участников
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.member-checkbox:checked');
            const countElement = document.getElementById('membersCount');
            if (countElement) {
                countElement.textContent = checkboxes.length;
            }
        }

        document.querySelectorAll('.member-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // Инициализация счетчика при загрузке
        updateSelectedCount();
        // Управление модальным окном
        const createGroupBtn = document.getElementById('createGroupBtn');
        const createGroupModal = document.getElementById('createGroupModal');

        if (createGroupBtn) {
            createGroupBtn.addEventListener('click', () => {
                createGroupModal.style.display = 'flex';
            });
        }

        function closeModal() {
            createGroupModal.style.display = 'none';
        }

        // Закрытие модального окна при клике вне его
        window.addEventListener('click', (e) => {
            if (e.target === createGroupModal) {
                closeModal();
            }
        });

        // Автопрокрутка чата вниз
        const chatMessages = document.getElementById('chatMessages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // Автоматическое увеличение высоты textarea при вводе
        const textarea = document.querySelector('.chat-input textarea');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }

        // Поиск диалогов
        const searchInput = document.getElementById('searchDialogs');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const dialogItems = document.querySelectorAll('.dialog-item');

                dialogItems.forEach(item => {
                    const name = item.querySelector('.dialog-name').textContent.toLowerCase();
                    if (name.includes(searchTerm)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        }

        // Валидация формы сообщения
        function validateMessageForm(form) {
            const message = form.message.value.trim();
            const fileInput = form.attachment;

            if (message === '' && (!fileInput.files || fileInput.files.length === 0)) {
                alert('Пожалуйста, введите сообщение или прикрепите файл');
                return false;
            }
            return true;
        }

        // Управление отображением участников в чате
        const toggleMembersBtn = document.getElementById('toggleMembersBtn');
        const groupMembers = document.getElementById('groupMembers');

        if (toggleMembersBtn && groupMembers) {
            toggleMembersBtn.addEventListener('click', () => {
                groupMembers.classList.toggle('visible');

                // Обновляем иконку кнопки
                const icon = toggleMembersBtn.querySelector('svg');
                if (groupMembers.classList.contains('visible')) {
                    icon.innerHTML = '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>';
                } else {
                    icon.innerHTML = '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>';
                }
            });
        }

        // Функция для начала личного чата с участником группы
        function startPrivateChat(userId) {
            if (confirm('Начать личный чат с этим пользователем?')) {
                window.location.href = `chat.php?dialog=${userId}`;
            }
        }

        // Функция для подтверждения удаления группы
        function confirmGroupDelete() {
            return confirm('Вы уверены, что хотите удалить эту группу? Все сообщения будут потеряны.');
        }

        // Обновление чата каждые 5 секунд
        setInterval(() => {
            if (window.location.href.indexOf('dialog') > -1) {
                const currentUrl = new URL(window.location.href);
                const dialogId = currentUrl.searchParams.get('dialog');
                const isGroup = currentUrl.searchParams.get('group');

                if (dialogId) {
                    let url = `chat_update.php?dialog=${dialogId}`;
                    if (isGroup) {
                        url += '&group=1';
                    }

                    fetch(url)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.text();
                        })
                        .then(data => {
                            if (data) {
                                document.getElementById('chatMessages').innerHTML = data;
                                document.getElementById('chatMessages').scrollTop = document.getElementById('chatMessages').scrollHeight;
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching chat updates:', error);
                        });
                }
            }
        }, 5000);
        // Предпросмотр изображений перед отправкой
        const fileInput = document.getElementById('fileInput');
        const attachmentPreview = document.getElementById('attachmentPreview');

        if (fileInput && attachmentPreview) {
            fileInput.addEventListener('change', function(e) {
                attachmentPreview.innerHTML = '';

                if (this.files) {
                    Array.from(this.files).forEach(file => {
                        if (file.type.startsWith('image/')) {
                            const reader = new FileReader();

                            reader.onload = function(event) {
                                const previewItem = document.createElement('div');
                                previewItem.className = 'attachment-item';

                                const img = document.createElement('img');
                                img.src = event.target.result;

                                const removeBtn = document.createElement('button');
                                removeBtn.className = 'remove-attachment';
                                removeBtn.innerHTML = '×';
                                removeBtn.onclick = function() {
                                    previewItem.remove();
                                    updateFileInput();
                                };

                                previewItem.appendChild(img);
                                previewItem.appendChild(removeBtn);
                                attachmentPreview.appendChild(previewItem);
                            }

                            reader.readAsDataURL(file);
                        }
                    });
                }
            });
        }

        // Обновление file input при удалении превью
        function updateFileInput() {
            const dataTransfer = new DataTransfer();
            const items = attachmentPreview.querySelectorAll('.attachment-item');

            Array.from(fileInput.files).forEach(file => {
                let fileExists = false;

                items.forEach(item => {
                    if (item.dataset.filename === file.name) {
                        fileExists = true;
                    }
                });

                if (fileExists) {
                    dataTransfer.items.add(file);
                }
            });

            fileInput.files = dataTransfer.files;
        }

        // Инициализация смайликов
        const emojiPickerBtn = document.getElementById('emojiPickerBtn');
        const emojiPicker = document.getElementById('emojiPicker');
        const messageInput = document.getElementById('messageInput');

        if (emojiPickerBtn && emojiPicker && messageInput) {
            // Список смайликов
            const emojis = [
                '😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '😊', '😇',
                '🙂', '🙃', '😉', '😌', '😍', '🥰', '😘', '😗', '😙', '😚',
                '😋', '😛', '😝', '😜', '🤪', '🤨', '🧐', '🤓', '😎', '🥸',
                '🤩', '🥳', '😏', '😒', '😞', '😔', '😟', '😕', '🙁', '☹️',
                '😣', '😖', '😫', '😩', '🥺', '😢', '😭', '😤', '😠', '😡',
                '🤬', '🤯', '😳', '🥵', '🥶', '😱', '😨', '😰', '😥', '😓',
                '🫣', '🤗', '🫡', '🤔', '🫢', '🤭', '🤫', '🤥', '😶', '😶‍🌫️',
                '😐', '😑', '😬', '🫨', '🙄', '😯', '😦', '😧', '😮', '😲',
                '🥱', '😴', '🤤', '😪', '😵', '😵‍💫', '🫥', '🤐', '🥴', '🤢',
                '🤮', '🤧', '😷', '🤒', '🤕', '🤑', '🤠', '😈', '👿', '👹'
            ];

            // Добавляем смайлики в пикер
            emojis.forEach(emoji => {
                const emojiElement = document.createElement('span');
                emojiElement.className = 'emoji-item';
                emojiElement.textContent = emoji;
                emojiElement.onclick = function() {
                    messageInput.value += emoji;
                    messageInput.focus();
                };
                emojiPicker.appendChild(emojiElement);
            });

            // Открытие/закрытие пикера смайликов
            emojiPickerBtn.addEventListener('click', function(e) {
                e.preventDefault();
                emojiPicker.classList.toggle('visible');
            });

            // Закрытие пикера при клике вне его
            document.addEventListener('click', function(e) {
                if (!emojiPicker.contains(e.target) && e.target !== emojiPickerBtn) {
                    emojiPicker.classList.remove('visible');
                }
            });
        }
        const toggleDialogsBtn = document.createElement('button');
        toggleDialogsBtn.className = 'toggle-dialogs-btn';
        toggleDialogsBtn.innerHTML = '☰';
        document.querySelector('.chat-main').prepend(toggleDialogsBtn);

        const chatSidebar = document.querySelector('.chat-sidebar');
        const chatMain = document.querySelector('.chat-main');

        toggleDialogsBtn.addEventListener('click', () => {
            chatSidebar.classList.toggle('visible');
        });


        document.querySelector(".toggle-btn").addEventListener("click", () => {
            chatSidebar.classList.toggle('visible');
        })

        // Закрытие списка диалогов при выборе диалога (на мобильных)
        document.querySelectorAll('.dialog-item').forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    chatSidebar.classList.remove('visible');
                }
            });
        });
    </script>
</body>

</html>