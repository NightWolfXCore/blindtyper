import { api } from '../api.js';
import { bonusNames, bonusDescriptions, celebrate, initLogout, notify } from '../game-ui.js';

const setupPanel = document.getElementById('setup-panel');
const typingPanel = document.getElementById('typing-panel');
const textContainer = document.getElementById('text-container');
const hiddenInput = document.getElementById('hidden-input');
const startBtn = document.getElementById('start-btn');
const finishBtn = document.getElementById('finish-btn');
const repeatBtn = document.getElementById('repeat-btn');
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
const greeting = document.getElementById('greeting');
const homeBonuses = document.getElementById('home-bonuses');
const bonusCard = document.getElementById('session-bonus');
const bonusName = document.getElementById('session-bonus-name');
const bonusDetail = document.getElementById('session-bonus-detail');
const logoutBtn = document.getElementById('logout-btn');
const countdownOverlay = document.getElementById('start-countdown');
const countdownValue = document.getElementById('start-countdown-value');
const wrongSound = new Audio('/assets/audio/wrong.mp3');
const victorySound = new Audio('/assets/audio/correct.mp3');
const countdownSound = new Audio('/assets/audio/3-2-1-countdown.mp3');

wrongSound.volume = .22;
victorySound.volume = .55;
countdownSound.volume = .55;

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
let bonusTimer = null;
let bonusAnnounced = false;
let lastWrongSound = 0;

initLogout();

document.getElementById('year').textContent = new Date().getFullYear();

function playSound(sound) {
  try {
    sound.currentTime = 0;
    sound.play().catch(() => {});
  } catch { /* Sound is optional if the browser blocks playback. */ }
}


async function showStartCountdown() {
  countdownOverlay.classList.remove('d-none');
  countdownValue.classList.remove('word');
  playSound(countdownSound);
  for (let seconds = 3; seconds > 0; seconds--) {
    countdownValue.textContent = String(seconds);
    countdownValue.classList.remove('tick');
    void countdownValue.offsetWidth;
    countdownValue.classList.add('tick');
    await new Promise(resolve => window.setTimeout(resolve, 1000));
  }
  countdownValue.textContent = '…';
}

async function loadBonusHistory() {
  try {
    const history = await api('/me/bonuses');
    const last = history.items[0];
    homeBonuses.textContent = last
      ? `Последний полученный бонус: ${bonusNames[last.type] || last.type}. В следующей тренировке выпадет случайный бонус.`
      : 'Во время тренировки выпадет случайный бонус. Полученные бонусы появятся в профиле.';
  } catch {
    homeBonuses.textContent = 'Во время тренировки может появиться случайный бонус.';
  }
}

