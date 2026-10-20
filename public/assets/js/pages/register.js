// public/assets/js/pages/register.js
import { api, isApiError } from '../api.js';

const form = document.getElementById('register-form');
const errorBox = document.getElementById('form-error');
const submitBtn = document.getElementById('submit-btn');
const exitBtn = document.getElementById('btn-exit');
// const backBtn = document.getElementById('btn-back');

// backBtn.addEventListener('click', () => {
//   window.history.back();
// });

exitBtn.addEventListener('click', () => {
  window.location.assign('/');
});

function showError(message) {
  errorBox.textContent = message;
  errorBox.classList.remove('d-none');
}

function hideError() {
  errorBox.classList.add('d-none');
  errorBox.textContent = '';
}

// Если уже вошёл — сразу на профиль.
(async () => {
  try {
    await api('/me');
    window.location.replace('/profile');
  } catch {
    // 401 или сеть — остаёмся на форме.
  }
})();

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  hideError();

  const email = form.elements.email.value.trim().toLowerCase();
  const displayName = form.elements.display_name.value.trim();
  const password = form.elements.password.value;

  if (!email) return showError('Введите email.');
  if (!displayName) return showError('Введите имя.');
  if (displayName.length < 2) return showError('Имя должно быть не короче 2 символов.');
  if (displayName.length > 40) return showError('Имя должно быть не длиннее 40 символов.');

  // Пароль ограничен в байтах UTF-8 (bcrypt, до 72 байт).
  const passwordBytes = new TextEncoder().encode(password).length;
  if (passwordBytes < 10) return showError('Пароль должен быть не короче 10 байт.');
  if (passwordBytes > 72) return showError('Пароль должен быть не длиннее 72 байт.');

  submitBtn.disabled = true;
  const originalText = submitBtn.textContent;
  submitBtn.textContent = 'Создаём…';

  try {
    await api('/auth/register', {
      method: 'POST',
      data: { email, password, display_name: displayName },
    });
    // Сервер сразу устанавливает сессию. Идём на профиль.
    window.location.assign('/profile');
  } catch (err) {
    if (isApiError(err)) {
      if (err.status === 422 && err.fields) {
        showError(Object.values(err.fields).join(' '));
      } else if (err.status === 429) {
        showError('Слишком много попыток регистрации. Попробуйте позже.');
      } else if (err.status === 0) {
        showError('Нет соединения с сервером.');
      } else if (err.code === 'VALIDATION_ERROR' && err.fields) {
        showError(Object.values(err.fields).join(' '));
      } else {
        showError(err.message || 'Не удалось зарегистрироваться.');
      }
    } else {
      showError('Неизвестная ошибка.');
    }
    submitBtn.disabled = false;
    submitBtn.textContent = originalText;
  }
});
