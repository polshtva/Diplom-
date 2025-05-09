<?php

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Получаем данные пользователя
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM User WHERE User_Id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$_SESSION['user_id'] = $user_id;
// Формируем инициалы
$initials = mb_substr($user['User_Surname'], 0, 1, 'UTF-8') .
    mb_substr($user['User_Name'], 0, 1, 'UTF-8');

// Проверяем наличие аватара
$hasPhoto = !empty($user['Profile_Photo']);

// Формируем путь к аватару
$avatarPath = '';
if ($hasPhoto) {
    $avatarPath = '../../uploads/profile_photos/' . $user['Profile_Photo'];

    // Проверяем существование файла
    if (!file_exists($avatarPath)) {
        $hasPhoto = false;
        $avatarPath = '';
    }
}

// Определяем активную страницу и директорию
$activePage = basename($_SERVER['PHP_SELF']);
$directory = basename(dirname($_SERVER['PHP_SELF']));
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Comic+Relief:wght@400;700&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap');

    * {
        font-family: "Comic Relief", system-ui;
    }

    :root {
        --header-bg: #ffffff;
        --nav-hover: #f0f2f5;
        --active-item: #4361ee;
        --text-color: #333333;
        --burger-color: #333333;
    }

    .header {
        background: var(--header-bg);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        position: sticky;
        top: 0;
        z-index: 1000;
        font-family: 'Segoe UI', system-ui, sans-serif;
        padding: 10px;
    }

    .header__container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 20px;
        position: relative;
    }

    .header__content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        height: 70px;
    }

    .logo__avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: #e0e0e0;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
    }

    .logo__initials {
        font-weight: 600;
        color: #555;
        font-size: 16px;
    }

    .burger-menu {
        position: absolute;
        top: 32%;
        right: 15px;
        z-index: 1000;
    }

    .header__block {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    /* Основное меню (десктоп) */
    .nav__list {
        display: flex;
        gap: 10px;
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .nav__item {
        position: relative;
    }

    .nav__item a {
        color: var(--text-color);
        text-decoration: none;
        padding: 8px 15px;
        border-radius: 6px;
        transition: all 0.2s;
        font-size: 15px;
        display: block;
    }

    .nav__item:hover a {
        background: var(--nav-hover);
    }

    .nav__item.active a {
        color: white;
        background: var(--active-item);
        font-weight: 500;
    }

    /* Бургер-меню */
    .burger-menu {
        display: none;
        flex-direction: column;
        justify-content: space-between;
        width: 30px;
        height: 20px;
        cursor: pointer;
        z-index: 2000;
    }

    .burger-line {
        width: 100%;
        height: 3px;
        background-color: var(--burger-color);
        border-radius: 2px;
        transition: all 0.3s ease;
    }

    /* Мобильное меню */
    .mobile-nav {
        position: fixed;
        top: 0;
        right: -100%;
        width: 280px;
        height: 100vh;
        background: white;
        box-shadow: -5px 0 15px rgba(0, 0, 0, 0.1);
        z-index: 1000;
        transition: right 0.3s ease;
        padding: 80px 20px 20px;
        overflow-y: auto;
    }

    .mobile-nav.active {
        right: 0;
    }

    .mobile-nav__list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .mobile-nav__item {
        margin-bottom: 10px;
    }

    .mobile-nav__item a {
        display: block;
        padding: 12px 15px;
        color: var(--text-color);
        text-decoration: none;
        border-radius: 6px;
        transition: all 0.2s;
    }

    .mobile-nav__item.active a {
        color: white;
        background: var(--active-item);
    }

    .mobile-nav__item a:hover {
        background: var(--nav-hover);
    }

    .mobile-logout {
        margin-top: 30px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--text-color);
        cursor: pointer;
        border-radius: 6px;
    }

    .mobile-logout:hover {
        background: var(--nav-hover);
    }

    .overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
    }

    .overlay.active {
        display: block;
    }

    /* Кнопка выхода (десктоп) */
    .logout-btn {
        background: none;
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 8px 15px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--text-color);
        transition: all 0.2s;
        font-size: 14px;
    }

    .logout-btn:hover {
        background: #f8f9fa;
        border-color: #ccc;
    }

    /* Адаптивность */
    @media (max-width: 768px) {
        .nav__list {
            display: none;
        }

        .burger-menu {
            display: flex;
        }

        .logout-btn {
            display: none;
        }
    }

    /* Модальное окно выхода */
    #logoutModal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 2000;
        align-items: center;
        justify-content: center;
    }

    .modal__content {
        background: white;
        padding: 25px;
        border-radius: 8px;
        max-width: 400px;
        width: 100%;
        text-align: center;
    }

    .modal__actions {
        display: flex;
        gap: 10px;
        justify-content: center;
        margin-top: 20px;
    }

    .modal__actions button {
        padding: 8px 20px;
        border-radius: 6px;
        cursor: pointer;
    }

    .btn-confirm {
        background-color: #186AF7;
        color: white;
        border: none;
    }

    .btn-cancel {
        background-color: #f1f1f1;
        color: #555;
        border: 1px solid #ddd;
    }
