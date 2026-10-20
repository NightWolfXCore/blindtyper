import { api } from '../api.js';

// --- МОК-ДАННЫЕ (пока нет бэкенда) ---
const MOCK_TEXT = "Пример текста для проверки слепой печати. Нажимайте правильные клавиши.";
// -------------------------------------

let session = null;
let events = [];
let startTime = 0;
let currentIndex = 0;
let correctCount = 0;
let errorCount = 0;

const setupPanel = document.getElementById('setup-panel');
const typingPanel = document.getElementById('typing-panel');
const textContainer = document.getElementById('text-container');
const hiddenInput = document.getElementById('hidden-input');
const startBtn = document.getElementById('start-btn');

// Инициализация UI
function renderText(text) {
  textContainer.innerHTML = '';
  Array.from(text).forEach((char, i) => {
    const span = document.createElement('span');
    span.textContent = char;
    span.className = 'char';
    if (i === 0) span.classList.add('active');
    textContainer.appendChild(span);
  });
}

// Запуск
startBtn.addEventListener('click', () => {
  setupPanel.classList.add('d-none');
  typingPanel.classList.remove('d-none');
  
  renderText(MOCK_TEXT);
  hiddenInput.focus();
  
  // В реальности здесь будет await api('/sessions', {method:'POST', ...})
  startTime = performance.now();
});

// Обработка ввода
hiddenInput.addEventListener('input', (e) => {
  const char = e.target.value.slice(-1); // берем последний введенный символ
  e.target.value = ''; // очищаем инпут
  
  if (!char) return;

  const expectedChar = MOCK_TEXT[currentIndex];
  const spans = textContainer.querySelectorAll('.char');
  
  const now = performance.now();
  const at_ms = Math.round(now - startTime);

  // Запись события
  events.push({ seq: events.length + 1, at_ms, char });

  if (char === expectedChar) {
    // Успех
    spans[currentIndex].className = 'char correct';
    correctCount++;
  } else {
    // Ошибка (в строгом режиме позиция не двигается)
    spans[currentIndex].classList.add('incorrect');
    errorCount++;
  }

  // Перемещение курсора, если символ верный
  if (char === expectedChar) {
    currentIndex++;
    if (currentIndex < MOCK_TEXT.length) {
      spans[currentIndex].classList.add('active');
    } else {
      finishTraining(); // Текст закончился
    }
  }

  updateUI();
});

// Обновление циферок
function updateUI() {
  const timeElapsed = (performance.now() - startTime) / 60000; // в минутах
  const cpm = timeElapsed > 0 ? Math.round(correctCount / timeElapsed) : 0;
  const accuracy = (correctCount + errorCount) > 0 
    ? Math.round((correctCount / (correctCount + errorCount)) * 100) 
    : 100;

  document.getElementById('ui-wpm').textContent = Math.round(cpm / 5);
  document.getElementById('ui-accuracy').textContent = accuracy + '%';
}

// Завершение
async function finishTraining() {
  hiddenInput.blur();
  const duration_ms = Math.round(performance.now() - startTime);
  
  alert(`Тренировка окончена!\nСкорость: ${document.getElementById('ui-wpm').textContent} WPM\nОшибок: ${errorCount}`);
  
  // В реальности здесь:
  // await api(`/sessions/${session.public_id}/finish`, { method: 'POST', data: { events } });
  
  window.location.reload();
}