function updateBonus() {
  if (!session || finished || !startedAt) return;
  const bonus = session.bonuses?.[0];
  if (!bonus) return;
  const elapsed = performance.now() - startedAt;
  if (elapsed < bonus.starts_at_ms) {
    bonusName.textContent = 'Бонус скоро появится';
    bonusDetail.textContent = `До бонуса ${Math.ceil((bonus.starts_at_ms - elapsed) / 1000)} с`;
    return;
  }
  if (elapsed < bonus.ends_at_ms) {
    bonusCard.classList.add('active');
    bonusName.textContent = bonusNames[bonus.type] || bonus.type;
    const boostedWpm = Math.round((position / Math.max(1, elapsed) * 12000) * 1.5);
    const speedText = bonus.type === 'speed_boost' ? ` · игровой темп ${boostedWpm} WPM` : '';
    bonusDetail.textContent = `${bonusDescriptions[bonus.type] || ''}${speedText} · ещё ${Math.ceil((bonus.ends_at_ms - elapsed) / 1000)} с`;
    if (!bonusAnnounced) {
      notify(`Новый бонус: ${bonusNames[bonus.type] || bonus.type}!`, 'bonus');
      bonusAnnounced = true;
    }
    return;
  }
  bonusCard.classList.remove('active');
  bonusName.textContent = 'Бонус завершён';
  bonusDetail.textContent = 'Продолжайте печатать до конца упражнения.';
}

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
    greeting.textContent = `С возвращением, ${user.display_name}!`;
    profileLink.textContent = `${user.display_name} · профиль`;
    profileLink.classList.remove('d-none');
    loginLink.classList.add('d-none');
    logoutBtn.classList.remove('d-none');
    loadBonusHistory();
    syncMode();
  } catch (error) {
    levelStatus.textContent = error.status === 401
      ? 'Вы не вошли в аккаунт. Войдите, чтобы сохранять прогресс.'
      : 'Не удалось загрузить ваш уровень.';
    greeting.textContent = 'Добро пожаловать в BlindTyper!';
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
    window.clearInterval(bonusTimer);
    bonusCard.classList.remove('active');
    bonusName.textContent = 'Тренировка завершена';
    bonusDetail.textContent = result.result.bonuses_applied?.length
      ? `Получен бонус: ${result.result.bonuses_applied.map(type => bonusNames[type] || type).join(', ')}`
      : 'Бонус не успел сработать. Попробуйте ещё раз.';
    document.getElementById('ui-wpm').textContent = String(Math.round(result.result.wpm));
    document.getElementById('ui-accuracy').textContent = `${Math.round(result.result.accuracy)}%`;
    document.getElementById('ui-combo').textContent = String(result.result.max_combo);
    showMessage(`Результат сохранён: ${result.result.xp_earned} XP. Скорость ${Math.round(result.result.cpm)} CPM.`);
    playSound(victorySound);
    celebrate();
    if (result.progress.level > currentLevel) {
      notify(`Новый уровень: ${result.progress.level}!`, 'success');
    }
    for (const achievement of result.new_achievements || []) {
      notify(`Новое достижение: ${achievement.title}`, 'success');
    }
    currentLevel = result.progress.level;
    levelStatus.textContent = `Ваш уровень: ${result.progress.level} · Опыт: ${result.progress.total_xp} XP`;
    loadBonusHistory();
    finishBtn.textContent = 'Новая тренировка';
    finishBtn.classList.remove('btn-outline-light');
    finishBtn.classList.add('btn-success');
    repeatBtn.disabled = false;
    repeatBtn.classList.remove('d-none');
  } catch (error) {
    showMessage(`${error.message || 'Не удалось сохранить результат'}. Повторите сохранение.`, true);
    finishBtn.textContent = 'Повторить сохранение';
  } finally {
    saving = false;
    finishBtn.disabled = false;
  }
}

function typeCharacter(char) {
  if (!session || !startedAt || saving || finished || position >= characters.length) return;
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
    if (performance.now() - lastWrongSound > 130) {
      playSound(wrongSound);
      lastWrongSound = performance.now();
    }
  }
  updateCounters();
}

startBtn.disabled = true;
loadCatalog();
loadIdentity();
modeSelect.addEventListener('change', syncMode);
textContainer.addEventListener('click', () => {
  if (startedAt && !finished) hiddenInput.focus();
});
async function startTraining() {
  if (saving || (session && !finished)) return;
  startBtn.disabled = true;
  repeatBtn.disabled = true;
  repeatBtn.classList.add('d-none');
  finishBtn.classList.remove('btn-success');
  finishBtn.classList.add('btn-outline-light');
  finishBtn.textContent = 'Новая тренировка';
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
    session = created;
    startedAt = 0;
    finished = false;
    bonusAnnounced = false;
    bonusCard.classList.remove('active');
    hiddenInput.disabled = true;
    events = [];
    position = errors = combo = maxCombo = 0;
    document.getElementById('ui-wpm').textContent = '0';
    document.getElementById('ui-accuracy').textContent = '100%';
    document.getElementById('ui-combo').textContent = '0';
    renderText(created.text);
    setupPanel.classList.add('d-none');
    typingPanel.classList.remove('d-none');
    await showStartCountdown();
    await api(`/sessions/${created.public_id}/start`, { method: 'POST' });
    countdownValue.textContent = 'Старт!';
    countdownValue.classList.add('word');
    startedAt = performance.now();
    hiddenInput.disabled = false;
    updateBonus();
    window.clearInterval(bonusTimer);
    bonusTimer = window.setInterval(updateBonus, 250);
    hiddenInput.focus();
    window.setTimeout(() => countdownOverlay.classList.add('d-none'), 450);
  } catch (error) {
    countdownSound.pause();
    countdownOverlay.classList.add('d-none');
    typingPanel.classList.add('d-none');
    setupPanel.classList.remove('d-none');
    hiddenInput.disabled = true;
    session = null;
    startedAt = 0;
    showMessage(error.status === 401 ? 'Войдите в аккаунт через страницу входа, затем начните тренировку.' :
      error.message || 'Не удалось начать тренировку', true);
    startBtn.disabled = false;
  }
}

startBtn.addEventListener('click', startTraining);
repeatBtn.addEventListener('click', startTraining);

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
