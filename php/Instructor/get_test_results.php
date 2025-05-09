<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../db_config.php';

header('Content-Type: application/json');

$lessonId = $_GET['lesson_id'] ?? null;
$searchTerm = $_GET['search'] ?? '';

if (!$lessonId) {
    echo json_encode(['error' => 'Не указан идентификатор урока']);
    exit;
}

try {
    // 1. Получаем ID теста для данного урока
    $testQuery = "SELECT Test_Id FROM Test WHERE Lesson_Id = :lessonId LIMIT 1";
    $testStmt = $pdo->prepare($testQuery);
    $testStmt->bindParam(':lessonId', $lessonId, PDO::PARAM_INT);
    $testStmt->execute();

    $test = $testStmt->fetch(PDO::FETCH_ASSOC);

    if (!$test) {
        echo json_encode(['error' => 'Для этого урока еще не создан тест']);
        exit;
    }

    $testId = $test['Test_Id'];

    // 2. Основной запрос для получения результатов
    $query = "
    SELECT 
        tr.Result_id,
        tr.Score,
        tr.MaxScore,
        tr.PassStatus,
        DATE_FORMAT(tr.DateCompleted, '%d.%m.%Y %H:%i') as DateCompleted,
        COALESCE(s.Student_Id, tr.Student_id) as Student_Id,
        DATE_FORMAT(COALESCE(s.Student_Birthday, u.User_DataCreate), '%d.%m.%Y') as Student_Birthday,
        u.User_Surname,
        u.User_Name,
        u.User_Patronymic
    FROM testresults tr
    LEFT JOIN student s ON tr.Student_id = s.Student_Id
    LEFT JOIN user u ON (s.User_id = u.User_Id OR tr.Student_id = u.User_Id)
    WHERE tr.Test_id = :testId
";

    // Добавляем условия поиска
    if ($searchTerm) {
        $query .= " AND (
            u.User_Surname LIKE :search OR 
            u.User_Name LIKE :search OR 
            COALESCE(u.User_Patronymic, '') LIKE :search OR 
            s.Student_Id = :exactId OR
            u.User_Id = :exactId
        )";
    }

    $query .= " ORDER BY tr.DateCompleted DESC";

    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':testId', $testId, PDO::PARAM_INT);

    if ($searchTerm) {
        $searchParam = "%$searchTerm%";
        $stmt->bindParam(':search', $searchParam, PDO::PARAM_STR);

        // Проверяем, является ли поисковый запрос числом (для поиска по ID)
        if (is_numeric($searchTerm)) {
            $stmt->bindParam(':exactId', $searchTerm, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':exactId', 0, PDO::PARAM_INT);
        }
    }

    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)) {
        echo json_encode(['error' => 'Пока никто не прошел этот тест']);
        exit;
    }

    // Добавляем полное имя в результаты
    foreach ($results as &$result) {
        $result['FullName'] = trim(
            $result['User_Surname'] . ' ' .
                $result['User_Name'] . ' ' .
                ($result['User_Patronymic'] ?? '')
        );
    }

    echo json_encode($results);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Ошибка базы данных: ' . $e->getMessage()]);
}
