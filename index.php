<?php
session_start();
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Educational IT Platform</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        input{
            width: 100%;
        }
        /* Стили для проверки пароля */
        .form-group {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #3498db;
            cursor: pointer;
            font-size: 14px;
            user-select: none;
        }

        .toggle-password:hover {
            text-decoration: underline;
        }

        .password-strength {
            margin-top: 8px;
            height: 4px;
            background-color: #eee;
            border-radius: 2px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .strength-0 {
            width: 25%;
            background-color: #e74c3c;
        }

        .strength-1 {
            width: 50%;
            background-color: #f39c12;
        }

        .strength-2 {
            width: 75%;
            background-color: #f1c40f;
        }

        .strength-3 {
            width: 90%;
            background-color: #2ecc71;
        }

        .strength-4 {
            width: 100%;
            background-color: #27ae60;
        }

        .match-indicator {
            font-size: 14px;
            margin-top: 5px;
            height: 18px;
        }

        .password-requirements {
            margin-top: 10px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
            font-size: 14px;
        }

        .password-requirements ul {
            margin: 8px 0 0 0;
            padding-left: 20px;
        }

        .password-requirements li {
            margin-bottom: 4px;
        }

        .requirement-met {
            color: #27ae60;
        }

        .requirement-met::before {
            content: "✓ ";
        }

        .requirement-not-met {
            color: #7f8c8d;
        }

    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body>

    <div class="wrapper">
        <section class="hero">
            <div class="container">
                <div class="hero__contant">
                    <div class="hero__info">
                        <div class="title">Платформа для обучения IT профессиям</div>
                        <div class="subtitle">Информационная система для изучения передовых технологий</div>
                        <div class="buttons">
                            <button class="btn" id="login-btn">Войти</button>
                        </div>
                    </div>
                    <img src="img/hero/isometric-cms-concept 2.png" alt="IT обучение" class="hero__img">
                </div>
            </div>
        </section>
    </div>

    <!-- Модальное окно авторизации -->
    <div id="login-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Авторизация пользователя</h2>
            <div class="modal__extra-info">Для доступа к системе требуется ввести учетные данные - логин и пароль.</div>
            <form class="modal__form" action="php/authorization.php" method="post">
                <input type="text" id="login" name="login" required placeholder="Логин">
                <div class="form-group">
                    <input type="password" id="password" name="password" required placeholder="Пароль">
                    <span class="toggle-password" onclick="togglePassword('password')">Показать</span>
                </div>
                <button type="submit" class="btn">Войти</button>
                <p>
                    <a href="#" id="forgot-password-link">Забыли пароль?</a>
                </p>
            </form>
        </div>
    </div>

    <!-- Модальное окно восстановления пароля -->
    <div id="forgot-password-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Восстановление пароля</h2>
            <form id="forgot-password-form" class="modal__form">
                <input type="email" id="email" name="email" required placeholder="Введите почту">
                <button type="submit" class="btn">Отправить</button>
            </form>
        </div>
    </div>

    <!-- Модальное окно для кода подтверждения -->
    <div id="recovery-code-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Подтверждение восстановления</h2>
            <div class="modal__extra-info">На вашу почту был отправлен код подтверждения.</div>
            <form id="recovery-code-form" class="modal__form">
                <input type="text" id="recovery-code" name="recovery-code" required placeholder="Введите 6-значный код">
                <button type="submit" class="btn">Подтвердить</button>
            </form>
        </div>
    </div>

    <!-- Модальное окно для нового пароля -->
    <div id="new-password-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Установка нового пароля</h2>
            <form id="new-password-form" class="modal__form">
                <div class="form-group">
                    <input type="password" id="new-password" name="new-password" required
                        placeholder="Новый пароль (мин. 8 символов)" oninput="checkPasswordStrength()">
                    <span class="toggle-password" onclick="togglePassword('new-password')">Показать</span>
                    <div class="password-strength">
                        <div class="strength-bar" id="strengthBar"></div>
                    </div>
                </div>

                <div class="form-group">
                    <input type="password" id="confirm-password" name="confirm-password" required
                        placeholder="Подтвердите пароль" oninput="checkPasswordMatch()">
                    <span class="toggle-password" onclick="togglePassword('confirm-password')">Показать</span>
                    <div id="passwordMatch" class="match-indicator"></div>
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

                <button type="submit" class="btn">Сохранить</button>
            </form>
        </div>
    </div>

    <!-- Модальное окно успешного восстановления -->
    <div id="recovery-success-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Пароль успешно изменен!</h2>
            <p>Теперь вы можете войти в систему с новым паролем.</p>
            <button class="btn" id="go-to-login">Перейти к авторизации</button>
        </div>
    </div>

    <!-- Модальное окно ошибки -->
    <div id="error-modal" class="modal" style="display: <?php echo isset($_GET['error']) ? 'block' : 'none'; ?>;">
        <div class="modal-content">
            <span class="close">&times;</span>
            <p><?php echo isset($_GET['error']) ? 'Ошибка авторизации: неверный логин или пароль' : ''; ?></p>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Открытие модального окна авторизации
            $('#login-btn').click(function(e) {
                e.preventDefault();
                $('#login-modal').fadeIn();
            });

            // Закрытие модальных окон
            $('.close').click(function() {
                $('.modal').fadeOut();
            });

            // Открытие окна восстановления пароля
            $('#forgot-password-link').click(function(e) {
                e.preventDefault();
                $('#login-modal').hide();
                $('#forgot-password-modal').show();
            });

            // Обработка формы восстановления пароля
            $('#forgot-password-form').submit(function(e) {
                e.preventDefault();
                const email = $('#email').val();

                $.post('php/forgot_password.php', {
                    email: email
                }, function(response) {
                    if (response.success) {
                        $('#forgot-password-modal').hide();
                        $('#recovery-code-modal').show();
                    } else {
                        alert(response.message || 'Ошибка при отправке запроса');
                    }
                }, 'json').fail(function() {
                    alert('Ошибка соединения с сервером');
                });
            });

            // Обработка формы кода подтверждения
            $('#recovery-code-form').submit(function(e) {
                e.preventDefault();
                const code = $('#recovery-code').val();

                $.post('php/verify_recovery_code.php', {
                    code: code
                }, function(response) {
                    if (response.success) {
                        $('#recovery-code-modal').hide();
                        $('#new-password-modal').show();
                    } else {
                        alert(response.message || 'Неверный код подтверждения');
                    }
                }, 'json');
            });

            // Обработка формы нового пароля
            $('#new-password-form').submit(function(e) {
                e.preventDefault();
                const newPassword = $('#new-password').val();
                const confirmPassword = $('#confirm-password').val();

                if (newPassword !== confirmPassword) {
                    alert('Пароли не совпадают');
                    return;
                }

                $.post('php/update_password.php', {
                    password: newPassword,
                    confirm_password: confirmPassword
                }, function(response) {
                    if (response.success) {
                        $('#new-password-modal').hide();
                        $('#recovery-success-modal').show();
                    } else {
                        alert(response.message || 'Ошибка при изменении пароля');
                    }
                }, 'json');
            });

            // Переход к авторизации после успешного восстановления
            $('#go-to-login').click(function() {
                $('#recovery-success-modal').hide();
                $('#login-modal').show();
            });
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
            const password = document.getElementById('new-password').value;
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
            const password = document.getElementById('new-password').value;
            const confirmPassword = document.getElementById('confirm-password').value;
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