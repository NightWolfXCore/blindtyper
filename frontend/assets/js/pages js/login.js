// public/assets/js/pages/login.js
import { api, isApiError } from '../api.js';

const form = document.getElementById('login-form');
const errorBox = document.getElementById('form-error');
const submitBtn = document.getElementById('submit-btn');

function showError(message) {
  errorBox.textContent = message;
  errorBox.classList.remove('d-none');
}

function hideError() {
  errorBox.classList.add('d-none');
  errorBox.textContent = '';
}

// Если пользователь уже вошёл — сразу на профиль.
(async () => {
  try {
    await api('/me');
    window.location.replace('/profile');
  } catch (e) {
    // 401 — нормально, остаёмся на форме. Сетевую ошибку тоже игнорируем.
  }
})();

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  hideError();

  const email = form.email.value.trim().toLowerCase();
  const password = form.password.value;

  if (!email) return showError('Введите email.');
  if (!password) return showError('Введите пароль.');

  submitBtn.disabled = true;
  const originalText = submitBtn.textContent;
  submitBtn.textContent = 'Входим…';

  try {
    await api('/auth/login', {
      method: 'POST',
      data: { email, password },
    });
    // api.js уже обновил CSRF-токен в фоне. Просто уходим на профиль.
    window.location.assign('/profile');
  } catch (err) {
    if (isApiError(err)) {
      if (err.status === 401) {
        showError('Неверный email или пароль.');
      } else if (err.status === 429) {
        showError('Слишком много попыток. Попробуйте позже.');
      } else if (err.status === 0) {
        showError('Нет соединения с сервером.');
      } else if (err.fields) {
        showError(Object.values(err.fields).join(' '));
      } else {
        showError(err.message || 'Не удалось войти.');
      }
    } else {
      showError('Неизвестная ошибка.');
    }
    submitBtn.disabled = false;
    submitBtn.textContent = originalText;
  }
});