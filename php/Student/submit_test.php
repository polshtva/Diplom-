<?php
session_start();
require '../db_config.php';

// Проверка авторизации
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: ../index.php');
    exit();
}

// Включение подробного логгирования
ini_set('log_errors', 1);
ini_set('error_log', '../php_errors.log');
error_log('Скрипт test_processing.php запущен');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['test_id'])) {
    error_log('Неверный метод запроса или отсутствует test_id');
    header('Location: student_dashboard.php');
    exit();
}

// Валидация входных данных
$test_id = filter_var($_POST['test_id'], FILTER_VALIDATE_INT);
if (!$test_id) {
    error_log('Неверный ID теста: ' . $_POST['test_id']);
    die("Неверный ID теста");
}

$student_id = $_SESSION['user_id'];
$answers = is_array($_POST['answers'] ?? []) ? $_POST['answers'] : [];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Получаем информацию о тесте
    $test_stmt = $pdo->prepare("SELECT * FROM Test WHERE Test_id = :test_id");
    $test_stmt->execute(['test_id' => $test_id]);
    $test = $test_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$test) {
        throw new Exception("Тест с ID $test_id не найден");
    }

    // 2. Получаем ID курса для этого теста
    $course_stmt = $pdo->prepare("
        SELECT c.Course_id 
        FROM Course c
        JOIN Lesson l ON c.Course_id = l.Course_Id
        JOIN Test t ON l.Lesson_Id = t.Lesson_Id
        WHERE t.Test_Id = :test_id
        LIMIT 1
    ");
    $course_stmt->execute(['test_id' => $test_id]);
    $course = $course_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$course) {
        throw new Exception("Курс для теста $test_id не найден");
    }

    // 3. Получаем ID студента
    $student_stmt = $pdo->prepare("SELECT Student_Id FROM Student WHERE User_id = :user_id LIMIT 1");
    $student_stmt->execute(['user_id' => $student_id]);
    $student = $student_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new Exception("Студент с User_id $student_id не найден");
    }

    // 4. Получаем все вопросы теста
    $questions_stmt = $pdo->prepare("SELECT * FROM Question WHERE Test_id = :test_id");
    $questions_stmt->execute(['test_id' => $test_id]);
    $questions = $questions_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($questions)) {
        throw new Exception("Вопросы для теста $test_id не найдены");
    }

    // 5. Получаем все правильные ответы
    $question_ids = array_column($questions, 'Question_id');
    $placeholders = implode(',', array_fill(0, count($question_ids), '?'));

    $correct_answers_stmt = $pdo->prepare("
        SELECT Question_id, VarAnswers_Id 
        FROM VarAnswers 
        WHERE Question_id IN ($placeholders) AND VarAnswers_Correct = 1
    ");
    $correct_answers_stmt->execute($question_ids);
    $correct_answers = $correct_answers_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Группируем правильные ответы по вопросам
    $correct_answers_grouped = [];
    foreach ($correct_answers as $answer) {
        $correct_answers_grouped[$answer['Question_id']][] = $answer['VarAnswers_Id'];
    }

    // 7. Проверяем ответы студента
    $total_score = 0;
    $max_score = 0;
    $results = [];

    foreach ($questions as $question) {
        $question_id = $question['Question_id'];
        $max_score += $question['Question_Mark'];

        if (!isset($answers[$question_id])) {
            continue;
        }

        $student_answer = $answers[$question_id];
        $correct_answer = $correct_answers_grouped[$question_id] ?? [];

        if ($question['Question_Type'] === 'radio') {
            if (in_array($student_answer, $correct_answer)) {
                $total_score += $question['Question_Mark'];
                $results[$question_id] = 'correct';
            } else {
                $results[$question_id] = 'wrong';
            }
        } else {
            $student_answer = is_array($student_answer) ? $student_answer : [$student_answer];
            $is_correct = true;

            foreach ($student_answer as $answer_id) {
                if (!in_array($answer_id, $correct_answer)) {
                    $is_correct = false;
                    break;
                }
            }

            if ($is_correct && count($student_answer) === count($correct_answer)) {
                $total_score += $question['Question_Mark'];
                $results[$question_id] = 'correct';
            } else {
                $results[$question_id] = 'wrong';
            }
        }
    }

    // 8. Рассчитываем процент выполнения теста
    $test_percentage = $max_score > 0 ? min(round(($total_score / $max_score) * 100), 100) : 0;
    $pass_status = ($total_score >= $test['Test_MinMark']) ? 'passed' : 'failed';

    // 9. Сохраняем результаты теста
    $insert_stmt = $pdo->prepare("
        INSERT INTO TestResults 
        (Test_id, Student_id, Score, MaxScore, PassStatus, DateCompleted) 
        VALUES (:test_id, :student_id, :score, :max_score, :pass_status, NOW())
    ");
    $insert_stmt->execute([
        'test_id' => $test_id,
        'student_id' => $student['Student_Id'],
        'score' => $total_score,
        'max_score' => $max_score,
        'pass_status' => $pass_status
    ]);
    $result_id = $pdo->lastInsertId();

    // 10. Обновляем прогресс в Enrollments
    $progress_to_update = min($test_percentage, 100);

    // Проверяем существование записи
    $check_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM Enrollments 
        WHERE Course_Id = :course_id AND Student_Id = :student_id
    ");
    $check_stmt->execute([
        'course_id' => $course['Course_id'],
        'student_id' => $student['Student_Id']
    ]);

    if ($check_stmt->fetchColumn() == 0) {
        // Создаем новую запись
        $insert_enrollment = $pdo->prepare("
            INSERT INTO Enrollments 
            (Course_Id, Student_Id, Enrollments_date, Progress) 
            VALUES (:course_id, :student_id, NOW(), :progress)
        ");
        $insert_enrollment->execute([
            'course_id' => $course['Course_id'],
            'student_id' => $student['Student_Id'],
            'progress' => $progress_to_update
        ]);
        error_log("Создана новая запись в Enrollments для student_id: {$student['Student_Id']}, course_id: {$course['Course_id']}");
    } else {
        // Обновляем существующую запись
        $update_stmt = $pdo->prepare("
            UPDATE Enrollments 
            SET Progress = GREATEST(Progress, :progress)
            WHERE Course_Id = :course_id AND Student_Id = :student_id
        ");
        $update_stmt->execute([
            'progress' => $progress_to_update,
            'course_id' => $course['Course_id'],
            'student_id' => $student['Student_Id']
        ]);
        error_log("Обновлен прогресс в Enrollments для student_id: {$student['Student_Id']}, course_id: {$course['Course_id']} до $progress_to_update%");
    }

    // Перенаправляем на страницу с результатами
    header("Location: test_results.php?test_id=$test_id&result_id=$result_id");
    exit();
} catch (PDOException $e) {
    error_log("Ошибка БД: " . $e->getMessage());
    die("Произошла ошибка при обработке теста. Пожалуйста, попробуйте позже.");
} catch (Exception $e) {
    error_log("Ошибка: " . $e->getMessage());
    die($e->getMessage());
}
