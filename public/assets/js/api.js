// public/assets/js/api.js
// API-клиент BlindTyper v1. Контракт: docs/api-contract.md

const API_BASE = '/api/v1';

let csrfToken = null;
let csrfPromise = null; // защита от параллельных запросов токена

/** Ошибка API с полями из контракта. */
export class ApiError extends Error {
  constructor(status, code, message, fields) {
    super(message || code || `HTTP ${status}`);
    this.name = 'ApiError';
    this.status = status;   // 0 для сетевой ошибки
    this.code = code;       // стабильный код из контракта
    this.fields = fields;   // опционально: { email: '...' }
  }
}

export function isApiError(e) {
  return e instanceof ApiError;
}

export function getCsrfToken() {
  return csrfToken;
}

export function clearCsrfToken() {
  csrfToken = null;
}

/** Получить/обновить CSRF-токен. Параллельные вызовы разделяют один запрос. */
export async function refreshCsrf() {
  if (csrfPromise) return csrfPromise;
  csrfPromise = (async () => {
    let res;
    try {
      res = await fetch(`${API_BASE}/auth/csrf`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
    } catch {
      throw new ApiError(0, 'NETWORK_ERROR', 'Нет соединения с сервером');
    }
    const body = await safeJson(res);
    if (!res.ok) throw toApiError(res, body);
    csrfToken = body?.data?.csrf_token ?? null;
    if (!csrfToken) {
      throw new ApiError(res.status, 'INTERNAL_ERROR', 'Сервер не вернул CSRF-токен');
    }
    return csrfToken;
  })().finally(() => {
    csrfPromise = null;
  });
  return csrfPromise;
}

/**
 * Универсальный вызов API.
 * @param {string} path  например '/catalog' или '/sessions/abc/start'
 * @param {object} [opts]
 * @param {'GET'|'POST'} [opts.method='GET']
 * @param {any} [opts.data]     тело POST (объект; по умолчанию {})
 * @param {object} [opts.query] query-параметры (undefined/null отбрасываются)
 * @param {AbortSignal} [opts.signal]
 * @returns {Promise<any>} поле data из ответа
 */
export async function api(path, { method = 'GET', data, query, signal } = {}) {
  const url = new URL(`${API_BASE}${path}`, window.location.origin);
  if (query) {
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null) url.searchParams.set(k, String(v));
    }
  }

  const headers = { Accept: 'application/json' };
  let body;

  if (method === 'POST') {
    if (!csrfToken) await refreshCsrf();
    headers['Content-Type'] = 'application/json';
    headers['X-CSRF-Token'] = csrfToken;
    body = JSON.stringify(data ?? {});
  }

  let res;
  try {
    res = await fetch(url.toString(), {
      method,
      credentials: 'same-origin',
      headers,
      body,
      signal,
    });
  } catch (e) {
    if (e.name === 'AbortError') throw e;
    throw new ApiError(0, 'NETWORK_ERROR', 'Нет соединения с сервером');
  }

  const respBody = await safeJson(res);

  if (!res.ok) {
    const err = toApiError(res, respBody);


    if (err.code === 'CSRF_INVALID') csrfToken = null;

    throw err;
  }

 
  if (path === '/auth/login' || path === '/auth/register' || path === '/auth/logout') {
    csrfToken = null;
  }

  return respBody?.data;
}



async function safeJson(res) {
  const ct = res.headers.get('content-type') || '';
  if (!ct.includes('application/json')) return null;
  try {
    return await res.json();
  } catch {
    return null;
  }
}

function toApiError(res, body) {
  if (body && body.error) {
    return new ApiError(res.status, body.error.code, body.error.message, body.error.fields);
  }
  return new ApiError(res.status, `HTTP_${res.status}`, res.statusText || `HTTP ${res.status}`);
}
