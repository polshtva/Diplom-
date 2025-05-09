<?php
include '../db_config.php';
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Получаем ID урока и теста из GET-параметров
$lessonId = isset($_GET['lesson_id']) ? (int)$_GET['lesson_id'] : 0;
$courseId = isset($_GET['$courseId']) ? (int)$_GET['$courseId'] : 0;
$testId = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;


// Получаем ID инструктора из сессии
$instructorId = $_SESSION['user_id'] ?? 0;
if ($instructorId === 0) {
    die("Ошибка: Пользователь не авторизован");
}

// Если передан lesson_id, но не test_id, ищем тест для этого урока
if ($lessonId > 0 && $testId === 0) {
    try {
        $stmt = $pdo->prepare("SELECT Test_Id FROM Test WHERE Lesson_Id = :lesson_id AND Instructor_Id = :instructor LIMIT 1");
        $stmt->bindParam(':lesson_id', $lessonId, PDO::PARAM_INT);
        $stmt->bindParam(':instructor', $instructorId, PDO::PARAM_INT);
        $stmt->execute();

        $testData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($testData) {
            $testId = (int)$testData['Test_Id'];
        } else {
            header("Location: create_test.php?lesson_id=" . $lessonId);
            exit();
        }
    } catch (PDOException $e) {
        die("Ошибка базы данных: " . $e->getMessage());
    }
}

// Обработка удаления теста
if (isset($_GET['delete_test']) && $_GET['delete_test'] == 1) {
    try {
        $pdo->beginTransaction();

        // Удаляем варианты ответов
        $stmt = $pdo->prepare("DELETE FROM VarAnswers WHERE Question_Id IN (SELECT Question_Id FROM Question WHERE Test_Id = :test_id)");
        $stmt->bindParam(':test_id', $testId, PDO::PARAM_INT);
        $stmt->execute();

        // Удаляем вопросы
        $stmt = $pdo->prepare("DELETE FROM Question WHERE Test_Id = :test_id");
        $stmt->bindParam(':test_id', $testId, PDO::PARAM_INT);
        $stmt->execute();

        // Удаляем сам тест
        $stmt = $pdo->prepare("DELETE FROM Test WHERE Test_Id = :test_id AND Instructor_Id = :instructor");
        $stmt->bindParam(':test_id', $testId, PDO::PARAM_INT);
        $stmt->bindParam(':instructor', $instructorId, PDO::PARAM_INT);
        $stmt->execute();

        $pdo->commit();

        // Перенаправляем после удаления
        header("Location: info_course.php?lesson_id=" . $lessonId);
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        die("Ошибка при удалении теста: " . $e->getMessage());
    }
}

// Получаем данные пользователя
$userName = $_SESSION['user_name'] ?? '';
$userSurname = $_SESSION['user_surname'] ?? '';
$userPatronymic = $_SESSION['user_patronymic'] ?? '';

// Получаем инициалы
$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);

// Получаем данные теста
$testData = [];
$questionsData = [];

try {
    // Получаем информацию о тесте с проверкой прав доступа
    $stmt = $pdo->prepare("SELECT * FROM Test WHERE Test_Id = :test_id AND Instructor_Id = :instructor");
    $stmt->bindParam(':test_id', $testId, PDO::PARAM_INT);
    $stmt->bindParam(':instructor', $instructorId, PDO::PARAM_INT);
    $stmt->execute();
    $testData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$testData) {
        die("Тест не найден или у вас нет прав на его редактирование");
    }

    // Получаем вопросы теста
    $stmt = $pdo->prepare("SELECT * FROM Question WHERE Test_Id = :test_id ORDER BY Question_Id");
    $stmt->bindParam(':test_id', $testId, PDO::PARAM_INT);
    $stmt->execute();
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Для каждого вопроса получаем варианты ответов
    foreach ($questions as $question) {
        $stmt = $pdo->prepare("SELECT * FROM VarAnswers WHERE Question_Id = :question_id ORDER BY VarAnswers_Id");
        $stmt->bindParam(':question_id', $question['Question_Id'], PDO::PARAM_INT);
        $stmt->execute();
        $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $questionsData[] = [
            'question' => $question,
            'answers' => $answers
        ];
    }
} catch (PDOException $e) {
    die("Ошибка базы данных: " . $e->getMessage());
}

