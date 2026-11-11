(function () {
  'use strict';

  var h = Vue.h;
  var createApp = Vue.createApp;
  var createRouter = VueRouter.createRouter;
  var createWebHistory = VueRouter.createWebHistory;
  var ElMessage = ElementPlus.ElMessage;
  var ElMessageBox = ElementPlus.ElMessageBox;

  var menuItems = [
    {
      path: '/panel',
      title: '控制面板',
      icon: 'Odometer',
      children: [
        { path: '/dashboard', title: '账号趋势', icon: 'TrendCharts' },
        { path: '/analytics', title: '数据看板', icon: 'DataAnalysis' }
      ]
    },
    { path: '/accounts', title: '账号列表', icon: 'User' },
    { path: '/videos', title: '视频数据', icon: 'VideoCamera' },
    { path: '/clues', title: '拓客线索', icon: 'Opportunity' },
    {
      path: '/ops',
      title: '视频运营',
      icon: 'Film',
      children: [
        { path: '/ops/publish', title: '发布视频', icon: 'Upload' },
        { path: '/ops/video-delivery', title: '视频交付', icon: 'Box' },
        { path: '/ops/youtube-accounts', title: 'YouTube 账号', icon: 'VideoCamera' },
        { path: '/ops/comment-reply', title: '回复评论', icon: 'ChatLineSquare' },
        { path: '/ops/pull-video-list', title: '拉取视频列表', icon: 'List' },
        { path: '/ops/pull-video-detail', title: '拉取视频详情', icon: 'Document' },
        { path: '/support', title: '客服坐席', icon: 'ChatDotRound' }
      ]
    }
  ];

  var AppLayout = {
    name: 'AppLayout',
    data: function () {
      return {
        user: null,
        menuItems: menuItems,
        pwdVisible: false,
        pwdLoading: false,
        pwdForm: { oldPassword: '', newPassword: '', confirmPassword: '' },
        noticeVisible: false,
        noticeLoading: false,
        notices: [],
        noticeUnread: 0
      };
    },
    computed: {
      active: function () {
        return this.$route.path;
      },
      openMenus: function () {
        var p = this.$route.path || '';
        if (p === '/dashboard' || p.indexOf('/analytics') === 0) return ['/panel'];
        if (p.indexOf('/ops') === 0 || p === '/support') return ['/ops'];
        return [];
      }
    },
    mounted: async function () {
      try {
        var st = await CompanyAPI.authStatus();
        this.user = st.user || { username: '企业客户' };
        if (!st.authenticated) {
          CompanyAPI.setToken('');
          this.$router.replace('/login');
          return;
        }
        this.refreshNoticeBadge();
      } catch (e) {
        this.user = { username: '企业客户' };
      }
    },
    methods: {
      logout: async function () {
        try {
          await ElMessageBox.confirm('确定退出当前企业门户登录吗？', '退出登录', {
            type: 'warning',
            confirmButtonText: '退出',
            cancelButtonText: '取消'
          });
        } catch (e) {
          return;
        }
        try { await CompanyAPI.logout(); } catch (e) {}
        CompanyAPI.setToken('');
        ElMessage.success('已退出');
        this.$router.replace('/login');
      },
      icon: function (name) {
        return ElementPlusIconsVue[name] || ElementPlusIconsVue.Document;
      },
      onUserCommand: function (cmd) {
        if (cmd === 'password') this.openPassword();
        else if (cmd === 'notice') this.openNotices();
        else if (cmd === 'logout') this.logout();
      },
      openPassword: function () {
        this.pwdForm = { oldPassword: '', newPassword: '', confirmPassword: '' };
        this.pwdVisible = true;
      },
      submitPassword: async function () {
        var f = this.pwdForm;
        if (!f.oldPassword || !f.newPassword) {
          ElMessage.warning('请填写原密码与新密码');
          return;
        }
        if (f.newPassword.length < 6) {
          ElMessage.warning('新密码至少 6 位');
          return;
        }
        if (f.newPassword !== f.confirmPassword) {
          ElMessage.warning('两次输入的新密码不一致');
          return;
        }
        this.pwdLoading = true;
        try {
          await CompanyAPI.updatePassword({
            oldPassword: f.oldPassword,
            newPassword: f.newPassword
          });
          ElMessage.success('密码修改成功，请重新登录');
          this.pwdVisible = false;
          try { await CompanyAPI.logout(); } catch (e) {}
          CompanyAPI.setToken('');
          this.$router.replace('/login');
        } catch (e) {
          ElMessage.error(e.message || '修改失败');
        } finally {
          this.pwdLoading = false;
        }
      },
      refreshNoticeBadge: async function () {
        try {
          var d = await CompanyAPI.notifications();
          this.noticeUnread = Number(d.unread || 0);
        } catch (e) {
          this.noticeUnread = 0;
        }
      },
      openNotices: async function () {
        this.noticeVisible = true;
        this.noticeLoading = true;
        try {
          var d = await CompanyAPI.notifications();
          this.notices = d.items || [];
          this.noticeUnread = Number(d.unread || 0);
        } catch (e) {
          ElMessage.error(e.message || '加载通知失败');
          this.notices = [];
        } finally {
          this.noticeLoading = false;
        }
      }
    },
    render: function () {
      var self = this;
      var displayName = (self.user && (self.user.nickname || self.user.username)) || '企业客户';
      var initial = String(displayName).trim().charAt(0).toUpperCase() || 'C';
      return h('div', { class: 'layout' }, [
        h('aside', { class: 'side' }, [
          h('div', { class: 'side-brand' }, [
            h('img', { class: 'brand-mark', src: '/images/icon.png?v=2', alt: 'TkSwarm' }),
            h('div', [
              h('div', { class: 'brand-title' }, 'TKSwarm'),
              h('div', { class: 'brand-sub' }, '企业门户')
            ])
          ]),
          h('div', { class: 'side-nav-label' }, '导航'),
          h('div', { class: 'side-nav' }, [
            h(ElementPlus.ElMenu, {
              defaultActive: self.active,
              defaultOpeneds: self.openMenus,
              router: true
            }, function () {
              return self.menuItems.map(function (item) {
                if (item.children && item.children.length) {
                  return h(ElementPlus.ElSubMenu, { index: item.path }, {
                    title: function () {
                      return h('div', { class: 'flex items-center gap-2' }, [
                        h(ElementPlus.ElIcon, null, function () { return h(self.icon(item.icon)); }),
                        h('span', item.title)
                      ]);
                    },
                    default: function () {
                      return item.children.map(function (child) {
                        return h(ElementPlus.ElMenuItem, { index: child.path, route: child.path }, {
                          default: function () {
                            return h('div', { class: 'flex items-center gap-2' }, [
                              h(ElementPlus.ElIcon, null, function () { return h(self.icon(child.icon || 'Document')); }),
                              h('span', child.title)
                            ]);
                          }
                        });
                      });
                    }
                  });
                }
                return h(ElementPlus.ElMenuItem, { index: item.path, route: item.path }, {
                  default: function () {
                    return h('div', { class: 'flex items-center gap-2' }, [
                      h(ElementPlus.ElIcon, null, function () { return h(self.icon(item.icon)); }),
                      h('span', item.title)
                    ]);
                  }
                });
              });
            })
          ])
        ]),
        h('div', { class: 'main-wrap' }, [
          h('header', { class: 'topbar' }, [
            h('div', { class: 'topbar-title' }, self.$route.meta.title || '企业门户'),
            h('div', { class: 'user-chip' }, [
              h(ElementPlus.ElDropdown, {
                trigger: 'click',
                onCommand: function (cmd) { self.onUserCommand(cmd); }
              }, {
                default: function () {
                  return h('button', { type: 'button', class: 'user-chip-trigger' }, [
                    h('span', { class: 'user-chip-avatar', 'aria-hidden': 'true' }, initial),
                    h('span', { class: 'user-chip-name' }, displayName),
                    self.noticeUnread > 0
                      ? h('span', { class: 'user-chip-dot', title: '有新消息' })
                      : null,
                    h(ElementPlus.ElIcon, { class: 'user-chip-caret' }, function () {
                      return h(ElementPlusIconsVue.ArrowDown);
                    })
                  ]);
                },
                dropdown: function () {
                  return h(ElementPlus.ElDropdownMenu, null, function () {
                    return [
                      h(ElementPlus.ElDropdownItem, { command: 'password' }, function () {
                        return h('div', { class: 'user-dd-item' }, [
                          h(ElementPlus.ElIcon, null, function () { return h(ElementPlusIconsVue.Lock); }),
                          h('span', '修改密码')
                        ]);
                      }),
                      h(ElementPlus.ElDropdownItem, { command: 'notice' }, function () {
                        return h('div', { class: 'user-dd-item' }, [
                          h(ElementPlus.ElIcon, null, function () { return h(ElementPlusIconsVue.Bell); }),
                          h('span', '消息通知'),
                          self.noticeUnread > 0
                            ? h('span', { class: 'user-dd-badge' }, String(self.noticeUnread))
                            : null
                        ]);
                      }),
                      h(ElementPlus.ElDropdownItem, { divided: true, command: 'logout' }, function () {
                        return h('div', { class: 'user-dd-item is-danger' }, [
                          h(ElementPlus.ElIcon, null, function () { return h(ElementPlusIconsVue.SwitchButton); }),
                          h('span', '退出登录')
                        ]);
                      })
                    ];
                  });
                }
              })
            ])
          ]),
          h('main', { class: 'content' }, [h(VueRouter.RouterView)]),
          h(ElementPlus.ElDialog, {
            modelValue: self.pwdVisible,
            'onUpdate:modelValue': function (v) { self.pwdVisible = v; },
            title: '修改密码',
            width: '420px',
            destroyOnClose: true
          }, {
            default: function () {
              return h(ElementPlus.ElForm, { labelPosition: 'top' }, function () {
                return [
                  h(ElementPlus.ElFormItem, { label: '原密码' }, function () {
                    return h(ElementPlus.ElInput, {
                      modelValue: self.pwdForm.oldPassword,
                      'onUpdate:modelValue': function (v) { self.pwdForm.oldPassword = v; },
                      type: 'password',
                      showPassword: true,
                      placeholder: '请输入原密码'
                    });
                  }),
                  h(ElementPlus.ElFormItem, { label: '新密码' }, function () {
                    return h(ElementPlus.ElInput, {
                      modelValue: self.pwdForm.newPassword,
                      'onUpdate:modelValue': function (v) { self.pwdForm.newPassword = v; },
                      type: 'password',
                      showPassword: true,
                      placeholder: '至少 6 位'
                    });
                  }),
                  h(ElementPlus.ElFormItem, { label: '确认新密码' }, function () {
                    return h(ElementPlus.ElInput, {
                      modelValue: self.pwdForm.confirmPassword,
                      'onUpdate:modelValue': function (v) { self.pwdForm.confirmPassword = v; },
                      type: 'password',
                      showPassword: true,
                      placeholder: '再次输入新密码'
                    });
                  })
                ];
              });
            },
            footer: function () {
              return [
                h(ElementPlus.ElButton, {
                  onClick: function () { self.pwdVisible = false; }
                }, function () { return '取消'; }),
                h(ElementPlus.ElButton, {
                  type: 'primary',
                  loading: self.pwdLoading,
                  onClick: function () { self.submitPassword(); }
                }, function () { return '保存'; })
              ];
            }
          }),
          h(ElementPlus.ElDrawer, {
            modelValue: self.noticeVisible,
            'onUpdate:modelValue': function (v) { self.noticeVisible = v; },
            title: '消息通知',
            size: '380px',
            direction: 'rtl'
          }, {
            default: function () {
              if (self.noticeLoading) {
                return h('div', { class: 'notice-empty' }, '加载中…');
              }
              if (!self.notices.length) {
                return h('div', { class: 'notice-empty' }, '暂无消息');
              }
              return h('div', { class: 'notice-list' }, self.notices.map(function (n) {
                return h('div', { class: 'notice-card level-' + (n.level || 'info'), key: n.id }, [
                  h('div', { class: 'notice-title' }, n.title || '通知'),
                  h('div', { class: 'notice-body' }, n.body || ''),
                  h('div', { class: 'notice-time' }, n.createdAt || '')
                ]);
              }));
            }
          })
        ])
      ]);
    }
  };

  var routes = [
    { path: '/login', name: 'login', component: CompanyViews.LoginView, meta: { public: true, title: '登录' } },
    {
      path: '/',
      component: AppLayout,
      redirect: '/dashboard',
      children: [
        { path: 'dashboard', component: CompanyViews.DashboardView, meta: { title: '账号趋势' } },
        { path: 'accounts', component: CompanyViews.AccountsView, meta: { title: '账号列表' } },
        { path: 'accounts/:id', component: CompanyViews.AccountDetailView, meta: { title: '账号详情' } },
        { path: 'videos', component: CompanyViews.VideosView, meta: { title: '视频数据' } },
        { path: 'videos/:id', component: CompanyViews.VideoDetailView, meta: { title: '视频详情' } },
        { path: 'clues', component: CompanyViews.CluesView, meta: { title: '拓客线索' } },
        { path: 'analytics', component: CompanyViews.AnalyticsView, meta: { title: '数据看板' } },
        { path: 'support', component: CompanyViews.SupportView, meta: { title: '客服坐席' } },
        { path: 'ops', redirect: '/ops/publish' },
        { path: 'ops/publish', component: CompanyViews.PublishView, meta: { title: '发布视频' } },
        { path: 'ops/video-delivery', component: CompanyViews.VideoDeliveryView, meta: { title: '视频交付' } },
        { path: 'ops/video-delivery/:id', component: CompanyViews.VideoDeliveryDetailView, meta: { title: '交付详情' } },
        { path: 'ops/youtube-accounts', component: CompanyViews.YoutubeAccountsView, meta: { title: 'YouTube 账号' } },
        { path: 'ops/comment-reply', component: CompanyViews.CommentReplyView, meta: { title: '回复评论' } },
        { path: 'ops/pull-video-list', component: CompanyViews.PullVideoListView, meta: { title: '拉取视频列表' } },
        { path: 'ops/pull-video-detail', component: CompanyViews.PullVideoDetailView, meta: { title: '拉取视频详情' } }
      ]
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' }
  ];

  var router = createRouter({
    history: createWebHistory('/company'),
    routes: routes
  });

  router.beforeEach(async function (to, from, next) {
    if (to.meta && to.meta.public) return next();
    var token = CompanyAPI.getToken();
    if (!token) {
      return next({ path: '/login', query: { redirect: to.fullPath } });
    }
    next();
  });

  window.__companyOnUnauthorized = function () {
    if (router.currentRoute.value.path !== '/login') {
      router.replace('/login');
    }
  };

  var app = createApp({
    render: function () {
      return h(VueRouter.RouterView);
    }
  });

  if (window.ElementPlusIconsVue) {
    for (var key in ElementPlusIconsVue) {
      if (Object.prototype.hasOwnProperty.call(ElementPlusIconsVue, key)) {
        app.component(key, ElementPlusIconsVue[key]);
      }
    }
  }

  app.use(ElementPlus, { locale: (ElementPlus.lang && ElementPlus.lang.zhCn) || undefined });
  app.use(router);
  app.mount('#app');
})();
