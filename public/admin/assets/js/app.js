(function () {
  'use strict';

  var h = Vue.h;
  var createApp = Vue.createApp;
  var createRouter = VueRouter.createRouter;
  var createWebHashHistory = VueRouter.createWebHashHistory;

  var menuItems = [
    { path: '/dashboard', title: '工作台', icon: 'Odometer' },
    { path: '/tickets', title: '工单处理', icon: 'Ticket' },
    { path: '/contact-inquiries', title: '官网咨询', icon: 'ChatDotRound' },
    { path: '/site-settings', title: '官网设置', icon: 'Link' },
    { path: '/versions', title: '版本发布', icon: 'Box' },
    { path: '/env-installers', title: '环境安装包', icon: 'Download' },
    { path: '/help-categories', title: '帮助分类', icon: 'FolderOpened' },
    { path: '/help-docs', title: '帮助文档', icon: 'Document' },
    { path: '/quick-ops', title: '快捷操作', icon: 'Operation' }
  ];

  var AppLayout = {
    name: 'AppLayout',
    data: function () {
      return {
        user: null,
        collapsed: false,
        menuItems: menuItems
      };
    },
    computed: {
      active: function () {
        return this.$route.path;
      }
    },
    mounted: async function () {
      try {
        var st = await AdminAPI.authStatus();
        this.user = st.user || { username: 'Admin' };
        if (st.authEnabled && !st.authenticated) {
          AdminAPI.setToken('');
          this.$router.replace('/login');
        }
      } catch (e) {
        this.user = { username: 'Admin' };
      }
    },
    methods: {
      logout: async function () {
        try { await AdminAPI.logout(); } catch (e) {}
        AdminAPI.setToken('');
        ElementPlus.ElMessage.success('已退出');
        this.$router.replace('/login');
      },
      icon: function (name) {
        return ElementPlusIconsVue[name] || ElementPlusIconsVue.Document;
      }
    },
    render: function () {
      var self = this;
      var displayName = (self.user && (self.user.nickname || self.user.username)) || '管理员';
      var initial = String(displayName).trim().charAt(0).toUpperCase() || 'A';
      return h('div', { class: 'layout' }, [
        h('aside', { class: 'side' }, [
          h('div', { class: 'side-brand' }, [
            h('img', { class: 'brand-logo brand-mark', src: '/images/icon.png?v=2', alt: 'TkSwarm' }),
            h('div', [
              h('div', { class: 'font-bold text-slate-800' }, 'TKSwarm'),
              h('div', { class: 'text-xs text-slate-400' }, '运营控制台')
            ])
          ]),
          h('div', { class: 'side-nav-label' }, '菜单'),
          h('div', { class: 'side-nav' }, [
            h(ElementPlus.ElMenu, {
              defaultActive: self.active,
              router: true
            }, function () {
              return self.menuItems.map(function (item) {
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
            h('div', { class: 'topbar-title' }, self.$route.meta.title || '控制台'),
            h('div', { class: 'user-chip' }, [
              h('span', { class: 'user-chip-avatar', 'aria-hidden': 'true' }, initial),
              h('span', { class: 'user-chip-name' }, displayName),
              h(ElementPlus.ElButton, { size: 'small', plain: true, onClick: function () { self.logout(); } }, function () { return '退出'; })
            ])
          ]),
          h('main', { class: 'content' }, [h(VueRouter.RouterView)])
        ])
      ]);
    }
  };

  var routes = [
    { path: '/login', name: 'login', component: AdminViews.LoginView, meta: { public: true, title: '登录' } },
    {
      path: '/',
      component: AppLayout,
      redirect: '/dashboard',
      children: [
        { path: 'dashboard', component: AdminViews.DashboardView, meta: { title: '工作台' } },
        { path: 'tickets', component: AdminViews.TicketsView, meta: { title: '工单处理' } },
        { path: 'contact-inquiries', component: AdminViews.ContactInquiriesView, meta: { title: '官网咨询' } },
        { path: 'site-settings', component: AdminViews.SiteSettingsView, meta: { title: '官网设置' } },
        { path: 'versions', component: AdminViews.VersionsView, meta: { title: '版本发布' } },
        { path: 'env-installers', component: AdminViews.EnvInstallersView, meta: { title: '环境安装包' } },
        { path: 'help-categories', component: AdminViews.HelpCategoriesView, meta: { title: '帮助分类' } },
        { path: 'help-docs', component: AdminViews.HelpDocsView, meta: { title: '帮助文档' } },
        { path: 'quick-ops', component: AdminViews.QuickOpsView, meta: { title: '快捷操作' } },
        // 兼容旧书签
        { path: 'task-reset', redirect: '/quick-ops' },
        { path: 'task-clear', redirect: '/quick-ops' }
      ]
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' }
  ];

  var router = createRouter({
    history: createWebHashHistory(),
    routes: routes
  });

  router.beforeEach(async function (to, from, next) {
    if (to.meta && to.meta.public) return next();
    var token = AdminAPI.getToken();
    if (!token) {
      try {
        var st = await AdminAPI.authStatus();
        if (!st.authEnabled) return next();
      } catch (e) {}
      return next({ path: '/login', query: { redirect: to.fullPath } });
    }
    next();
  });

  window.__adminOnUnauthorized = function () {
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
