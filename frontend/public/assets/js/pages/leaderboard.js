import { api } from '../api.js';

async function loadBoard() {
  try {
    const board = await api('/leaderboard?metric=cpm&period=week');
    const tbody = document.getElementById('leaderboard-body');
    
    if (board.items.length === 0) {
      tbody.innerHTML = '<tr><td colspan="4" class="text-muted py-4">Пока нет результатов</td></tr>';
      return;
    }

    tbody.innerHTML = board.items.map(item => `
      <tr>
        <td class="fw-bold">${item.rank}</td>
        <td>${item.user.display_name}</td>
        <td class="text-primary fw-bold">${Math.round(item.value)}</td>
        <td>${item.accuracy ? Math.round(item.accuracy) + '%' : '-'}</td>
      </tr>
    `).join('');
  } catch (e) {
    console.error(e);
  }
}
loadBoard();