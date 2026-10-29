(function (global) {
  'use strict';

  var h = Vue.h;
  var ElMessage = ElementPlus.ElMessage;
  var ElMessageBox = ElementPlus.ElMessageBox;

  function statusTag(status) {
    var map = {
      open: { t: '待处理', type: 'warning' },
      processing: { t: '处理中', type: '' },
      replied: { t: '已回复', type: 'success' },
      closed: { t: '已关闭', type: 'info' },
      draft: { t: '草稿', type: 'info' },
      published: { t: '已发布', type: 'success' },
      archived: { t: '已归档', type: 'info' },
      new: { t: '新咨询', type: 'warning' },
      done: { t: '已完成', type: 'success' },
      spam: { t: '垃圾', type: 'info' }
    };
    var m = map[status] || { t: status || '-', type: 'info' };
    return h(ElementPlus.ElTag, { type: m.type, size: 'small', effect: 'light' }, function () { return m.t; });
  }

  function pageHead(title, desc, actions) {
    var kids = [
      h('div', { class: 'page-head-text' }, [
        h('h2', title),
        desc ? h('p', desc) : null
      ].filter(Boolean))
    ];
    if (actions) {
      kids.push(h('div', { class: 'page-toolbar' }, Array.isArray(actions) ? actions : [actions]));
    }
    return h('div', { class: 'page-head' }, kids);
  }

  function panelHead(title, extra) {
    var kids = [typeof title === 'string' ? h('b', title) : title];
    if (extra) kids.push(extra);
    return h('div', { class: 'panel-head' }, kids);
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
    mounted: function () { this.refreshCaptcha(); },
    methods: {
      refreshCaptcha: async function () {
        try {
          var d = await AdminAPI.captcha();
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
          var data = await AdminAPI.login(this.form);
          AdminAPI.setToken(data.token);
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
      return h('div', { class: 'login-shell login-shell--split' }, [
        h('aside', { class: 'login-atmosphere', 'aria-hidden': 'true' }, [
          h('div', { class: 'login-atmosphere-grid' }),
          h('div', { class: 'login-atmosphere-glow' }),
          h('div', { class: 'login-atmosphere-brand' }, [
            h('img', { src: '/images/icon.png?v=2', alt: '' }),
            h('div', { class: 'login-atmosphere-name' }, 'TKSwarm')
          ]),
          h('div', { class: 'login-atmosphere-line' }, '运营控制台 · 工单 / 版本 / 帮助文档')
        ]),
        h('div', { class: 'login-stage' }, [
          h('div', { class: 'glass-card login-panel' }, [
            h('div', { class: 'login-brand' }, [
              h('img', { class: 'brand-logo brand-logo-wide', src: '/images/logo.png?v=2', alt: 'TkSwarm' }),
              h('div', [
                h('div', { class: 'login-title' }, '登录控制台'),
                h('div', { class: 'login-sub' }, '请使用管理员账号进入系统')
              ])
            ]),
            h(ElementPlus.ElForm, { labelPosition: 'top', onSubmit: function (e) { e.preventDefault(); self.submit(); } }, function () {
              return [
                h(ElementPlus.ElFormItem, { label: '账号' }, function () {
                  return h(ElementPlus.ElInput, {
                    modelValue: self.form.username,
                    'onUpdate:modelValue': function (v) { self.form.username = v; },
                    placeholder: '管理员账号',
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
                      style: 'flex:1'
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
                  }, function () { return '记住登录'; }),
                  h('span', { class: 'text-xs text-slate-400' }, '会话保持')
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
      return { loading: false, stats: {}, recent: [] };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var d = await AdminAPI.overview();
          this.stats = d.stats || {};
          this.recent = d.recentTickets || [];
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.loading = false;
        }
      }
    },
    render: function () {
      var s = this.stats || {};
      var cards = [
        { label: '待处理工单', value: s.ticketsOpen || 0, hint: '共 ' + (s.ticketsTotal || 0) + ' 条' },
        { label: '已发布版本', value: s.versionsPublished || 0, hint: '共 ' + (s.versionsTotal || 0) + ' 个' },
        { label: '已发布文档', value: s.docsPublished || 0, hint: '共 ' + (s.docsTotal || 0) + ' 篇' },
        { label: '帮助分类', value: s.categories || 0, hint: '文档分类数量' }
      ];
      return h('div', { class: 'page-view', 'v-loading': this.loading }, [
        pageHead('工作台', '一眼看清工单、版本与帮助文档概况'),
        h('div', { class: 'stat-grid' }, cards.map(function (c) {
          return h('div', { class: 'stat-card' }, [
            h('div', { class: 'label' }, c.label),
            h('div', { class: 'value' }, String(c.value)),
            h('div', { class: 'hint' }, c.hint)
          ]);
        })),
        h('div', { class: 'panel', style: 'margin-bottom:16px' }, [
          panelHead('任务数据运维', h('span', { class: 'text-xs text-slate-400' }, '总任务 / 子任务维护入口')),
          h('div', { class: 'page-toolbar' }, [
            h(ElementPlus.ElButton, {
              type: 'warning',
              onClick: function () { location.hash = '#/task-reset'; }
            }, function () { return '重置任务数据'; }),
            h(ElementPlus.ElButton, {
              type: 'danger',
              plain: true,
              onClick: function () { location.hash = '#/task-clear'; }
            }, function () { return '清除任务数据'; })
          ])
        ]),
        h('div', { class: 'panel' }, [
          panelHead('最近工单', h(ElementPlus.ElButton, { text: true, type: 'primary', onClick: function () { location.hash = '#/tickets'; } }, function () { return '查看全部'; })),
          h(ElementPlus.ElTable, { data: this.recent, stripe: true, size: 'default', style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'ticketNo', label: '工单号', width: 160 }),
              h(ElementPlus.ElTableColumn, { prop: 'subject', label: '主题', minWidth: 200 }),
              h(ElementPlus.ElTableColumn, { label: '状态', width: 100 }, {
                default: function (scope) { return statusTag(scope.row.status); }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'createdAt', label: '提交时间', width: 180 })
            ];
          })
        ])
      ]);
    }
  };

  var TicketsView = {
    name: 'TicketsView',
    data: function () {
      return {
        loading: false,
        rows: [],
        status: '',
        dialog: false,
        current: null,
        form: { status: 'open', priority: 'normal', reply: '' }
      };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var q = this.status ? ('?status=' + encodeURIComponent(this.status)) : '';
          var data = await AdminAPI.tickets(q);
          this.rows = Array.isArray(data) ? data : [];
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.loading = false;
        }
      },
      open: async function (row) {
        try {
          var d = await AdminAPI.ticket(row.id);
          this.current = d;
          this.form = { status: d.status || 'open', priority: d.priority || 'normal', reply: d.reply || '' };
          this.dialog = true;
        } catch (e) {
          ElMessage.error(e.message);
        }
      },
      save: async function () {
        try {
          await AdminAPI.updateTicket(this.current.id, this.form);
          ElMessage.success('工单已更新');
          this.dialog = false;
          this.load();
        } catch (e) {
          ElMessage.error(e.message);
        }
      },
      remove: async function (row) {
        try {
          await ElMessageBox.confirm('确定删除工单 ' + (row.ticketNo || row.id) + ' 吗？', '确认', { type: 'warning' });
          await AdminAPI.deleteTicket(row.id);
          ElMessage.success('已删除');
          this.load();
        } catch (e) {
          if (e !== 'cancel') ElMessage.error(e.message || String(e));
        }
      }
    },
    render: function () {
      var self = this;
      return h('div', [
        pageHead('工单处理', '查看并回复客户端提交的问题反馈', [
          h(ElementPlus.ElSelect, {
            modelValue: self.status,
            'onUpdate:modelValue': function (v) { self.status = v; self.load(); },
            clearable: true,
            placeholder: '全部状态',
            style: 'width:140px'
          }, function () {
            return [
              h(ElementPlus.ElOption, { label: '待处理', value: 'open' }),
              h(ElementPlus.ElOption, { label: '处理中', value: 'processing' }),
              h(ElementPlus.ElOption, { label: '已回复', value: 'replied' }),
              h(ElementPlus.ElOption, { label: '已关闭', value: 'closed' })
            ];
          }),
          h(ElementPlus.ElButton, { onClick: function () { self.load(); } }, function () { return '刷新'; })
        ]),
        h('div', { class: 'panel' }, [
          h(ElementPlus.ElTable, { data: self.rows, vLoading: self.loading, stripe: true, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'ticketNo', label: '工单号', width: 150 }),
              h(ElementPlus.ElTableColumn, { prop: 'subject', label: '主题', minWidth: 180 }),
              h(ElementPlus.ElTableColumn, { prop: 'contactName', label: '联系人', width: 110 }),
              h(ElementPlus.ElTableColumn, { label: '状态', width: 100 }, {
                default: function (scope) { return statusTag(scope.row.status); }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'createdAt', label: '时间', width: 170 }),
              h(ElementPlus.ElTableColumn, { label: '操作', width: 160, fixed: 'right' }, {
                default: function (scope) {
                  return [
                    h(ElementPlus.ElButton, { size: 'small', type: 'primary', link: true, onClick: function () { self.open(scope.row); } }, function () { return '处理'; }),
                    h(ElementPlus.ElButton, { size: 'small', type: 'danger', link: true, onClick: function () { self.remove(scope.row); } }, function () { return '删除'; })
                  ];
                }
              })
            ];
          })
        ]),
        h(ElementPlus.ElDialog, {
          modelValue: self.dialog,
          'onUpdate:modelValue': function (v) { self.dialog = v; },
          title: self.current ? ('处理工单 ' + (self.current.ticketNo || '')) : '处理工单',
          width: '640px'
        }, {
          default: function () {
            if (!self.current) return null;
            return h('div', { class: 'space-y-3' }, [
              h('div', { class: 'text-sm text-slate-500' }, '主题：' + self.current.subject),
              h('div', {
                class: 'text-sm whitespace-pre-wrap bg-slate-50 rounded-lg p-3 border border-slate-100'
              }, self.current.content || ''),
              h(ElementPlus.ElForm, { labelWidth: '80px' }, function () {
                return [
                  h(ElementPlus.ElFormItem, { label: '状态' }, function () {
                    return h(ElementPlus.ElSelect, {
                      modelValue: self.form.status,
                      'onUpdate:modelValue': function (v) { self.form.status = v; },
                      style: 'width:100%'
                    }, function () {
                      return ['open', 'processing', 'replied', 'closed'].map(function (v) {
                        return h(ElementPlus.ElOption, { label: ({ open: '待处理', processing: '处理中', replied: '已回复', closed: '已关闭' })[v], value: v });
                      });
                    });
                  }),
                  h(ElementPlus.ElFormItem, { label: '优先级' }, function () {
                    return h(ElementPlus.ElSelect, {
                      modelValue: self.form.priority,
                      'onUpdate:modelValue': function (v) { self.form.priority = v; },
                      style: 'width:100%'
                    }, function () {
                      return ['low', 'normal', 'high', 'urgent'].map(function (v) {
                        return h(ElementPlus.ElOption, { label: ({ low: '低', normal: '普通', high: '高', urgent: '紧急' })[v], value: v });
                      });
                    });
                  }),
                  h(ElementPlus.ElFormItem, { label: '回复' }, function () {
                    return h(ElementPlus.ElInput, {
                      type: 'textarea',
                      rows: 5,
                      modelValue: self.form.reply,
                      'onUpdate:modelValue': function (v) { self.form.reply = v; },
                      placeholder: '填写回复内容'
                    });
                  })
                ];
              })
            ]);
          },
          footer: function () {
            return [
              h(ElementPlus.ElButton, { onClick: function () { self.dialog = false; } }, function () { return '取消'; }),
              h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.save(); } }, function () { return '保存'; })
            ];
          }
        })
      ]);
    }
  };

  var VersionsView = {
    name: 'VersionsView',
    data: function () {
      return {
        loading: false,
        rows: [],
        dialog: false,
        editing: null,
        form: { version: '', title: '', channel: 'stable', status: 'draft', description: '', downloadUrl: '' }
      };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var data = await AdminAPI.versions();
          this.rows = Array.isArray(data) ? data : [];
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.loading = false;
        }
      },
      openCreate: function () {
        this.editing = null;
        this.form = { version: '', title: '', channel: 'stable', status: 'draft', description: '', downloadUrl: '' };
        this.dialog = true;
      },
      openEdit: function (row) {
        this.editing = row;
        this.form = {
          version: row.version || '',
          title: row.title || '',
          channel: row.channel || 'stable',
          status: row.status || 'draft',
          description: row.description || '',
          downloadUrl: row.downloadUrlRaw || row.download_url || ''
        };
        this.dialog = true;
      },
      save: async function () {
        try {
          if (this.editing) await AdminAPI.updateVersion(this.editing.id, this.form);
          else await AdminAPI.createVersion(this.form);
          ElMessage.success('已保存');
          this.dialog = false;
          this.load();
        } catch (e) {
          ElMessage.error(e.message);
        }
      },
      upload: function (row) {
        var self = this;
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = '.zip,application/zip';
        input.onchange = async function () {
          var file = input.files && input.files[0];
          if (!file) return;
          try {
            ElMessage.info('正在上传…');
            await AdminAPI.uploadVersion(row.id, file);
            ElMessage.success('代码包已上传');
            self.load();
          } catch (e) {
            ElMessage.error(e.message);
          }
        };
        input.click();
      },
      publish: async function (row) {
        try {
          await ElMessageBox.confirm('发布后客户端即可下载该版本，确认？', '发布确认');
          await AdminAPI.publishVersion(row.id);
          ElMessage.success('已发布');
          this.load();
        } catch (e) {
          if (e !== 'cancel') ElMessage.error(e.message || String(e));
        }
      },
      setLatest: async function (row) {
        try {
          await AdminAPI.latestVersion(row.id);
          ElMessage.success('已设为最新');
          this.load();
        } catch (e) {
          ElMessage.error(e.message);
        }
      },
      remove: async function (row) {
        try {
          await ElMessageBox.confirm('确定删除版本 ' + row.version + ' 吗？', '确认', { type: 'warning' });
          await AdminAPI.deleteVersion(row.id);
          ElMessage.success('已删除');
          this.load();
        } catch (e) {
          if (e !== 'cancel') ElMessage.error(e.message || String(e));
        }
      }
    },
    render: function () {
      var self = this;
      return h('div', [
        pageHead('版本发布', '上传代码包或填写下载链接后发布，供客户端安装', [
          h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.openCreate(); } }, function () { return '新建版本'; })
        ]),
        h('div', { class: 'panel' }, [
          h(ElementPlus.ElTable, { data: self.rows, vLoading: self.loading, stripe: true, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 70 }),
              h(ElementPlus.ElTableColumn, { prop: 'version', label: '版本号', width: 120 }),
              h(ElementPlus.ElTableColumn, { prop: 'title', label: '标题', minWidth: 140 }),
              h(ElementPlus.ElTableColumn, { prop: 'channel', label: '通道', width: 90 }),
              h(ElementPlus.ElTableColumn, { label: '状态', width: 100 }, {
                default: function (scope) { return statusTag(scope.row.status); }
              }),
              h(ElementPlus.ElTableColumn, { label: '包', width: 100 }, {
                default: function (scope) {
                  if (scope.row.has_file || scope.row.hasFile) {
                    return h(ElementPlus.ElTag, { type: 'success', size: 'small' }, function () { return '已上传'; });
                  }
                  if (scope.row.hasUrl || scope.row.downloadUrlRaw || scope.row.download_url) {
                    return h(ElementPlus.ElTag, { type: '', size: 'small' }, function () { return '外链'; });
                  }
                  return h('span', { class: 'text-slate-400 text-xs' }, '未配置');
                }
              }),
              h(ElementPlus.ElTableColumn, { label: '操作', width: 280, fixed: 'right' }, {
                default: function (scope) {
                  var row = scope.row;
                  return h('div', { class: 'flex flex-wrap gap-1' }, [
                    h(ElementPlus.ElButton, { size: 'small', link: true, onClick: function () { self.openEdit(row); } }, function () { return '编辑'; }),
                    h(ElementPlus.ElButton, { size: 'small', link: true, onClick: function () { self.upload(row); } }, function () { return '上传包'; }),
                    row.status !== 'published'
                      ? h(ElementPlus.ElButton, { size: 'small', link: true, type: 'primary', onClick: function () { self.publish(row); } }, function () { return '发布'; })
                      : null,
                    row.status === 'published' && !(row.is_latest || row.isLatest)
                      ? h(ElementPlus.ElButton, { size: 'small', link: true, onClick: function () { self.setLatest(row); } }, function () { return '设最新'; })
                      : null,
                    h(ElementPlus.ElButton, { size: 'small', link: true, type: 'danger', onClick: function () { self.remove(row); } }, function () { return '删除'; })
                  ]);
                }
              })
            ];
          })
        ]),
        h(ElementPlus.ElDialog, {
          modelValue: self.dialog,
          'onUpdate:modelValue': function (v) { self.dialog = v; },
          title: self.editing ? '编辑版本' : '新建版本',
          width: '560px'
        }, {
          default: function () {
            return h(ElementPlus.ElForm, { labelWidth: '80px' }, function () {
              return [
                h(ElementPlus.ElFormItem, { label: '版本号' }, function () {
                  return h(ElementPlus.ElInput, { modelValue: self.form.version, 'onUpdate:modelValue': function (v) { self.form.version = v; }, placeholder: '如 1.0.2' });
                }),
                h(ElementPlus.ElFormItem, { label: '标题' }, function () {
                  return h(ElementPlus.ElInput, { modelValue: self.form.title, 'onUpdate:modelValue': function (v) { self.form.title = v; } });
                }),
                h(ElementPlus.ElFormItem, { label: '通道' }, function () {
                  return h(ElementPlus.ElSelect, { modelValue: self.form.channel, 'onUpdate:modelValue': function (v) { self.form.channel = v; }, style: 'width:100%' }, function () {
                    return [h(ElementPlus.ElOption, { label: '正式 stable', value: 'stable' }), h(ElementPlus.ElOption, { label: '测试 beta', value: 'beta' })];
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '状态' }, function () {
                  return h(ElementPlus.ElSelect, { modelValue: self.form.status, 'onUpdate:modelValue': function (v) { self.form.status = v; }, style: 'width:100%' }, function () {
                    return [h(ElementPlus.ElOption, { label: '草稿', value: 'draft' }), h(ElementPlus.ElOption, { label: '已发布', value: 'published' }), h(ElementPlus.ElOption, { label: '已归档', value: 'archived' })];
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '下载链接' }, function () {
                  return h(ElementPlus.ElInput, {
                    modelValue: self.form.downloadUrl,
                    'onUpdate:modelValue': function (v) { self.form.downloadUrl = v; },
                    placeholder: 'https://… 包直链（可不上传文件）'
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '说明' }, function () {
                  return h(ElementPlus.ElInput, { type: 'textarea', rows: 3, modelValue: self.form.description, 'onUpdate:modelValue': function (v) { self.form.description = v; } });
                })
              ];
            });
          },
          footer: function () {
            return [
              h(ElementPlus.ElButton, { onClick: function () { self.dialog = false; } }, function () { return '取消'; }),
              h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.save(); } }, function () { return '保存'; })
            ];
          }
        })
      ]);
    }
  };

  var HelpCategoriesView = {
    name: 'HelpCategoriesView',
    data: function () {
      return { loading: false, rows: [], dialog: false, editing: null, form: { name: '', slug: '', sortOrder: 0, visible: true } };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var data = await AdminAPI.categories();
          this.rows = Array.isArray(data) ? data : [];
        } catch (e) { ElMessage.error(e.message); }
        finally { this.loading = false; }
      },
      openCreate: function () {
        this.editing = null;
        this.form = { name: '', slug: '', sortOrder: 0, visible: true };
        this.dialog = true;
      },
      openEdit: function (row) {
        this.editing = row;
        this.form = { name: row.name || '', slug: row.slug || '', sortOrder: row.sortOrder || 0, visible: row.visible !== false };
        this.dialog = true;
      },
      save: async function () {
        try {
          if (this.editing) await AdminAPI.updateCategory(this.editing.id, this.form);
          else await AdminAPI.createCategory(this.form);
          ElMessage.success('分类已保存');
          this.dialog = false;
          this.load();
        } catch (e) { ElMessage.error(e.message); }
      },
      remove: async function (row) {
        try {
          await ElMessageBox.confirm('确定删除分类「' + row.name + '」吗？', '确认', { type: 'warning' });
          await AdminAPI.deleteCategory(row.id);
          ElMessage.success('已删除');
          this.load();
        } catch (e) { if (e !== 'cancel') ElMessage.error(e.message || String(e)); }
      }
    },
    render: function () {
      var self = this;
      return h('div', [
        pageHead('帮助分类', '管理客户端帮助文档左侧分类', [
          h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.openCreate(); } }, function () { return '新建分类'; })
        ]),
        h('div', { class: 'panel' }, [
          h(ElementPlus.ElTable, { data: self.rows, stripe: true, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 70 }),
              h(ElementPlus.ElTableColumn, { prop: 'name', label: '名称', minWidth: 140 }),
              h(ElementPlus.ElTableColumn, { prop: 'slug', label: 'Slug', minWidth: 120 }),
              h(ElementPlus.ElTableColumn, { prop: 'sortOrder', label: '排序', width: 80 }),
              h(ElementPlus.ElTableColumn, { label: '可见', width: 90 }, {
                default: function (scope) {
                  return h(ElementPlus.ElTag, { type: scope.row.visible ? 'success' : 'info', size: 'small' }, function () {
                    return scope.row.visible ? '显示' : '隐藏';
                  });
                }
              }),
              h(ElementPlus.ElTableColumn, { label: '操作', width: 160 }, {
                default: function (scope) {
                  return [
                    h(ElementPlus.ElButton, { size: 'small', link: true, onClick: function () { self.openEdit(scope.row); } }, function () { return '编辑'; }),
                    h(ElementPlus.ElButton, { size: 'small', link: true, type: 'danger', onClick: function () { self.remove(scope.row); } }, function () { return '删除'; })
                  ];
                }
              })
            ];
          })
        ]),
        h(ElementPlus.ElDialog, {
          modelValue: self.dialog,
          'onUpdate:modelValue': function (v) { self.dialog = v; },
          title: self.editing ? '编辑分类' : '新建分类',
          width: '480px'
        }, {
          default: function () {
            return h(ElementPlus.ElForm, { labelWidth: '80px' }, function () {
              return [
                h(ElementPlus.ElFormItem, { label: '名称' }, function () {
                  return h(ElementPlus.ElInput, { modelValue: self.form.name, 'onUpdate:modelValue': function (v) { self.form.name = v; } });
                }),
                h(ElementPlus.ElFormItem, { label: 'Slug' }, function () {
                  return h(ElementPlus.ElInput, { modelValue: self.form.slug, 'onUpdate:modelValue': function (v) { self.form.slug = v; } });
                }),
                h(ElementPlus.ElFormItem, { label: '排序' }, function () {
                  return h(ElementPlus.ElInputNumber, { modelValue: self.form.sortOrder, 'onUpdate:modelValue': function (v) { self.form.sortOrder = v; }, min: 0 });
                }),
                h(ElementPlus.ElFormItem, { label: '可见' }, function () {
                  return h(ElementPlus.ElSwitch, { modelValue: self.form.visible, 'onUpdate:modelValue': function (v) { self.form.visible = v; } });
                })
              ];
            });
          },
          footer: function () {
            return [
              h(ElementPlus.ElButton, { onClick: function () { self.dialog = false; } }, function () { return '取消'; }),
              h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.save(); } }, function () { return '保存'; })
            ];
          }
        })
      ]);
    }
  };

  var HelpDocsView = {
    name: 'HelpDocsView',
    data: function () {
      return {
        loading: false,
        rows: [],
        cats: [],
        categoryId: '',
        dialog: false,
        editing: null,
        form: { title: '', categoryId: 0, summary: '', content: '', status: 'draft', sortOrder: 0 }
      };
    },
    mounted: function () { this.bootstrap(); },
    methods: {
      bootstrap: async function () {
        try { this.cats = await AdminAPI.categories() || []; } catch (e) { this.cats = []; }
        this.load();
      },
      load: async function () {
        this.loading = true;
        try {
          var q = this.categoryId ? ('?category_id=' + encodeURIComponent(this.categoryId)) : '';
          var data = await AdminAPI.documents(q);
          this.rows = Array.isArray(data) ? data : [];
        } catch (e) { ElMessage.error(e.message); }
        finally { this.loading = false; }
      },
      openCreate: function () {
        this.editing = null;
        this.form = { title: '', categoryId: Number(this.categoryId || 0), summary: '', content: '', status: 'draft', sortOrder: 0 };
        this.dialog = true;
      },
      openEdit: function (row) {
        this.editing = row;
        this.form = {
          title: row.title || '',
          categoryId: row.categoryId || 0,
          summary: row.summary || '',
          content: row.content || '',
          status: row.status || 'draft',
          sortOrder: row.sortOrder || 0
        };
        this.dialog = true;
      },
      save: async function () {
        try {
          var body = Object.assign({}, this.form, { categoryId: Number(this.form.categoryId || 0) });
          if (this.editing) await AdminAPI.updateDocument(this.editing.id, body);
          else await AdminAPI.createDocument(body);
          ElMessage.success('文档已保存');
          this.dialog = false;
          this.load();
        } catch (e) { ElMessage.error(e.message); }
      },
      remove: async function (row) {
        try {
          await ElMessageBox.confirm('确定删除文档「' + row.title + '」吗？', '确认', { type: 'warning' });
          await AdminAPI.deleteDocument(row.id);
          ElMessage.success('已删除');
          this.load();
        } catch (e) { if (e !== 'cancel') ElMessage.error(e.message || String(e)); }
      }
    },
    render: function () {
      var self = this;
      return h('div', [
        pageHead('帮助文档', '编写并发布给客户端展示的帮助内容', [
          h(ElementPlus.ElSelect, {
            modelValue: self.categoryId,
            'onUpdate:modelValue': function (v) { self.categoryId = v; self.load(); },
            clearable: true,
            placeholder: '全部分类',
            style: 'width:160px'
          }, function () {
            return (self.cats || []).map(function (c) {
              return h(ElementPlus.ElOption, { label: c.name, value: String(c.id) });
            });
          }),
          h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.openCreate(); } }, function () { return '新建文档'; })
        ]),
        h('div', { class: 'panel' }, [
          h(ElementPlus.ElTable, { data: self.rows, stripe: true, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 70 }),
              h(ElementPlus.ElTableColumn, { prop: 'title', label: '标题', minWidth: 180 }),
              h(ElementPlus.ElTableColumn, { prop: 'categoryName', label: '分类', width: 120 }),
              h(ElementPlus.ElTableColumn, { label: '状态', width: 100 }, {
                default: function (scope) { return statusTag(scope.row.status); }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'viewCount', label: '阅读', width: 80 }),
              h(ElementPlus.ElTableColumn, { label: '操作', width: 160 }, {
                default: function (scope) {
                  return [
                    h(ElementPlus.ElButton, { size: 'small', link: true, onClick: function () { self.openEdit(scope.row); } }, function () { return '编辑'; }),
                    h(ElementPlus.ElButton, { size: 'small', link: true, type: 'danger', onClick: function () { self.remove(scope.row); } }, function () { return '删除'; })
                  ];
                }
              })
            ];
          })
        ]),
        h(ElementPlus.ElDialog, {
          modelValue: self.dialog,
          'onUpdate:modelValue': function (v) { self.dialog = v; },
          title: self.editing ? '编辑文档' : '新建文档',
          width: '720px'
        }, {
          default: function () {
            return h(ElementPlus.ElForm, { labelWidth: '80px' }, function () {
              return [
                h(ElementPlus.ElFormItem, { label: '标题' }, function () {
                  return h(ElementPlus.ElInput, { modelValue: self.form.title, 'onUpdate:modelValue': function (v) { self.form.title = v; } });
                }),
                h(ElementPlus.ElFormItem, { label: '分类' }, function () {
                  return h(ElementPlus.ElSelect, {
                    modelValue: String(self.form.categoryId || 0),
                    'onUpdate:modelValue': function (v) { self.form.categoryId = Number(v); },
                    style: 'width:100%'
                  }, function () {
                    return [h(ElementPlus.ElOption, { label: '未分类', value: '0' })].concat(
                      (self.cats || []).map(function (c) {
                        return h(ElementPlus.ElOption, { label: c.name, value: String(c.id) });
                      })
                    );
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '摘要' }, function () {
                  return h(ElementPlus.ElInput, { type: 'textarea', rows: 2, modelValue: self.form.summary, 'onUpdate:modelValue': function (v) { self.form.summary = v; } });
                }),
                h(ElementPlus.ElFormItem, { label: '正文' }, function () {
                  return h(ElementPlus.ElInput, { type: 'textarea', rows: 10, modelValue: self.form.content, 'onUpdate:modelValue': function (v) { self.form.content = v; }, placeholder: '支持 Markdown' });
                }),
                h(ElementPlus.ElFormItem, { label: '状态' }, function () {
                  return h(ElementPlus.ElSelect, { modelValue: self.form.status, 'onUpdate:modelValue': function (v) { self.form.status = v; }, style: 'width:100%' }, function () {
                    return [h(ElementPlus.ElOption, { label: '草稿', value: 'draft' }), h(ElementPlus.ElOption, { label: '已发布', value: 'published' })];
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '排序' }, function () {
                  return h(ElementPlus.ElInputNumber, { modelValue: self.form.sortOrder, 'onUpdate:modelValue': function (v) { self.form.sortOrder = v; }, min: 0 });
                })
              ];
            });
          },
          footer: function () {
            return [
              h(ElementPlus.ElButton, { onClick: function () { self.dialog = false; } }, function () { return '取消'; }),
              h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.save(); } }, function () { return '保存' })
            ];
          }
        })
      ]);
    }
  };

  var EnvInstallersView = {
    name: 'EnvInstallersView',
    data: function () {
      return {
        loading: false,
        rows: [],
        kind: '',
        platform: '',
        dialog: false,
        editing: null,
        form: {
          kind: 'node', platform: 'win', version: '', title: '',
          downloadUrl: '', status: 'draft', isRecommended: false, sortOrder: 0, notes: ''
        }
      };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var qs = [];
          if (this.kind) qs.push('kind=' + encodeURIComponent(this.kind));
          if (this.platform) qs.push('platform=' + encodeURIComponent(this.platform));
          var q = qs.length ? ('?' + qs.join('&')) : '';
          var data = await AdminAPI.envInstallers(q);
          this.rows = Array.isArray(data) ? data : [];
        } catch (e) { ElMessage.error(e.message); }
        finally { this.loading = false; }
      },
      openCreate: function () {
        this.editing = null;
        this.form = {
          kind: this.kind || 'node',
          platform: this.platform || 'win',
          version: '', title: '', downloadUrl: '', status: 'draft',
          isRecommended: false, sortOrder: 0, notes: ''
        };
        this.dialog = true;
      },
      openEdit: function (row) {
        this.editing = row;
        this.form = {
          kind: row.kind || 'node',
          platform: row.platform || 'win',
          version: row.version || '',
          title: row.title || '',
          downloadUrl: row.downloadUrlRaw || '',
          status: row.status || 'draft',
          isRecommended: !!row.isRecommended,
          sortOrder: row.sortOrder || 0,
          notes: row.notes || ''
        };
        this.dialog = true;
      },
      save: async function () {
        try {
          if (this.editing) await AdminAPI.updateEnvInstaller(this.editing.id, this.form);
          else await AdminAPI.createEnvInstaller(this.form);
          ElMessage.success('已保存');
          this.dialog = false;
          this.load();
        } catch (e) { ElMessage.error(e.message); }
      },
      upload: function (row) {
        var self = this;
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = row.kind === 'node'
          ? (row.platform === 'win' ? '.msi,.exe' : '.pkg,.dmg')
          : (row.platform === 'win' ? '.exe,.msi,.zip' : '.dmg,.pkg,.zip');
        input.onchange = async function () {
          var file = input.files && input.files[0];
          if (!file) return;
          try {
            ElMessage.info('正在上传…');
            await AdminAPI.uploadEnvInstaller(row.id, file);
            ElMessage.success('安装包已上传');
            self.load();
          } catch (e) { ElMessage.error(e.message); }
        };
        input.click();
      },
      publish: async function (row) {
        try {
          await AdminAPI.publishEnvInstaller(row.id);
          ElMessage.success('已发布');
          this.load();
        } catch (e) { ElMessage.error(e.message); }
      },
      recommend: async function (row) {
        try {
          await AdminAPI.recommendEnvInstaller(row.id);
          ElMessage.success('已设为推荐');
          this.load();
        } catch (e) { ElMessage.error(e.message); }
      },
      remove: async function (row) {
        try {
          await ElMessageBox.confirm('确定删除「' + row.kind + '/' + row.platform + ' ' + row.version + '」吗？', '确认', { type: 'warning' });
          await AdminAPI.deleteEnvInstaller(row.id);
          ElMessage.success('已删除');
          this.load();
        } catch (e) { if (e !== 'cancel') ElMessage.error(e.message || String(e)); }
      }
    },
    render: function () {
      var self = this;
      return h('div', [
        pageHead('环境安装包', '分别维护 Windows / macOS 的 Node.js 与比特浏览器安装包，供客户端直链下载安装', [
          h(ElementPlus.ElSelect, {
            modelValue: self.kind,
            'onUpdate:modelValue': function (v) { self.kind = v; self.load(); },
            clearable: true, placeholder: '全部类型', style: 'width:120px'
          }, function () {
            return [
              h(ElementPlus.ElOption, { label: 'Node.js', value: 'node' }),
              h(ElementPlus.ElOption, { label: '比特浏览器', value: 'bit' }),
              h(ElementPlus.ElOption, { label: '自研浏览器', value: 'self' })
            ];
          }),
          h(ElementPlus.ElSelect, {
            modelValue: self.platform,
            'onUpdate:modelValue': function (v) { self.platform = v; self.load(); },
            clearable: true, placeholder: '全部系统', style: 'width:120px'
          }, function () {
            return [
              h(ElementPlus.ElOption, { label: 'Windows', value: 'win' }),
              h(ElementPlus.ElOption, { label: 'macOS', value: 'mac' })
            ];
          }),
          h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.openCreate(); } }, function () { return '新建'; })
        ]),
        h('div', { class: 'panel' }, [
          h(ElementPlus.ElTable, { data: self.rows, stripe: true, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 60 }),
              h(ElementPlus.ElTableColumn, { prop: 'kind', label: '类型', width: 90 }, {
                default: function (scope) {
                  if (scope.row.kind === 'node') return 'Node.js';
                  if (scope.row.kind === 'self' || scope.row.kind === 'tkswarm') return '自研';
                  return '比特';
                }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'platform', label: '系统', width: 90 }, {
                default: function (scope) {
                  return scope.row.platform === 'mac' ? 'macOS' : 'Windows';
                }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'version', label: '版本', width: 110 }),
              h(ElementPlus.ElTableColumn, { prop: 'title', label: '标题', minWidth: 160 }),
              h(ElementPlus.ElTableColumn, { label: '包/链', width: 100 }, {
                default: function (scope) {
                  if (scope.row.hasFile) return h(ElementPlus.ElTag, { type: 'success', size: 'small' }, function () { return '已上传'; });
                  if (scope.row.hasExternalUrl) return h(ElementPlus.ElTag, { type: '', size: 'small' }, function () { return '外链'; });
                  return h('span', { class: 'text-slate-400 text-xs' }, '未配置');
                }
              }),
              h(ElementPlus.ElTableColumn, { label: '状态', width: 100 }, {
                default: function (scope) { return statusTag(scope.row.status); }
              }),
              h(ElementPlus.ElTableColumn, { label: '推荐', width: 70 }, {
                default: function (scope) {
                  return scope.row.isRecommended
                    ? h(ElementPlus.ElTag, { type: 'warning', size: 'small' }, function () { return '是'; })
                    : h('span', { class: 'text-slate-300' }, '-');
                }
              }),
              h(ElementPlus.ElTableColumn, { label: '操作', width: 300, fixed: 'right' }, {
                default: function (scope) {
                  var row = scope.row;
                  return h('div', { class: 'flex flex-wrap gap-1' }, [
                    h(ElementPlus.ElButton, { size: 'small', link: true, onClick: function () { self.openEdit(row); } }, function () { return '编辑'; }),
                    h(ElementPlus.ElButton, { size: 'small', link: true, onClick: function () { self.upload(row); } }, function () { return '上传包'; }),
                    row.status !== 'published'
                      ? h(ElementPlus.ElButton, { size: 'small', link: true, type: 'primary', onClick: function () { self.publish(row); } }, function () { return '发布'; })
                      : h(ElementPlus.ElButton, { size: 'small', link: true, onClick: function () { self.recommend(row); } }, function () { return '设推荐'; }),
                    h(ElementPlus.ElButton, { size: 'small', link: true, type: 'danger', onClick: function () { self.remove(row); } }, function () { return '删除'; })
                  ]);
                }
              })
            ];
          })
        ]),
        h(ElementPlus.ElDialog, {
          modelValue: self.dialog,
          'onUpdate:modelValue': function (v) { self.dialog = v; },
          title: self.editing ? '编辑安装包' : '新建安装包',
          width: '600px'
        }, {
          default: function () {
            return h(ElementPlus.ElForm, { labelWidth: '90px' }, function () {
              return [
                h(ElementPlus.ElFormItem, { label: '类型' }, function () {
                  return h(ElementPlus.ElSelect, { modelValue: self.form.kind, 'onUpdate:modelValue': function (v) { self.form.kind = v; }, style: 'width:100%' }, function () {
                    return [
                      h(ElementPlus.ElOption, { label: 'Node.js', value: 'node' }),
                      h(ElementPlus.ElOption, { label: '比特浏览器', value: 'bit' }),
                      h(ElementPlus.ElOption, { label: '自研浏览器', value: 'self' })
                    ];
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '系统' }, function () {
                  return h(ElementPlus.ElSelect, { modelValue: self.form.platform, 'onUpdate:modelValue': function (v) { self.form.platform = v; }, style: 'width:100%' }, function () {
                    return [h(ElementPlus.ElOption, { label: 'Windows', value: 'win' }), h(ElementPlus.ElOption, { label: 'macOS', value: 'mac' })];
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '版本号' }, function () {
                  return h(ElementPlus.ElInput, { modelValue: self.form.version, 'onUpdate:modelValue': function (v) { self.form.version = v; }, placeholder: '如 22.14.0' });
                }),
                h(ElementPlus.ElFormItem, { label: '标题' }, function () {
                  return h(ElementPlus.ElInput, { modelValue: self.form.title, 'onUpdate:modelValue': function (v) { self.form.title = v; } });
                }),
                h(ElementPlus.ElFormItem, { label: '下载直链' }, function () {
                  return h(ElementPlus.ElInput, {
                    modelValue: self.form.downloadUrl,
                    'onUpdate:modelValue': function (v) { self.form.downloadUrl = v; },
                    placeholder: 'https://... （也可不填，改为上传安装包）'
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '状态' }, function () {
                  return h(ElementPlus.ElSelect, { modelValue: self.form.status, 'onUpdate:modelValue': function (v) { self.form.status = v; }, style: 'width:100%' }, function () {
                    return [h(ElementPlus.ElOption, { label: '草稿', value: 'draft' }), h(ElementPlus.ElOption, { label: '已发布', value: 'published' })];
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '推荐' }, function () {
                  return h(ElementPlus.ElSwitch, { modelValue: self.form.isRecommended, 'onUpdate:modelValue': function (v) { self.form.isRecommended = v; } });
                }),
                h(ElementPlus.ElFormItem, { label: '排序' }, function () {
                  return h(ElementPlus.ElInputNumber, { modelValue: self.form.sortOrder, 'onUpdate:modelValue': function (v) { self.form.sortOrder = v; }, min: 0 });
                }),
                h(ElementPlus.ElFormItem, { label: '备注' }, function () {
                  return h(ElementPlus.ElInput, { type: 'textarea', rows: 2, modelValue: self.form.notes, 'onUpdate:modelValue': function (v) { self.form.notes = v; } });
                })
              ];
            });
          },
          footer: function () {
            return [
              h(ElementPlus.ElButton, { onClick: function () { self.dialog = false; } }, function () { return '取消'; }),
              h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.save(); } }, function () { return '保存'; })
            ];
          }
        })
      ]);
    }
  };

  /**
   * 任务数据运维入口（对接 public/reset-work-pending.php、clear-work-data.php）
   */
  function makeTaskToolView(cfg) {
    return {
      name: cfg.name,
      methods: {
        openPreview: function () {
          window.open(cfg.toolUrl, '_blank', 'noopener');
        },
        runConfirm: async function () {
          try {
            await ElMessageBox.confirm(cfg.confirmText, cfg.confirmTitle, {
              type: cfg.danger ? 'error' : 'warning',
              confirmButtonText: cfg.confirmButton || '确认执行',
              cancelButtonText: '取消',
              distinguishCancelAndClose: true
            });
          } catch (e) {
            return;
          }
          window.location.href = cfg.toolUrl + (cfg.toolUrl.indexOf('?') >= 0 ? '&' : '?') + 'confirm=1';
        }
      },
      render: function () {
        var self = this;
        return h('div', [
          pageHead(cfg.title, cfg.subtitle),
          h('div', { class: 'panel', style: 'max-width:720px' }, [
            h(ElementPlus.ElAlert, {
              type: cfg.danger ? 'error' : 'warning',
              showIcon: true,
              closable: false,
              title: cfg.alertTitle,
              description: cfg.alertDesc,
              style: 'margin-bottom:18px'
            }),
            h('ul', { class: 'text-sm text-slate-600', style: 'margin:0 0 18px;padding-left:18px;line-height:1.7' },
              (cfg.bullets || []).map(function (t) { return h('li', t); })
            ),
            h('div', { class: 'flex items-center gap-3 flex-wrap' }, [
              h(ElementPlus.ElButton, {
                type: cfg.danger ? 'danger' : 'warning',
                onClick: function () { self.runConfirm(); }
              }, function () { return cfg.confirmButton || '确认执行'; }),
              h(ElementPlus.ElButton, {
                onClick: function () { self.openPreview(); }
              }, function () { return '先打开工具页查看统计'; })
            ]),
            h('p', { class: 'text-xs text-slate-400 mt-4' }, '工具地址：' + cfg.toolUrl)
          ])
        ]);
      }
    };
  }

  var TaskResetView = makeTaskToolView({
    name: 'TaskResetView',
    title: '重置任务数据',
    subtitle: '将全部账号会话总任务 / 子任务改回「待执行」，便于重新领取执行',
    danger: false,
    alertTitle: '会改写执行状态，不会删除记录',
    alertDesc: '总任务与全部子任务 → pending；清锁、推送态、结束时间；关联 tasks 回到 queued。软删除记录也会被拉回。',
    bullets: [
      '适用：卡住、失败后需要整库重新跑一轮',
      '保留任务与子任务行数据，仅重置状态与计数',
      '执行后请确保本机 Node 执行器已开启'
    ],
    confirmTitle: '确认重置全部任务？',
    confirmText: '将把全部总任务、子任务状态改回待执行。此操作影响全库任务队列，是否继续？',
    confirmButton: '确认重置为待执行',
    toolUrl: '/reset-work-pending.php'
  });

  var TaskClearView = makeTaskToolView({
    name: 'TaskClearView',
    title: '清除任务数据',
    subtitle: '永久清空各类任务及衍生关联表（TRUNCATE，不可逆）',
    danger: true,
    alertTitle: '危险操作：数据将被永久删除',
    alertDesc: '将清空会话总任务、计划任务、增粉/拉评/AI拓客衍生、plan_tasks 等，并重置自增 ID。账号、素材、代理主数据不受影响。',
    bullets: [
      '适用：测试环境洗库、或正式环境确认要丢掉全部任务历史',
      '清空：account_work_*、tasks 系列、auto_follow_*、ai_lead*、pull_comment_*、plan_tasks 等',
      '执行前建议先备份数据库'
    ],
    confirmTitle: '确认永久删除全部任务数据？',
    confirmText: '将 TRUNCATE 全部任务及衍生关联表，不可撤销！是否继续？',
    confirmButton: '确认一键清空',
    toolUrl: '/clear-work-data.php'
  });

  /**
   * 快捷操作：汇总 public 下可浏览器直接访问的运维 PHP 工具
   */
  var QuickOpsView = {
    name: 'QuickOpsView',
    data: function () {
      return {
        tools: [
          {
            group: '任务队列',
            items: [
              {
                title: '清空计划任务数据',
                desc: 'TRUNCATE tasks/task_* + 会话总/子任务。解决「任务汇总清了，开关一开又冒出私信同步/发布」——源头是仍 queued 的计划任务，不是 Redis。',
                url: '/clear-plan-tasks-data.php',
                danger: true,
                confirm: true,
                confirmText: '确认永久清空计划任务与会话总/子任务？不可撤销！'
              },
              {
                title: '清空全部任务数据',
                desc: 'TRUNCATE 会话总任务、计划任务、增粉/拉评/AI拓客衍生、plan_tasks 等（比「清空计划任务」更全）。不可逆。',
                url: '/clear-work-data.php',
                danger: true,
                confirm: true,
                confirmText: '确认永久清空全部任务及衍生数据？不可撤销！'
              },
              {
                title: '重置任务为待执行',
                desc: '不删记录，把总任务/子任务改回 pending，清锁后可重新领取。',
                url: '/reset-work-pending.php',
                danger: false,
                confirm: true,
                confirmText: '确认将全部任务状态重置为待执行？'
              },
              {
                title: '终止 AI 拓客私信派发',
                desc: '关闭全部任务私信派发开关，并取消队列中 pending/running 的 ai_lead_dm。清空任务数据后若仍在派发，用本工具止血。',
                url: '/stop-ai-lead-dm-dispatch.php',
                danger: true,
                confirm: true,
                confirmText: '确认立即终止全部 AI 拓客私信派发？'
              },
              {
                title: '终止增粉间断关注派发',
                desc: '关闭全部增粉任务自动化开关（dispatch_enabled），并取消队列中 pending/running 的 auto_follow。',
                url: '/stop-auto-follow-dispatch.php',
                danger: true,
                confirm: true,
                confirmText: '确认立即终止全部增粉间断关注派发？'
              },
              {
                title: '终止养号常态化派发',
                desc: '关闭全部养号任务常态化派发开关（recurring_dispatch_enabled），并取消队列中 pending/running 的 warm。',
                url: '/stop-warm-recurring-dispatch.php',
                danger: true,
                confirm: true,
                confirmText: '确认立即终止全部养号常态化派发？将同时取消队列中的 warm 子任务。'
              }
            ]
          },
          {
            group: '浏览器环境',
            items: [
              {
                title: '环境归属 / 绑定维护',
                desc: '预览统计；可重置 owner、同步归属、回填绑定、清空全部环境绑定。',
                url: '/clear-browser-env-owner.php',
                danger: false,
                confirm: false
              }
            ]
          },
          {
            group: '代理 / 行业',
            items: [
              {
                title: '解绑全部账号代理',
                desc: 'accounts.proxy_id 置空，不删代理与账号。',
                url: '/unbind-proxies.php',
                danger: true,
                confirm: true,
                confirmText: '确认解除全部账号与代理的绑定？'
              },
              {
                title: '识别代理国家',
                desc: '按代理 host IP 写入 proxies.country / region / city（预览后确认）。',
                url: '/detect-proxy-countries.php',
                danger: false,
                confirm: false
              },
              {
                title: '行业数据管理',
                desc: 'industry_data 树形编辑：展开、改名、排序、增删节点。',
                url: '/industry-admin.php',
                danger: false,
                confirm: false
              }
            ]
          }
        ]
      };
    },
    methods: {
      openTool: function (item, withConfirm) {
        var url = item.url;
        if (withConfirm) {
          url += (url.indexOf('?') >= 0 ? '&' : '?') + 'confirm=1';
        }
        window.open(url, '_blank', 'noopener');
      },
      runDanger: async function (item) {
        try {
          await ElMessageBox.confirm(
            item.confirmText || ('确认执行：' + item.title + '？'),
            item.danger ? '危险操作确认' : '操作确认',
            {
              type: item.danger ? 'error' : 'warning',
              confirmButtonText: '打开并确认执行',
              cancelButtonText: '取消'
            }
          );
        } catch (e) {
          return;
        }
        this.openTool(item, true);
      }
    },
    render: function () {
      var self = this;
      return h('div', { class: 'page' }, [
        pageHead(
          '快捷操作',
          '浏览器可直接访问的运维工具入口。危险项会先二次确认，再打开对应 PHP 页执行。'
        ),
        h(ElementPlus.ElAlert, {
          type: 'warning',
          showIcon: true,
          closable: false,
          title: '生产环境请谨慎',
          description: '清空/解绑类操作不可逆或影响全库；建议先点「预览统计」，确认后再执行。',
          style: 'margin-bottom:16px'
        }),
        self.tools.map(function (group) {
          return h('div', {
            class: 'panel',
            style: 'margin-bottom:16px'
          }, [
            panelHead(group.group),
            h('div', {
              style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;padding:4px 0 8px'
            }, group.items.map(function (item) {
              return h('div', {
                style: 'border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;background:#fff'
              }, [
                h('div', {
                  style: 'display:flex;align-items:center;gap:8px;margin-bottom:6px'
                }, [
                  h('b', { style: 'font-size:14px' }, item.title),
                  item.danger
                    ? h(ElementPlus.ElTag, { type: 'danger', size: 'small', effect: 'plain' }, function () { return '危险'; })
                    : null
                ].filter(Boolean)),
                h('p', {
                  class: 'text-sm text-slate-500',
                  style: 'margin:0 0 12px;line-height:1.55;min-height:44px'
                }, item.desc),
                h('div', { class: 'flex items-center gap-2 flex-wrap' }, [
                  h(ElementPlus.ElButton, {
                    size: 'small',
                    onClick: function () { self.openTool(item, false); }
                  }, function () { return item.confirm ? '预览统计' : '打开工具'; }),
                  item.confirm
                    ? h(ElementPlus.ElButton, {
                      size: 'small',
                      type: item.danger ? 'danger' : 'warning',
                      onClick: function () { self.runDanger(item); }
                    }, function () { return '确认执行'; })
                    : null
                ].filter(Boolean)),
                h('p', {
                  class: 'text-xs text-slate-400',
                  style: 'margin:10px 0 0'
                }, item.url)
              ]);
            }))
          ]);
        })
      ]);
    }
  };

  var ContactInquiriesView = {
    name: 'ContactInquiriesView',
    data: function () {
      return {
        loading: false,
        rows: [],
        status: '',
        keyword: '',
        dialog: false,
        current: null,
        form: { status: 'new', remark: '' }
      };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var qs = [];
          if (this.status) qs.push('status=' + encodeURIComponent(this.status));
          if (this.keyword) qs.push('keyword=' + encodeURIComponent(this.keyword));
          var q = qs.length ? ('?' + qs.join('&')) : '';
          var data = await AdminAPI.contactInquiries(q);
          this.rows = Array.isArray(data) ? data : [];
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.loading = false;
        }
      },
      open: async function (row) {
        try {
          var d = await AdminAPI.contactInquiry(row.id);
          this.current = d;
          this.form = { status: d.status || 'new', remark: d.remark || '' };
          this.dialog = true;
        } catch (e) {
          ElMessage.error(e.message);
        }
      },
      save: async function () {
        try {
          await AdminAPI.updateContactInquiry(this.current.id, this.form);
          ElMessage.success('已更新');
          this.dialog = false;
          this.load();
        } catch (e) {
          ElMessage.error(e.message);
        }
      },
      remove: async function (row) {
        try {
          await ElMessageBox.confirm('确定删除咨询 #' + row.id + ' 吗？', '确认', { type: 'warning' });
          await AdminAPI.deleteContactInquiry(row.id);
          ElMessage.success('已删除');
          this.load();
        } catch (e) {
          if (e !== 'cancel') ElMessage.error(e.message || String(e));
        }
      }
    },
    render: function () {
      var self = this;
      return h('div', [
        pageHead('官网咨询', '落地页「联系我们」表单提交的留资', [
          h(ElementPlus.ElInput, {
            modelValue: self.keyword,
            'onUpdate:modelValue': function (v) { self.keyword = v; },
            placeholder: '姓名/手机/公司',
            clearable: true,
            style: 'width:180px',
            onKeyup: function (e) { if (e.key === 'Enter') self.load(); }
          }),
          h(ElementPlus.ElSelect, {
            modelValue: self.status,
            'onUpdate:modelValue': function (v) { self.status = v; self.load(); },
            clearable: true,
            placeholder: '全部状态',
            style: 'width:140px'
          }, function () {
            return [
              h(ElementPlus.ElOption, { label: '新咨询', value: 'new' }),
              h(ElementPlus.ElOption, { label: '跟进中', value: 'processing' }),
              h(ElementPlus.ElOption, { label: '已完成', value: 'done' }),
              h(ElementPlus.ElOption, { label: '垃圾', value: 'spam' })
            ];
          }),
          h(ElementPlus.ElButton, { onClick: function () { self.load(); } }, function () { return '刷新'; })
        ]),
        h('div', { class: 'panel' }, [
          h(ElementPlus.ElTable, { data: self.rows, vLoading: self.loading, stripe: true, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 70 }),
              h(ElementPlus.ElTableColumn, { prop: 'name', label: '姓名', width: 100 }),
              h(ElementPlus.ElTableColumn, { prop: 'phone', label: '手机/微信', width: 130 }),
              h(ElementPlus.ElTableColumn, { prop: 'company', label: '公司', minWidth: 120 }),
              h(ElementPlus.ElTableColumn, { prop: 'accountScale', label: '规模', width: 100 }),
              h(ElementPlus.ElTableColumn, { label: '状态', width: 100 }, {
                default: function (scope) { return statusTag(scope.row.status); }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'createdAt', label: '提交时间', width: 170 }),
              h(ElementPlus.ElTableColumn, { label: '操作', width: 160, fixed: 'right' }, {
                default: function (scope) {
                  return [
                    h(ElementPlus.ElButton, { size: 'small', type: 'primary', link: true, onClick: function () { self.open(scope.row); } }, function () { return '处理'; }),
                    h(ElementPlus.ElButton, { size: 'small', type: 'danger', link: true, onClick: function () { self.remove(scope.row); } }, function () { return '删除'; })
                  ];
                }
              })
            ];
          })
        ]),
        h(ElementPlus.ElDialog, {
          modelValue: self.dialog,
          'onUpdate:modelValue': function (v) { self.dialog = v; },
          title: self.current ? ('咨询 #' + self.current.id) : '咨询详情',
          width: '640px'
        }, {
          default: function () {
            if (!self.current) return null;
            var c = self.current;
            return h('div', { class: 'space-y-3' }, [
              h('div', { class: 'text-sm text-slate-600' }, [
                h('div', '姓名：' + (c.name || '-')),
                h('div', '手机/微信：' + (c.phone || '-')),
                h('div', '邮箱：' + (c.email || '-')),
                h('div', '公司：' + (c.company || '-')),
                h('div', '账号规模：' + (c.accountScale || '-')),
                h('div', 'IP：' + (c.ip || '-'))
              ]),
              h('div', {
                class: 'text-sm whitespace-pre-wrap bg-slate-50 rounded-lg p-3 border border-slate-100'
              }, c.message || ''),
              h(ElementPlus.ElForm, { labelWidth: '80px' }, function () {
                return [
                  h(ElementPlus.ElFormItem, { label: '状态' }, function () {
                    return h(ElementPlus.ElSelect, {
                      modelValue: self.form.status,
                      'onUpdate:modelValue': function (v) { self.form.status = v; },
                      style: 'width:100%'
                    }, function () {
                      return [
                        h(ElementPlus.ElOption, { label: '新咨询', value: 'new' }),
                        h(ElementPlus.ElOption, { label: '跟进中', value: 'processing' }),
                        h(ElementPlus.ElOption, { label: '已完成', value: 'done' }),
                        h(ElementPlus.ElOption, { label: '垃圾', value: 'spam' })
                      ];
                    });
                  }),
                  h(ElementPlus.ElFormItem, { label: '备注' }, function () {
                    return h(ElementPlus.ElInput, {
                      type: 'textarea',
                      rows: 4,
                      modelValue: self.form.remark,
                      'onUpdate:modelValue': function (v) { self.form.remark = v; },
                      placeholder: '跟进记录'
                    });
                  })
                ];
              })
            ]);
          },
          footer: function () {
            return [
              h(ElementPlus.ElButton, { onClick: function () { self.dialog = false; } }, function () { return '取消'; }),
              h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.save(); } }, function () { return '保存'; })
            ];
          }
        })
      ]);
    }
  };

  var SiteSettingsView = {
    name: 'SiteSettingsView',
    data: function () {
      return {
        loading: false,
        saving: false,
        form: {
          landingDownloadWinUrl: '/lastest.exe',
          landingDownloadMacUrl: '/lastest.dmg'
        }
      };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var data = await AdminAPI.siteSettings();
          this.form = {
            landingDownloadWinUrl: data.landingDownloadWinUrl || '/lastest.exe',
            landingDownloadMacUrl: data.landingDownloadMacUrl || '/lastest.dmg'
          };
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.loading = false;
        }
      },
      save: async function () {
        this.saving = true;
        try {
          await AdminAPI.saveSiteSettings(this.form);
          ElMessage.success('官网下载链接已保存');
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          this.saving = false;
        }
      }
    },
    render: function () {
      var self = this;
      return h('div', { class: 'page' }, [
        pageHead('官网设置', '配置首页与轮播区的 Windows / macOS 客户端下载链接。支持站内路径（如 /lastest.exe）或完整外链。'),
        h(ElementPlus.ElCard, { shadow: 'never', vLoading: self.loading }, {
          default: function () {
            return h(ElementPlus.ElForm, { labelWidth: '140px', style: 'max-width:720px' }, function () {
              return [
                h(ElementPlus.ElFormItem, { label: 'Windows 下载' }, function () {
                  return h(ElementPlus.ElInput, {
                    modelValue: self.form.landingDownloadWinUrl,
                    'onUpdate:modelValue': function (v) { self.form.landingDownloadWinUrl = v; },
                    placeholder: '例如 /lastest.exe 或 https://.../TkSwarmBrowser-1.0.19-win-portable.exe'
                  });
                }),
                h(ElementPlus.ElFormItem, { label: 'macOS 下载' }, function () {
                  return h(ElementPlus.ElInput, {
                    modelValue: self.form.landingDownloadMacUrl,
                    'onUpdate:modelValue': function (v) { self.form.landingDownloadMacUrl = v; },
                    placeholder: '例如 /lastest.dmg 或 https://.../TkSwarmBrowser-1.0.19-mac-arm64.dmg'
                  });
                }),
                h(ElementPlus.ElFormItem, null, function () {
                  return h(ElementPlus.ElButton, {
                    type: 'primary',
                    loading: self.saving,
                    onClick: function () { self.save(); }
                  }, function () { return '保存'; });
                })
              ];
            });
          }
        })
      ]);
    }
  };

  global.AdminViews = {
    LoginView: LoginView,
    DashboardView: DashboardView,
    TicketsView: TicketsView,
    ContactInquiriesView: ContactInquiriesView,
    SiteSettingsView: SiteSettingsView,
    VersionsView: VersionsView,
    HelpCategoriesView: HelpCategoriesView,
    HelpDocsView: HelpDocsView,
    EnvInstallersView: EnvInstallersView,
    TaskResetView: TaskResetView,
    TaskClearView: TaskClearView,
    QuickOpsView: QuickOpsView
  };
})(window);
