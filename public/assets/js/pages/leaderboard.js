import { api } from '../api.js';

const metricSelect = document.getElementById('metric-select');
const periodSelect = document.getElementById('period-select');
const tbody = document.getElementById('leaderboard-body');
const heading = document.getElementById('metric-heading');
let requestNumber = 0;

document.getElementById('year').textContent = new Date().getFullYear();

async function loadBoard() {
  const currentRequest = ++requestNumber;
  const metric = metricSelect.value;
  const period = periodSelect.value;
  heading.textContent = { wpm: 'WPM', cpm: 'CPM', accuracy: 'Точность', xp: 'XP' }[metric];
  const row = document.createElement('tr');
  const cell = document.createElement('td');
  cell.colSpan = 3;
  cell.className = 'text-light py-4';
  cell.textContent = 'Загрузка…';
  row.append(cell);
  tbody.replaceChildren(row);
  try {
    const board = await api('/leaderboard', { query: { metric: metric === 'wpm' ? 'cpm' : metric, period } });
    if (currentRequest !== requestNumber) return;
    if (board.items.length === 0) {
      cell.textContent = 'Пока нет результатов за выбранный период';
      return;
    }
    tbody.replaceChildren(...board.items.map(item => {
      const resultRow = document.createElement('tr');
      const value = metric === 'wpm' ? item.wpm : metric === 'cpm' ? item.cpm : metric === 'accuracy' ? item.accuracy : item.value;
      const displayValue = metric === 'accuracy' ? `${Number(value).toFixed(1)}%` : metric === 'xp' ? `${value} XP` : Number(value).toFixed(1);
      for (const content of [item.rank, item.user.display_name, displayValue]) {
        const resultCell = document.createElement('td');
        resultCell.textContent = String(content);
        resultRow.append(resultCell);
      }
      return resultRow;
    }));
  } catch (error) {
    if (currentRequest === requestNumber) cell.textContent = error.message || 'Не удалось загрузить рейтинг';
  }
}

metricSelect.addEventListener('change', loadBoard);
periodSelect.addEventListener('change', loadBoard);
loadBoard();
