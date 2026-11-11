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
      return h('div', { class: 'auth-gate' }, [
        h('div', { class: 'auth-split' }, [
          h('section', { class: 'auth-hero' }, [
            h('div', { class: 'brand' }, [
              h('img', { class: 'brand-logo', src: '/images/logo.png?v=2', alt: 'TkSwarm', width: 168, height: 64 }),
              h('span', { class: 'brand-tag' }, '运营控制台')
            ]),
            h('h1', '扬帆出海 · 融通全球'),
            h('p', '跨洋巨轮破浪远航，TikTok 矩阵全域赋能。将全球多账号调度、跨境代理与 AI 创作装载入统一的航海旗舰中台。'),
            h('ul', [
              h('li', '工单协同与版本发布一站管理'),
              h('li', '帮助文档与运营资料统一沉淀'),
              h('li', '企业门户与后台权限联动闭环')
            ])
          ]),
          h('section', { class: 'auth-panel' }, [
            h('h2', '登录控制台'),
            h('p', { class: 'muted' }, '请使用管理员账号进入系统'),
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
                title: '终止私信同步派发',
                desc: '关闭 admin 计划任务 dm_sync + 全局 dmSyncDispatchEnabled，并取消 dm_sync 计划/会话任务。日常启停请优先用「计划任务」页开关。',
                url: '/stop-dm-sync-dispatch.php',
                danger: true,
                confirm: true,
                confirmText: '确认立即终止全部私信同步派发？'
              },
              {
                title: '清空计划任务数据',
                desc: 'TRUNCATE tasks/task_* + 会话总/子任务，并关闭 dm:sync 全局派发，避免清空后 cron 立刻重建私信同步。',
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
            group: '拓客线索',
            items: [
              {
                title: '清除线索企业用户分配',
                desc: '清空评论/用户线索抄送与任务共享表。只删分配关系，不删线索/评论/任务。',
                url: '/clear-ai-lead-clue-shares.php',
                danger: true,
                confirm: true,
                confirmText: '确认清除全部拓客线索的企业用户分配？不可撤销！'
              }
            ]
          },
          {
            group: '拉取视频列表',
            items: [
              {
                title: '清除拉取列表线上视频',
                desc: '删除 materials.origin=online、关联评论、tiktok_videos、详情截图。用于清理误拉的非本账号视频；可按 accountId 过滤。本地上传素材不受影响。',
                url: '/clear-pulled-online-videos.php',
                danger: true,
                confirm: false
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
              },
              {
                title: '环境包精简（原名覆盖）',
                desc: '分页列出中心库环境包；单包解压后按会话白名单去掉 IndexedDB/Cache 等，再打包覆盖原 {profileId}.tgz，并更新体积与 revision。',
                url: '/slim-browser-env-packs.php',
                danger: true,
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

  var ScheduledJobsView = {
    name: 'ScheduledJobsView',
    data: function () {
      return {
        loading: false,
        jobs: [],
        mountHint: '',
        note: '',
        groupFilter: '全部',
        keyword: '',
        toggling: {},
        running: {}
      };
    },
    computed: {
      groups: function () {
        var set = {};
        (this.jobs || []).forEach(function (j) {
          set[j.group || '其他'] = true;
        });
        return ['全部'].concat(Object.keys(set).sort());
      },
      filteredJobs: function () {
        var self = this;
        var kw = String(self.keyword || '').trim().toLowerCase();
        return (self.jobs || []).filter(function (j) {
          if (self.groupFilter && self.groupFilter !== '全部' && (j.group || '其他') !== self.groupFilter) {
            return false;
          }
          if (!kw) return true;
          var hay = [j.title, j.key, j.command, j.description, j.group].join(' ').toLowerCase();
          return hay.indexOf(kw) >= 0;
        });
      },
      enabledCount: function () {
        return (this.jobs || []).filter(function (j) { return !!j.enabled; }).length;
      }
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var data = await AdminAPI.scheduledJobs();
          this.jobs = data.jobs || [];
          this.mountHint = data.mountHint || '';
          this.note = data.note || '';
        } catch (e) {
          ElMessage.error(e.message || '加载失败');
        } finally {
          this.loading = false;
        }
      },
      applyJobs: function (data) {
        if (data && data.jobs) this.jobs = data.jobs;
      },
      onToggle: async function (row, val) {
        var key = row.key;
        this.toggling[key] = true;
        try {
          var data = await AdminAPI.updateScheduledJob(key, { enabled: !!val });
          this.applyJobs(data);
          ElMessage.success((row.title || key) + (val ? ' 已开启' : ' 已关闭'));
        } catch (e) {
          row.enabled = !val;
          ElMessage.error(e.message || '更新失败');
        } finally {
          this.toggling[key] = false;
        }
      },
      runNow: async function (row) {
        var key = row.key;
        try {
          await ElMessageBox.confirm(
            '立即执行「' + (row.title || key) + '」？（不受开关与 cron 限制）',
            '手动触发',
            { type: 'warning', confirmButtonText: '执行', cancelButtonText: '取消' }
          );
        } catch (e) {
          return;
        }
        this.running[key] = true;
        try {
          var data = await AdminAPI.runScheduledJob(key);
          this.applyJobs(data);
          var r = data.result || {};
          if (r.ok) ElMessage.success('执行完成：' + (r.message || r.status));
          else ElMessage.error('执行失败：' + (r.message || r.status));
        } catch (e) {
          ElMessage.error(e.message || '执行失败');
        } finally {
          this.running[key] = false;
        }
      },
      copyMount: async function () {
        var text = this.mountHint || '';
        if (!text) return;
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
          } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
          }
          ElMessage.success('已复制 crontab 语句');
        } catch (e) {
          ElMessage.warning('复制失败，请手动选择文本');
        }
      },
      groupTagType: function (group) {
        var g = String(group || '');
        if (g === 'YouTube') return 'danger';
        if (g === '拓客') return 'warning';
        if (g === '运维') return 'info';
        if (g === '增长' || g === '养号') return 'success';
        return '';
      }
    },
    render: function () {
      var self = this;
      return h('div', { class: 'page' }, [
        pageHead(
          '计划任务',
          '统一调度入口：服务器 crontab 只挂 schedule.php；下方开关控制全部计划任务（含 YouTube / 拓客 / 封禁恢复等）。已开启 '
            + self.enabledCount + ' / ' + (self.jobs || []).length,
          h(ElementPlus.ElButton, { onClick: function () { self.load(); } }, function () { return '刷新'; })
        ),
        h(ElementPlus.ElAlert, {
          type: 'info',
          showIcon: true,
          closable: false,
          style: 'margin-bottom:16px',
          title: self.note || '开启后才会被 schedule.php 调度执行'
        }, function () {
          return h('div', { class: 'mt-2' }, [
            h('div', { class: 'text-xs text-slate-500 mb-1' }, '推荐挂载（每分钟）：'),
            h('code', { style: 'display:block;white-space:pre-wrap;word-break:break-all;font-size:12px' }, self.mountHint || ''),
            h(ElementPlus.ElButton, {
              size: 'small',
              style: 'margin-top:8px',
              onClick: function () { self.copyMount(); }
            }, function () { return '复制语句'; })
          ]);
        }),
        h('div', {
          style: 'display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px'
        }, [
          h(ElementPlus.ElRadioGroup, {
            modelValue: self.groupFilter,
            'onUpdate:modelValue': function (v) { self.groupFilter = v; },
            size: 'small'
          }, function () {
            return self.groups.map(function (g) {
              return h(ElementPlus.ElRadioButton, { label: g, key: g }, function () { return g; });
            });
          }),
          h(ElementPlus.ElInput, {
            modelValue: self.keyword,
            'onUpdate:modelValue': function (v) { self.keyword = v; },
            placeholder: '搜索任务名/命令/说明',
            clearable: true,
            style: 'width:220px;margin-left:auto'
          })
        ]),
        h(ElementPlus.ElCard, { shadow: 'never', vLoading: self.loading }, {
          default: function () {
            return h(ElementPlus.ElTable, {
              data: self.filteredJobs,
              stripe: true,
              style: 'width:100%',
              emptyText: '没有匹配的计划任务'
            }, {
              default: function () {
                return [
                  h(ElementPlus.ElTableColumn, { label: '分组', width: 100 }, {
                    default: function (scope) {
                      return h(ElementPlus.ElTag, {
                        size: 'small',
                        type: self.groupTagType(scope.row.group),
                        effect: 'plain'
                      }, function () { return scope.row.group || '其他'; });
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { prop: 'title', label: '任务', minWidth: 160 }, {
                    default: function (scope) {
                      return h('div', [
                        h('div', { class: 'font-medium' }, scope.row.title),
                        h('div', { class: 'text-xs text-slate-400' }, scope.row.key)
                      ]);
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { prop: 'command', label: '命令', minWidth: 200 }, {
                    default: function (scope) {
                      var args = (scope.row.args || []).join(' ');
                      return h('code', { class: 'text-xs' }, scope.row.command + (args ? ' ' + args : ''));
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { prop: 'cron', label: 'Cron', width: 120 }, {
                    default: function (scope) {
                      return h('code', { class: 'text-xs' }, scope.row.cron);
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { prop: 'description', label: '说明', minWidth: 240 }),
                  h(ElementPlus.ElTableColumn, { label: '开启', width: 90 }, {
                    default: function (scope) {
                      return h(ElementPlus.ElSwitch, {
                        modelValue: !!scope.row.enabled,
                        'onUpdate:modelValue': function (v) {
                          scope.row.enabled = v;
                          self.onToggle(scope.row, v);
                        },
                        disabled: !!self.toggling[scope.row.key],
                        loading: !!self.toggling[scope.row.key]
                      });
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '最近执行', minWidth: 180 }, {
                    default: function (scope) {
                      var st = scope.row.lastStatus || '-';
                      var tip = (scope.row.lastRunAt || '-') + ' · ' + st;
                      if (scope.row.lastDurationMs != null) tip += ' · ' + scope.row.lastDurationMs + 'ms';
                      return h('div', [
                        h('div', { class: 'text-xs' }, tip),
                        scope.row.lastMessage
                          ? h('div', { class: 'text-xs text-slate-400', title: scope.row.lastMessage }, String(scope.row.lastMessage).slice(0, 60))
                          : null
                      ]);
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '操作', width: 110, fixed: 'right' }, {
                    default: function (scope) {
                      return h(ElementPlus.ElButton, {
                        size: 'small',
                        loading: !!self.running[scope.row.key],
                        onClick: function () { self.runNow(scope.row); }
                      }, function () { return '立即执行'; });
                    }
                  })
                ];
              }
            });
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

  var GithubConfigsView = {
    name: 'GithubConfigsView',
    data: function () {
      return {
        loading: false,
        rows: [],
        dialog: false,
        editing: null,
        form: {
          name: '', owner: '', repo: '', token: '', tagPrefix: 'videos',
          urlTemplate: 'https://github.com/{owner}/{repo}/releases/download/{tag}/{name}',
          cdnPrefix: '', notes: '', enableNow: false
        }
      };
    },
    mounted: function () { this.load(); },
    methods: {
      load: async function () {
        this.loading = true;
        try {
          var data = await AdminAPI.githubConfigs();
          this.rows = (data && data.items) || [];
        } catch (e) { ElMessage.error(e.message); }
        finally { this.loading = false; }
      },
      openCreate: function () {
        this.editing = null;
        this.form = {
          name: '', owner: '', repo: '', token: '', tagPrefix: 'videos',
          urlTemplate: 'https://github.com/{owner}/{repo}/releases/download/{tag}/{name}',
          cdnPrefix: '', notes: '', enableNow: true
        };
        this.dialog = true;
      },
      openEdit: function (row) {
        this.editing = row;
        this.form = {
          name: row.name || '',
          owner: row.owner || '',
          repo: row.repo || '',
          token: '',
          tagPrefix: row.tagPrefix || 'videos',
          urlTemplate: row.urlTemplate || 'https://github.com/{owner}/{repo}/releases/download/{tag}/{name}',
          cdnPrefix: row.cdnPrefix || '',
          notes: row.notes || '',
          enableNow: false
        };
        this.dialog = true;
      },
      save: async function () {
        try {
          if (!this.form.owner || !this.form.repo) {
            ElMessage.warning('请填写 owner 与 repo');
            return;
          }
          if (!this.editing && !this.form.token) {
            ElMessage.warning('请填写 GitHub Token');
            return;
          }
          var body = {
            name: this.form.name,
            owner: this.form.owner,
            repo: this.form.repo,
            token: this.form.token,
            tagPrefix: this.form.tagPrefix,
            urlTemplate: this.form.urlTemplate,
            cdnPrefix: this.form.cdnPrefix,
            notes: this.form.notes,
            enableNow: !!this.form.enableNow
          };
          if (this.editing) await AdminAPI.updateGithubConfig(this.editing.id, body);
          else await AdminAPI.createGithubConfig(body);
          ElMessage.success('已保存');
          this.dialog = false;
          this.load();
        } catch (e) { ElMessage.error(e.message); }
      },
      enable: async function (row) {
        try {
          await AdminAPI.enableGithubConfig(row.id);
          ElMessage.success('已设为当前上传启用项');
          this.load();
        } catch (e) { ElMessage.error(e.message); }
      },
      remove: async function (row) {
        try {
          await ElMessageBox.confirm('确定删除「' + (row.name || (row.owner + '/' + row.repo)) + '」吗？', '确认', { type: 'warning' });
          await AdminAPI.deleteGithubConfig(row.id);
          ElMessage.success('已删除');
          this.load();
        } catch (e) { if (e !== 'cancel') ElMessage.error(e.message || String(e)); }
      }
    },
    render: function () {
      var self = this;
      return h('div', [
        pageHead('GitHub配置', '配置多个 GitHub Release 仓库；企业上传视频时使用「启用」项自动上传并生成分发链接', [
          h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.openCreate(); } }, function () { return '新建配置'; })
        ]),
        h('div', { class: 'panel' }, [
          h(ElementPlus.ElTable, { data: self.rows, stripe: true, vLoading: self.loading, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 60 }),
              h(ElementPlus.ElTableColumn, { prop: 'name', label: '名称', minWidth: 120 }),
              h(ElementPlus.ElTableColumn, { label: '仓库', minWidth: 160 }, {
                default: function (scope) {
                  return (scope.row.owner || '') + '/' + (scope.row.repo || '');
                }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'tagPrefix', label: 'Tag前缀', width: 110 }),
              h(ElementPlus.ElTableColumn, { label: 'CDN前缀', minWidth: 140 }, {
                default: function (scope) {
                  return scope.row.cdnPrefix || h('span', { class: 'text-slate-400' }, '直链');
                }
              }),
              h(ElementPlus.ElTableColumn, { label: '启用', width: 90 }, {
                default: function (scope) {
                  return scope.row.isEnabled
                    ? h(ElementPlus.ElTag, { type: 'success', size: 'small' }, function () { return '当前'; })
                    : h('span', { class: 'text-slate-300' }, '-');
                }
              }),
              h(ElementPlus.ElTableColumn, { label: 'Token', width: 80 }, {
                default: function (scope) {
                  return scope.row.hasToken ? '已配置' : h('span', { class: 'text-red-400' }, '无');
                }
              }),
              h(ElementPlus.ElTableColumn, { label: '操作', width: 220, fixed: 'right' }, {
                default: function (scope) {
                  var row = scope.row;
                  return h('div', { class: 'flex flex-wrap gap-1' }, [
                    h(ElementPlus.ElButton, { size: 'small', link: true, onClick: function () { self.openEdit(row); } }, function () { return '编辑'; }),
                    !row.isEnabled
                      ? h(ElementPlus.ElButton, { size: 'small', link: true, type: 'primary', onClick: function () { self.enable(row); } }, function () { return '设为启用'; })
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
          title: self.editing ? '编辑 GitHub 配置' : '新建 GitHub 配置',
          width: '640px'
        }, {
          default: function () {
            return h('div', { class: 'form-grid', style: 'display:grid;gap:12px' }, [
              h(ElementPlus.ElFormItem, { label: '名称' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.name,
                  'onUpdate:modelValue': function (v) { self.form.name = v; },
                  placeholder: '可选，默认 owner/repo'
                });
              }),
              h(ElementPlus.ElFormItem, { label: 'Owner' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.owner,
                  'onUpdate:modelValue': function (v) { self.form.owner = v; },
                  placeholder: 'GitHub 用户或组织'
                });
              }),
              h(ElementPlus.ElFormItem, { label: 'Repo' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.repo,
                  'onUpdate:modelValue': function (v) { self.form.repo = v; },
                  placeholder: '仓库名'
                });
              }),
              h(ElementPlus.ElFormItem, { label: 'Token' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.token,
                  'onUpdate:modelValue': function (v) { self.form.token = v; },
                  type: 'password',
                  showPassword: true,
                  placeholder: self.editing ? '留空则不修改' : 'classic PAT，需 repo/releases 权限'
                });
              }),
              h(ElementPlus.ElFormItem, { label: 'Tag 前缀' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.tagPrefix,
                  'onUpdate:modelValue': function (v) { self.form.tagPrefix = v; },
                  placeholder: 'videos → videos-20260326'
                });
              }),
              h(ElementPlus.ElFormItem, { label: 'URL 模板' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.urlTemplate,
                  'onUpdate:modelValue': function (v) { self.form.urlTemplate = v; },
                  placeholder: '{owner} {repo} {tag} {name}'
                });
              }),
              h(ElementPlus.ElFormItem, { label: 'CDN/镜像前缀' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.cdnPrefix,
                  'onUpdate:modelValue': function (v) { self.form.cdnPrefix = v; },
                  placeholder: '可选，如 https://ghfast.top/ 或带 {url}'
                });
              }),
              h(ElementPlus.ElFormItem, { label: '备注' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.notes,
                  'onUpdate:modelValue': function (v) { self.form.notes = v; },
                  type: 'textarea',
                  rows: 2
                });
              }),
              !self.editing
                ? h(ElementPlus.ElFormItem, { label: '启用' }, function () {
                    return h(ElementPlus.ElSwitch, {
                      modelValue: self.form.enableNow,
                      'onUpdate:modelValue': function (v) { self.form.enableNow = v; }
                    });
                  })
                : null
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

  var SshServersView = {
    name: 'SshServersView',
    data: function () {
      return {
        loading: false,
        rows: [],
        dialog: false,
        logDialog: false,
        editing: null,
        logText: '',
        logRow: null,
        pollTimer: null,
        busyId: 0,
        form: {
          name: '', host: '', sshPort: 22, username: 'root', authType: 'password',
          password: '', privateKey: '', publicHost: '', browserPort: 54345,
          apiBase: '', vncPassword: '',
          gitUrl: 'https://github.com/MinWU2023/tkswarm-linuxbrowse.git',
          gitBranch: 'main', notes: ''
        }
      };
    },
    mounted: function () { this.load(); },
    unmounted: function () { this.stopPoll(); },
    methods: {
      statusMeta: function (row) {
        if (row.healthOk) return { t: '可用', type: 'success' };
        var s = row.status || 'pending';
        var map = {
          pending: { t: '待部署', type: 'info' },
          deploying: { t: '部署中', type: 'warning' },
          ok: { t: '可用', type: 'success' },
          failed: { t: '失败', type: 'danger' },
          unreachable: { t: 'SSH不通', type: 'danger' }
        };
        return map[s] || { t: s, type: 'info' };
      },
      load: async function () {
        this.loading = true;
        try {
          var data = await AdminAPI.sshServers();
          this.rows = (data && data.items) || [];
        } catch (e) { ElMessage.error(e.message); }
        finally { this.loading = false; }
      },
      openCreate: function () {
        this.editing = null;
        var origin = '';
        try { origin = location.origin || ''; } catch (e) {}
        this.form = {
          name: '', host: '', sshPort: 22, username: 'root', authType: 'password',
          password: '', privateKey: '', publicHost: '', browserPort: 54345,
          apiBase: origin ? (origin.replace(/\/+$/, '') + '/api') : '',
          vncPassword: '',
          gitUrl: 'https://github.com/MinWU2023/tkswarm-linuxbrowse.git',
          gitBranch: 'main', notes: ''
        };
        this.dialog = true;
      },
      openEdit: function (row) {
        this.editing = row;
        this.form = {
          name: row.name || '',
          host: row.host || '',
          sshPort: row.sshPort || 22,
          username: row.username || 'root',
          authType: row.authType || 'password',
          password: '',
          privateKey: '',
          publicHost: row.publicHost || '',
          browserPort: row.browserPort || 54345,
          apiBase: row.apiBase || '',
          vncPassword: '',
          gitUrl: row.gitUrl || 'https://github.com/MinWU2023/tkswarm-linuxbrowse.git',
          gitBranch: row.gitBranch || 'main',
          notes: row.notes || ''
        };
        this.dialog = true;
      },
      save: async function () {
        try {
          if (!this.form.host) {
            ElMessage.warning('请填写主机');
            return;
          }
          if (!this.editing && this.form.authType === 'password' && !this.form.password) {
            ElMessage.warning('请填写 SSH 密码');
            return;
          }
          if (!this.editing && this.form.authType === 'key' && !this.form.privateKey) {
            ElMessage.warning('请填写 SSH 私钥');
            return;
          }
          var body = {
            name: this.form.name,
            host: this.form.host,
            sshPort: Number(this.form.sshPort) || 22,
            username: this.form.username,
            authType: this.form.authType,
            password: this.form.password,
            privateKey: this.form.privateKey,
            publicHost: this.form.publicHost,
            browserPort: Number(this.form.browserPort) || 54345,
            apiBase: this.form.apiBase,
            vncPassword: this.form.vncPassword,
            gitUrl: this.form.gitUrl,
            gitBranch: this.form.gitBranch,
            notes: this.form.notes
          };
          if (this.editing) await AdminAPI.updateSshServer(this.editing.id, body);
          else await AdminAPI.createSshServer(body);
          ElMessage.success('已保存');
          this.dialog = false;
          this.load();
        } catch (e) { ElMessage.error(e.message); }
      },
      remove: async function (row) {
        try {
          await ElMessageBox.confirm('确定删除「' + (row.name || row.host) + '」吗？', '确认', { type: 'warning' });
          await AdminAPI.deleteSshServer(row.id);
          ElMessage.success('已删除');
          this.load();
        } catch (e) { if (e !== 'cancel') ElMessage.error(e.message || String(e)); }
      },
      test: async function (row) {
        this.busyId = row.id;
        try {
          var data = await AdminAPI.testSshServer(row.id);
          ElMessage.success((data && data.stdout) ? ('SSH 成功：' + String(data.stdout).slice(0, 120)) : 'SSH 连接成功');
          this.load();
        } catch (e) { ElMessage.error(e.message); }
        finally { this.busyId = 0; }
      },
      health: async function (row) {
        this.busyId = row.id;
        try {
          await AdminAPI.healthSshServer(row.id);
          ElMessage.success('健康检测通过，Node 基础设置可选此服务器');
          this.load();
        } catch (e) { ElMessage.error(e.message); this.load(); }
        finally { this.busyId = 0; }
      },
      deploy: async function (row) {
        try {
          await ElMessageBox.confirm(
            '将通过 SSH 在该机执行 linuxbrowse 一键安装（耗时可能较长）。确认开始？',
            '部署服务器浏览器',
            { type: 'warning', confirmButtonText: '开始部署' }
          );
        } catch (e) { return; }
        this.busyId = row.id;
        try {
          var data = await AdminAPI.deploySshServer(row.id, {});
          ElMessage.success((data && data.async) ? '已开始后台部署' : '部署完成');
          this.openLog(row);
          this.startPoll(row.id);
          this.load();
        } catch (e) {
          ElMessage.error(e.message);
          this.openLog(row);
          this.load();
        } finally { this.busyId = 0; }
      },
      openLog: async function (row) {
        this.logRow = row;
        this.logDialog = true;
        await this.refreshLog();
      },
      refreshLog: async function () {
        if (!this.logRow) return;
        try {
          var data = await AdminAPI.sshServerDeployLog(this.logRow.id);
          this.logText = (data && data.deployLog) || '';
          if (data && data.server) {
            this.logRow = data.server;
            var idx = this.rows.findIndex(function (r) { return r.id === data.server.id; });
            if (idx >= 0) this.rows.splice(idx, 1, data.server);
          }
          if (data && data.status && data.status !== 'deploying') {
            this.stopPoll();
          }
        } catch (e) { /* ignore poll errors */ }
      },
      startPoll: function (id) {
        var self = this;
        this.stopPoll();
        this.pollTimer = setInterval(function () {
          if (self.logRow && self.logRow.id === id) self.refreshLog();
          else self.load();
        }, 4000);
      },
      stopPoll: function () {
        if (this.pollTimer) {
          clearInterval(this.pollTimer);
          this.pollTimer = null;
        }
      }
    },
    render: function () {
      var self = this;
      return h('div', [
        pageHead('SSH服务器', '管理 Linux 主机；在线 SSH 部署服务器浏览器（linuxbrowse / EL）。检测成功后，Node「基础设置」可下拉选择，无需手输地址', [
          h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.openCreate(); } }, function () { return '添加服务器'; })
        ]),
        h('div', { class: 'panel' }, [
          h(ElementPlus.ElTable, { data: self.rows, stripe: true, vLoading: self.loading, style: 'width:100%' }, function () {
            return [
              h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 60 }),
              h(ElementPlus.ElTableColumn, { prop: 'name', label: '名称', minWidth: 110 }),
              h(ElementPlus.ElTableColumn, { label: 'SSH', minWidth: 160 }, {
                default: function (scope) {
                  return (scope.row.username || 'root') + '@' + (scope.row.host || '') + ':' + (scope.row.sshPort || 22);
                }
              }),
              h(ElementPlus.ElTableColumn, { prop: 'browserApiUrl', label: '浏览器 URL', minWidth: 180 }),
              h(ElementPlus.ElTableColumn, { label: '状态', width: 100 }, {
                default: function (scope) {
                  var m = self.statusMeta(scope.row);
                  return h(ElementPlus.ElTag, { type: m.type, size: 'small' }, function () { return m.t; });
                }
              }),
              h(ElementPlus.ElTableColumn, { label: '最近检测', width: 160, prop: 'lastHealthAt' }),
              h(ElementPlus.ElTableColumn, { label: '操作', width: 320, fixed: 'right' }, {
                default: function (scope) {
                  var row = scope.row;
                  var busy = self.busyId === row.id;
                  return h('div', { class: 'flex flex-wrap gap-1' }, [
                    h(ElementPlus.ElButton, { size: 'small', link: true, disabled: busy, onClick: function () { self.openEdit(row); } }, function () { return '编辑'; }),
                    h(ElementPlus.ElButton, { size: 'small', link: true, disabled: busy, onClick: function () { self.test(row); } }, function () { return '测SSH'; }),
                    h(ElementPlus.ElButton, { size: 'small', link: true, type: 'primary', disabled: busy || row.status === 'deploying', onClick: function () { self.deploy(row); } }, function () { return '部署'; }),
                    h(ElementPlus.ElButton, { size: 'small', link: true, disabled: busy, onClick: function () { self.health(row); } }, function () { return '检测'; }),
                    h(ElementPlus.ElButton, { size: 'small', link: true, onClick: function () { self.openLog(row); self.startPoll(row.id); } }, function () { return '日志'; }),
                    h(ElementPlus.ElButton, { size: 'small', link: true, type: 'danger', disabled: busy, onClick: function () { self.remove(row); } }, function () { return '删除'; })
                  ]);
                }
              })
            ];
          })
        ]),
        h(ElementPlus.ElDialog, {
          modelValue: self.dialog,
          'onUpdate:modelValue': function (v) { self.dialog = v; },
          title: self.editing ? '编辑 SSH 服务器' : '添加 SSH 服务器',
          width: '680px'
        }, {
          default: function () {
            return h('div', { class: 'form-grid', style: 'display:grid;gap:12px' }, [
              h(ElementPlus.ElFormItem, { label: '名称' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.name,
                  'onUpdate:modelValue': function (v) { self.form.name = v; },
                  placeholder: '可选，默认用主机名'
                });
              }),
              h(ElementPlus.ElFormItem, { label: 'SSH 主机' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.host,
                  'onUpdate:modelValue': function (v) { self.form.host = v; },
                  placeholder: 'IP 或域名'
                });
              }),
              h(ElementPlus.ElFormItem, { label: 'SSH 端口' }, function () {
                return h(ElementPlus.ElInputNumber, {
                  modelValue: self.form.sshPort,
                  'onUpdate:modelValue': function (v) { self.form.sshPort = v; },
                  min: 1, max: 65535
                });
              }),
              h(ElementPlus.ElFormItem, { label: '用户名' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.username,
                  'onUpdate:modelValue': function (v) { self.form.username = v; },
                  placeholder: 'root'
                });
              }),
              h(ElementPlus.ElFormItem, { label: '认证方式' }, function () {
                return h(ElementPlus.ElRadioGroup, {
                  modelValue: self.form.authType,
                  'onUpdate:modelValue': function (v) { self.form.authType = v; }
                }, function () {
                  return [
                    h(ElementPlus.ElRadio, { label: 'password' }, function () { return '密码'; }),
                    h(ElementPlus.ElRadio, { label: 'key' }, function () { return '私钥'; })
                  ];
                });
              }),
              self.form.authType === 'password'
                ? h(ElementPlus.ElFormItem, { label: 'SSH 密码' }, function () {
                    return h(ElementPlus.ElInput, {
                      modelValue: self.form.password,
                      'onUpdate:modelValue': function (v) { self.form.password = v; },
                      type: 'password', showPassword: true,
                      placeholder: self.editing ? '留空则不修改' : '必填'
                    });
                  })
                : h(ElementPlus.ElFormItem, { label: '私钥 PEM' }, function () {
                    return h(ElementPlus.ElInput, {
                      modelValue: self.form.privateKey,
                      'onUpdate:modelValue': function (v) { self.form.privateKey = v; },
                      type: 'textarea', rows: 4,
                      placeholder: self.editing ? '留空则不修改' : '-----BEGIN OPENSSH PRIVATE KEY-----'
                    });
                  }),
              h(ElementPlus.ElFormItem, { label: '对外访问主机' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.publicHost,
                  'onUpdate:modelValue': function (v) { self.form.publicHost = v; },
                  placeholder: '可选；Node 访问用，空则用 SSH 主机'
                });
              }),
              h(ElementPlus.ElFormItem, { label: '浏览器端口' }, function () {
                return h(ElementPlus.ElInputNumber, {
                  modelValue: self.form.browserPort,
                  'onUpdate:modelValue': function (v) { self.form.browserPort = v; },
                  min: 1, max: 65535
                });
              }),
              h(ElementPlus.ElFormItem, { label: 'API Base' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.apiBase,
                  'onUpdate:modelValue': function (v) { self.form.apiBase = v; },
                  placeholder: 'https://你的API域名/api（部署写入 linuxbrowse）'
                });
              }),
              h(ElementPlus.ElFormItem, { label: 'VNC 密码' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.vncPassword,
                  'onUpdate:modelValue': function (v) { self.form.vncPassword = v; },
                  type: 'password', showPassword: true,
                  placeholder: self.editing ? '留空则不修改' : '有头扫码建议填写'
                });
              }),
              h(ElementPlus.ElFormItem, { label: 'Git 仓库' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.gitUrl,
                  'onUpdate:modelValue': function (v) { self.form.gitUrl = v; }
                });
              }),
              h(ElementPlus.ElFormItem, { label: '分支' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.gitBranch,
                  'onUpdate:modelValue': function (v) { self.form.gitBranch = v; }
                });
              }),
              h(ElementPlus.ElFormItem, { label: '备注' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.notes,
                  'onUpdate:modelValue': function (v) { self.form.notes = v; },
                  type: 'textarea', rows: 2
                });
              })
            ]);
          },
          footer: function () {
            return [
              h(ElementPlus.ElButton, { onClick: function () { self.dialog = false; } }, function () { return '取消'; }),
              h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.save(); } }, function () { return '保存'; })
            ];
          }
        }),
        h(ElementPlus.ElDialog, {
          modelValue: self.logDialog,
          'onUpdate:modelValue': function (v) {
            self.logDialog = v;
            if (!v) self.stopPoll();
          },
          title: self.logRow ? ('部署日志 · ' + (self.logRow.name || self.logRow.host)) : '部署日志',
          width: '760px'
        }, {
          default: function () {
            return h('pre', {
              style: 'max-height:420px;overflow:auto;background:#0b1220;color:#e2e8f0;padding:12px;border-radius:8px;font-size:12px;white-space:pre-wrap;word-break:break-all'
            }, self.logText || '（暂无日志）');
          },
          footer: function () {
            return [
              h(ElementPlus.ElButton, { onClick: function () { self.refreshLog(); } }, function () { return '刷新'; }),
              h(ElementPlus.ElButton, { type: 'primary', onClick: function () { self.logDialog = false; self.stopPoll(); } }, function () { return '关闭'; })
            ];
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
    ScheduledJobsView: ScheduledJobsView,
    VersionsView: VersionsView,
    HelpCategoriesView: HelpCategoriesView,
    HelpDocsView: HelpDocsView,
    EnvInstallersView: EnvInstallersView,
    GithubConfigsView: GithubConfigsView,
    SshServersView: SshServersView,
    TaskResetView: TaskResetView,
    TaskClearView: TaskClearView,
    QuickOpsView: QuickOpsView
  };
})(window);
