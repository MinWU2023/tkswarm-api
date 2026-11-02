(function () {
  'use strict';

  var h = Vue.h;
  var createApp = Vue.createApp;
  var createRouter = VueRouter.createRouter;
  var createWebHistory = VueRouter.createWebHistory;

  var menuItems = [
    { path: '/dashboard', title: '控制面板', icon: 'Odometer' },
    { path: '/accounts', title: '账号列表', icon: 'User' },
    { path: '/videos', title: '视频数据', icon: 'VideoCamera' },
    { path: '/clues', title: '拓客线索', icon: 'Opportunity' },
    { path: '/analytics', title: '数据看板', icon: 'DataAnalysis' }
  ];

  var AppLayout = {
    name: 'AppLayout',
    data: function () {
      return { user: null, menuItems: menuItems };
    },
    computed: {
      active: function () {
        return this.$route.path;
      }
    },
    mounted: async function () {
      try {
        var st = await CompanyAPI.authStatus();
        this.user = st.user || { username: '企业客户' };
        if (!st.authenticated) {
          CompanyAPI.setToken('');
          this.$router.replace('/login');
        }
      } catch (e) {
        this.user = { username: '企业客户' };
      }
    },
    methods: {
      logout: async function () {
        try { await CompanyAPI.logout(); } catch (e) {}
        CompanyAPI.setToken('');
        ElementPlus.ElMessage.success('已退出');
        this.$router.replace('/login');
      },
      icon: function (name) {
        return ElementPlusIconsVue[name] || ElementPlusIconsVue.Document;
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
            h('div', { class: 'topbar-title' }, self.$route.meta.title || '企业门户'),
            h('div', { class: 'user-chip' }, [
              h('span', { class: 'user-chip-avatar', 'aria-hidden': 'true' }, initial),
              h('span', { class: 'user-chip-name' }, displayName),
              h(ElementPlus.ElButton, {
                size: 'small',
                plain: true,
                onClick: function () { self.logout(); }
              }, function () { return '退出'; })
            ])
          ]),
          h('main', { class: 'content' }, [h(VueRouter.RouterView)])
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
        { path: 'dashboard', component: CompanyViews.DashboardView, meta: { title: '控制面板' } },
        { path: 'accounts', component: CompanyViews.AccountsView, meta: { title: '账号列表' } },
        { path: 'videos', component: CompanyViews.VideosView, meta: { title: '视频数据' } },
        { path: 'clues', component: CompanyViews.CluesView, meta: { title: '拓客线索' } },
        { path: 'analytics', component: CompanyViews.AnalyticsView, meta: { title: '数据看板' } }
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
