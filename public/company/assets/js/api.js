(function (global) {
  'use strict';

  var TOKEN_KEY = 'tkswarm_company_token';

  function getToken() {
    return localStorage.getItem(TOKEN_KEY) || '';
  }

  function setToken(token) {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
  }

  async function request(path, options) {
    var opts = options || {};
    var headers = Object.assign({}, opts.headers || {});
    var token = getToken();
    if (token) headers.Authorization = 'Bearer ' + token;
    var body = opts.body;
    if (body && !(body instanceof FormData) && typeof body === 'object') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    var res = await fetch(path, Object.assign({}, opts, { headers: headers, body: body }));
    var json = await res.json().catch(function () {
      return { success: false, message: '响应无效' };
    });
    if (res.status === 401) {
      setToken('');
      if (global.__companyOnUnauthorized) global.__companyOnUnauthorized();
      throw new Error(json.message || '请先登录');
    }
    if (!res.ok || json.success === false) {
      throw new Error(json.message || ('请求失败 HTTP ' + res.status));
    }
    return json.data;
  }

  function qs(params) {
    var parts = [];
    Object.keys(params || {}).forEach(function (k) {
      var v = params[k];
      if (v === undefined || v === null || v === '') return;
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(v)));
    });
    return parts.length ? ('?' + parts.join('&')) : '';
  }

  global.CompanyAPI = {
    TOKEN_KEY: TOKEN_KEY,
    getToken: getToken,
    setToken: setToken,
    get: function (path) { return request(path, { method: 'GET' }); },
    post: function (path, body) { return request(path, { method: 'POST', body: body }); },

    captcha: function () { return request('/api/company/auth/captcha', { method: 'GET' }); },
    login: function (body) { return request('/api/company/auth/login', { method: 'POST', body: body }); },
    logout: function () { return request('/api/company/auth/logout', { method: 'POST', body: {} }); },
    authStatus: function () { return request('/api/company/auth/status', { method: 'GET' }); },

    dashboard: function () { return request('/api/company/dashboard', { method: 'GET' }); },
    dashboardPublishes: function (params) {
      return request('/api/company/dashboard/publishes' + qs(params), { method: 'GET' });
    },
    accounts: function (params) { return request('/api/company/accounts' + qs(params), { method: 'GET' }); },
    videos: function (params) { return request('/api/company/videos' + qs(params), { method: 'GET' }); },
    clues: function (params) { return request('/api/company/clues' + qs(params), { method: 'GET' }); },
    analytics: function () { return request('/api/company/analytics', { method: 'GET' }); }
  };
})(window);
