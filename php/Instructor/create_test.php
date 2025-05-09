<?php
include '../db_config.php';
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Получаем ID урока из GET-параметра
$lessonId = isset($_GET['lesson_id']) ? (int)$_GET['lesson_id'] : 0;
if ($lessonId === 0) {
    die("Ошибка: Не указан ID урока");
}

// Получаем ID инструктора из сессии
$instructorId = $_SESSION['user_id'] ?? 0;
if ($instructorId === 0) {
    die("Ошибка: Пользователь не авторизован");
}

// Получаем данные пользователя
$userName = $_SESSION['user_name'] ?? '';
$userSurname = $_SESSION['user_surname'] ?? '';
$userPatronymic = $_SESSION['user_patronymic'] ?? '';

// Получаем инициалы
$initials = mb_substr($userSurname, 0, 1) . mb_substr($userName, 0, 1);

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

        // Вставка теста с lesson_id
        $stmt = $pdo->prepare("INSERT INTO Test (Test_Name, Test_Desc, Test_Type, Test_Quantity, Test_MinMark, Test_Status, Test_DateCreate, Instructor_Id, Lesson_Id)
                              VALUES (:name, :desc, :type, :quantity, :minmark, 'active', NOW(), :instructor, :lesson)");
        $stmt->bindParam(':name', $testName);
        $stmt->bindParam(':desc', $testDesc);
        $stmt->bindParam(':type', $testType);
        $stmt->bindParam(':quantity', $testQuantity, PDO::PARAM_INT);
        $stmt->bindParam(':minmark', $testMinMark, PDO::PARAM_INT);
        $stmt->bindParam(':instructor', $instructorId, PDO::PARAM_INT);
        $stmt->bindParam(':lesson', $lessonId, PDO::PARAM_INT);
        $stmt->execute();

        $testId = $pdo->lastInsertId();

        // Вставка вопросов
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
        echo "<p style='color: green;'>✅ Тест успешно создан!</p>";
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo "<p style='color: red;'>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Создание теста</title>
    <link rel="stylesheet" href="../../css/style_Instructor/create_test.css">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    
</head>

<body>
    <?php include '../components/header.php'; ?>

    <div class="container">
        <h2>Создание теста</h2>
    
        <form method="post" id="testForm">
            <input type="hidden" name="lesson_id" value="<?= $lessonId ?>">
            <label>Название теста:</label>
            <input type="text" name="test_name" required>

            <label>Описание:</label>
            <textarea name="test_desc"></textarea>

            <label>Тип теста:</label>
            <select name="test_type" required>
                <option value="" disabled selected>Выберите тип теста</option>
                <option value="one">С одним правильным ответом</option>
                <option value="multi">С несколькими правильными ответами</option>
            </select>

            <label>Минимальный балл для прохождения:</label>
            <input type="text" name="test_min_mark" required>

            <div id="questions" class="sortable"></div>
            <button type="button" style="margin-top: 20px;" class="btn" onclick="addQuestion()">+ Добавить вопрос</button>
            <br><br>
            <div class="btn-group">
                <button type="button" class="btn" onclick="previewTest()">👀 Предпросмотр</button>
                <button type="submit" class="btn">💾 Сохранить тест</button>
            </div>
        </form>

        <div id="previewContainer" style="display:none; margin-top: 40px;">
            <h3>Предпросмотр теста</h3>
            <div id="previewContent" style="border:1px solid #ccc; padding:20px; background:#f9f9f9;"></div>
        </div>
    </div>

    <script>
        let questionIndex = 0;

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
            <option value="" disabled selected>Выберите тип вопроса</option>
            <option value="radio">Один верный</option>
            <option value="checkbox">Несколько верных</option>
        </select>

        <label>Баллы за вопрос:</label>
        <input type="text" name="question_mark[${questionIndex}]" required>

        <div class="answer-block" id="answers-${questionIndex}"></div>

        <button type="button" class="btn" onclick="addAnswer(${questionIndex})">+ Добавить ответ</button>
        <button type="button" class="btn btn-danger" onclick="confirmRemoveQuestion(this)">Удалить вопрос</button>
    `;
            container.appendChild(qBlock);
            addAnswer(questionIndex);
            questionIndex++;
        }

        function addAnswer(qIdx) {
            const aContainer = document.getElementById(`answers-${qIdx}`);
            const aIndex = aContainer.children.length;

            const aItem = document.createElement('div');
            aItem.className = 'answer-item';
            aItem.innerHTML = `
            <input type="checkbox" name="correct[${qIdx}][]" value="${aIndex}" data-q="${qIdx}" onchange="enforceSingleCorrect(${qIdx})">
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