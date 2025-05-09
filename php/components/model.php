
 <!-- Модальное окно -->
 <div id="myModal" class="modal" style="display: none;">
     <div class="modal-content">
         <span class="close" onclick="closeModal()">&times;</span>
         <p class="modal__title">Вы действительно хотите выйти?</p>
         <div class="modal__btn-block">
             <button class="btn btn-danger btn__confirm" onclick="confirmExit()">Да</button>
             <button class="btn btn-primary btn__close" onclick="closeModal()">Нет</button>
         </div>
     </div>
 </div>