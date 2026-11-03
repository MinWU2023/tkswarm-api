(function (global) {
  'use strict';

  var h = Vue.h;
  var ElMessage = ElementPlus.ElMessage;

  function pageHead(title, desc) {
    return h('div', { class: 'page-head' }, [
      typeof title === 'string' ? h('h1', title) : h('h1', [title]),
      desc ? h('p', desc) : null
    ]);
  }

  var PILL_TONES = ['pill-sky', 'pill-violet', 'pill-pink', 'pill-teal', 'pill-orange', 'pill-emerald', 'pill-amber', 'pill-indigo'];
  function pillTone(i) {
    return PILL_TONES[i % PILL_TONES.length];
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

  function axisLabel(val) {
    var v = Number(val) || 0;
    if (Math.abs(v) >= 10000) return (v / 10000).toFixed(v >= 100000 ? 0 : 1) + '万';
    if (Math.abs(v) >= 1000) return (v / 1000).toFixed(v >= 10000 ? 0 : 1) + 'k';
    return String(Math.round(v * 100) / 100);
  }

  /** SVG 折线图：pack = { labels, series:[{key,name,color,values}] } */
  function lineChartBlock(pack, hiddenMap, onToggle) {
    var labels = (pack && pack.labels) || [];
    var series = (pack && pack.series) || [];
    var hidden = hiddenMap || {};
    if (!labels.length) {
      return h('div', { class: 'cx-chart-empty' }, '暂无数据');
    }
    var active = series.filter(function (s) { return !hidden[s.key]; });
    var W = 760, H = 260, padL = 48, padR = 16, padT = 18, padB = 36;
    var plotW = W - padL - padR, plotH = H - padT - padB;
    var n = labels.length;
    var allVals = [];
    active.forEach(function (s) {
      (s.values || []).forEach(function (v) { allVals.push(Number(v) || 0); });
    });
    var minV = 0;
    var maxV = Math.max(1, allVals.length ? Math.max.apply(null, allVals) : 1);
    if (allVals.length && Math.min.apply(null, allVals) < 0) minV = Math.min.apply(null, allVals);
    var span = maxV - minV || 1;
    function xAt(i) { return padL + (n <= 1 ? plotW / 2 : (i / (n - 1)) * plotW); }
    function yAt(v) { return padT + plotH - ((Number(v) - minV) / span) * plotH; }

    var children = [];
    for (var g = 0; g <= 4; g++) {
      var y = padT + (plotH * g) / 4;
      var val = maxV - (span * g) / 4;
      children.push(h('line', {
        x1: padL, y1: y, x2: W - padR, y2: y,
        class: 'cx-chart-grid', stroke: '#e8eef4', 'stroke-width': 1
      }));
      children.push(h('text', {
        x: padL - 8, y: y + 4, 'text-anchor': 'end', class: 'cx-chart-axis',
        fill: '#94a3b8', 'font-size': 10
      }, axisLabel(val)));
    }
    var step = Math.max(1, Math.ceil(n / 8));
    for (var i = 0; i < n; i++) {
      if (i % step !== 0 && i !== n - 1) continue;
      children.push(h('text', {
        x: xAt(i), y: H - 10, 'text-anchor': 'middle', class: 'cx-chart-axis',
        fill: '#94a3b8', 'font-size': 10
      }, String(labels[i] || '')));
    }
    active.forEach(function (s) {
      var pts = (s.values || []).map(function (v, idx) {
        return (idx === 0 ? 'M' : 'L') + xAt(idx).toFixed(1) + ',' + yAt(v).toFixed(1);
      }).join(' ');
      children.push(h('path', {
        d: pts, fill: 'none', stroke: s.color, 'stroke-width': 2,
        'stroke-linejoin': 'round', 'stroke-linecap': 'round'
      }));
      (s.values || []).forEach(function (v, idx) {
        children.push(h('circle', {
          cx: xAt(idx).toFixed(1), cy: yAt(v).toFixed(1), r: 2.5, fill: s.color
        }, [h('title', s.name + ' ' + labels[idx] + ': ' + v)]));
      });
    });

    return h('div', { class: 'cx-chart-wrap' }, [
      h('svg', {
        class: 'cx-chart-svg',
        viewBox: '0 0 ' + W + ' ' + H,
        role: 'img'
      }, children),
      h('div', { class: 'cx-chart-legend' }, series.map(function (s) {
        var off = !!hidden[s.key];
        return h('button', {
          type: 'button',
          class: 'cx-legend-item' + (off ? ' is-off' : ''),
          onClick: function () { if (onToggle) onToggle(s.key); }
        }, [
          h('i', { style: 'background:' + s.color }),
          h('span', s.name)
        ]);
      }))
    ]);
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
      return {
        loading: false,
        pubLoading: false,
        chartLoading: false,
        stats: {},
        welcome: '',
        publishTab: 'success',
        accountId: '',
        accountOptions: [],
        publishItems: [],
        publishTotal: 0,
        publishPage: 1,
        publishPageSize: 12,
        publishCounts: { success: 0, progress: 0 },
        chartAccountId: '',
        chartAccountOptions: [],
        daily28: [],
        snapshots: [],
        hasDailyData: false,
        hasSnapshots: false,
        chartHidden: { daily28: {}, snapshots: {} }
      };
    },
    mounted: function () {
      this.load();
      this.loadPublishes();
      this.loadCharts();
    },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var d = await CompanyAPI.dashboard();
          this.stats = d.stats || {};
          this.welcome = d.welcome || '';
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.loading = false;
        }
      },
      loadPublishes: async function () {
        this.pubLoading = true;
        try {
          var d = await CompanyAPI.dashboardPublishes({
            tab: this.publishTab,
            accountId: this.accountId || undefined,
            page: this.publishPage,
            pageSize: this.publishPageSize
          });
          this.publishItems = d.items || [];
          this.publishTotal = d.total || 0;
          this.publishCounts = d.counts || { success: 0, progress: 0 };
          if (Array.isArray(d.accountOptions) && d.accountOptions.length) {
            this.accountOptions = d.accountOptions;
          }
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.pubLoading = false;
        }
      },
      loadCharts: async function () {
        this.chartLoading = true;
        try {
          var d = await CompanyAPI.dashboardCharts({
            accountId: this.chartAccountId || undefined
          });
          if (Array.isArray(d.accountOptions)) {
            this.chartAccountOptions = d.accountOptions;
            if (!this.accountOptions.length) this.accountOptions = d.accountOptions;
          }
          if (d.accountId) this.chartAccountId = d.accountId;
          this.daily28 = d.daily28 || [];
          this.snapshots = d.snapshots || [];
          this.hasDailyData = !!d.hasDailyData;
          this.hasSnapshots = !!d.hasSnapshots;
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.chartLoading = false;
        }
      },
      setPublishTab: function (tab) {
        if (this.publishTab === tab) return;
        this.publishTab = tab;
        this.publishPage = 1;
        this.loadPublishes();
      },
      onAccountFilter: function (v) {
        this.accountId = v === null || v === undefined ? '' : v;
        this.publishPage = 1;
        this.loadPublishes();
      },
      onChartAccount: function (v) {
        this.chartAccountId = v === null || v === undefined ? '' : v;
        this.loadCharts();
      },
      toggleChartSeries: function (panel, key) {
        var cur = Object.assign({}, this.chartHidden[panel] || {});
        cur[key] = !cur[key];
        var next = { daily28: this.chartHidden.daily28 || {}, snapshots: this.chartHidden.snapshots || {} };
        next[panel] = cur;
        this.chartHidden = next;
      },
      dailyPack: function () {
        var rows = this.daily28 || [];
        return {
          labels: rows.map(function (d) { return String(d.metricDate || '').slice(5); }),
          series: [
            { key: 'videoViews', name: '播放', color: '#3b9eff', values: rows.map(function (d) { return Number(d.videoViews || 0); }) },
            { key: 'profileViews', name: '主页浏览', color: '#10b981', values: rows.map(function (d) { return Number(d.profileViews || 0); }) },
            { key: 'likes', name: '点赞', color: '#f59e0b', values: rows.map(function (d) { return Number(d.likes || 0); }) },
            { key: 'comments', name: '评论', color: '#ec4899', values: rows.map(function (d) { return Number(d.comments || 0); }) },
            { key: 'shares', name: '转发', color: '#8b5cf6', values: rows.map(function (d) { return Number(d.shares || 0); }) },
            { key: 'estRewards', name: '预估收益', color: '#64748b', values: rows.map(function (d) { return Number(d.estRewards || 0); }) }
          ]
        };
      },
      snapPack: function () {
        var rows = this.snapshots || [];
        return {
          labels: rows.map(function (s) {
            var t = String(s.capturedAt || '');
            return t.length >= 16 ? t.slice(5, 16) : t.slice(5);
          }),
          series: [
            { key: 'followers', name: '粉丝', color: '#3b9eff', values: rows.map(function (s) { return Number(s.followers || 0); }) },
            { key: 'following', name: '关注', color: '#10b981', values: rows.map(function (s) { return Number(s.following || 0); }) },
            { key: 'likes', name: '获赞', color: '#f59e0b', values: rows.map(function (s) { return Number(s.likes || 0); }) }
          ]
        };
      }
    },
    render: function () {
      var self = this;
      var s = this.stats || {};
      var cards = [
        { label: '账号', value: s.accounts || 0, hint: '在线 ' + (s.online || 0), cls: 'tone-sky' },
        { label: '视频', value: s.videos || 0, hint: '线上收录', cls: 'tone-emerald' },
        { label: '浏览', value: s.views || 0, hint: '累计播放', cls: 'tone-violet', format: true },
        { label: '评论', value: s.comments || 0, hint: '累计评论', cls: 'tone-pink', format: true },
        { label: '线索', value: s.clues || 0, hint: '拓客沉淀', cls: 'tone-orange' }
      ];
      var counts = this.publishCounts || {};
      var chartOpts = this.chartAccountOptions.length ? this.chartAccountOptions : this.accountOptions;
      return h('div', { class: 'page-view', 'v-loading': this.loading }, [
        pageHead(
          h('span', [h('span', '控制'), h('span', { class: 'grad' }, '面板')]),
          (this.welcome ? ('你好，' + this.welcome + '。') : '') + '企业门户数据总览'
        ),
        h('div', { class: 'stat-grid stat-grid-5' }, cards.map(function (c) {
          return h('div', { class: 'stat-card ' + c.cls }, [
            h('div', { class: 'label' }, c.label),
            h('div', { class: 'value' }, c.format ? fmtNum(c.value) : String(c.value)),
            h('div', { class: 'hint' }, c.hint)
          ]);
        })),
        h('div', { class: 'panel' }, [
          panelHead('最近发布视频', h(ElementPlus.ElButton, {
            text: true, type: 'primary',
            onClick: function () { self.$router.push('/videos'); }
          }, function () { return '全部视频'; })),
          h('div', { class: 'page-toolbar dash-pub-toolbar' }, [
            h('div', { class: 'dash-tabs' }, [
              h('button', {
                type: 'button',
                class: 'dash-tab' + (self.publishTab === 'success' ? ' is-active' : ''),
                onClick: function () { self.setPublishTab('success'); }
              }, '成功 (' + (counts.success || 0) + ')'),
              h('button', {
                type: 'button',
                class: 'dash-tab dash-tab-progress' + (self.publishTab === 'progress' ? ' is-active' : ''),
                onClick: function () { self.setPublishTab('progress'); }
              }, '进行中 (' + (counts.progress || 0) + ')')
            ]),
            h('div', { class: 'dash-pub-actions' }, [
              h(ElementPlus.ElSelect, {
                modelValue: self.accountId,
                'onUpdate:modelValue': function (v) { self.onAccountFilter(v); },
                placeholder: '筛选账号',
                clearable: true,
                filterable: true,
                style: 'width:240px'
              }, function () {
                return (self.accountOptions || []).map(function (a) {
                  var label = a.username || ('#' + a.id);
                  if (a.nickname) label += ' · ' + a.nickname;
                  return h(ElementPlus.ElOption, { key: a.id, label: label, value: a.id });
                });
              }),
              h(ElementPlus.ElButton, {
                onClick: function () { self.loadPublishes(); }
              }, function () { return '刷新'; })
            ])
          ]),
          self.pubLoading
            ? h('div', { class: 'text-center text-slate-400 py-16' }, '加载中…')
            : (self.publishItems.length
              ? h('div', { class: 'video-grid' }, self.publishItems.map(function (item) {
                  var isProgress = self.publishTab === 'progress' || item.publishStatus === 'publishing';
                  return h('div', { class: 'video-card', key: item.materialId }, [
                    item.coverUrl
                      ? h('img', { class: 'cover', src: item.coverUrl, alt: '' })
                      : h('div', { class: 'cover-ph' }, '▶'),
                    h('div', { class: 'body' }, [
                      h('div', { class: 'title-row' }, [
                        h('div', { class: 'title' }, item.title || '未命名视频'),
                        h('span', {
                          class: 'pub-badge ' + (isProgress ? 'is-progress' : 'is-success')
                        }, isProgress ? '进行中' : '已成功')
                      ]),
                      h('div', { class: 'meta' }, [
                        h('span', '▶ ' + fmtNum(item.views)),
                        h('span', '♡ ' + fmtNum(item.likes)),
                        h('span', '💬 ' + fmtNum(item.comments))
                      ]),
                      h('div', { class: 'acct' },
                        '@' + (item.accountUsername || '—') +
                        (item.postedAt || item.updatedAt
                          ? ' · ' + String(item.postedAt || item.updatedAt).slice(0, 16)
                          : '')
                      )
                    ])
                  ]);
                }))
              : h('div', { class: 'empty-pub' },
                  self.publishTab === 'progress' ? '暂无进行中的发布任务' : '暂无成功发布的视频')),
          h('div', { class: 'pager-row' }, [
            h(ElementPlus.ElPagination, {
              currentPage: self.publishPage,
              'onUpdate:currentPage': function (v) { self.publishPage = v; self.loadPublishes(); },
              pageSize: self.publishPageSize,
              total: self.publishTotal,
              layout: 'total, prev, pager, next',
              background: true
            })
          ])
        ]),
        h('div', { class: 'panel chart-panel' }, [
          panelHead('账号数据趋势', h('div', { class: 'dash-pub-actions' }, [
            h(ElementPlus.ElSelect, {
              modelValue: self.chartAccountId,
              'onUpdate:modelValue': function (v) { self.onChartAccount(v); },
              placeholder: '切换账号',
              filterable: true,
              style: 'width:240px'
            }, function () {
              return (chartOpts || []).map(function (a) {
                var label = a.username || ('#' + a.id);
                if (a.nickname) label += ' · ' + a.nickname;
                return h(ElementPlus.ElOption, { key: a.id, label: label, value: a.id });
              });
            }),
            h(ElementPlus.ElButton, {
              onClick: function () { self.loadCharts(); }
            }, function () { return '刷新'; })
          ])),
          self.chartLoading
            ? h('div', { class: 'text-center text-slate-400 py-12' }, '图表加载中…')
            : (!chartOpts.length
              ? h('div', { class: 'empty-pub' }, '暂无关联账号，无法展示趋势')
              : h('div', { class: 'chart-grid' }, [
                  h('div', { class: 'chart-box' }, [
                    h('div', { class: 'chart-box-head' }, [
                      h('h4', '近 28 天数据'),
                      !self.hasDailyData
                        ? h('span', { class: 'chart-hint' }, '未拉取，已按日期补 0')
                        : null
                    ]),
                    lineChartBlock(
                      self.dailyPack(),
                      self.chartHidden.daily28,
                      function (key) { self.toggleChartSeries('daily28', key); }
                    )
                  ]),
                  h('div', { class: 'chart-box' }, [
                    h('div', { class: 'chart-box-head' }, [
                      h('h4', '历史快照'),
                      !self.hasSnapshots
                        ? h('span', { class: 'chart-hint' }, '暂无快照')
                        : null
                    ]),
                    self.hasSnapshots
                      ? lineChartBlock(
                          self.snapPack(),
                          self.chartHidden.snapshots,
                          function (key) { self.toggleChartSeries('snapshots', key); }
                        )
                      : h('div', { class: 'cx-chart-empty' }, '拉取数据后会生成历史快照')
                  ])
                ]))
        ])
      ]);
    }
  };

  var AccountsView = {
    name: 'AccountsView',
    data: function () {
      return { loading: false, items: [], total: 0, page: 1, pageSize: 24, keyword: '' };
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
          self.loading
            ? h('div', { class: 'text-center text-slate-400 py-16' }, '加载中…')
            : (self.items.length
              ? h('div', { class: 'account-card-grid' }, self.items.map(function (item) {
                  var online = item.loginStatus === 'online';
                  var banned = item.accountStatus === 'banned';
                  var initial = String(item.username || item.nickname || '#').trim().charAt(0).toUpperCase() || 'A';
                  return h('div', { class: 'account-card', key: item.id }, [
                    h('div', { class: 'account-card-hero' }, [
                      item.avatarUrl
                        ? h('img', { class: 'account-card-avatar', src: item.avatarUrl, alt: '', loading: 'lazy' })
                        : h('div', { class: 'account-card-avatar-ph' }, initial),
                      h('div', { class: 'account-card-main' }, [
                        h('div', { class: 'account-card-name' }, item.username || ('#' + item.id)),
                        h('div', { class: 'account-card-sub' },
                          (item.nickname ? item.nickname + ' · ' : '') + 'ID #' + item.id
                        ),
                        h('div', { class: 'account-card-tags' }, [
                          h(ElementPlus.ElTag, {
                            type: online ? 'success' : 'info',
                            size: 'small',
                            effect: 'light'
                          }, function () { return loginLabel(item.loginStatus); }),
                          h(ElementPlus.ElTag, {
                            type: banned ? 'danger' : 'success',
                            size: 'small',
                            effect: 'light'
                          }, function () { return banned ? '封号' : '正常'; })
                        ])
                      ])
                    ]),
                    h('div', { class: 'account-card-body' }, [
                      h('div', { class: 'account-card-stats' }, [
                        h('div', { class: 'account-card-stat' }, [
                          h('small', '粉丝'),
                          h('b', fmtNum(item.followers))
                        ]),
                        h('div', { class: 'account-card-stat' }, [
                          h('small', '关注'),
                          h('b', fmtNum(item.following))
                        ]),
                        h('div', { class: 'account-card-stat' }, [
                          h('small', '获赞'),
                          h('b', fmtNum(item.likes))
                        ])
                      ]),
                      h('div', { class: 'account-card-meta' }, [
                        h('div', ['分组：', h('span', item.groupName || '未分组')]),
                        h('div', ['国家：', h('span', item.country || '—')]),
                        h('div', ['更新：', h('span', String(item.updatedAt || '—').slice(0, 16))])
                      ])
                    ]),
                    h('div', { class: 'card-detail-bar' }, [
                      h('button', {
                        type: 'button',
                        class: 'card-detail-btn',
                        onClick: function () { self.$router.push('/accounts/' + item.id); }
                      }, '查看详情')
                    ])
                  ]);
                }))
              : h('div', { class: 'empty-pub' }, '还没有账号')),
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
      return { loading: false, items: [], total: 0, page: 1, pageSize: 12, keyword: '' };
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
            h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.search(); } }, function () { return '搜索'; }),
            h(ElementPlus.ElButton, { onClick: function () { self.load(); } }, function () { return '刷新'; })
          ]),
          self.loading
            ? h('div', { class: 'text-center text-slate-400 py-16' }, '加载中…')
            : (self.items.length
              ? h('div', { class: 'material-grid' }, self.items.map(function (item) {
                  return h('div', { class: 'material-card', key: item.materialId }, [
                    h('div', { class: 'material-thumb' }, [
                      item.coverUrl
                        ? h('img', { class: 'material-thumb-img', src: item.coverUrl, alt: '', loading: 'lazy' })
                        : h('div', { class: 'material-thumb-empty' }, '无封面')
                    ]),
                    h('div', { class: 'material-body' }, [
                      h('div', {
                        class: 'material-title',
                        title: item.title || ''
                      }, item.title || '未命名视频'),
                      h('div', null, [
                        h(ElementPlus.ElTag, {
                          type: 'success',
                          size: 'small',
                          effect: 'light'
                        }, function () { return item.origin === 'online' ? '线上收录' : '已发布'; })
                      ]),
                      h('div', { class: 'material-meta-row' }, [
                        h('span', '▶ ' + fmtNum(item.views)),
                        h('span', '♡ ' + fmtNum(item.likes)),
                        h('span', '💬 ' + fmtNum(item.comments))
                      ]),
                      h('div', { class: 'material-acct' },
                        '@' + (item.accountUsername || '—') +
                        (item.postedAt || item.updatedAt
                          ? ' · ' + String(item.postedAt || item.updatedAt).slice(0, 16)
                          : '')
                      )
                    ]),
                    h('div', { class: 'card-detail-bar' }, [
                      h('button', {
                        type: 'button',
                        class: 'card-detail-btn',
                        onClick: function () { self.$router.push('/videos/' + item.materialId); }
                      }, '查看详情')
                    ])
                  ]);
                }))
              : h('div', { class: 'empty-pub' }, '暂无视频数据')),
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

  function detailKv(label, value) {
    return h('div', { class: 'detail-kv' }, [
      h('small', label),
      h('div', value == null || value === '' ? '—' : value)
    ]);
  }

  var AccountDetailView = {
    name: 'AccountDetailView',
    data: function () {
      return {
        loading: false,
        account: null,
        stats: {},
        recentVideos: [],
        daily28: [],
        snapshots: [],
        hasDailyData: false,
        hasSnapshots: false,
        chartHidden: { daily28: {}, snapshots: {} }
      };
    },
    mounted: function () { this.load(); },
    watch: {
      '$route.params.id': function () { this.load(); }
    },
    methods: {
      load: async function () {
        var id = this.$route.params.id;
        if (!id) return;
        this.loading = true;
        try {
          var d = await CompanyAPI.accountDetail(id);
          this.account = d.account || null;
          this.stats = d.stats || {};
          this.recentVideos = d.recentVideos || [];
          this.daily28 = d.daily28 || [];
          this.snapshots = d.snapshots || [];
          this.hasDailyData = !!d.hasDailyData;
          this.hasSnapshots = !!d.hasSnapshots;
        } catch (e) {
          ElMessage.error(e.message || '加载失败');
          this.account = null;
        } finally {
          this.loading = false;
        }
      },
      toggleChartSeries: function (panel, key) {
        var cur = Object.assign({}, this.chartHidden[panel] || {});
        cur[key] = !cur[key];
        var next = { daily28: this.chartHidden.daily28 || {}, snapshots: this.chartHidden.snapshots || {} };
        next[panel] = cur;
        this.chartHidden = next;
      },
      dailyPack: function () {
        var rows = this.daily28 || [];
        return {
          labels: rows.map(function (d) { return String(d.metricDate || '').slice(5); }),
          series: [
            { key: 'videoViews', name: '播放', color: '#3b9eff', values: rows.map(function (d) { return Number(d.videoViews || 0); }) },
            { key: 'profileViews', name: '主页浏览', color: '#10b981', values: rows.map(function (d) { return Number(d.profileViews || 0); }) },
            { key: 'likes', name: '点赞', color: '#f59e0b', values: rows.map(function (d) { return Number(d.likes || 0); }) },
            { key: 'comments', name: '评论', color: '#ec4899', values: rows.map(function (d) { return Number(d.comments || 0); }) },
            { key: 'shares', name: '转发', color: '#8b5cf6', values: rows.map(function (d) { return Number(d.shares || 0); }) },
            { key: 'estRewards', name: '预估收益', color: '#64748b', values: rows.map(function (d) { return Number(d.estRewards || 0); }) }
          ]
        };
      },
      snapPack: function () {
        var rows = this.snapshots || [];
        return {
          labels: rows.map(function (s) {
            var t = String(s.capturedAt || '');
            return t.length >= 16 ? t.slice(5, 16) : t.slice(5);
          }),
          series: [
            { key: 'followers', name: '粉丝', color: '#3b9eff', values: rows.map(function (s) { return Number(s.followers || 0); }) },
            { key: 'following', name: '关注', color: '#10b981', values: rows.map(function (s) { return Number(s.following || 0); }) },
            { key: 'likes', name: '获赞', color: '#f59e0b', values: rows.map(function (s) { return Number(s.likes || 0); }) }
          ]
        };
      }
    },
    render: function () {
      var self = this;
      var a = this.account || {};
      var s = this.stats || {};
      if (this.loading) {
        return h('div', { class: 'page-view' }, [h('div', { class: 'empty-pub' }, '加载中…')]);
      }
      if (!this.account) {
        return h('div', { class: 'page-view' }, [
          h('div', { class: 'empty-pub' }, [
            h('div', '账号不存在或无权访问'),
            h(ElementPlus.ElButton, {
              style: 'margin-top:12px',
              onClick: function () { self.$router.push('/accounts'); }
            }, function () { return '返回列表'; })
          ])
        ]);
      }
      var initial = String(a.username || a.nickname || '#').trim().charAt(0).toUpperCase() || 'A';
      var online = a.loginStatus === 'online';
      var banned = a.accountStatus === 'banned';
      var periodCards = [
        { label: '播放 · 28天', value: s.periodVideoViews || 0, cls: 'tone-sky' },
        { label: '主页浏览 · 28天', value: s.periodProfileViews || 0, cls: 'tone-emerald' },
        { label: '点赞 · 28天', value: s.periodLikes || 0, cls: 'tone-violet' },
        { label: '评论 · 28天', value: s.periodComments || 0, cls: 'tone-pink' },
        { label: '转发 · 28天', value: s.periodShares || 0, cls: 'tone-orange' }
      ];
      return h('div', { class: 'page-view detail-page' }, [
        h('div', { class: 'detail-topbar' }, [
          h(ElementPlus.ElButton, {
            text: true,
            onClick: function () { self.$router.push('/accounts'); }
          }, function () { return '← 返回账号列表'; }),
          h('div', { class: 'detail-topbar-title' }, '账号详情')
        ]),
        h('div', { class: 'panel detail-hero-panel' }, [
          h('div', { class: 'detail-hero' }, [
            a.avatarUrl
              ? h('img', { class: 'detail-hero-avatar', src: a.avatarUrl, alt: '' })
              : h('div', { class: 'detail-hero-avatar-ph' }, initial),
            h('div', { class: 'detail-hero-main' }, [
              h('div', { class: 'detail-hero-name' }, a.username || ('#' + a.id)),
              h('div', { class: 'detail-hero-sub' },
                (a.nickname ? a.nickname + ' · ' : '') + 'ID #' + a.id +
                (a.country ? ' · ' + a.country : '')
              ),
              h('div', { class: 'account-card-tags' }, [
                h(ElementPlus.ElTag, { type: online ? 'success' : 'info', size: 'small', effect: 'light' },
                  function () { return loginLabel(a.loginStatus); }),
                h(ElementPlus.ElTag, { type: banned ? 'danger' : 'success', size: 'small', effect: 'light' },
                  function () { return banned ? '封号' : '正常'; }),
                h(ElementPlus.ElTag, { type: a.enabled ? 'primary' : 'info', size: 'small', effect: 'light' },
                  function () { return a.enabled ? '已启用' : '已停用'; })
              ])
            ]),
            h('div', { class: 'detail-hero-stats' }, [
              h('div', { class: 'detail-hero-stat' }, [h('b', fmtNum(s.followers)), h('small', '粉丝')]),
              h('div', { class: 'detail-hero-stat' }, [h('b', fmtNum(s.following)), h('small', '关注')]),
              h('div', { class: 'detail-hero-stat' }, [h('b', fmtNum(s.likes)), h('small', '获赞')]),
              h('div', { class: 'detail-hero-stat' }, [h('b', fmtNum(s.videos)), h('small', '视频')])
            ])
          ])
        ]),
        h('div', { class: 'stat-grid stat-grid-5', style: 'margin-top:16px' }, periodCards.map(function (c) {
          return h('div', { class: 'stat-card ' + c.cls }, [
            h('div', { class: 'label' }, c.label),
            h('div', { class: 'value' }, fmtNum(c.value)),
            h('div', { class: 'hint' }, 'Studio 周期指标')
          ]);
        })),
        h('div', { class: 'detail-grid-2', style: 'margin-top:16px' }, [
          h('div', { class: 'panel' }, [
            panelHead('基本信息'),
            h('div', { class: 'detail-kv-grid' }, [
              detailKv('分组', a.groupName || '未分组'),
              detailKv('国家', a.country || '—'),
              detailKv('创建时间', a.createdAt || '—'),
              detailKv('更新时间', a.updatedAt || '—'),
              detailKv('备注', a.notes || '—'),
              detailKv('简介', a.bio || '—')
            ])
          ]),
          h('div', { class: 'panel' }, [
            panelHead('近 28 天数据', !self.hasDailyData ? h('span', { class: 'chart-hint' }, '未拉取，已补 0') : null),
            lineChartBlock(self.dailyPack(), self.chartHidden.daily28, function (key) {
              self.toggleChartSeries('daily28', key);
            })
          ])
        ]),
        h('div', { class: 'panel', style: 'margin-top:16px' }, [
          panelHead('历史快照', !self.hasSnapshots ? h('span', { class: 'chart-hint' }, '暂无快照') : null),
          self.hasSnapshots
            ? lineChartBlock(self.snapPack(), self.chartHidden.snapshots, function (key) {
                self.toggleChartSeries('snapshots', key);
              })
            : h('div', { class: 'cx-chart-empty' }, '拉取数据后会生成历史快照')
        ]),
        h('div', { class: 'panel', style: 'margin-top:16px' }, [
          panelHead('近期视频', h('span', { class: 'text-xs text-slate-400' }, '共 ' + (s.videos || 0) + ' 条')),
          self.recentVideos.length
            ? h('div', { class: 'material-grid' }, self.recentVideos.map(function (item) {
                return h('div', { class: 'material-card', key: item.materialId }, [
                  h('div', { class: 'material-thumb' }, [
                    item.coverUrl
                      ? h('img', { class: 'material-thumb-img', src: item.coverUrl, alt: '', loading: 'lazy' })
                      : h('div', { class: 'material-thumb-empty' }, '无封面')
                  ]),
                  h('div', { class: 'material-body' }, [
                    h('div', { class: 'material-title' }, item.title || '未命名视频'),
                    h('div', { class: 'material-meta-row' }, [
                      h('span', '▶ ' + fmtNum(item.views)),
                      h('span', '♡ ' + fmtNum(item.likes)),
                      h('span', '💬 ' + fmtNum(item.comments))
                    ])
                  ]),
                  h('div', { class: 'card-detail-bar' }, [
                    h('button', {
                      type: 'button',
                      class: 'card-detail-btn',
                      onClick: function () { self.$router.push('/videos/' + item.materialId); }
                    }, '查看详情')
                  ])
                ]);
              }))
            : h('div', { class: 'empty-pub' }, '暂无视频')
        ])
      ]);
    }
  };

  var VideoDetailView = {
    name: 'VideoDetailView',
    data: function () {
      return { loading: false, video: null, account: null, screenshots: [] };
    },
    mounted: function () { this.load(); },
    watch: {
      '$route.params.id': function () { this.load(); }
    },
    methods: {
      load: async function () {
        var id = this.$route.params.id;
        if (!id) return;
        this.loading = true;
        try {
          var d = await CompanyAPI.videoDetail(id);
          this.video = d.video || null;
          this.account = d.account || null;
          this.screenshots = d.screenshots || [];
        } catch (e) {
          ElMessage.error(e.message || '加载失败');
          this.video = null;
        } finally {
          this.loading = false;
        }
      }
    },
    render: function () {
      var self = this;
      var v = this.video || {};
      var a = this.account || {};
      if (this.loading) {
        return h('div', { class: 'page-view' }, [h('div', { class: 'empty-pub' }, '加载中…')]);
      }
      if (!this.video) {
        return h('div', { class: 'page-view' }, [
          h('div', { class: 'empty-pub' }, [
            h('div', '视频不存在或无权访问'),
            h(ElementPlus.ElButton, {
              style: 'margin-top:12px',
              onClick: function () { self.$router.push('/videos'); }
            }, function () { return '返回列表'; })
          ])
        ]);
      }
      var statusText = v.origin === 'online' ? '线上收录' : (v.publishStatus === 'published' ? '已发布' : (v.publishStatus || '—'));
      return h('div', { class: 'page-view detail-page' }, [
        h('div', { class: 'detail-topbar' }, [
          h(ElementPlus.ElButton, {
            text: true,
            onClick: function () { self.$router.push('/videos'); }
          }, function () { return '← 返回视频列表'; }),
          h('div', { class: 'detail-topbar-title' }, '视频详情')
        ]),
        h('div', { class: 'detail-grid-2' }, [
          h('div', { class: 'panel video-detail-cover-panel' }, [
            v.coverUrl
              ? h('img', { class: 'video-detail-cover', src: v.coverUrl, alt: '' })
              : h('div', { class: 'video-detail-cover-ph' }, '无封面'),
            h('div', { class: 'video-detail-actions' }, [
              v.videoUrl
                ? h('a', {
                    class: 'card-detail-btn',
                    href: v.videoUrl,
                    target: '_blank',
                    rel: 'noopener'
                  }, '打开原链接')
                : null,
              a.id
                ? h('button', {
                    type: 'button',
                    class: 'card-detail-btn is-ghost',
                    onClick: function () { self.$router.push('/accounts/' + a.id); }
                  }, '查看账号')
                : null
            ])
          ]),
          h('div', { class: 'panel' }, [
            h('div', { class: 'video-detail-title' }, v.title || '未命名视频'),
            h('div', { class: 'account-card-tags', style: 'margin:10px 0 14px' }, [
              h(ElementPlus.ElTag, { type: 'success', size: 'small', effect: 'light' }, function () { return statusText; }),
              v.videoId ? h(ElementPlus.ElTag, { type: 'info', size: 'small', effect: 'light' }, function () { return 'ID ' + v.videoId; }) : null
            ]),
            h('div', { class: 'stat-grid', style: 'grid-template-columns:repeat(3,1fr);margin-bottom:14px' }, [
              h('div', { class: 'stat-card tone-sky' }, [
                h('div', { class: 'label' }, '播放'),
                h('div', { class: 'value' }, fmtNum(v.views))
              ]),
              h('div', { class: 'stat-card tone-pink' }, [
                h('div', { class: 'label' }, '点赞'),
                h('div', { class: 'value' }, fmtNum(v.likes))
              ]),
              h('div', { class: 'stat-card tone-violet' }, [
                h('div', { class: 'label' }, '评论'),
                h('div', { class: 'value' }, fmtNum(v.comments))
              ])
            ]),
            h('div', { class: 'detail-kv-grid' }, [
              detailKv('所属账号', '@' + (a.username || '—') + (a.nickname ? ' · ' + a.nickname : '')),
              detailKv('素材 ID', '#' + v.materialId),
              detailKv('文件名', v.fileName || '—'),
              detailKv('发布时间', v.postedAt || '—'),
              detailKv('创建时间', v.createdAt || '—'),
              detailKv('更新时间', v.updatedAt || '—'),
              detailKv('文案 / Caption', v.caption || '—')
            ])
          ])
        ]),
        h('div', { class: 'panel', style: 'margin-top:16px' }, [
          panelHead('视频截图', h('span', { class: 'text-xs text-slate-400' },
            self.screenshots.length ? ('保留 ' + self.screenshots.length + ' 张') : '暂无截图')),
          self.screenshots.length
            ? h('div', { class: 'shot-grid' }, self.screenshots.map(function (shot) {
                return h('div', { class: 'shot-card', key: shot.id }, [
                  shot.screenshotBase64
                    ? h('img', { src: shot.screenshotBase64, alt: '', loading: 'lazy' })
                    : h('div', { class: 'shot-empty' }, '无图'),
                  h('div', { class: 'shot-meta' }, shot.createdAt || shot.source || '')
                ]);
              }))
            : h('div', { class: 'empty-pub' }, '拉取视频详情后会保留截图')
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
            h('div', { class: 'pill-row' }, (this.loginBreakdown.length ? this.loginBreakdown : [{ key: '暂无', count: 0 }]).map(function (x, i) {
              return h('span', { class: 'pill ' + pillTone(i) }, [
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

  var SupportView = {
    name: 'SupportView',
    render: function () {
      return h('div', { class: 'page-view' }, [
        pageHead('客服消息', '企业账号私信与客服会话（即将开放）'),
        h('div', { class: 'panel placeholder-panel' }, [
          h('div', { class: 'placeholder-icon' }, '💬'),
          h('h3', '客服消息功能筹备中'),
          h('p', { class: 'muted' }, '后续将支持查看与回复企业关联账号的客服私信。')
        ])
      ]);
    }
  };

  var PublishView = {
    name: 'PublishView',
    data: function () {
      return {
        loading: false,
        submitting: false,
        accounts: [],
        items: [],
        total: 0,
        page: 1,
        pageSize: 20,
        form: { accountId: null, title: '', caption: '', file: null, fileName: '' }
      };
    },
    mounted: function () {
      this.loadAccounts();
      this.loadTasks();
    },
    methods: {
      loadAccounts: async function () {
        try {
          var d = await CompanyAPI.publishAccounts();
          this.accounts = d.items || [];
        } catch (e) {
          ElMessage.error(e.message || '加载账号失败');
        }
      },
      loadTasks: async function () {
        this.loading = true;
        try {
          var d = await CompanyAPI.publishTasks({ page: this.page, pageSize: this.pageSize });
          this.items = d.items || [];
          this.total = d.total || 0;
        } catch (e) {
          ElMessage.error(e.message || '加载委托任务失败');
        } finally {
          this.loading = false;
        }
      },
      onFileChange: function (uploadFile) {
        var raw = uploadFile && (uploadFile.raw || uploadFile);
        if (!raw) return;
        this.form.file = raw;
        this.form.fileName = raw.name || 'video.mp4';
        if (!this.form.title) {
          this.form.title = String(raw.name || '').replace(/\.[^.]+$/, '');
        }
      },
      clearFile: function () {
        this.form.file = null;
        this.form.fileName = '';
      },
      statusLabel: function (s) {
        var map = {
          draft: '待处理', queued: '排队中', running: '发布中', paused: '已暂停',
          completed: '已完成', failed: '失败', cancelled: '已取消'
        };
        return map[s] || s || '-';
      },
      submit: async function () {
        if (!this.form.accountId) {
          ElMessage.warning('请选择发布账号');
          return;
        }
        if (!this.form.file) {
          ElMessage.warning('请上传视频文件');
          return;
        }
        this.submitting = true;
        try {
          var fd = new FormData();
          fd.append('accountId', String(this.form.accountId));
          fd.append('file', this.form.file);
          if (this.form.title) fd.append('title', this.form.title);
          if (this.form.caption) fd.append('caption', this.form.caption);
          await CompanyAPI.createPublishTask(fd);
          ElMessage.success('委托任务已创建，等待运营处理');
          this.form.title = '';
          this.form.caption = '';
          this.clearFile();
          this.page = 1;
          this.loadTasks();
        } catch (e) {
          ElMessage.error(e.message || '提交失败');
        } finally {
          this.submitting = false;
        }
      }
    },
    render: function () {
      var self = this;
      return h('div', { class: 'page-view' }, [
        pageHead('发布视频', '选择账号并上传视频，生成委托发布任务供运营执行'),
        h('div', { class: 'panel publish-form-panel' }, [
          panelHead('新建委托'),
          h('div', { class: 'publish-form-grid' }, [
            h('div', { class: 'field' }, [
              h('label', '发布账号'),
              h(ElementPlus.ElSelect, {
                modelValue: self.form.accountId,
                'onUpdate:modelValue': function (v) { self.form.accountId = v; },
                placeholder: '选择账号',
                filterable: true,
                style: 'width:100%'
              }, function () {
                return self.accounts.map(function (a) {
                  return h(ElementPlus.ElOption, {
                    key: a.id,
                    label: (a.label || a.username) + ' (#' + a.id + ')',
                    value: a.id
                  });
                });
              })
            ]),
            h('div', { class: 'field' }, [
              h('label', '视频标题（可选）'),
              h(ElementPlus.ElInput, {
                modelValue: self.form.title,
                'onUpdate:modelValue': function (v) { self.form.title = v; },
                placeholder: '发布标题'
              })
            ]),
            h('div', { class: 'field full' }, [
              h('label', '视频描述（可选）'),
              h(ElementPlus.ElInput, {
                type: 'textarea',
                rows: 3,
                modelValue: self.form.caption,
                'onUpdate:modelValue': function (v) { self.form.caption = v; },
                placeholder: '发布文案 / 描述'
              })
            ]),
            h('div', { class: 'field full' }, [
              h('label', '上传视频'),
              h(ElementPlus.ElUpload, {
                drag: true,
                autoUpload: false,
                showFileList: false,
                accept: 'video/*,.mp4,.mov,.webm,.mkv,.avi,.m4v',
                onChange: function (f) { self.onFileChange(f); }
              }, {
                default: function () {
                  return [
                    h('div', { class: 'el-upload__text' }, [
                      self.form.fileName
                        ? ('已选：' + self.form.fileName)
                        : h('span', [h('em', '点击或拖拽'), ' 视频到此处'])
                    ])
                  ];
                }
              }),
              self.form.fileName
                ? h('div', { class: 'publish-file-row' }, [
                    h('span', { class: 'muted' }, self.form.fileName),
                    h(ElementPlus.ElButton, { link: true, type: 'danger', onClick: function () { self.clearFile(); } }, function () { return '清除'; })
                  ])
                : null
            ])
          ]),
          h('div', { class: 'publish-form-actions' }, [
            h(ElementPlus.ElButton, {
              type: 'primary',
              loading: self.submitting,
              onClick: function () { self.submit(); }
            }, function () { return '提交委托'; })
          ])
        ]),
        h('div', { class: 'panel' }, [
          panelHead('委托任务列表'),
          h(ElementPlus.ElTable, { data: self.items, stripe: true, vLoading: self.loading, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 80 }),
              h(ElementPlus.ElTableColumn, { prop: 'name', label: '任务', minWidth: 180, showOverflowTooltip: true }),
              h(ElementPlus.ElTableColumn, { label: '账号', minWidth: 140 }, {
                default: function (scope) {
                  var r = scope.row;
                  return (r.accountNickname || r.accountUsername || '') + (r.accountId ? (' #' + r.accountId) : '');
                }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'materialName', label: '素材', minWidth: 140, showOverflowTooltip: true }),
              h(ElementPlus.ElTableColumn, { label: '状态', width: 100 }, {
                default: function (scope) { return self.statusLabel(scope.row.status); }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'createdAt', label: '创建时间', width: 170 })
            ];
          }),
          h('div', { class: 'pager-row' }, [
            h(ElementPlus.ElPagination, {
              currentPage: self.page,
              'onUpdate:currentPage': function (v) { self.page = v; self.loadTasks(); },
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

  global.CompanyViews = {
    LoginView: LoginView,
    DashboardView: DashboardView,
    AccountsView: AccountsView,
    AccountDetailView: AccountDetailView,
    VideosView: VideosView,
    VideoDetailView: VideoDetailView,
    CluesView: CluesView,
    AnalyticsView: AnalyticsView,
    SupportView: SupportView,
    PublishView: PublishView
  };
})(window);
