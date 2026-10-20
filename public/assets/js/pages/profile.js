import { api } from '../api.js';
import { bonusNames, bonusDescriptions, initLogout } from '../game-ui.js';

initLogout();

document.getElementById('year').textContent = new Date().getFullYear();

async function loadProfile() {
  try {
    const user = await api('/me');
    document.getElementById('user-name').textContent = user.display_name;
    document.getElementById('user-level').textContent = `Уровень ${user.level}`;

    // Прогресс в пределах текущего уровня.
    const previousLevelXp = 100 * (user.level - 1) ** 2;
    const percent = Math.min(100, 100 * (user.total_xp - previousLevelXp) / (user.next_level_xp - previousLevelXp));
    document.getElementById('xp-progress').style.width = `${percent}%`;
    document.getElementById('xp-text').textContent = `${user.total_xp} / ${user.next_level_xp} XP`;

    const stats = await api('/me/stats?days=30');
    document.getElementById('stat-cpm').textContent = `${Math.round(stats.totals.best_cpm || 0)} CPM`;

    const bonuses = await api('/me/bonuses');
    const bonusContainer = document.getElementById('profile-bonuses');
    if (bonuses.items.length === 0) {
      bonusContainer.textContent = 'Бонусы появятся здесь после тренировки, в которой они сработали.';
    } else {
      bonusContainer.replaceChildren(...bonuses.items.map(bonus => {
        const column = document.createElement('div');
        column.className = 'col-md-6';
        const card = document.createElement('div');
        card.className = 'bonus-card h-100';
        const title = document.createElement('strong');
        title.textContent = bonusNames[bonus.type] || bonus.type;
        const details = document.createElement('p');
        details.className = 'small mt-1';
        details.textContent = `${bonusDescriptions[bonus.type] || ''} · ${new Date(bonus.earned_at).toLocaleDateString('ru-RU')}`;
        card.append(title, details);
        column.append(card);
        return column;
      }));
    }

    const ach = await api('/me/achievements');
    const container = document.getElementById('achievements-container');
    container.replaceChildren(...ach.items.map(a => {
      const column = document.createElement('div');
      column.className = 'col-md-4';
      const card = document.createElement('div');
      card.className = `card h-100 ${a.unlocked_at ? 'border-success' : 'border-secondary'}`;
      const body = document.createElement('div');
      body.className = 'card-body';
      const title = document.createElement('h6');
      title.className = 'card-title';
      title.textContent = a.title;
      const description = document.createElement('p');
      description.className = 'card-text small text-muted';
      description.textContent = a.description;
      const badge = document.createElement('span');
      badge.className = `badge ${a.unlocked_at ? 'bg-success' : 'bg-secondary'}`;
      badge.textContent = a.unlocked_at ? 'Получено' : 'Закрыто';
      body.append(title, description, badge);
      card.appendChild(body);
      column.appendChild(card);
      return column;
    }));
  } catch (e) {
    if (e.status === 401) window.location.href = '/login';
    else document.getElementById('user-name').textContent = 'Не удалось загрузить профиль';
  }
}
loadProfile();
