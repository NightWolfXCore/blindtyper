import { api } from './api.js';

export const bonusNames = {
  double_xp: 'Двойной опыт',
  speed_boost: 'Ускорение',
};

export const bonusDescriptions = {
  double_xp: '×2 XP на 10 секунд',
  speed_boost: '+50% к игровой скорости и XP на 10 секунд',
};

export function notify(text, kind = 'success') {
  let stack = document.querySelector('.toast-stack');
  if (!stack) {
    stack = document.createElement('div');
    stack.className = 'toast-stack';
    stack.setAttribute('aria-live', 'polite');
    document.body.append(stack);
  }
  const toast = document.createElement('div');
  toast.className = `game-toast ${kind}`;
  toast.textContent = text;
  stack.append(toast);
  window.setTimeout(() => {
    toast.classList.add('exit');
    window.setTimeout(() => toast.remove(), 300);
  }, 4800);
}

export function celebrate() {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const layer = document.createElement('div');
  layer.className = 'confetti-layer';
  layer.setAttribute('aria-hidden', 'true');
  const colors = ['#60a5fa', '#34d399', '#fbbf24', '#f472b6'];
  for (let i = 0; i < 36; i++) {
    const piece = document.createElement('span');
    piece.className = 'confetti-piece';
    piece.style.left = `${Math.random() * 100}%`;
    piece.style.backgroundColor = colors[i % colors.length];
    piece.style.setProperty('--delay', `${Math.random() * .45}s`);
    piece.style.setProperty('--duration', `${1.6 + Math.random() * 1.1}s`);
    piece.style.setProperty('--drift', `${Math.random() * 120 - 60}px`);
    piece.style.setProperty('--turn', `${Math.random() * 540 - 270}deg`);
    layer.append(piece);
  }
  document.body.append(layer);
  window.setTimeout(() => layer.remove(), 3400);
}

export function initLogout() {
  const button = document.getElementById('logout-btn');
  if (!button) return;
  button.addEventListener('click', async () => {
    button.disabled = true;
    try {
      await api('/auth/logout', { method: 'POST' });
      window.location.assign('/login');
    } catch (error) {
      notify(error.message || 'Не удалось выйти из аккаунта', 'error');
      button.disabled = false;
    }
  });
}
