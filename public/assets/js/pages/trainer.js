import { api } from '../api.js';

const setupPanel = document.getElementById('setup-panel');
const typingPanel = document.getElementById('typing-panel');
const textContainer = document.getElementById('text-container');
const hiddenInput = document.getElementById('hidden-input');
const startBtn = document.getElementById('start-btn');
const finishBtn = document.getElementById('finish-btn');
const message = document.getElementById('training-message');
const modeSelect = document.getElementById('mode-select');
const languageSelect = document.getElementById('lang-select');
const difficultySelect = document.getElementById('difficulty-select');
const lengthSelect = document.getElementById('length-select');
const codeLanguageSelect = document.getElementById('code-language-select');
const codeLanguageField = document.getElementById('code-language-field');
const loginLink = document.getElementById('login-link');
const profileLink = document.getElementById('profile-link');
const levelStatus = document.getElementById('level-status');
const modeHelp = document.getElementById('mode-help');

let session = null;
let characters = [];
let spans = [];
let events = [];
let startedAt = 0;
let position = 0;
let errors = 0;
let combo = 0;
let maxCombo = 0;
let saving = false;
let finished = false;
let modes = [];
let currentLevel = 1;

function showMessage(text, isError = false) {
  message.textContent = text;
  message.className = `alert ${isError ? 'alert-danger' : 'alert-info'}`;
}

function fill(select, values, labels = {}) {
  select.replaceChildren(...values.map(value => {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = labels[value] ?? value;
    return option;
  }));
}

function syncMode() {
  const code = modeSelect.value === 'code';
  codeLanguageField.classList.toggle('d-none', !code);
  languageSelect.disabled = code;
  if (code) languageSelect.value = 'en';
  const selected = modes.find(mode => mode.id === modeSelect.value);
  if (selected) {
    modeHelp.textContent = selected.unlocked
      ? `Режим доступен с уровня ${selected.required_level}. Ваш уровень: ${currentLevel}.`
      : `Режим откроется на уровне ${selected.required_level}. Ваш уровень: ${currentLevel}.`;
    startBtn.disabled = !selected.unlocked;
  }
}

async function loadCatalog() {
  try {
    const catalog = await api('/catalog');
    modes = catalog.modes;
    const names = {
      words: 'Слова', sentences: 'Предложения', symbols: 'Символы', code: 'Код',
    };
    fill(modeSelect, modes.map(mode => mode.id), Object.fromEntries(
      modes.map(mode => [mode.id, `${names[mode.id]} · с уровня ${mode.required_level}`])
    ));
    fill(languageSelect, catalog.languages, { ru: 'Русский', en: 'English' });
    fill(difficultySelect, catalog.difficulties, { easy: 'Легко', medium: 'Средне', hard: 'Сложно' });
    fill(lengthSelect, catalog.lengths.map(String), Object.fromEntries(catalog.lengths.map(n => [n, `${n} символов`])));
    fill(codeLanguageSelect, catalog.code_languages, {
      python: 'Python', javascript: 'JavaScript', html: 'HTML', css: 'CSS', mixed: 'Все языки вместе',
    });
    syncMode();
  } catch (error) {
    showMessage(error.message || 'Не удалось загрузить каталог', true);
  }
}

async function loadIdentity() {
  try {
    const user = await api('/me');
    currentLevel = user.level;
    levelStatus.textContent = `Ваш уровень: ${user.level} · Опыт: ${user.total_xp} XP`;
    profileLink.textContent = `${user.display_name} · профиль`;
    profileLink.classList.remove('d-none');
    loginLink.classList.add('d-none');
    syncMode();
  } catch (error) {
    levelStatus.textContent = error.status === 401
      ? 'Вы не вошли в аккаунт. Войдите, чтобы сохранять прогресс.'
      : 'Не удалось загрузить ваш уровень.';
    if (error.status !== 401) profileLink.classList.remove('d-none');
  }
}

function renderText(text) {
  characters = Array.from(text);
  spans = characters.map(char => {
    const span = document.createElement('span');
    span.className = 'char';
    span.textContent = char;
    return span;
  });
  textContainer.replaceChildren(...spans);
  spans[0]?.classList.add('active');
}

