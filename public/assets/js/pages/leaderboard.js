import { api } from '../api.js';

async function loadBoard() {
  try {
    const board = await api('/leaderboard?metric=cpm&period=week');
    const tbody = document.getElementById('leaderboard-body');

    if (board.items.length === 0) {
      tbody.innerHTML = '<tr><td colspan="4" class="text-muted py-4">Пока нет результатов</td></tr>';
      return;
    }

    tbody.replaceChildren(...board.items.map(item => {
      const row = document.createElement('tr');
      for (const value of [item.rank, item.user.display_name, Math.round(item.value),
        item.accuracy === null ? '-' : `${Math.round(item.accuracy)}%`]) {
        const cell = document.createElement('td');
        cell.textContent = String(value);
        row.appendChild(cell);
      }
      return row;
    }));
  } catch (e) {
    document.getElementById('leaderboard-body').innerHTML = '<tr><td colspan="4">Не удалось загрузить рейтинг</td></tr>';
  }
}
loadBoard();
