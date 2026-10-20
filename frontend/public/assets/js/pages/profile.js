import { api } from '../api.js';

async function loadProfile() {
  try {
    const user = await api('/me');
    document.getElementById('user-name').textContent = user.display_name;
    document.getElementById('user-level').textContent = `Уровень ${user.level}`;
    
    // Прогресс бар (примитивный)
    const percent = Math.min(100, (user.total_xp / user.next_level_xp) * 100);
    document.getElementById('xp-progress').style.width = `${percent}%`;
    document.getElementById('xp-text').textContent = `${user.total_xp} / ${user.next_level_xp} XP`;

    const stats = await api('/me/stats?days=30');
    document.getElementById('stat-cpm').textContent = `${Math.round(stats.totals.best_cpm || 0)} CPM`;

    const ach = await api('/me/achievements');
    const container = document.getElementById('achievements-container');
    container.innerHTML = ach.items.map(a => `
      <div class="col-md-4">
        <div class="card h-100 ${a.unlocked_at ? 'border-success' : 'opacity-50'}">
          <div class="card-body">
            <h6 class="card-title">${a.title}</h6>
            <p class="card-text small text-muted">${a.description}</p>
            <span class="badge ${a.unlocked_at ? 'bg-success' : 'bg-secondary'}">
              ${a.unlocked_at ? 'Получено' : 'Закрыто'}
            </span>
          </div>
        </div>
      </div>
    `).join('');
  } catch (e) {
    if (e.status === 401) window.location.href = '/login';
  }
}
loadProfile();