// Проверка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['preview']) && isset($_POST['test_name'])) {
    $pdo->beginTransaction();

    try {
        // Подготовка данных теста
        $testName = htmlspecialchars($_POST['test_name']);
        $testDesc = htmlspecialchars($_POST['test_desc']);
        $testType = $_POST['test_type'];
        $testQuantity = count($_POST['question_text']);
        $testMinMark = (int)$_POST['test_min_mark'];

        // Обновление теста
        $stmt = $pdo->prepare("UPDATE Test SET 
                             Test_Name = :name, 
                             Test_Desc = :desc, 
                             Test_Type = :type, 
                             Test_Quantity = :quantity, 
                             Test_MinMark = :minmark 
                             WHERE Test_Id = :test_id AND Instructor_Id = :instructor");
        $stmt->bindParam(':name', $testName);
        $stmt->bindParam(':desc', $testDesc);
        $stmt->bindParam(':type', $testType);
        $stmt->bindParam(':quantity', $testQuantity, PDO::PARAM_INT);
        $stmt->bindParam(':minmark', $testMinMark, PDO::PARAM_INT);
        $stmt->bindParam(':test_id', $testId, PDO::PARAM_INT);
        $stmt->bindParam(':instructor', $instructorId, PDO::PARAM_INT);
        $stmt->execute();

        // Удаляем старые вопросы и ответы
        $stmt = $pdo->prepare("DELETE FROM VarAnswers WHERE Question_Id IN (SELECT Question_Id FROM Question WHERE Test_Id = :test_id)");
        $stmt->bindParam(':test_id', $testId, PDO::PARAM_INT);
        $stmt->execute();

        $stmt = $pdo->prepare("DELETE FROM Question WHERE Test_Id = :test_id");
        $stmt->bindParam(':test_id', $testId, PDO::PARAM_INT);
        $stmt->execute();

        // Вставка новых вопросов
        foreach ($_POST['question_text'] as $index => $qText) {
            $qText = htmlspecialchars($qText);
            $qType = $_POST['question_type'][$index];
            $qMark = (int)$_POST['question_mark'][$index];

            $stmt = $pdo->prepare("INSERT INTO Question (Test_Id, Question_Text, Question_Type, Question_Mark)
                                  VALUES (:test_id, :text, :type, :mark)");
            $stmt->bindParam(':test_id', $testId, PDO::PARAM_INT);
            $stmt->bindParam(':text', $qText);
            $stmt->bindParam(':type', $qType);
            $stmt->bindParam(':mark', $qMark, PDO::PARAM_INT);
            $stmt->execute();

            $questionId = $pdo->lastInsertId();

            // Вставка ответов
            if (isset($_POST['answers'][$index])) {
                foreach ($_POST['answers'][$index] as $aIndex => $answerText) {
                    $answerText = htmlspecialchars($answerText);
                    $isCorrect = isset($_POST['correct'][$index]) && in_array($aIndex, $_POST['correct'][$index]) ? 1 : 0;

                    $stmt = $pdo->prepare("INSERT INTO VarAnswers (Question_Id, VarAnswers_Text, VarAnswers_Correct)
                                          VALUES (:question_id, :text, :correct)");
                    $stmt->bindParam(':question_id', $questionId, PDO::PARAM_INT);
                    $stmt->bindParam(':text', $answerText);
                    $stmt->bindParam(':correct', $isCorrect, PDO::PARAM_INT);
                    $stmt->execute();
                }
            }
        }

        $pdo->commit();
        header("Location: edit_test.php?test_id=" . $testId . "&lesson_id=" . $lessonId . "&success=1");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $errorMessage = "Ошибка: " . htmlspecialchars($e->getMessage());
    }
}

// Проверяем success-параметр
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $successMessage = "✅ Тест успешно обновлен!";
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Редактирование теста</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../../css/style_Instructor/create_test.css">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    
</head>

<body>
    <?php include '../components/header.php'; ?>

    <div class="container">
        <h2>Редактирование теста</h2>

        <div class="action-buttons">
            <button onclick="confirmDeleteTest()" class="btn btn-danger">🗑️ Удалить тест</button>
        </div>

        <?php if (!empty($successMessage)): ?>
            <div class="success-message"><?= $successMessage ?></div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="error-message"><?= $errorMessage ?></div>
        <?php endif; ?>

        <form method="post" id="testForm">
            <input type="hidden" name="test_id" value="<?= $testId ?>">
            <label>Название теста:</label>
            <input type="text" name="test_name" value="<?= htmlspecialchars($testData['Test_Name'] ?? '') ?>" required>

            <label>Описание:</label>
            <textarea name="test_desc"><?= htmlspecialchars($testData['Test_Desc'] ?? '') ?></textarea>

            <label>Тип теста:</label>
            <select name="test_type" required>
                <option value="one" <?= ($testData['Test_Type'] ?? '') === 'one' ? 'selected' : '' ?>>С одним правильным ответом</option>
                <option value="multi" <?= ($testData['Test_Type'] ?? '') === 'multi' ? 'selected' : '' ?>>С несколькими правильными ответами</option>
            </select>

            <label>Минимальный балл для прохождения:</label>
            <input type="text" name="test_min_mark" value="<?= htmlspecialchars($testData['Test_MinMark'] ?? '') ?>" required>

            <div id="questions" class="sortable">
                <?php foreach ($questionsData as $index => $questionData): ?>
                    <div class="question-block" data-id="<?= $index ?>">
                        <label>Вопрос:</label>
                        <input type="text" name="question_text[<?= $index ?>]" value="<?= htmlspecialchars($questionData['question']['Question_Text'] ?? '') ?>" required>

                        <label>Тип вопроса:</label>
                        <select name="question_type[<?= $index ?>]" onchange="changeAnswerType(<?= $index ?>, this.value)" required>
                            <option value="radio" <?= ($questionData['question']['Question_Type'] ?? '') === 'radio' ? 'selected' : '' ?>>Один верный</option>
                            <option value="checkbox" <?= ($questionData['question']['Question_Type'] ?? '') === 'checkbox' ? 'selected' : '' ?>>Несколько верных</option>
                        </select>

                        <label>Баллы за вопрос:</label>
                        <input type="text" name="question_mark[<?= $index ?>]" value="<?= htmlspecialchars($questionData['question']['Question_Mark'] ?? '') ?>" required>

                        <div class="answer-block" id="answers-<?= $index ?>">
                            <?php foreach ($questionData['answers'] as $aIndex => $answer): ?>
                                <div class="answer-item">
                                    <input type="<?= $questionData['question']['Question_Type'] === 'radio' ? 'radio' : 'checkbox' ?>"
                                        name="correct[<?= $index ?>][]"
                                        value="<?= $aIndex ?>"
                                        data-q="<?= $index ?>"
                                        <?= $answer['VarAnswers_Correct'] ? 'checked' : '' ?>
                                        onchange="enforceSingleCorrect(<?= $index ?>)">
                                    <input type="text" name="answers[<?= $index ?>][]" value="<?= htmlspecialchars($answer['VarAnswers_Text'] ?? '') ?>" placeholder="Ответ" required>
                                    <button type="button" class="btn btn-danger" onclick="confirmRemoveAnswer(this)">✖</button>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" class="btn" onclick="addAnswer(<?= $index ?>)">+ Добавить ответ</button>
                        <button type="button" class="btn btn-danger" onclick="confirmRemoveQuestion(this)">Удалить вопрос</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" style="margin-top: 20px;" class="btn" onclick="addQuestion()">+ Добавить вопрос</button>
            <br><br>
            <div class="btn-group">
                <button type="button" class="btn" onclick="previewTest()">👀 Предпросмотр</button>
                <button type="submit" class="btn">💾 Сохранить изменения</button>
            </div>
        </form>

        <div id="previewContainer" style="display:none; margin-top: 40px;">
            <h3>Предпросмотр теста</h3>
            <div id="previewContent" style="border:1px solid #ccc; padding:20px; background:#f9f9f9;"></div>
        </div>
    </div>

    <script>
        let questionIndex = <?= count($questionsData) ?>;

        function addQuestion() {
            const container = document.getElementById('questions');

            const qBlock = document.createElement('div');
            qBlock.className = 'question-block';
            qBlock.setAttribute("data-id", questionIndex);
            qBlock.innerHTML = `
                <label>Вопрос:</label>
                <input type="text" name="question_text[${questionIndex}]" required>

                <label>Тип вопроса:</label>
                <select name="question_type[${questionIndex}]" onchange="changeAnswerType(${questionIndex}, this.value)" required>
                    <option value="radio" selected>Один верный</option>
                    <option value="checkbox">Несколько верных</option>
                </select>

                <label>Баллы за вопрос:</label>
                <input type="text" name="question_mark[${questionIndex}]" required>

                <div class="answer-block" id="answers-${questionIndex}"></div>

                <button type="button" class="btn" onclick="addAnswer(${questionIndex})">+ Добавить ответ</button>
                <button type="button"  class="btn btn-danger" onclick="confirmRemoveQuestion(this)">Удалить вопрос</button>
            `;
            container.appendChild(qBlock);
            addAnswer(questionIndex);
            questionIndex++;
        }

        function addAnswer(qIdx) {
            const aContainer = document.getElementById(`answers-${qIdx}`);
            const aIndex = aContainer.children.length;
            const qType = document.querySelector(`select[name="question_type[${qIdx}]"]`).value;

            const aItem = document.createElement('div');
            aItem.className = 'answer-item';
            aItem.innerHTML = `
                <input type="${qType}" name="correct[${qIdx}][]" value="${aIndex}" data-q="${qIdx}" onchange="enforceSingleCorrect(${qIdx})">
                <input type="text" name="answers[${qIdx}][]" placeholder="Ответ" required>
                <button type="button" class="btn btn-danger" onclick="confirmRemoveAnswer(this)">✖</button>
            `;
            aContainer.appendChild(aItem);
        }

        function confirmRemoveAnswer(btn) {
            if (confirm("Удалить этот вариант ответа?")) {
                btn.parentElement.remove();
            }
        }

        function confirmRemoveQuestion(btn) {
            if (confirm("Удалить весь вопрос со всеми вариантами?")) {
                btn.parentElement.remove();
            }
        }

        function confirmDeleteTest() {
            if (confirm("Вы уверены, что хотите полностью удалить этот тест?\nЭто действие нельзя отменить!")) {
                window.location.href = "edit_test.php?test_id=<?= $testId ?>&lesson_id=<?= $lessonId ?>&delete_test=1";
            }
        }

        function changeAnswerType(qIdx, type) {
            const answers = document.querySelectorAll(`#answers-${qIdx} input[type="checkbox"], #answers-${qIdx} input[type="radio"]`);
            answers.forEach(el => {
                el.type = type === 'radio' ? 'radio' : 'checkbox';
                el.name = `correct[${qIdx}][]`;
            });
        }

        function enforceSingleCorrect(qIdx) {
            const type = document.querySelector(`select[name="question_type[${qIdx}]"]`).value;
            if (type === 'radio') {
                const radios = document.querySelectorAll(`input[name="correct[${qIdx}][]"]`);
                radios.forEach(r => r.checked = false);
                event.target.checked = true;
            }
        }

        new Sortable(document.getElementById('questions'), {
            animation: 150,
            ghostClass: 'sortable-ghost'
        });

        function previewTest() {
            const form = document.getElementById('testForm');
            const formData = new FormData(form);
            let html = `<strong>Название:</strong> ${formData.get('test_name')}<br>
                    <strong>Описание:</strong> ${formData.get('test_desc')}<br>
                    <strong>Минимальный балл:</strong> ${formData.get('test_min_mark')}<br><hr>`;

            const questions = document.querySelectorAll('.question-block');
            questions.forEach((block, idx) => {
                const qText = block.querySelector(`input[name^="question_text"]`).value;
                const qType = block.querySelector(`select[name^="question_type"]`).value;
                const qMark = block.querySelector(`input[name^="question_mark"]`).value;
                html += `<div><strong>Вопрос ${idx + 1}:</strong> ${qText} (${qMark} баллов, тип: ${qType})<ul>`;

                const answers = block.querySelectorAll('.answer-item');
                answers.forEach(a => {
                    const txt = a.querySelector('input[type="text"]').value;
                    const isCorrect = a.querySelector('input[type="checkbox"], input[type="radio"]').checked ? '✅' : '';
                    html += `<li>${txt} ${isCorrect}</li>`;
                });

                html += `</ul></div><hr>`;
            });

            document.getElementById('previewContent').innerHTML = html;
            document.getElementById('previewContainer').style.display = 'block';
            window.scrollTo(0, document.body.scrollHeight);
        }
    </script>
</body>

</html>