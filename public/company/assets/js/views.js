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

  /** 近 28 天横轴：日历日凑满；有数据用真实值，缺天补 0 */
  function padDaily28(rows) {
    var map = {};
    (rows || []).forEach(function (r) {
      if (!r) return;
      var d = String(r.metricDate || r.metric_date || '').slice(0, 10);
      if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return;
      map[d] = r;
    });
    var out = [];
    var end = new Date();
    end.setHours(0, 0, 0, 0);
    for (var i = 27; i >= 0; i--) {
      var dt = new Date(end.getTime());
      dt.setDate(end.getDate() - i);
      var y = dt.getFullYear();
      var m = String(dt.getMonth() + 1).padStart(2, '0');
      var day = String(dt.getDate()).padStart(2, '0');
      var key = y + '-' + m + '-' + day;
      var row = map[key];
      out.push({
        metricDate: key,
        videoViews: Number(row && (row.videoViews != null ? row.videoViews : row.video_views) || 0),
        profileViews: Number(row && (row.profileViews != null ? row.profileViews : row.profile_views) || 0),
        likes: Number(row && (row.likes != null ? row.likes : row.likes_count) || 0),
        comments: Number(row && (row.comments != null ? row.comments : row.comments_count) || 0),
        shares: Number(row && (row.shares != null ? row.shares : row.shares_count) || 0),
        estRewards: Number(row && (row.estRewards != null ? row.estRewards : row.est_rewards) || 0)
      });
    }
    return out;
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
        class: 'cx-chart-grid', stroke: '#e8eef4', 'stroke-width': 1,
        'vector-effect': 'non-scaling-stroke'
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
        return (idx === 0 ? 'M' : 'L') + xAt(idx).toFixed(2) + ',' + yAt(v).toFixed(2);
      }).join(' ');
      children.push(h('path', {
        d: pts,
        fill: 'none',
        stroke: s.color,
        'stroke-width': 1.25,
        'stroke-linejoin': 'round',
        'stroke-linecap': 'round',
        'vector-effect': 'non-scaling-stroke',
        class: 'cx-chart-line'
      }));
      (s.values || []).forEach(function (v, idx) {
        children.push(h('circle', {
          cx: xAt(idx).toFixed(2),
          cy: yAt(v).toFixed(2),
          r: 1.75,
          fill: '#fff',
          stroke: s.color,
          'stroke-width': 1.25,
          'vector-effect': 'non-scaling-stroke',
          class: 'cx-chart-dot'
        }, [h('title', s.name + ' ' + labels[idx] + ': ' + v)]));
      });
    });

    return h('div', { class: 'cx-chart-wrap' }, [
      h('svg', {
        class: 'cx-chart-svg',
        viewBox: '0 0 ' + W + ' ' + H,
        preserveAspectRatio: 'xMidYMid meet',
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
          this.daily28 = padDaily28(d.daily28 || []);
          this.snapshots = d.snapshots || [];
          this.hasDailyData = !!d.hasDailyData;
          this.hasSnapshots = !!d.hasSnapshots;
        } catch (e) {
          ElMessage.error(e.message);
          this.daily28 = padDaily28([]);
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
        var rows = padDaily28(this.daily28 || []);
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
          this.daily28 = padDaily28(d.daily28 || []);
          this.snapshots = d.snapshots || [];
          this.hasDailyData = !!d.hasDailyData;
          this.hasSnapshots = !!d.hasSnapshots;
        } catch (e) {
          ElMessage.error(e.message || '加载失败');
          this.account = null;
          this.daily28 = padDaily28([]);
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
        var rows = padDaily28(this.daily28 || []);
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
    data: function () {
      return {
        loading: false,
        accounts: [],
        accountStats: { total: 0, online: 0, unread: 0 },
        accountPage: 1,
        accountPages: 1,
        accountTotal: 0,
        accountKeyword: '',
        accountId: null,
        friendTab: 'dm',
        friends: [],
        friendCounts: { dm: 0, follower: 0, following: 0 },
        friendId: null,
        friend: null,
        messages: [],
        msgLoading: false
      };
    },
    mounted: function () {
      this.loadAccounts();
    },
    methods: {
      avatarLetter: function (name) {
        var s = String(name || '?').trim();
        return (s.charAt(0) || '?').toUpperCase();
      },
      loadAccounts: async function () {
        this.loading = true;
        try {
          var d = await CompanyAPI.chatAccounts({
            page: this.accountPage,
            pageSize: 20,
            keyword: this.accountKeyword || undefined,
            includeId: this.accountId || undefined
          });
          this.accounts = d.items || [];
          this.accountStats = d.stats || { total: 0, online: 0, unread: 0 };
          this.accountPage = d.page || 1;
          this.accountPages = d.pages || 1;
          this.accountTotal = d.total || 0;
          if (d.selected && d.selected.id) {
            this.accountId = d.selected.id;
          } else if (!this.accountId && this.accounts.length) {
            var online = this.accounts.find(function (a) { return a.loginStatus === 'online'; });
            this.accountId = online ? online.id : this.accounts[0].id;
          }
          if (this.accountId) {
            await this.loadFriends();
          } else {
            this.friends = [];
            this.friendId = null;
            this.friend = null;
            this.messages = [];
          }
        } catch (e) {
          ElMessage.error(e.message || '加载客服账号失败');
          this.accounts = [];
        } finally {
          this.loading = false;
        }
      },
      selectAccount: async function (id) {
        if (this.accountId === id) return;
        this.accountId = id;
        this.friendId = null;
        this.friend = null;
        this.messages = [];
        await this.loadFriends();
      },
      setFriendTab: async function (tab) {
        if (this.friendTab === tab) return;
        this.friendTab = tab || 'dm';
        this.friendId = null;
        this.friend = null;
        this.messages = [];
        await this.loadFriends();
      },
      loadFriends: async function () {
        if (!this.accountId) return;
        try {
          var d = await CompanyAPI.chatFriends({
            accountId: this.accountId,
            filter: this.friendTab || 'dm',
            pageSize: 100
          });
          this.friends = d.items || [];
          this.friendCounts = d.counts || { dm: 0, follower: 0, following: 0 };
          if (this.friendId && !this.friends.some(function (f) { return f.id === this.friendId; }.bind(this))) {
            this.friendId = null;
            this.friend = null;
            this.messages = [];
          }
          if (!this.friendId && this.friends[0]) {
            await this.selectFriend(this.friends[0].id);
          }
        } catch (e) {
          ElMessage.error(e.message || '加载联系人失败');
          this.friends = [];
        }
      },
      selectFriend: async function (id) {
        this.friendId = id;
        this.msgLoading = true;
        try {
          var d = await CompanyAPI.chatMessages({ friendId: id, pageSize: 100 });
          this.friend = d.friend || null;
          this.messages = d.items || [];
          var self = this;
          this.friends = (this.friends || []).map(function (f) {
            if (f.id === id) return Object.assign({}, f, { unreadCount: 0 });
            return f;
          });
          this.$nextTick(function () {
            var el = self._threadEl;
            if (el) el.scrollTop = el.scrollHeight;
          });
        } catch (e) {
          ElMessage.error(e.message || '加载消息失败');
          this.messages = [];
          this.friend = null;
        } finally {
          this.msgLoading = false;
        }
      },
      searchAccounts: function () {
        this.accountPage = 1;
        this.loadAccounts();
      },
      changeAccountPage: function (p) {
        this.accountPage = p;
        this.loadAccounts();
      }
    },
    render: function () {
      var self = this;
      var tabLabel = { dm: '私信', follower: '粉丝', following: '关注' };
      var emptyFriend = '暂无' + (tabLabel[self.friendTab] || '联系人');
      var currentAccount = (self.accounts || []).find(function (a) {
        return Number(a.id) === Number(self.accountId);
      }) || null;

      return h('div', { class: 'page-view support-page' }, [
        pageHead('客服坐席', '企业账号私信、粉丝与关注会话'),
        h('div', { class: 'cx-chat-shell' + (self.loading ? ' is-loading' : '') }, [
          h('aside', { class: 'cx-chat-col cx-chat-accounts' }, [
            h('div', { class: 'cx-chat-col-head' }, [h('h3', '账号')]),
            h('div', { class: 'cx-chat-stats' }, [
              h('span', ['总 ', h('b', String(self.accountStats.total || 0))]),
              h('span', ['在线 ', h('b', String(self.accountStats.online || 0))]),
              h('span', ['未读 ', h('b', String(self.accountStats.unread || 0))])
            ]),
            h('div', { class: 'cx-chat-search' }, [
              h(ElementPlus.ElInput, {
                modelValue: self.accountKeyword,
                'onUpdate:modelValue': function (v) { self.accountKeyword = v; },
                placeholder: '筛选本企业账号',
                clearable: true,
                size: 'small',
                onKeyup: function (e) {
                  if (e.key === 'Enter') self.searchAccounts();
                }
              }),
              h(ElementPlus.ElButton, { size: 'small', onClick: function () { self.searchAccounts(); } }, function () { return '查询'; })
            ]),
            h('div', { class: 'cx-chat-list' },
              self.accounts.length
                ? self.accounts.map(function (a) {
                  return h('button', {
                    type: 'button',
                    class: 'cx-chat-item' + (Number(a.id) === Number(self.accountId) ? ' is-active' : ''),
                    onClick: function () { self.selectAccount(a.id); }
                  }, [
                    h('div', { class: 'cx-chat-avatar' }, self.avatarLetter(a.username || a.nickname)),
                    h('div', { class: 'cx-chat-meta' }, [
                      h('b', [
                        a.username || ('#' + a.id),
                        a.unreadCount
                          ? h('span', { class: 'cx-chat-badge' }, String(a.unreadCount))
                          : null
                      ]),
                      h('small', [
                        h('i', { class: 'cx-dot' + (a.loginStatus === 'online' ? ' on' : '') }),
                        (a.loginStatus === 'online' ? '已登录' : '未登录') + ' · 联系人 ' + (a.friendCount || 0)
                      ])
                    ])
                  ]);
                })
                : [h('div', { class: 'cx-chat-empty' }, '暂无可见账号')]
            ),
            self.accountPages > 1
              ? h('div', { class: 'cx-chat-pager' }, [
                h(ElementPlus.ElPagination, {
                  small: true,
                  layout: 'prev, pager, next',
                  currentPage: self.accountPage,
                  pageSize: 20,
                  total: self.accountTotal,
                  'onUpdate:currentPage': function (p) { self.changeAccountPage(p); }
                })
              ])
              : null
          ]),
          h('aside', { class: 'cx-chat-col cx-chat-friends' }, [
            h('div', { class: 'cx-chat-tabs' }, [
              h('button', {
                type: 'button',
                class: self.friendTab === 'dm' ? 'is-active' : '',
                onClick: function () { self.setFriendTab('dm'); }
              }, '私信' + (self.friendCounts.dm ? ' (' + self.friendCounts.dm + ')' : '')),
              h('button', {
                type: 'button',
                class: self.friendTab === 'follower' ? 'is-active' : '',
                onClick: function () { self.setFriendTab('follower'); }
              }, '粉丝' + (self.friendCounts.follower ? ' (' + self.friendCounts.follower + ')' : '')),
              h('button', {
                type: 'button',
                class: self.friendTab === 'following' ? 'is-active' : '',
                onClick: function () { self.setFriendTab('following'); }
              }, '关注' + (self.friendCounts.following ? ' (' + self.friendCounts.following + ')' : ''))
            ]),
            h('div', { class: 'cx-chat-list' },
              !self.accountId
                ? [h('div', { class: 'cx-chat-empty' }, '请先选择左侧账号')]
                : (self.friends.length
                  ? self.friends.map(function (f) {
                    return h('button', {
                      type: 'button',
                      class: 'cx-chat-item' + (Number(f.id) === Number(self.friendId) ? ' is-active' : ''),
                      onClick: function () { self.selectFriend(f.id); }
                    }, [
                      h('div', { class: 'cx-chat-avatar' }, self.avatarLetter(f.nickname || f.username || f.friendUid)),
                      h('div', { class: 'cx-chat-meta' }, [
                        h('b', [
                          f.isPinned ? '📌 ' : '',
                          f.nickname || f.username || f.friendUid || ('#' + f.id),
                          f.unreadCount
                            ? h('span', { class: 'cx-chat-badge' }, String(f.unreadCount))
                            : null
                        ]),
                        h('small', f.lastMessage || (f.relation ? ('关系：' + f.relation) : '暂无消息'))
                      ])
                    ]);
                  })
                  : [h('div', { class: 'cx-chat-empty' }, emptyFriend)])
            )
          ]),
          h('section', { class: 'cx-chat-col cx-chat-thread' }, [
            h('div', { class: 'cx-chat-col-head' }, [
              h('div', [
                h('h3', self.friend
                  ? (self.friend.nickname || self.friend.username || self.friend.friendUid || '会话')
                  : '会话'),
                h('div', { class: 'muted' }, self.friend
                  ? ('关系：' + (self.friend.relation || '-') + ' · UID ' + (self.friend.friendUid || '-'))
                  : (currentAccount
                    ? ('账号：' + (currentAccount.username || ''))
                    : '请选择联系人查看消息'))
              ])
            ]),
            h('div', {
              class: 'cx-chat-messages',
              ref: function (el) { self._threadEl = el; }
            }, self.msgLoading
              ? [h('div', { class: 'cx-chat-empty' }, '加载中…')]
              : (self.messages.length
                ? self.messages.map(function (m) {
                  var isOut = m.direction === 'out';
                  var body = m.msgType === 'image'
                    ? h('img', { src: m.content, alt: '图片', class: 'cx-chat-img' })
                    : h('span', m.content || '');
                  return h('div', { class: 'cx-bubble-wrap' + (isOut ? ' is-out' : '') }, [
                    h('div', { class: 'cx-bubble' + (isOut ? ' is-out' : '') }, [
                      body,
                      h('span', { class: 'cx-bubble-time' },
                        (m.createdAt || '') + ' · ' + (isOut ? '我' : '对方'))
                    ])
                  ]);
                })
                : [h('div', { class: 'cx-chat-empty' }, self.friend
                  ? '该会话暂无消息'
                  : '请选择联系人查看会话')])
            )
          ])
        ])
      ]);
    }
  };

  var PUBLISH_VIDEO_EXTS = ['mp4', 'mov', 'webm'];
  var PUBLISH_VIDEO_MAX = 500 * 1024 * 1024;
  var PUBLISH_COVER_EXTS = ['jpg', 'jpeg', 'png', 'webp'];
  var PUBLISH_COVER_MAX = 5 * 1024 * 1024;

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
        form: { accountId: null, title: '', caption: '', file: null, fileName: '' },
        videoUrl: '',
        videoDuration: 0,
        scrubTime: 0,
        coverBlob: null,
        coverPreview: '',
        coverSource: '',
        coverName: ''
      };
    },
    mounted: function () {
      this.loadAccounts();
      this.loadTasks();
    },
    beforeUnmount: function () {
      this.revokeVideoUrl();
      this.revokeCoverPreview();
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
      fileExt: function (name) {
        var m = String(name || '').toLowerCase().match(/\.([a-z0-9]+)$/);
        return m ? m[1] : '';
      },
      revokeVideoUrl: function () {
        if (this.videoUrl) {
          try { URL.revokeObjectURL(this.videoUrl); } catch (e) {}
          this.videoUrl = '';
        }
      },
      revokeCoverPreview: function () {
        if (this.coverPreview && String(this.coverPreview).indexOf('blob:') === 0) {
          try { URL.revokeObjectURL(this.coverPreview); } catch (e) {}
        }
        this.coverPreview = '';
      },
      clearCover: function () {
        this.revokeCoverPreview();
        this.coverBlob = null;
        this.coverSource = '';
        this.coverName = '';
      },
      clearFile: function () {
        this.revokeVideoUrl();
        this.form.file = null;
        this.form.fileName = '';
        this.videoDuration = 0;
        this.scrubTime = 0;
        this.clearCover();
      },
      validateVideoFile: function (file) {
        var ext = this.fileExt(file.name);
        if (ext === 'm4v') ext = 'mp4';
        if (PUBLISH_VIDEO_EXTS.indexOf(ext) < 0) {
          return '视频格式不支持。仅支持 TikTok / YouTube 通用格式：MP4、MOV、WebM；单文件最大 500MB';
        }
        if ((file.size || 0) > PUBLISH_VIDEO_MAX) {
          return '视频过大：单文件最大 500MB（当前约 ' + (file.size / 1048576).toFixed(1) + 'MB）';
        }
        return '';
      },
      onFileChange: function (uploadFile) {
        var raw = uploadFile && (uploadFile.raw || uploadFile);
        if (!raw) return;
        var err = this.validateVideoFile(raw);
        if (err) {
          ElMessage.warning(err);
          return;
        }
        this.revokeVideoUrl();
        this.clearCover();
        this.form.file = raw;
        this.form.fileName = raw.name || 'video.mp4';
        if (!this.form.title) {
          this.form.title = String(raw.name || '').replace(/\.[^.]+$/, '');
        }
        this.videoUrl = URL.createObjectURL(raw);
        this.videoDuration = 0;
        this.scrubTime = 0;
        var self = this;
        this.$nextTick(function () { self.bindVideoMeta(); });
      },
      bindVideoMeta: function () {
        var el = document.getElementById('company-publish-video');
        if (!el) return;
        var self = this;
        var onMeta = function () {
          self.videoDuration = Number(el.duration || 0) || 0;
          if (self.scrubTime <= 0) self.scrubTime = Math.min(0.5, self.videoDuration || 0);
        };
        el.onloadedmetadata = onMeta;
        if (el.readyState >= 1) onMeta();
      },
      onScrub: function (v) {
        this.scrubTime = Number(v) || 0;
        var el = document.getElementById('company-publish-video');
        if (el && isFinite(this.scrubTime)) {
          try { el.currentTime = this.scrubTime; } catch (e) {}
        }
      },
      captureFrame: function () {
        var el = document.getElementById('company-publish-video');
        if (!el || !this.form.file) {
          ElMessage.warning('请先选择视频');
          return;
        }
        var self = this;
        var doCapture = function () {
          try {
            var w = el.videoWidth || 0;
            var h = el.videoHeight || 0;
            if (!w || !h) {
              ElMessage.warning('视频尚未加载完成，请稍后再截取');
              return;
            }
            var canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(el, 0, 0, w, h);
            canvas.toBlob(function (blob) {
              if (!blob) {
                ElMessage.error('截取封面失败');
                return;
              }
              self.revokeCoverPreview();
              self.coverBlob = blob;
              self.coverPreview = URL.createObjectURL(blob);
              self.coverSource = 'frame';
              self.coverName = 'cover-frame.jpg';
              ElMessage.success('已截取当前帧作为封面');
            }, 'image/jpeg', 0.92);
          } catch (e) {
            ElMessage.error(e.message || '截取封面失败');
          }
        };
        try {
          el.pause();
          if (Math.abs((el.currentTime || 0) - this.scrubTime) > 0.05) {
            el.currentTime = this.scrubTime;
            el.onseeked = function () {
              el.onseeked = null;
              doCapture();
            };
            return;
          }
        } catch (e) {}
        doCapture();
      },
      onCoverUpload: function (uploadFile) {
        var raw = uploadFile && (uploadFile.raw || uploadFile);
        if (!raw) return;
        var ext = this.fileExt(raw.name);
        if (PUBLISH_COVER_EXTS.indexOf(ext) < 0) {
          ElMessage.warning('封面仅支持 JPG / PNG / WebP（最大 5MB）');
          return;
        }
        if ((raw.size || 0) > PUBLISH_COVER_MAX) {
          ElMessage.warning('封面过大：最大 5MB');
          return;
        }
        this.revokeCoverPreview();
        this.coverBlob = raw;
        this.coverPreview = URL.createObjectURL(raw);
        this.coverSource = 'upload';
        this.coverName = raw.name || 'cover.jpg';
      },
      statusLabel: function (s) {
        var map = {
          draft: '待处理', queued: '排队中', running: '发布中', paused: '已暂停',
          completed: '已完成', failed: '失败', cancelled: '已取消'
        };
        return map[s] || s || '-';
      },
      formatTime: function (sec) {
        var s = Math.max(0, Number(sec) || 0);
        var m = Math.floor(s / 60);
        var r = Math.floor(s % 60);
        return m + ':' + String(r).padStart(2, '0');
      },
      submit: async function () {
        if (!this.form.accountId) {
          ElMessage.warning('请选择发布账号');
          return;
        }
        if (!this.form.file) {
          ElMessage.warning('请上传视频（仅支持 MP4 / MOV / WebM，最大 500MB）');
          return;
        }
        var verr = this.validateVideoFile(this.form.file);
        if (verr) {
          ElMessage.warning(verr);
          return;
        }
        if (!this.coverBlob) {
          ElMessage.warning('请设置封面：拖动进度条后点「截取当前帧」，或上传封面图');
          return;
        }
        this.submitting = true;
        try {
          var fd = new FormData();
          fd.append('accountId', String(this.form.accountId));
          fd.append('file', this.form.file);
          fd.append('cover', this.coverBlob, this.coverName || 'cover.jpg');
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
          h('div', { class: 'publish-hint' }, [
            h('b', '上传要求：'),
            '仅支持 TikTok / YouTube 通用视频格式 ',
            h('code', 'MP4 / MOV / WebM'),
            '，单文件最大 ',
            h('b', '500MB'),
            '；封面可逐帧截取或自行上传（JPG / PNG / WebP，最大 5MB），提交后视频与封面都会上传到 GitHub CDN。'
          ]),
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
                accept: '.mp4,.mov,.webm,video/mp4,video/quicktime,video/webm',
                onChange: function (f) { self.onFileChange(f); }
              }, {
                default: function () {
                  return [
                    h('div', { class: 'el-upload__text' }, [
                      self.form.fileName
                        ? ('已选：' + self.form.fileName)
                        : h('span', [h('em', '点击或拖拽'), ' MP4 / MOV / WebM（≤500MB）到此处'])
                    ])
                  ];
                }
              }),
              self.form.fileName
                ? h('div', { class: 'publish-file-row' }, [
                    h('span', { class: 'muted' }, self.form.fileName + ' · ' + ((self.form.file && self.form.file.size) ? ((self.form.file.size / 1048576).toFixed(2) + ' MB') : '')),
                    h(ElementPlus.ElButton, { link: true, type: 'danger', onClick: function () { self.clearFile(); } }, function () { return '清除'; })
                  ])
                : null
            ]),
            self.videoUrl
              ? h('div', { class: 'field full publish-cover-box' }, [
                  h('label', '封面设置（必填）'),
                  h('div', { class: 'publish-cover-grid' }, [
                    h('div', { class: 'publish-cover-video' }, [
                      h('video', {
                        id: 'company-publish-video',
                        src: self.videoUrl,
                        controls: true,
                        preload: 'metadata',
                        playsinline: true,
                        style: 'width:100%;max-height:280px;background:#0f172a;border-radius:10px'
                      }),
                      h('div', { class: 'publish-scrub' }, [
                        h('span', { class: 'muted' }, self.formatTime(self.scrubTime)),
                        h(ElementPlus.ElSlider, {
                          modelValue: self.scrubTime,
                          'onUpdate:modelValue': function (v) { self.onScrub(v); },
                          min: 0,
                          max: Math.max(0.1, self.videoDuration || 0.1),
                          step: 0.05,
                          showTooltip: false,
                          style: 'flex:1;margin:0 12px'
                        }),
                        h('span', { class: 'muted' }, self.formatTime(self.videoDuration))
                      ]),
                      h('div', { class: 'publish-cover-actions' }, [
                        h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.captureFrame(); } }, function () { return '截取当前帧'; }),
                        h(ElementPlus.ElUpload, {
                          autoUpload: false,
                          showFileList: false,
                          accept: '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp',
                          onChange: function (f) { self.onCoverUpload(f); }
                        }, {
                          default: function () {
                            return h(ElementPlus.ElButton, {}, function () { return '上传封面图'; });
                          }
                        })
                      ])
                    ]),
                    h('div', { class: 'publish-cover-preview' }, [
                      h('div', { class: 'muted', style: 'margin-bottom:8px' }, self.coverPreview
                        ? ('已选封面' + (self.coverSource === 'frame' ? '（逐帧）' : '（上传）'))
                        : '暂无封面预览'),
                      self.coverPreview
                        ? h('img', { src: self.coverPreview, alt: 'cover' })
                        : h('div', { class: 'publish-cover-empty' }, '请截取或上传封面'),
                      self.coverPreview
                        ? h(ElementPlus.ElButton, {
                            link: true,
                            type: 'danger',
                            style: 'margin-top:8px',
                            onClick: function () { self.clearCover(); }
                          }, function () { return '清除封面'; })
                        : null
                    ])
                  ])
                ])
              : null
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

  function entrustStatusLabel(s) {
    var map = {
      draft: '待处理', queued: '排队中', running: '执行中', paused: '已暂停',
      completed: '已完成', failed: '失败', cancelled: '已取消'
    };
    return map[s] || s || '-';
  }

  function entrustTaskTable(self, extraCols) {
    var cols = [
      h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 80 }),
      h(ElementPlus.ElTableColumn, { prop: 'name', label: '任务', minWidth: 180, showOverflowTooltip: true }),
      h(ElementPlus.ElTableColumn, { label: '账号', minWidth: 140 }, {
        default: function (scope) {
          var r = scope.row;
          var base = (r.accountNickname || r.accountUsername || '') + (r.accountId ? (' #' + r.accountId) : '');
          if (r.accountCount > 1) base += ' 等' + r.accountCount + '个';
          return base || '-';
        }
      })
    ];
    (extraCols || []).forEach(function (c) { cols.push(c); });
    cols.push(
      h(ElementPlus.ElTableColumn, { label: '状态', width: 100 }, {
        default: function (scope) { return entrustStatusLabel(scope.row.status); }
      }),
      h(ElementPlus.ElTableColumn, { prop: 'createdAt', label: '创建时间', width: 170 })
    );
    return h(ElementPlus.ElTable, { data: self.items, stripe: true, vLoading: self.loading, style: 'width:100%' }, function () {
      return cols;
    });
  }

  function entrustPager(self) {
    return h('div', { class: 'pager-row' }, [
      h(ElementPlus.ElPagination, {
        currentPage: self.page,
        'onUpdate:currentPage': function (v) { self.page = v; self.loadTasks(); },
        pageSize: self.pageSize,
        total: self.total,
        layout: 'total, prev, pager, next',
        background: true
      })
    ]);
  }

  var CommentReplyView = {
    name: 'CommentReplyView',
    data: function () {
      return {
        loading: false,
        submitting: false,
        accounts: [],
        fixedTemplates: [],
        aiTemplates: [],
        items: [],
        total: 0,
        page: 1,
        pageSize: 20,
        form: {
          accountId: null,
          replyMode: 'fixed',
          replyTemplateIds: [],
          replyAiTemplateId: null,
          maxReplies: 10
        }
      };
    },
    mounted: function () {
      this.loadAccounts();
      this.loadTemplates();
      this.loadTasks();
    },
    methods: {
      loadAccounts: async function () {
        try {
          var d = await CompanyAPI.opsAccounts();
          this.accounts = d.items || [];
        } catch (e) {
          ElMessage.error(e.message || '加载账号失败');
        }
      },
      loadTemplates: async function () {
        try {
          var d = await CompanyAPI.commentReplyTemplates();
          var items = d.items || [];
          this.fixedTemplates = items.filter(function (x) { return x.contentMode === 'fixed'; });
          this.aiTemplates = items.filter(function (x) { return x.contentMode === 'ai'; });
        } catch (e) {
          ElMessage.error(e.message || '加载回复模板失败');
        }
      },
      loadTasks: async function () {
        this.loading = true;
        try {
          var d = await CompanyAPI.opsTasks({ type: 'comment_reply', page: this.page, pageSize: this.pageSize });
          this.items = d.items || [];
          this.total = d.total || 0;
        } catch (e) {
          ElMessage.error(e.message || '加载委托任务失败');
        } finally {
          this.loading = false;
        }
      },
      submit: async function () {
        if (!this.form.accountId) {
          ElMessage.warning('请选择账号');
          return;
        }
        if (this.form.replyMode === 'fixed' && !(this.form.replyTemplateIds || []).length) {
          ElMessage.warning('请至少勾选一条固定回复模板');
          return;
        }
        if (this.form.replyMode === 'ai' && !this.form.replyAiTemplateId) {
          ElMessage.warning('请选择一条 AI 回复模板');
          return;
        }
        this.submitting = true;
        try {
          await CompanyAPI.createCommentReplyTask({
            accountId: this.form.accountId,
            replyMode: this.form.replyMode,
            replyTemplateIds: this.form.replyMode === 'fixed' ? this.form.replyTemplateIds : [],
            replyAiTemplateId: this.form.replyMode === 'ai' ? this.form.replyAiTemplateId : 0,
            maxReplies: this.form.maxReplies
          });
          ElMessage.success('委托任务已创建，等待运营处理');
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
        pageHead('回复评论', '选择账号与回复模板，生成委托任务供关联运营专员在 Node 端启动执行'),
        h('div', { class: 'panel publish-form-panel' }, [
          panelHead('新建委托'),
          h('div', { class: 'publish-form-grid' }, [
            h('div', { class: 'field' }, [
              h('label', '执行账号'),
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
              h('label', '每账号最多回复条数'),
              h(ElementPlus.ElInputNumber, {
                modelValue: self.form.maxReplies,
                'onUpdate:modelValue': function (v) { self.form.maxReplies = v; },
                min: 1,
                max: 50,
                style: 'width:100%'
              })
            ]),
            h('div', { class: 'field full' }, [
              h('label', '回复模式'),
              h(ElementPlus.ElRadioGroup, {
                modelValue: self.form.replyMode,
                'onUpdate:modelValue': function (v) { self.form.replyMode = v; }
              }, function () {
                return [
                  h(ElementPlus.ElRadioButton, { label: 'fixed' }, function () { return '固定模板'; }),
                  h(ElementPlus.ElRadioButton, { label: 'ai' }, function () { return 'AI 模板'; })
                ];
              })
            ]),
            self.form.replyMode === 'fixed'
              ? h('div', { class: 'field full' }, [
                  h('label', '固定回复模板'),
                  h(ElementPlus.ElSelect, {
                    modelValue: self.form.replyTemplateIds,
                    'onUpdate:modelValue': function (v) { self.form.replyTemplateIds = v; },
                    multiple: true,
                    filterable: true,
                    placeholder: self.fixedTemplates.length ? '可多选模板' : '暂无固定模板',
                    style: 'width:100%'
                  }, function () {
                    return self.fixedTemplates.map(function (t) {
                      return h(ElementPlus.ElOption, {
                        key: t.id,
                        label: t.name + (t.text ? (' · ' + String(t.text).slice(0, 40)) : ''),
                        value: t.id
                      });
                    });
                  })
                ])
              : h('div', { class: 'field full' }, [
                  h('label', 'AI 回复模板'),
                  h(ElementPlus.ElSelect, {
                    modelValue: self.form.replyAiTemplateId,
                    'onUpdate:modelValue': function (v) { self.form.replyAiTemplateId = v; },
                    filterable: true,
                    clearable: true,
                    placeholder: self.aiTemplates.length ? '选择一条 AI 模板' : '暂无 AI 模板',
                    style: 'width:100%'
                  }, function () {
                    return self.aiTemplates.map(function (t) {
                      return h(ElementPlus.ElOption, {
                        key: t.id,
                        label: t.name + (t.text ? (' · ' + String(t.text).slice(0, 40)) : ''),
                        value: t.id
                      });
                    });
                  })
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
          entrustTaskTable(self, [
            h(ElementPlus.ElTableColumn, { label: '模式', width: 100 }, {
              default: function (scope) {
                return scope.row.replyMode === 'ai' ? 'AI' : (scope.row.replyMode === 'fixed' ? '固定' : '-');
              }
            }),
            h(ElementPlus.ElTableColumn, { label: '上限', width: 80 }, {
              default: function (scope) { return scope.row.maxReplies || '-'; }
            })
          ]),
          entrustPager(self)
        ])
      ]);
    }
  };

  var PullVideoListView = {
    name: 'PullVideoListView',
    data: function () {
      return {
        loading: false,
        submitting: false,
        accounts: [],
        items: [],
        total: 0,
        page: 1,
        pageSize: 20,
        form: { accountIds: [], browserBackend: 'tkswarm' }
      };
    },
    mounted: function () {
      this.loadAccounts();
      this.loadTasks();
    },
    methods: {
      loadAccounts: async function () {
        try {
          var d = await CompanyAPI.opsAccounts();
          this.accounts = d.items || [];
        } catch (e) {
          ElMessage.error(e.message || '加载账号失败');
        }
      },
      loadTasks: async function () {
        this.loading = true;
        try {
          var d = await CompanyAPI.opsTasks({ type: 'pull_video_list', page: this.page, pageSize: this.pageSize });
          this.items = d.items || [];
          this.total = d.total || 0;
        } catch (e) {
          ElMessage.error(e.message || '加载委托任务失败');
        } finally {
          this.loading = false;
        }
      },
      submit: async function () {
        if (!(this.form.accountIds || []).length) {
          ElMessage.warning('请选择至少一个账号');
          return;
        }
        this.submitting = true;
        try {
          await CompanyAPI.createPullVideoListTask({
            accountIds: this.form.accountIds,
            browserBackend: this.form.browserBackend || 'tkswarm'
          });
          ElMessage.success('委托任务已创建，等待运营处理');
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
        pageHead('拉取视频列表', '从 TikTok Studio 同步账号线上视频到素材库；任务由关联运营专员在 Node 启动'),
        h('div', { class: 'panel publish-form-panel' }, [
          panelHead('新建委托'),
          h('div', { class: 'publish-form-grid' }, [
            h('div', { class: 'field full' }, [
              h('label', '账号（可多选）'),
              h(ElementPlus.ElSelect, {
                modelValue: self.form.accountIds,
                'onUpdate:modelValue': function (v) { self.form.accountIds = v; },
                multiple: true,
                filterable: true,
                collapseTags: true,
                collapseTagsTooltip: true,
                placeholder: '选择账号',
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
              h('label', '浏览器后端'),
              h(ElementPlus.ElSelect, {
                modelValue: self.form.browserBackend,
                'onUpdate:modelValue': function (v) { self.form.browserBackend = v; },
                style: 'width:100%'
              }, function () {
                return [
                  h(ElementPlus.ElOption, { label: 'TkSwarm 自研', value: 'tkswarm' }),
                  h(ElementPlus.ElOption, { label: '比特浏览器', value: 'bit' })
                ];
              })
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
          entrustTaskTable(self, [
            h(ElementPlus.ElTableColumn, { label: '账号数', width: 90 }, {
              default: function (scope) { return scope.row.accountCount || 1; }
            })
          ]),
          entrustPager(self)
        ])
      ]);
    }
  };

  var PullVideoDetailView = {
    name: 'PullVideoDetailView',
    data: function () {
      return {
        loading: false,
        matLoading: false,
        submitting: false,
        materials: [],
        matTotal: 0,
        matPage: 1,
        matPageSize: 20,
        matKeyword: '',
        selectedIds: [],
        items: [],
        total: 0,
        page: 1,
        pageSize: 20,
        form: { browserBackend: 'tkswarm' }
      };
    },
    mounted: function () {
      this.loadMaterials();
      this.loadTasks();
    },
    methods: {
      loadMaterials: async function () {
        this.matLoading = true;
        try {
          var d = await CompanyAPI.publishedMaterials({
            page: this.matPage,
            pageSize: this.matPageSize,
            keyword: this.matKeyword || undefined
          });
          this.materials = d.items || [];
          this.matTotal = d.total || 0;
        } catch (e) {
          ElMessage.error(e.message || '加载已发布视频失败');
        } finally {
          this.matLoading = false;
        }
      },
      loadTasks: async function () {
        this.loading = true;
        try {
          var d = await CompanyAPI.opsTasks({ type: 'pull_comments', page: this.page, pageSize: this.pageSize });
          this.items = d.items || [];
          this.total = d.total || 0;
        } catch (e) {
          ElMessage.error(e.message || '加载委托任务失败');
        } finally {
          this.loading = false;
        }
      },
      onMatSelect: function (rows) {
        this.selectedIds = (rows || []).map(function (r) { return r.id; });
      },
      submit: async function () {
        if (!(this.selectedIds || []).length) {
          ElMessage.warning('请至少选择一个已发布视频');
          return;
        }
        this.submitting = true;
        try {
          await CompanyAPI.createPullCommentsTask({
            materialIds: this.selectedIds,
            browserBackend: this.form.browserBackend || 'tkswarm'
          });
          ElMessage.success('委托任务已创建，等待运营处理');
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
        pageHead('拉取视频详情', '选择已发布视频拉取评论与详情数据；由关联运营专员在 Node 客户任务中启动'),
        h('div', { class: 'panel publish-form-panel' }, [
          panelHead('选择已发布视频'),
          h('div', { class: 'publish-form-grid', style: 'margin-bottom:12px' }, [
            h('div', { class: 'field', style: 'grid-column: span 2' }, [
              h('label', '搜索'),
              h(ElementPlus.ElInput, {
                modelValue: self.matKeyword,
                'onUpdate:modelValue': function (v) { self.matKeyword = v; },
                placeholder: '标题 / 文件名 / 视频 ID',
                clearable: true,
                onKeyup: function (e) {
                  if (e.key === 'Enter') { self.matPage = 1; self.loadMaterials(); }
                }
              })
            ]),
            h('div', { class: 'field' }, [
              h('label', '浏览器后端'),
              h(ElementPlus.ElSelect, {
                modelValue: self.form.browserBackend,
                'onUpdate:modelValue': function (v) { self.form.browserBackend = v; },
                style: 'width:100%'
              }, function () {
                return [
                  h(ElementPlus.ElOption, { label: 'TkSwarm 自研', value: 'tkswarm' }),
                  h(ElementPlus.ElOption, { label: '比特浏览器', value: 'bit' })
                ];
              })
            ]),
            h('div', { class: 'field', style: 'display:flex;align-items:flex-end' }, [
              h(ElementPlus.ElButton, {
                onClick: function () { self.matPage = 1; self.loadMaterials(); }
              }, function () { return '搜索'; })
            ])
          ]),
          h(ElementPlus.ElTable, {
            data: self.materials,
            stripe: true,
            vLoading: self.matLoading,
            style: 'width:100%',
            onSelectionChange: function (rows) { self.onMatSelect(rows); }
          }, function () {
            return [
              h(ElementPlus.ElTableColumn, { type: 'selection', width: 48 }),
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 80 }),
              h(ElementPlus.ElTableColumn, { prop: 'label', label: '视频', minWidth: 180, showOverflowTooltip: true }),
              h(ElementPlus.ElTableColumn, { prop: 'publishAccount', label: '发布账号', minWidth: 120, showOverflowTooltip: true }),
              h(ElementPlus.ElTableColumn, { prop: 'platformVideoId', label: '平台 ID', minWidth: 140, showOverflowTooltip: true })
            ];
          }),
          h('div', { class: 'pager-row' }, [
            h(ElementPlus.ElPagination, {
              currentPage: self.matPage,
              'onUpdate:currentPage': function (v) { self.matPage = v; self.loadMaterials(); },
              pageSize: self.matPageSize,
              total: self.matTotal,
              layout: 'total, prev, pager, next',
              background: true
            })
          ]),
          h('div', { class: 'publish-form-actions' }, [
            h('span', { class: 'muted', style: 'margin-right:12px' }, '已选 ' + (self.selectedIds.length || 0) + ' 个'),
            h(ElementPlus.ElButton, {
              type: 'primary',
              loading: self.submitting,
              onClick: function () { self.submit(); }
            }, function () { return '提交委托'; })
          ])
        ]),
        h('div', { class: 'panel' }, [
          panelHead('委托任务列表'),
          entrustTaskTable(self, [
            h(ElementPlus.ElTableColumn, { label: '视频数', width: 90 }, {
              default: function (scope) { return scope.row.materialCount || scope.row.totalCount || '-'; }
            })
          ]),
          entrustPager(self)
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
    PublishView: PublishView,
    CommentReplyView: CommentReplyView,
    PullVideoListView: PullVideoListView,
    PullVideoDetailView: PullVideoDetailView
  };
})(window);