</style>

<header class="header">
    <div class="header__container">
        <div class="header__content">
            <div class="header__block">
                <div class="logo logo__avatar" style="<?= $hasPhoto ? "background-image: url('$avatarPath')" : '' ?>">
                    <?php if (!$hasPhoto): ?>
                        <div class="logo__initials"><?= htmlspecialchars($initials) ?></div>
                    <?php endif; ?>
                </div>
                <!-- Основное меню (десктоп) -->
                <nav class="nav">
                    <!-- Бургер-меню (мобильная версия) -->
                    <div class="burger-menu" id="burgerMenu">
                        <span class="burger-line"></span>
                        <span class="burger-line"></span>
                        <span class="burger-line"></span>
                    </div>
                    <ul class="nav__list">
                        <?php if ($directory == 'Instructor'): ?>
                            <li class="nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                                <a href="main_page.php">Курсы</a>
                            </li>
                            <li class="nav__item <?= $activePage == 'info_student.php' ? 'active' : '' ?>">
                                <a href="info_student.php">Инофрмация о студентах</a>
                            </li>
                        <?php elseif ($directory == 'components'  && $user['Role_Id'] == 2): ?>
                            <li class="nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                                <a href="../Instructor/main_page.php">Курсы</a>
                            </li>
                            <li class="nav__item <?= $activePage == 'info_student.php' ? 'active' : '' ?>">
                                <a href="../Instructor/info_student.php">Инофрмация о студентах</a>
                            </li>
                        <?php elseif ($directory == 'Admin'): ?>
                            <li class="nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                                <a href="main_page.php">Главная</a>
                            </li>
                            <li class="nav__item <?= $activePage == 'createCourse.php' ? 'active' : '' ?>">
                                <a href="createCourse.php">Создать курсы</a>
                            </li>
                            <li class="nav__item <?= $activePage == 'infoUser.php' ? 'active' : '' ?>">
                                <a href="infoUser.php">Пользователи</a>
                            </li>
                        <?php elseif ($directory == 'components' && $user['Role_Id'] == 1): ?>
                            <li class="nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                                <a href="../Admin/main_page.php">Главная</a>
                            </li>
                            <li class="nav__item <?= $activePage == 'createCourse.php' ? 'active' : '' ?>">
                                <a href="../Admin/createCourse.php">Создать курсы</a>
                            </li>
                            <li class="nav__item <?= $activePage == 'infoUser.php' ? 'active' : '' ?>">
                                <a href="../Admin/infoUser.php">Пользователи</a>
                            </li>
                        <?php elseif ($directory == 'Student'): ?>
                            <li class="nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                                <a href="main_page.php">Главная</a>
                            </li>
                        <?php elseif ($directory == 'components' && $user['Role_Id'] == 3): ?>
                            <li class="nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                                <a href="../Student/main_page.php">Главная</a>
                            </li>
                        <?php endif; ?>
                        <li class="nav__item <?= $activePage == 'chat.php' ? 'active' : '' ?>">
                            <a href="../components/chat.php">Чат</a>
                        </li>
                        <li class="nav__item <?= $activePage == 'settings.php' ? 'active' : '' ?>">
                            <a href="../components/settings.php">Настройки</a>
                        </li>
                    </ul>
                </nav>
            </div>

            <!-- Кнопка выхода (десктоп) -->
            <button class="logout-btn" onclick="showLogoutModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                Выйти
            </button>
        </div>
    </div>
</header>

