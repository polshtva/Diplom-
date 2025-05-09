$(document).ready(function () {
  // Открытие модального окна
  $("#login-btn").on("click", function (e) {
    e.preventDefault(); // Отключаем стандартное поведение ссылки
    $("#login-modal").fadeIn();
    $("body").css("overflow", "hidden"); // Отключаем прокрутку страницы
  });

  // Открытие модального окна для восстановления пароля
  $("#forgot-password-link").on("click", function (e) {
    e.preventDefault(); // Отключаем стандартное поведение ссылки
    $("#login-modal").fadeOut(); // Закрываем окно авторизации
    $("#forgot-password-modal").fadeIn(); // Открываем окно восстановления пароля
  });

  // Закрытие модального окна при нажатии на крестик
  $(".close").on("click", function () {
    $("#forgot-password-modal").fadeOut();
    $("#login-modal").fadeOut();
    $("body").css("overflow", "auto"); // Включаем прокрутку обратно
  });

  // Закрытие модального окна при клике вне контента
  $(window).on("click", function (e) {
    if ($(e.target).is("#login-modal")) {
      $("#login-modal").fadeOut();
      $("body").css("overflow", "auto"); // Включаем прокрутку обратно
    }
  });
});
