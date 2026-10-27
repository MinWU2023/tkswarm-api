(function (global) {
  'use strict';

  var TOKEN_KEY = 'tkswarm_token';

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
      if (global.__adminOnUnauthorized) global.__adminOnUnauthorized();
      throw new Error(json.message || '请先登录');
    }
    if (!res.ok || json.success === false) {
      throw new Error(json.message || ('请求失败 HTTP ' + res.status));
    }
    return json.data;
  }

  global.AdminAPI = {
    TOKEN_KEY: TOKEN_KEY,
    getToken: getToken,
    setToken: setToken,
    get: function (path) { return request(path, { method: 'GET' }); },
    post: function (path, body) { return request(path, { method: 'POST', body: body }); },
    put: function (path, body) { return request(path, { method: 'PUT', body: body }); },
    del: function (path) { return request(path, { method: 'DELETE' }); },
    upload: function (path, formData) { return request(path, { method: 'POST', body: formData }); },

    captcha: function () { return request('/api/auth/captcha', { method: 'GET' }); },
    login: function (body) { return request('/api/auth/login', { method: 'POST', body: body }); },
    logout: function () { return request('/api/auth/logout', { method: 'POST', body: {} }); },
    authStatus: function () { return request('/api/auth/status', { method: 'GET' }); },

    overview: function () { return request('/admin/api/overview', { method: 'GET' }); },
    siteSettings: function () { return request('/admin/api/site-settings', { method: 'GET' }); },
    saveSiteSettings: function (body) { return request('/admin/api/site-settings', { method: 'PUT', body: body }); },

    ticketStats: function () { return request('/admin/api/tickets/stats', { method: 'GET' }); },
    tickets: function (q) { return request('/admin/api/tickets' + (q || ''), { method: 'GET' }); },
    ticket: function (id) { return request('/admin/api/tickets/' + id, { method: 'GET' }); },
    updateTicket: function (id, body) { return request('/admin/api/tickets/' + id, { method: 'PUT', body: body }); },
    deleteTicket: function (id) { return request('/admin/api/tickets/' + id, { method: 'DELETE' }); },

    contactStats: function () { return request('/admin/api/contact-inquiries/stats', { method: 'GET' }); },
    contactInquiries: function (q) { return request('/admin/api/contact-inquiries' + (q || ''), { method: 'GET' }); },
    contactInquiry: function (id) { return request('/admin/api/contact-inquiries/' + id, { method: 'GET' }); },
    updateContactInquiry: function (id, body) { return request('/admin/api/contact-inquiries/' + id, { method: 'PUT', body: body }); },
    deleteContactInquiry: function (id) { return request('/admin/api/contact-inquiries/' + id, { method: 'DELETE' }); },

    versionStats: function () { return request('/admin/api/versions/stats', { method: 'GET' }); },
    versions: function () { return request('/admin/api/versions', { method: 'GET' }); },
    createVersion: function (body) { return request('/admin/api/versions', { method: 'POST', body: body }); },
    updateVersion: function (id, body) { return request('/admin/api/versions/' + id, { method: 'PUT', body: body }); },
    uploadVersion: function (id, file) {
      var fd = new FormData();
      fd.append('file', file);
      return request('/admin/api/versions/' + id + '/upload', { method: 'POST', body: fd });
    },
    publishVersion: function (id) { return request('/admin/api/versions/' + id + '/publish', { method: 'POST', body: {} }); },
    latestVersion: function (id) { return request('/admin/api/versions/' + id + '/latest', { method: 'POST', body: {} }); },
    deleteVersion: function (id) { return request('/admin/api/versions/' + id, { method: 'DELETE' }); },

    helpStats: function () { return request('/admin/api/help/stats', { method: 'GET' }); },
    categories: function () { return request('/admin/api/help/categories', { method: 'GET' }); },
    createCategory: function (body) { return request('/admin/api/help/categories', { method: 'POST', body: body }); },
    updateCategory: function (id, body) { return request('/admin/api/help/categories/' + id, { method: 'PUT', body: body }); },
    deleteCategory: function (id) { return request('/admin/api/help/categories/' + id, { method: 'DELETE' }); },
    documents: function (q) { return request('/admin/api/help/documents' + (q || ''), { method: 'GET' }); },
    createDocument: function (body) { return request('/admin/api/help/documents', { method: 'POST', body: body }); },
    updateDocument: function (id, body) { return request('/admin/api/help/documents/' + id, { method: 'PUT', body: body }); },
    deleteDocument: function (id) { return request('/admin/api/help/documents/' + id, { method: 'DELETE' }); },

    envInstallers: function (q) { return request('/admin/api/env-installers' + (q || ''), { method: 'GET' }); },
    createEnvInstaller: function (body) { return request('/admin/api/env-installers', { method: 'POST', body: body }); },
    updateEnvInstaller: function (id, body) { return request('/admin/api/env-installers/' + id, { method: 'PUT', body: body }); },
    uploadEnvInstaller: function (id, file) {
      var fd = new FormData();
      fd.append('file', file);
      return request('/admin/api/env-installers/' + id + '/upload', { method: 'POST', body: fd });
    },
    publishEnvInstaller: function (id) { return request('/admin/api/env-installers/' + id + '/publish', { method: 'POST', body: {} }); },
    recommendEnvInstaller: function (id) { return request('/admin/api/env-installers/' + id + '/recommended', { method: 'POST', body: {} }); },
    deleteEnvInstaller: function (id) { return request('/admin/api/env-installers/' + id, { method: 'DELETE' }); }
  };
})(window);