<!-- Мобильное меню -->
<div class="overlay" id="overlay"></div>
<nav class="mobile-nav" id="mobileNav">
    <ul class="mobile-nav__list">
        <?php if ($directory == 'Instructor'): ?>
            <li class="mobile-nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                <a href="main_page.php">Курсы</a>
            </li>
            <li class="mobile-nav__item <?= $activePage == 'info_student.php' ? 'active' : '' ?>">
                <a href="info_student.php">Студенты</a>
            </li>
            <li class="mobile-nav__item <?= $activePage == 'settings.php' ? 'active' : '' ?>">
                <a href="../components/settings.php">Настройки</a>
            </li>
        <?php elseif ($directory == 'components' && $user['Role_Id'] == 2): ?>
            <li class="nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                <a href="../Instructor/main_page.php">Курсы</a>
            </li>
            <li class="nav__item <?= $activePage == 'info_student.php' ? 'active' : '' ?>">
                <a href="../Instructor/info_student.php">Инофрмация о студентах</a>
            </li>
        <?php elseif ($directory == 'Admin'): ?>
            <li class="mobile-nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                <a href="main_page.php">Главная</a>
            </li>
            <li class="mobile-nav__item <?= $activePage == 'createCourse.php' ? 'active' : '' ?>">
                <a href="createCourse.php">Создать курсы</a>
            </li>
            <li class="mobile-nav__item <?= $activePage == 'infoUser.php' ? 'active' : '' ?>">
                <a href="infoUser.php">Пользователи</a>
            </li>
        <?php elseif ($directory == 'components' && $user['Role_Id'] == 1): ?>
            <li class="mobile-nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                <a href="../Admin/main_page.php">Главная</a>
            </li>
            <li class="mobile-nav__item <?= $activePage == 'createCourse.php' ? 'active' : '' ?>">
                <a href="../Admin/createCourse.php">Создать курсы</a>
            </li>
            <li class="mobile-nav__item <?= $activePage == 'infoUser.php' ? 'active' : '' ?>">
                <a href="../Admin/infoUser.php">Пользователи</a>
            </li>
        <?php elseif ($directory == 'Student'): ?>
            <li class="nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                <a href="main_page.php">Главная</a>
            </li>
        <?php elseif ($directory == 'components' && $user['Role_Id'] == 3): ?>
            <li class="nav__item <?= $activePage == 'main_page.php' ? 'active' : '' ?>">
                <a href="../Student/main_page.php">Главная</a>
            </li>
        <?php endif; ?>
        <li class="nav__item <?= $activePage == 'chat.php' ? 'active' : '' ?>">
            <a href="../components/chat.php">Чат</a>
        </li>
        <li class="nav__item <?= $activePage == 'settings.php' ? 'active' : '' ?>">
            <a href="../components/settings.php">Настройки</a>
        </li>
    </ul>


    <div class="mobile-logout" onclick="showLogoutModal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
        </svg>
        Выйти
    </div>
</nav>

<!-- Модальное окно выхода -->
<div id="logoutModal" class="modal">
    <div class="modal__content">
        <p>Вы уверены, что хотите выйти из системы?</p>
        <div class="modal__actions">
            <button class="btn-confirm" onclick="logout()">Выйти</button>
            <button class="btn-cancel" onclick="hideLogoutModal()">Отмена</button>
        </div>
    </div>
</div>

<script>
    // Управление бургер-меню
    const burgerMenu = document.getElementById('burgerMenu');
    const mobileNav = document.getElementById('mobileNav');
    const overlay = document.getElementById('overlay');

    burgerMenu.addEventListener('click', function() {
        this.classList.toggle('active');
        mobileNav.classList.toggle('active');
        overlay.classList.toggle('active');

    });

    overlay.addEventListener('click', function() {
        burgerMenu.classList.remove('active');
        mobileNav.classList.remove('active');
        this.classList.remove('active');

        // Возвращаем бургер-иконку в исходное состояние
        burgerMenu.querySelectorAll('.burger-line')[0].style.transform = 'rotate(0) translate(0)';
        burgerMenu.querySelectorAll('.burger-line')[1].style.opacity = '1';
        burgerMenu.querySelectorAll('.burger-line')[2].style.transform = 'rotate(0) translate(0)';
    });

    // Модальное окно выхода
    function showLogoutModal() {
        document.getElementById('logoutModal').style.display = 'flex';
        // Закрываем мобильное меню, если открыто
        if (mobileNav.classList.contains('active')) {
            burgerMenu.classList.remove('active');
            mobileNav.classList.remove('active');
            overlay.classList.remove('active');
        }
    }

    function hideLogoutModal() {
        document.getElementById('logoutModal').style.display = 'none';
    }

    function logout() {
        window.location.href = '../../index.php';
    }

    // Закрытие по клику вне модалки
    document.addEventListener('click', function(e) {
        if (e.target.id === 'logoutModal') {
            hideLogoutModal();
        }
    });
</script>