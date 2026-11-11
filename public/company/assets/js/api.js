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
    put: function (path, body) { return request(path, { method: 'PUT', body: body }); },

    captcha: function () { return request('/api/company/auth/captcha', { method: 'GET' }); },
    login: function (body) { return request('/api/company/auth/login', { method: 'POST', body: body }); },
    logout: function () { return request('/api/company/auth/logout', { method: 'POST', body: {} }); },
    authStatus: function () { return request('/api/company/auth/status', { method: 'GET' }); },
    updatePassword: function (body) {
      return request('/api/company/auth/password', { method: 'PUT', body: body });
    },

    dashboard: function () { return request('/api/company/dashboard', { method: 'GET' }); },
    dashboardPublishes: function (params) {
      return request('/api/company/dashboard/publishes' + qs(params), { method: 'GET' });
    },
    dashboardCharts: function (params) {
      return request('/api/company/dashboard/charts' + qs(params), { method: 'GET' });
    },
    pointsRecharge: function () {
      return request('/api/company/points/recharge', { method: 'POST', body: {} });
    },
    pointsSync: function () {
      return request('/api/company/points/sync', { method: 'POST', body: {} });
    },
    notifications: function () { return request('/api/company/notifications', { method: 'GET' }); },
    accounts: function (params) { return request('/api/company/accounts' + qs(params), { method: 'GET' }); },
    accountDetail: function (id) { return request('/api/company/accounts/' + id, { method: 'GET' }); },
    videos: function (params) { return request('/api/company/videos' + qs(params), { method: 'GET' }); },
    videoDetail: function (id) { return request('/api/company/videos/' + id, { method: 'GET' }); },
    clues: function (params) { return request('/api/company/clues' + qs(params), { method: 'GET' }); },
    analytics: function () { return request('/api/company/analytics', { method: 'GET' }); },

    publishAccounts: function () { return request('/api/company/publish/accounts', { method: 'GET' }); },
    publishTasks: function (params) {
      return request('/api/company/publish/tasks' + qs(params), { method: 'GET' });
    },
    createPublishTask: function (formData) {
      return request('/api/company/publish/tasks', { method: 'POST', body: formData });
    },

    opsAccounts: function () { return request('/api/company/ops/accounts', { method: 'GET' }); },
    opsTasks: function (params) {
      return request('/api/company/ops/tasks' + qs(params), { method: 'GET' });
    },
    commentReplyTemplates: function (params) {
      return request('/api/company/ops/comment-reply/templates' + qs(params), { method: 'GET' });
    },
    createCommentReplyTask: function (body) {
      return request('/api/company/ops/comment-reply/tasks', { method: 'POST', body: body });
    },
    createPullVideoListTask: function (body) {
      return request('/api/company/ops/pull-video-list/tasks', { method: 'POST', body: body });
    },
    publishedMaterials: function (params) {
      return request('/api/company/ops/published-materials' + qs(params), { method: 'GET' });
    },
    createPullCommentsTask: function (body) {
      return request('/api/company/ops/pull-video-detail/tasks', { method: 'POST', body: body });
    },
    videoDeliveryList: function (params) {
      return request('/api/company/ops/video-delivery' + qs(params), { method: 'GET' });
    },
    videoDeliveryTypes: function () {
      return request('/api/company/ops/video-delivery/types', { method: 'GET' });
    },
    videoDeliverySubmit: function (body) {
      return request('/api/company/ops/video-delivery', { method: 'POST', body: body || {} });
    },
    videoDeliveryDetail: function (id) {
      return request('/api/company/ops/video-delivery/' + id, { method: 'GET' });
    },
    videoDeliveryConfirm: function (id) {
      return request('/api/company/ops/video-delivery/' + id + '/confirm', { method: 'POST', body: {} });
    },

    youtubeAccounts: function (params) {
      return request('/api/company/ops/youtube-accounts' + qs(params), { method: 'GET' });
    },
    createYoutubeAccount: function (body) {
      return request('/api/company/ops/youtube-accounts', { method: 'POST', body: body || {} });
    },
    updateYoutubeAccount: function (id, body) {
      return request('/api/company/ops/youtube-accounts/' + id, { method: 'PUT', body: body || {} });
    },
    deleteYoutubeAccount: function (id) {
      return request('/api/company/ops/youtube-accounts/' + id, { method: 'DELETE' });
    },
    startYoutubeAuth: function (id) {
      return request('/api/company/ops/youtube-accounts/' + id + '/auth', { method: 'POST', body: {} });
    },
    refreshYoutubeToken: function (id) {
      return request('/api/company/ops/youtube-accounts/' + id + '/refresh-token', { method: 'POST', body: {} });
    },
    youtubeAccountVideos: function (id, params) {
      return request('/api/company/ops/youtube-accounts/' + id + '/videos' + qs(params), { method: 'GET' });
    },
    pullYoutubeAccountVideos: function (id, body) {
      return request('/api/company/ops/youtube-accounts/' + id + '/videos/pull', { method: 'POST', body: body || {} });
    },
    pullYoutubeAccountStats: function (id) {
      return request('/api/company/ops/youtube-accounts/' + id + '/stats/pull', { method: 'POST', body: {} });
    },
    youtubeAccountVideo: function (id, videoId, params) {
      return request('/api/company/ops/youtube-accounts/' + id + '/videos/' + videoId + qs(params), { method: 'GET' });
    },
    deleteYoutubeAccountVideo: function (id, videoId) {
      return request('/api/company/ops/youtube-accounts/' + id + '/videos/' + videoId, { method: 'DELETE' });
    },
    youtubeVideoComments: function (id, videoId) {
      return request('/api/company/ops/youtube-accounts/' + id + '/videos/' + videoId + '/comments', { method: 'GET' });
    },
    pullYoutubeVideoComments: function (id, videoId, body) {
      return request('/api/company/ops/youtube-accounts/' + id + '/videos/' + videoId + '/comments/pull', {
        method: 'POST',
        body: body || { maxPages: 5, fetchAllReplies: true }
      });
    },
    replyYoutubeComment: function (id, commentId, body) {
      return request('/api/company/ops/youtube-accounts/' + id + '/comments/' + commentId + '/reply', {
        method: 'POST',
        body: body || {}
      });
    },
    aiReplyYoutubeComment: function (id, commentId, body) {
      return request('/api/company/ops/youtube-accounts/' + id + '/comments/' + commentId + '/ai-reply', {
        method: 'POST',
        body: body || {}
      });
    },
    deleteYoutubeComment: function (id, commentId) {
      return request('/api/company/ops/youtube-accounts/' + id + '/comments/' + commentId, { method: 'DELETE' });
    },

    chatAccounts: function (params) {
      return request('/api/company/chat/accounts' + qs(params), { method: 'GET' });
    },
    chatFriends: function (params) {
      return request('/api/company/chat/friends' + qs(params), { method: 'GET' });
    },
    chatMessages: function (params) {
      return request('/api/company/chat/messages' + qs(params), { method: 'GET' });
    },
    createChatFriend: function (body) {
      return request('/api/company/chat/friends', { method: 'POST', body: body || {} });
    },
    enqueueSeatTask: function (body) {
      return request('/api/company/chat/seat-tasks', { method: 'POST', body: body || {} });
    },
    seatItemStatus: function (id) {
      return request('/api/company/chat/seat-items/' + id, { method: 'GET' });
    },
    chatSearchCache: function (params) {
      return request('/api/company/chat/search-cache' + qs(params), { method: 'GET' });
    },
    resendChatMessage: function (body) {
      return request('/api/company/chat/messages/resend', { method: 'POST', body: body || {} });
    }
  };
})(window);
