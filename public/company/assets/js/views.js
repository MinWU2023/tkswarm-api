(function (global) {
  'use strict';

  var h = Vue.h;
  var ElMessage = ElementPlus.ElMessage;

  function pageHead(title, desc) {
    return h('div', { class: 'page-head' }, [
      h('h1', title),
      desc ? h('p', desc) : null
    ]);
  }

  function panelHead(title, extra) {
    return h('div', { class: 'panel-head' }, [
      h('h3', title),
      extra || null
    ]);
  }

  function fmtNum(n) {
    var v = Number(n) || 0;
    if (v >= 10000) return (v / 10000).toFixed(v >= 100000 ? 0 : 1) + '万';
    return String(v);
  }

  function loginLabel(s) {
    var m = { online: '在线', offline: '离线', expired: '失效', unknown: '未知' };
    return m[s] || s || '—';
  }

  var LoginView = {
    name: 'LoginView',
    data: function () {
      return {
        form: { username: '', password: '', captchaCode: '', captchaKey: '', rememberMe: true },
        captchaSvg: '',
        loading: false
      };
    },
    mounted: function () {
      var saved = localStorage.getItem('tkswarm_company_remember_user') || '';
      if (saved) this.form.username = saved;
      this.refreshCaptcha();
    },
    methods: {
      refreshCaptcha: async function () {
        try {
          var d = await CompanyAPI.captcha();
          this.form.captchaKey = d.captchaKey;
          this.captchaSvg = d.svg;
          this.form.captchaCode = '';
        } catch (e) {
          ElMessage.error(e.message || '验证码加载失败');
        }
      },
      submit: async function () {
        if (!this.form.username || !this.form.password) {
          ElMessage.warning('请输入账号和密码');
          return;
        }
        this.loading = true;
        try {
          var data = await CompanyAPI.login(this.form);
          CompanyAPI.setToken(data.token);
          if (this.form.rememberMe) {
            localStorage.setItem('tkswarm_company_remember_user', this.form.username);
          } else {
            localStorage.removeItem('tkswarm_company_remember_user');
          }
          ElMessage.success('登录成功');
          this.$router.replace('/dashboard');
        } catch (e) {
          ElMessage.error(e.message || '登录失败');
          this.refreshCaptcha();
        } finally {
          this.loading = false;
        }
      }
    },
    render: function () {
      var self = this;
      return h('div', { class: 'auth-gate' }, [
        h('div', { class: 'auth-split' }, [
          h('section', { class: 'auth-hero' }, [
            h('div', { class: 'brand' }, [
              h('img', { class: 'brand-logo', src: '/images/logo.png?v=2', alt: 'TkSwarm', width: 168, height: 64 }),
              h('span', { class: 'brand-tag' }, '企业门户')
            ]),
            h('h1', '扬帆出海 · 融通全球'),
            h('p', '跨洋巨轮破浪远航，TikTok 矩阵全域赋能。为企业客户提供账号、视频与拓客线索的统一数据门户。'),
            h('ul', [
              h('li', '企业账号矩阵一眼可见'),
              h('li', '视频数据与发布表现追踪'),
              h('li', 'AI 拓客线索沉淀与跟进')
            ])
          ]),
          h('section', { class: 'auth-panel' }, [
            h('h2', '企业客户登录'),
            h('p', { class: 'muted' }, '仅企业客户角色账号可进入本门户'),
            h(ElementPlus.ElForm, {
              labelPosition: 'top',
              onSubmit: function (e) { e.preventDefault(); self.submit(); }
            }, function () {
              return [
                h(ElementPlus.ElFormItem, { label: '账号' }, function () {
                  return h(ElementPlus.ElInput, {
                    modelValue: self.form.username,
                    'onUpdate:modelValue': function (v) { self.form.username = v; },
                    placeholder: '企业客户账号',
                    size: 'large',
                    prefixIcon: ElementPlusIconsVue.User
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '密码' }, function () {
                  return h(ElementPlus.ElInput, {
                    modelValue: self.form.password,
                    'onUpdate:modelValue': function (v) { self.form.password = v; },
                    type: 'password',
                    showPassword: true,
                    size: 'large',
                    placeholder: '登录密码',
                    prefixIcon: ElementPlusIconsVue.Lock
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '验证码' }, function () {
                  return h('div', { class: 'captcha-box' }, [
                    h(ElementPlus.ElInput, {
                      modelValue: self.form.captchaCode,
                      'onUpdate:modelValue': function (v) { self.form.captchaCode = v; },
                      placeholder: '验证码',
                      size: 'large',
                      style: 'flex:1',
                      maxlength: 4
                    }),
                    self.captchaSvg
                      ? h('img', { src: self.captchaSvg, title: '点击刷新', onClick: function () { self.refreshCaptcha(); } })
                      : h(ElementPlus.ElButton, { onClick: function () { self.refreshCaptcha(); } }, function () { return '刷新'; })
                  ]);
                }),
                h('div', { class: 'login-meta-row' }, [
                  h(ElementPlus.ElCheckbox, {
                    modelValue: self.form.rememberMe,
                    'onUpdate:modelValue': function (v) { self.form.rememberMe = v; }
                  }, function () { return '记住账号'; }),
                  h('span', { class: 'text-xs text-slate-400' }, '企业门户专用')
                ]),
                h(ElementPlus.ElButton, {
                  type: 'primary',
                  class: 'w-full',
                  size: 'large',
                  loading: self.loading,
                  onClick: function () { self.submit(); }
                }, function () { return '登录'; })
              ];
            })
          ])
        ])
      ]);
    }
  };

  var DashboardView = {
    name: 'DashboardView',
    data: function () {
      return { loading: false, stats: {}, recent: [], welcome: '' };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var d = await CompanyAPI.dashboard();
          this.stats = d.stats || {};
          this.recent = d.recentAccounts || [];
          this.welcome = d.welcome || '';
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.loading = false;
        }
      }
    },
    render: function () {
      var self = this;
      var s = this.stats || {};
      var cards = [
        { label: '账号总数', value: s.accounts || 0, hint: '在线 ' + (s.online || 0), cls: 'accent-a' },
        { label: '在线账号', value: s.online || 0, hint: '当前可作业', cls: 'accent-b' },
        { label: '视频数据', value: s.videos || 0, hint: '已收录线上视频', cls: 'accent-c' },
        { label: '拓客线索', value: s.clues || 0, hint: 'AI 线索沉淀', cls: 'accent-d' }
      ];
      return h('div', { class: 'page-view', 'v-loading': this.loading }, [
        pageHead('控制面板', (this.welcome ? ('你好，' + this.welcome + '。') : '') + '企业门户数据总览'),
        h('div', { class: 'stat-grid' }, cards.map(function (c) {
          return h('div', { class: 'stat-card ' + c.cls }, [
            h('div', { class: 'label' }, c.label),
            h('div', { class: 'value' }, String(c.value)),
            h('div', { class: 'hint' }, c.hint)
          ]);
        })),
        h('div', { class: 'panel' }, [
          panelHead('最近更新账号', h(ElementPlus.ElButton, {
            text: true, type: 'primary',
            onClick: function () { self.$router.push('/accounts'); }
          }, function () { return '查看全部'; })),
          h(ElementPlus.ElTable, { data: this.recent, stripe: true, size: 'default', style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 80 }),
              h(ElementPlus.ElTableColumn, { prop: 'username', label: '账号', minWidth: 140 }),
              h(ElementPlus.ElTableColumn, { prop: 'nickname', label: '昵称', minWidth: 140 }),
              h(ElementPlus.ElTableColumn, { label: '登录态', width: 100 }, {
                default: function (scope) {
                  var online = scope.row.loginStatus === 'online';
                  return h('span', { class: online ? 'status-online' : 'status-offline' }, loginLabel(scope.row.loginStatus));
                }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'updatedAt', label: '更新时间', width: 180 })
            ];
          })
        ])
      ]);
    }
  };

  var AccountsView = {
    name: 'AccountsView',
    data: function () {
      return { loading: false, items: [], total: 0, page: 1, pageSize: 20, keyword: '' };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var d = await CompanyAPI.accounts({ page: this.page, pageSize: this.pageSize, keyword: this.keyword });
          this.items = d.items || [];
          this.total = d.total || 0;
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.loading = false;
        }
      },
      search: function () {
        this.page = 1;
        this.load();
      }
    },
    render: function () {
      var self = this;
      return h('div', { class: 'page-view' }, [
        pageHead('账号列表', '查看本企业名下 TikTok 账号状态'),
        h('div', { class: 'panel' }, [
          h('div', { class: 'page-toolbar' }, [
            h(ElementPlus.ElInput, {
              modelValue: self.keyword,
              'onUpdate:modelValue': function (v) { self.keyword = v; },
              placeholder: '搜索账号 / 昵称 / ID',
              clearable: true,
              style: 'width:280px',
              onKeyup: function (e) { if (e.key === 'Enter') self.search(); }
            }),
            h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.search(); } }, function () { return '搜索'; }),
            h(ElementPlus.ElButton, { onClick: function () { self.load(); } }, function () { return '刷新'; })
          ]),
          h(ElementPlus.ElTable, { data: this.items, stripe: true, vLoading: this.loading, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 80 }),
              h(ElementPlus.ElTableColumn, { prop: 'username', label: '账号', minWidth: 140 }),
              h(ElementPlus.ElTableColumn, { prop: 'nickname', label: '昵称', minWidth: 140 }),
              h(ElementPlus.ElTableColumn, { prop: 'country', label: '国家', width: 90 }),
              h(ElementPlus.ElTableColumn, { prop: 'groupName', label: '分组', width: 120 }),
              h(ElementPlus.ElTableColumn, { label: '登录态', width: 100 }, {
                default: function (scope) {
                  var online = scope.row.loginStatus === 'online';
                  return h(ElementPlus.ElTag, {
                    type: online ? 'success' : 'info',
                    size: 'small',
                    effect: 'light'
                  }, function () { return loginLabel(scope.row.loginStatus); });
                }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'accountStatus', label: '状态', width: 100 }),
              h(ElementPlus.ElTableColumn, { prop: 'updatedAt', label: '更新时间', width: 180 })
            ];
          }),
          h('div', { class: 'pager-row' }, [
            h(ElementPlus.ElPagination, {
              currentPage: self.page,
              'onUpdate:currentPage': function (v) { self.page = v; self.load(); },
              pageSize: self.pageSize,
              total: self.total,
              layout: 'total, prev, pager, next',
              background: true
            })
          ])
        ])
      ]);
    }
  };

  var VideosView = {
    name: 'VideosView',
    data: function () {
      return { loading: false, items: [], total: 0, page: 1, pageSize: 24, keyword: '' };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var d = await CompanyAPI.videos({ page: this.page, pageSize: this.pageSize, keyword: this.keyword });
          this.items = d.items || [];
          this.total = d.total || 0;
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.loading = false;
        }
      },
      search: function () {
        this.page = 1;
        this.load();
      }
    },
    render: function () {
      var self = this;
      return h('div', { class: 'page-view' }, [
        pageHead('视频数据', '企业账号已发布 / 线上收录视频'),
        h('div', { class: 'panel' }, [
          h('div', { class: 'page-toolbar' }, [
            h(ElementPlus.ElInput, {
              modelValue: self.keyword,
              'onUpdate:modelValue': function (v) { self.keyword = v; },
              placeholder: '搜索标题 / 视频ID / 账号',
              clearable: true,
              style: 'width:300px',
              onKeyup: function (e) { if (e.key === 'Enter') self.search(); }
            }),
            h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.search(); } }, function () { return '搜索'; })
          ]),
          this.loading
            ? h('div', { class: 'text-center text-slate-400 py-16' }, '加载中…')
            : (this.items.length
              ? h('div', { class: 'video-grid' }, this.items.map(function (item) {
                  return h('div', { class: 'video-card', key: item.materialId }, [
                    item.coverUrl
                      ? h('img', { class: 'cover', src: item.coverUrl, alt: '' })
                      : h('div', { class: 'cover-ph' }, '▶'),
                    h('div', { class: 'body' }, [
                      h('div', { class: 'title' }, item.title || '未命名视频'),
                      h('div', { class: 'meta' }, [
                        h('span', '▶ ' + fmtNum(item.views)),
                        h('span', '♡ ' + fmtNum(item.likes)),
                        h('span', '💬 ' + fmtNum(item.comments))
                      ]),
                      h('div', { class: 'acct' }, '@' + (item.accountUsername || '—'))
                    ])
                  ]);
                }))
              : h('div', { class: 'text-center text-slate-400 py-16' }, '暂无视频数据')),
          h('div', { class: 'pager-row' }, [
            h(ElementPlus.ElPagination, {
              currentPage: self.page,
              'onUpdate:currentPage': function (v) { self.page = v; self.load(); },
              pageSize: self.pageSize,
              total: self.total,
              layout: 'total, prev, pager, next',
              background: true
            })
          ])
        ])
      ]);
    }
  };

  var CluesView = {
    name: 'CluesView',
    data: function () {
      return { loading: false, items: [], total: 0, page: 1, pageSize: 20, keyword: '' };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var d = await CompanyAPI.clues({ page: this.page, pageSize: this.pageSize, keyword: this.keyword });
          this.items = d.items || [];
          this.total = d.total || 0;
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.loading = false;
        }
      },
      search: function () {
        this.page = 1;
        this.load();
      }
    },
    render: function () {
      var self = this;
      return h('div', { class: 'page-view' }, [
        pageHead('拓客线索', 'AI 拓客沉淀的意向用户线索'),
        h('div', { class: 'panel' }, [
          h('div', { class: 'page-toolbar' }, [
            h(ElementPlus.ElInput, {
              modelValue: self.keyword,
              'onUpdate:modelValue': function (v) { self.keyword = v; },
              placeholder: '搜索用户名 / 昵称 / 评论',
              clearable: true,
              style: 'width:300px',
              onKeyup: function (e) { if (e.key === 'Enter') self.search(); }
            }),
            h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.search(); } }, function () { return '搜索'; })
          ]),
          h(ElementPlus.ElTable, { data: this.items, stripe: true, vLoading: this.loading, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 80 }),
              h(ElementPlus.ElTableColumn, { prop: 'username', label: '用户名', minWidth: 120 }),
              h(ElementPlus.ElTableColumn, { prop: 'nickname', label: '昵称', minWidth: 120 }),
              h(ElementPlus.ElTableColumn, { prop: 'commentText', label: '评论/内容', minWidth: 220, showOverflowTooltip: true }),
              h(ElementPlus.ElTableColumn, { prop: 'intentScore', label: '意向', width: 80 }),
              h(ElementPlus.ElTableColumn, { prop: 'dmStatus', label: '私信', width: 90 }),
              h(ElementPlus.ElTableColumn, { prop: 'leadName', label: '所属任务', minWidth: 120 }),
              h(ElementPlus.ElTableColumn, { prop: 'createdAt', label: '时间', width: 170 })
            ];
          }),
          h('div', { class: 'pager-row' }, [
            h(ElementPlus.ElPagination, {
              currentPage: self.page,
              'onUpdate:currentPage': function (v) { self.page = v; self.load(); },
              pageSize: self.pageSize,
              total: self.total,
              layout: 'total, prev, pager, next',
              background: true
            })
          ])
        ])
      ]);
    }
  };

  var AnalyticsView = {
    name: 'AnalyticsView',
    data: function () {
      return { loading: false, loginBreakdown: [], topVideos: [], daily: [] };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var d = await CompanyAPI.analytics();
          this.loginBreakdown = d.loginBreakdown || [];
          this.topVideos = d.topVideos || [];
          this.daily = d.daily || [];
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.loading = false;
        }
      }
    },
    render: function () {
      var daily = this.daily || [];
      var maxViews = Math.max.apply(null, daily.map(function (d) { return d.views || 0; }).concat([1]));
      return h('div', { class: 'page-view' }, [
        pageHead('数据看板', '账号登录分布、播放趋势与热门视频'),
        h('div', { class: 'stat-grid', style: 'grid-template-columns: 1fr 1fr; margin-bottom:18px' }, [
          h('div', { class: 'panel' }, [
            panelHead('账号登录分布'),
            h('div', { class: 'pill-row' }, (this.loginBreakdown.length ? this.loginBreakdown : [{ key: '暂无', count: 0 }]).map(function (x) {
              return h('span', { class: 'pill' }, [
                h('span', loginLabel(x.key)),
                h('span', { class: 'n' }, String(x.count))
              ]);
            }))
          ]),
          h('div', { class: 'panel' }, [
            panelHead('近 14 日播放量'),
            daily.length
              ? h('div', { class: 'chart-bars' }, daily.map(function (d) {
                  var hPct = Math.max(4, Math.round(((d.views || 0) / maxViews) * 100));
                  return h('div', { class: 'bar-col', title: d.date + ' · ' + fmtNum(d.views) }, [
                    h('div', { class: 'bar', style: 'height:' + hPct + '%' }),
                    h('div', { class: 'bar-label' }, String(d.date || '').slice(5))
                  ]);
                }))
              : h('div', { class: 'text-slate-400 text-sm py-8' }, '暂无趋势数据')
          ])
        ]),
        h('div', { class: 'panel' }, [
          panelHead('热门视频 TOP10'),
          h(ElementPlus.ElTable, { data: this.topVideos, stripe: true, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'title', label: '标题', minWidth: 220, showOverflowTooltip: true }),
              h(ElementPlus.ElTableColumn, { prop: 'username', label: '账号', width: 140 }),
              h(ElementPlus.ElTableColumn, { label: '播放', width: 100 }, {
                default: function (scope) { return fmtNum(scope.row.views); }
              }),
              h(ElementPlus.ElTableColumn, { label: '点赞', width: 100 }, {
                default: function (scope) { return fmtNum(scope.row.likes); }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'videoId', label: '视频ID', width: 160 })
            ];
          })
        ])
      ]);
    }
  };

  global.CompanyViews = {
    LoginView: LoginView,
    DashboardView: DashboardView,
    AccountsView: AccountsView,
    VideosView: VideosView,
    CluesView: CluesView,
    AnalyticsView: AnalyticsView
  };
})(window);
