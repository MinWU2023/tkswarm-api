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
      return h('div', { class: 'login-shell' }, [
        h('div', { class: 'glass-card login-panel' }, [
          h('div', { class: 'login-brand' }, [
            h('img', { class: 'brand-logo brand-logo-wide', src: '/images/logo.png?v=2', alt: '第一页科技 TkSwarm' }),
            h('div', [
              h('div', { class: 'login-title' }, 'TKSwarm'),
              h('div', { class: 'login-sub' }, '运营控制台 · 工单 / 版本 / 帮助文档')
            ])
          ]),
          h(ElementPlus.ElForm, { labelPosition: 'top', onSubmit: function (e) { e.preventDefault(); self.submit(); } }, function () {
            return [
              h(ElementPlus.ElFormItem, { label: '账号' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.username,
                  'onUpdate:modelValue': function (v) { self.form.username = v; },
                  placeholder: '管理员账号',
                  prefixIcon: ElementPlusIconsVue.User
                });
              }),
              h(ElementPlus.ElFormItem, { label: '密码' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.password,
                  'onUpdate:modelValue': function (v) { self.form.password = v; },
                  type: 'password',
                  showPassword: true,
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
                    style: 'flex:1'
                  }),
                  self.captchaSvg
                    ? h('img', { src: self.captchaSvg, title: '点击刷新', onClick: function () { self.refreshCaptcha(); } })
                    : h(ElementPlus.ElButton, { onClick: function () { self.refreshCaptcha(); } }, function () { return '刷新'; })
                ]);
              }),
              h('div', { class: 'flex items-center justify-between mb-4' }, [
                h(ElementPlus.ElCheckbox, {
                  modelValue: self.form.rememberMe,
                  'onUpdate:modelValue': function (v) { self.form.rememberMe = v; }
                }, function () { return '记住登录'; }),
                h('span', { class: 'text-xs text-slate-400' }, '使用后台管理员账号')
              ]),
              h(ElementPlus.ElButton, {
                type: 'primary',
                class: 'w-full',
                loading: self.loading,
                onClick: function () { self.submit(); }
              }, function () { return '登录控制台'; })
            ];
          })
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
      return h('div', { 'v-loading': this.loading }, [
        h('div', { class: 'page-head' }, [
          h('div', [h('h2', '工作台'), h('p', '一眼看清工单、版本与帮助文档概况')])
        ]),
        h('div', { class: 'stat-grid' }, cards.map(function (c) {
          return h('div', { class: 'stat-card' }, [
            h('div', { class: 'label' }, c.label),
            h('div', { class: 'value' }, String(c.value)),
            h('div', { class: 'hint' }, c.hint)
          ]);
        })),
        h('div', { class: 'panel', style: 'margin-bottom:16px' }, [
          h('div', { class: 'flex items-center justify-between mb-3 flex-wrap gap-2' }, [
            h('b', '任务数据运维'),
            h('span', { class: 'text-xs text-slate-400' }, '总任务 / 子任务维护入口')
          ]),
          h('div', { class: 'flex items-center gap-3 flex-wrap' }, [
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
          h('div', { class: 'flex items-center justify-between mb-3' }, [
            h('b', '最近工单'),
            h(ElementPlus.ElButton, { text: true, type: 'primary', onClick: function () { location.hash = '#/tickets'; } }, function () { return '查看全部'; })
          ]),
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
        h('div', { class: 'page-head' }, [
          h('div', [h('h2', '工单处理'), h('p', '查看并回复客户端提交的问题反馈')]),
          h('div', { class: 'flex gap-2' }, [
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
          ])
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
        h('div', { class: 'page-head' }, [
          h('div', [h('h2', '版本发布'), h('p', '上传代码包或填写下载链接后发布，供客户端安装')]),
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
        h('div', { class: 'page-head' }, [
          h('div', [h('h2', '帮助分类'), h('p', '管理客户端帮助文档左侧分类')]),
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
        h('div', { class: 'page-head' }, [
          h('div', [h('h2', '帮助文档'), h('p', '编写并发布给客户端展示的帮助内容')]),
          h('div', { class: 'flex gap-2' }, [
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
          ])
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
        h('div', { class: 'page-head' }, [
          h('div', [
            h('h2', '环境安装包'),
            h('p', '分别维护 Windows / macOS 的 Node.js 与比特浏览器安装包，供客户端直链下载安装')
          ]),
          h('div', { class: 'flex gap-2 flex-wrap' }, [
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
          ])
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
          h('div', { class: 'page-head' }, [
            h('div', [
              h('h2', cfg.title),
              h('p', cfg.subtitle)
            ])
          ]),
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
    subtitle: '永久清空总任务 / 子任务及相关业务任务表（TRUNCATE，不可逆）',
    danger: true,
    alertTitle: '危险操作：数据将被永久删除',
    alertDesc: '将清空 account_work_* 与 tasks / task_items 等关联表，并重置自增 ID。账号、素材、代理等主数据不受影响。',
    bullets: [
      '适用：测试环境洗库、或正式环境确认要丢掉全部任务历史',
      '清空：account_work_batches / items / logs，以及 tasks 系列表',
      '执行前建议先备份数据库'
    ],
    confirmTitle: '确认永久删除全部任务数据？',
    confirmText: '将 TRUNCATE 全部总任务、子任务及相关任务表，不可撤销！是否继续？',
    confirmButton: '确认一键删除',
    toolUrl: '/clear-work-data.php'
  });

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
        h('div', { class: 'page-head' }, [
          h('div', [h('h2', '官网咨询'), h('p', '落地页「联系我们」表单提交的留资')]),
          h('div', { class: 'flex gap-2 flex-wrap' }, [
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
          ])
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
        h('div', { class: 'page-head' }, [
          h('h2', { class: 'page-title' }, '官网设置'),
          h('p', { class: 'page-desc' }, '配置首页与轮播区的 Windows / macOS 客户端下载链接。支持站内路径（如 /lastest.exe）或完整外链（如 GitHub Release 地址）。')
        ]),
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
    TaskClearView: TaskClearView
  };
})(window);
