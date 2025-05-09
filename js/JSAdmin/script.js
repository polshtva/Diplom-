

function showModal() {
  document.getElementById("myModal").style.display = "block";
}

function closeModal() {
  document.getElementById("myModal").style.display = "none";
}

function confirmExit() {
  window.location.href = "../../index.php";
}

function createTeacher() {
  window.location.href = "createData.php?type=teacher";
}

function createAdmin() {
  window.location.href = "createData.php?type=admin";
}

function createStudent() {
  window.location.href = "createData.php?type=student";
}

function createData() {
  window.location.href = "createCourse.php";
}

// createData
// Функция для показа модального окна
function showModal() {
  $("#myModal").css("display", "block");
}

// Функция для закрытия модального окна
function closeModal() {
  $("#myModal").css("display", "none");
}

// Функция для подтверждения выхода
function confirmExit() {
  window.location.href = "../../index.php";
}

// Показать/Скрыть пароль
$("#togglePassword").on("click", function () {
  const passwordField = $("#password");
  const type = passwordField.attr("type") === "password" ? "text" : "password";
  passwordField.attr("type", type);
  $(this).text(type === "password" ? "Показать" : "Скрыть");
});

//createCourse

function showModal() {
  document.getElementById("myModal").style.display = "block";
}

function closeModal() {
  document.getElementById("myModal").style.display = "none";
}

function openModal(id) {
  document.getElementById(id).style.display = "block";
}

function closeModal(id) {
  document.getElementById(id).style.display = "none";
}


// Показать/скрыть пароль
document
  .getElementById("togglePassword")
  .addEventListener("change", function () {
    const passwordField = document.getElementById("password");
    passwordField.type = this.checked ? "text" : "password";
  });
// Генерация сложного пароля
$("#generatePassword").on("click", function () {
  const passwordField = $("#password");
  const generatedPassword = generatePassword(12); // Генерация пароля длиной 12 символов
  passwordField.val(generatedPassword);
});

// Функция генерации сложного пароля
function generatePassword(length) {
  const charset =
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+~";
  let password = "";
  for (let i = 0; i < length; i++) {
    const randomIndex = Math.floor(Math.random() * charset.length);
    password += charset[randomIndex];
  }
  return password;
}

// Генерация логина на основе фамилии, имени и отчества
$("#generateUsername").on("click", function () {
  const surname = $("#surname").val();
  const name = $("#name").val();
  const patronymic = $("#patronymic").val();

  const usernameField = $("#username");

  if (surname && name) {
    let username = surname.toLowerCase(); // Фамилия полностью (в нижнем регистре)
    username += name.charAt(0).toLowerCase(); // Первая буква имени (в нижнем регистре)

    if (patronymic) {
      username += patronymic.charAt(0).toLowerCase(); // Первая буква отчества (в нижнем регистре)
    }

    // Добавим случайный идентификатор для уникальности (например, 3 цифры)
    const randomSuffix = Math.floor(Math.random() * 900) + 100; // от 100 до 999
    username += randomSuffix;

    usernameField.val(username); // Устанавливаем сгенерированный логин в поле
  } else {
    alert("Пожалуйста, введите фамилию и имя для генерации логина");
  }
});

document.addEventListener("DOMContentLoaded", function () {
  const parentsContainer = document.getElementById("parents-container");
  const addParentsButton = document.querySelector(".parents__add");
  let parentsCount = 1;
  const maxParents = 3;

  addParentsButton.addEventListener("click", function () {
    if (parentsCount < maxParents) {
      const newParentsDiv = document.createElement("div");
      newParentsDiv.classList.add("parents");
      newParentsDiv.innerHTML = `
                        <div class="data__extra">Данные о родителях</div>
                        <div class="form-group">
                            <label for="surname">Введите фамилию</label>
                            <input type="text" class="input__data" id="surname" name="parent_surname[]" placeholder="Фамилия" required>
                        </div>
                        <div class="form-group">
                            <label for="name">Введите имя</label>
                            <input type="text" class="input__data" id="name" name="parent_name[]" placeholder="Имя" required>
                        </div>
                        <div class="form-group">
                            <label for="patronymic">Введите отчество</label>
                            <input type="text" class="input__data" id="patronymic" name="parent_patronymic[]" placeholder="Отчество" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Номер телефона</label>
                            <input type="text" class="input__data" id="phone" name="parent_phone[]" placeholder="Номер телефона" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Почта</label>
                            <input type="email" class="input__data" id="email" name="parent_email[]" placeholder="Почта" required>
                        </div>
                        <button type="button" class="remove-parents">Удалить</button>
                    `;

      parentsContainer.appendChild(newParentsDiv);
      parentsCount++;

      const removeButton = newParentsDiv.querySelector(".remove-parents");
      removeButton.addEventListener("click", function () {
        parentsContainer.removeChild(newParentsDiv);
        parentsCount--;

        if (parentsCount < maxParents) {
          addParentsButton.style.display = "block";
        }
      });

      if (parentsCount === maxParents) {
        addParentsButton.style.display = "none";
      }
    }
  });
});



        let selectedStudents = [];

        // Открытие модального окна
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        // Закрытие модального окна
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Выбор преподавателя
        function selectTeacher(id, surname, name) {
            document.getElementById('selectedTeacherId').value = id;
            document.getElementById('selectedTeacherName').textContent = `${surname} ${name}`;
            closeModal('teacherModal');
        }

        // Выбор студента
        function selectStudent(id, surname, name, patronymic) {
            // Проверяем, не выбран ли уже этот студент
            if (selectedStudents.some(s => s.id === id)) {
                alert('Этот студент уже выбран');
                return;
            }

            // Добавляем студента в массив
            selectedStudents.push({
                id,
                surname,
                name,
                patronymic
            });

            // Обновляем скрытое поле с ID студентов
            document.getElementById('studentsInput').value = JSON.stringify(selectedStudents.map(s => s.id));

            // Обновляем таблицу выбранных студентов
            updateSelectedStudentsTable();

            closeModal('studentModal');
        }

        // Удаление студента из выбранных
        function removeStudent(id) {
            selectedStudents = selectedStudents.filter(s => s.id !== id);
            document.getElementById('studentsInput').value = JSON.stringify(selectedStudents.map(s => s.id));
            updateSelectedStudentsTable();
        }

        // Обновление таблицы выбранных студентов
        function updateSelectedStudentsTable() {
            const tbody = document.querySelector('#selectedStudentsTable tbody');
            tbody.innerHTML = '';

            if (selectedStudents.length > 0) {
                document.getElementById('selectedStudentsTable').style.display = 'table';

                selectedStudents.forEach(student => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${student.surname}</td>
                        <td>${student.name}</td>
                        <td>${student.patronymic}</td>
                        <td><button class="btn btn-sm btn-danger" onclick="removeStudent(${student.id})">Удалить</button></td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                document.getElementById('selectedStudentsTable').style.display = 'none';
            }
        }


        