function updateCounters() {
  const elapsedMinutes = Math.max(1, performance.now() - startedAt) / 60000;
  const cpm = position / elapsedMinutes;
  document.getElementById('ui-wpm').textContent = String(Math.round(cpm / 5));
  document.getElementById('ui-accuracy').textContent = `${Math.round(100 * position / Math.max(1, position + errors))}%`;
  document.getElementById('ui-combo').textContent = String(combo);
}

async function saveResult() {
  if (saving || finished) return;
  saving = true;
  finishBtn.disabled = true;
  try {
    const result = await api(`/sessions/${session.public_id}/finish`, {
      method: 'POST', data: { events },
    });
    finished = true;
    document.getElementById('ui-wpm').textContent = String(Math.round(result.result.wpm));
    document.getElementById('ui-accuracy').textContent = `${Math.round(result.result.accuracy)}%`;
    document.getElementById('ui-combo').textContent = String(result.result.max_combo);
    showMessage(`Результат сохранён: ${result.result.xp_earned} XP. Скорость ${Math.round(result.result.cpm)} CPM.`);
    finishBtn.textContent = 'Новая тренировка';
  } catch (error) {
    showMessage(`${error.message || 'Не удалось сохранить результат'}. Повторите сохранение.`, true);
    finishBtn.textContent = 'Повторить сохранение';
  } finally {
    saving = false;
    finishBtn.disabled = false;
  }
}

function typeCharacter(char) {
  if (!session || saving || finished || position >= characters.length) return;
  const atMs = Math.max(0, Math.round(performance.now() - startedAt));
  if (atMs > session.max_duration_ms) {
    hiddenInput.blur();
    showMessage('Время тренировки истекло. Начните новую.', true);
    return;
  }
  if (Array.from(char).length !== 1) return;
  events.push({ seq: events.length + 1, at_ms: atMs, char });
  const span = spans[position];
  if (char === characters[position]) {
    span.classList.remove('active', 'incorrect');
    span.classList.add('correct');
    position++;
    combo++;
    maxCombo = Math.max(maxCombo, combo);
    if (position < spans.length) spans[position].classList.add('active');
    else {
      hiddenInput.blur();
      saveResult();
    }
  } else {
    span.classList.add('incorrect');
    errors++;
    combo = 0;
  }
  updateCounters();
}

startBtn.disabled = true;
loadCatalog();
loadIdentity();
modeSelect.addEventListener('change', syncMode);
textContainer.addEventListener('click', () => hiddenInput.focus());
startBtn.addEventListener('click', async () => {
  startBtn.disabled = true;
  message.classList.add('d-none');
  try {
    const created = await api('/sessions', {
      method: 'POST',
      data: {
        mode: modeSelect.value,
        language: languageSelect.value,
        difficulty: difficultySelect.value,
        length: Number(lengthSelect.value),
        code_language: modeSelect.value === 'code' ? codeLanguageSelect.value : null,
      },
    });
    await api(`/sessions/${created.public_id}/start`, { method: 'POST' });
    session = created;
    events = [];
    position = errors = combo = maxCombo = 0;
    renderText(created.text);
    setupPanel.classList.add('d-none');
    typingPanel.classList.remove('d-none');
    startedAt = performance.now();
    hiddenInput.focus();
  } catch (error) {
    showMessage(error.status === 401 ? 'Войдите в аккаунт через страницу входа, затем начните тренировку.' :
      error.message || 'Не удалось начать тренировку', true);
    startBtn.disabled = false;
  }
});

hiddenInput.addEventListener('paste', event => event.preventDefault());
hiddenInput.addEventListener('drop', event => event.preventDefault());
hiddenInput.addEventListener('keydown', event => {
  if (event.key === 'Enter' && !event.isComposing) {
    event.preventDefault();
    typeCharacter('\n');
  }
});
hiddenInput.addEventListener('input', event => {
  if (event.isComposing) return;
  if (event.inputType === 'insertFromPaste') {
    hiddenInput.value = '';
    return;
  }
  const typed = Array.from(hiddenInput.value);
  hiddenInput.value = '';
  for (const char of typed) typeCharacter(char);
});
finishBtn.addEventListener('click', () => {
  if (position === characters.length && !finished) saveResult();
  else window.location.reload();
});
