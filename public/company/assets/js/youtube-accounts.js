(function (global) {
  'use strict';

  var h = Vue.h;
  var ElMessage = ElementPlus.ElMessage;
  var ElMessageBox = ElementPlus.ElMessageBox;

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

  function ytFmtNum(n) {
    var v = Number(n) || 0;
    if (v >= 10000) return (v / 10000).toFixed(v >= 100000 ? 0 : 1) + '万';
    return String(v);
  }

  function ytFmtDuration(sec) {
    var s = Math.max(0, Number(sec) || 0);
    var hh = Math.floor(s / 3600);
    var m = Math.floor((s % 3600) / 60);
    var r = s % 60;
    if (hh > 0) return hh + ':' + String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
    return m + ':' + String(r).padStart(2, '0');
  }

  function sliceTime(v) {
    return String(v || '').slice(0, 16) || '-';
  }

  var YoutubeAccountsView = {
    name: 'YoutubeAccountsView',
    data: function () {
      return {
        pane: 'list',
        loading: false,
        saving: false,
        items: [],
        companies: [],
        oauthConfigured: false,
        keyword: '',
        dialogVisible: false,
        dialogMode: 'create',
        form: { id: 0, username: '', nickname: '', notes: '', companyId: null },
        opsOpenId: 0,
        panelAccountId: 0,
        panelVideoId: 0,
        panelKeyword: '',
        panelPage: 1,
        panelPages: 1,
        panelTotal: 0,
        panelAccount: null,
        panelVideos: [],
        videoDetail: null,
        videoAccount: null,
        comments: [],
        commentsLoading: false,
        pulling: false,
        replyVisible: false,
        replySaving: false,
        replyAiLoading: false,
        replyComment: null,
        replyText: '',
        replyAiStatus: ''
      };
    },
    mounted: function () {
      this.loadList();
    },
    methods: {
      authLabel: function (row) {
        if (row.authorized && row.tokenValid) return '已授权';
        if (row.authorized) return '需刷新';
        if (row.status === 'authorizing') return '授权中';
        if (row.status === 'error') return '异常';
        return '未授权';
      },
      authType: function (row) {
        if (row.authorized && row.tokenValid) return 'success';
        if (row.authorized) return 'warning';
        if (row.status === 'authorizing') return 'info';
        if (row.status === 'error') return 'danger';
        return 'info';
      },
      toggleOps: function (id) {
        this.opsOpenId = this.opsOpenId === id ? 0 : id;
      },
      closeOps: function () {
        this.opsOpenId = 0;
      },
      loadList: async function () {
        this.loading = true;
        this.closeOps();
        try {
          var d = await CompanyAPI.youtubeAccounts({ keyword: this.keyword || undefined });
          this.items = d.items || [];
          this.companies = d.companies || [];
          this.oauthConfigured = !!d.oauthConfigured;
          if (!this.form.companyId && this.companies.length) {
            this.form.companyId = this.companies[0].id;
          }
        } catch (e) {
          ElMessage.error(e.message || '加载 YouTube 账号失败');
        } finally {
          this.loading = false;
        }
      },
      search: function () {
        this.loadList();
      },
      openCreate: function () {
        if (!this.oauthConfigured) {
          ElMessage.warning('平台尚未配置 YouTube OAuth，请联系运营');
          return;
        }
        this.dialogMode = 'create';
        this.form = {
          id: 0,
          username: '',
          nickname: '',
          notes: '',
          companyId: this.companies.length ? this.companies[0].id : null
        };
        this.dialogVisible = true;
      },
      openEdit: function (row) {
        this.closeOps();
        this.dialogMode = 'edit';
        this.form = {
          id: row.id,
          username: row.username || '',
          nickname: row.nickname || '',
          notes: row.notes || '',
          companyId: row.companyId || (this.companies[0] && this.companies[0].id) || null
        };
        this.dialogVisible = true;
      },
      save: async function () {
        var f = this.form;
        this.saving = true;
        try {
          var body = {
            username: String(f.username || '').trim(),
            nickname: String(f.nickname || '').trim(),
            notes: String(f.notes || '').trim(),
            companyId: f.companyId || undefined
          };
          if (this.dialogMode === 'create') {
            await CompanyAPI.createYoutubeAccount(body);
            ElMessage.success('已添加，请在「…」中点「授权登录」');
          } else {
            await CompanyAPI.updateYoutubeAccount(f.id, body);
            ElMessage.success('已保存');
          }
          this.dialogVisible = false;
          this.loadList();
        } catch (e) {
          ElMessage.error(e.message || '保存失败');
        } finally {
          this.saving = false;
        }
      },
      startAuth: async function (row) {
        this.closeOps();
        try {
          var d = await CompanyAPI.startYoutubeAuth(row.id);
          if (d && d.authUrl) {
            window.open(d.authUrl, '_blank', 'noopener');
            ElMessage.success('已打开 Google 授权页，完成后请刷新列表');
            this.loadList();
          } else {
            ElMessage.warning('未返回授权链接');
          }
        } catch (e) {
          ElMessage.error(e.message || '发起授权失败');
        }
      },
      refreshToken: async function (row) {
        this.closeOps();
        try {
          await CompanyAPI.refreshYoutubeToken(row.id);
          ElMessage.success('已刷新 Token / 频道信息');
          this.loadList();
        } catch (e) {
          ElMessage.error(e.message || '刷新失败');
        }
      },
      deleteAccount: async function (row) {
        this.closeOps();
        try {
          await ElMessageBox.confirm('确认删除 YouTube 账号 #' + row.id + '？', '删除确认', {
            type: 'warning',
            confirmButtonText: '删除',
            cancelButtonText: '取消'
          });
        } catch (_) {
          return;
        }
        try {
          await CompanyAPI.deleteYoutubeAccount(row.id);
          ElMessage.success('已删除');
          this.loadList();
        } catch (e) {
          ElMessage.error(e.message || '删除失败');
        }
      },
      openPanel: function (row) {
        this.closeOps();
        this.panelAccountId = Number(row.id) || 0;
        this.panelVideoId = 0;
        this.panelKeyword = '';
        this.panelPage = 1;
        this.pane = 'videos';
        this.loadPanel();
      },
      backToList: function () {
        this.pane = 'list';
        this.panelAccountId = 0;
        this.panelVideoId = 0;
        this.loadList();
      },
      loadPanel: async function () {
        if (!this.panelAccountId) {
          this.backToList();
          return;
        }
        this.loading = true;
        try {
          var d = await CompanyAPI.youtubeAccountVideos(this.panelAccountId, {
            page: this.panelPage,
            pageSize: 20,
            keyword: this.panelKeyword || undefined
          });
          this.panelAccount = d.account || null;
          this.panelVideos = d.items || [];
          this.panelTotal = Number(d.total || 0);
          this.panelPages = Math.max(1, Number(d.pages || 1));
          this.panelPage = Number(d.page || this.panelPage);
        } catch (e) {
          ElMessage.error(e.message || '加载数据面板失败');
        } finally {
          this.loading = false;
        }
      },
      searchPanel: function () {
        this.panelPage = 1;
        this.loadPanel();
      },
      pullStats: async function () {
        if (!this.panelAccountId) return;
        this.pulling = true;
        try {
          var d = await CompanyAPI.pullYoutubeAccountStats(this.panelAccountId);
          if (d && d.account) this.panelAccount = d.account;
          ElMessage.success('已同步频道基础数据');
          this.loadPanel();
        } catch (e) {
          ElMessage.error(e.message || '同步失败');
        } finally {
          this.pulling = false;
        }
      },
      pullVideos: async function () {
        if (!this.panelAccountId) return;
        try {
          await ElMessageBox.confirm('从 YouTube 拉取该频道内容列表并更新本地数据？', '拉取列表', {
            type: 'info',
            confirmButtonText: '拉取',
            cancelButtonText: '取消'
          });
        } catch (_) {
          return;
        }
        this.pulling = true;
        try {
          var r = await CompanyAPI.pullYoutubeAccountVideos(this.panelAccountId, { maxPages: 10 });
          ElMessage.success('已同步 ' + (r && r.pulled != null ? r.pulled : 0) + ' 条内容');
          this.panelPage = 1;
          this.loadPanel();
        } catch (e) {
          ElMessage.error(e.message || '拉取失败');
        } finally {
          this.pulling = false;
        }
      },
      openVideo: function (video) {
        this.panelVideoId = Number(video.id) || 0;
        this.pane = 'video';
        this.loadVideoDetail();
      },
      backToPanel: function () {
        this.pane = 'videos';
        this.panelVideoId = 0;
        this.videoDetail = null;
        this.comments = [];
        this.loadPanel();
      },
      loadVideoDetail: async function () {
        if (!this.panelAccountId || !this.panelVideoId) {
          this.backToPanel();
          return;
        }
        this.loading = true;
        this.commentsLoading = true;
        try {
          var pack = await CompanyAPI.youtubeAccountVideo(this.panelAccountId, this.panelVideoId, { refresh: 1 });
          this.videoAccount = pack.account || null;
          this.videoDetail = pack.video || null;
        } catch (e) {
          ElMessage.error(e.message || '加载内容失败');
          this.loading = false;
          this.commentsLoading = false;
          return;
        }
        this.loading = false;
        try {
          var cpack = await CompanyAPI.youtubeVideoComments(this.panelAccountId, this.panelVideoId);
          this.comments = cpack.items || [];
        } catch (_) {
          this.comments = [];
        } finally {
          this.commentsLoading = false;
        }
      },
      pullComments: async function () {
        if (!this.panelAccountId || !this.panelVideoId) return;
        this.pulling = true;
        try {
          var r = await CompanyAPI.pullYoutubeVideoComments(this.panelAccountId, this.panelVideoId, {
            maxPages: 5,
            fetchAllReplies: true
          });
          ElMessage.success('已同步评论 ' + (r && r.comments != null ? r.comments : 0) + ' / 回复 ' + (r && r.replies != null ? r.replies : 0));
          this.loadVideoDetail();
        } catch (e) {
          ElMessage.error(e.message || '同步评论失败');
        } finally {
          this.pulling = false;
        }
      },
      deleteVideo: async function (video, goBack) {
        var vid = Number((video && video.id) || this.panelVideoId);
        if (!vid) return;
        try {
          await ElMessageBox.confirm('确认从 YouTube 删除该视频？此操作不可恢复。', '删除视频', {
            type: 'warning',
            confirmButtonText: '删除',
            cancelButtonText: '取消'
          });
        } catch (_) {
          return;
        }
        try {
          await CompanyAPI.deleteYoutubeAccountVideo(this.panelAccountId, vid);
          ElMessage.success('已删除');
          if (goBack) this.backToPanel();
          else this.loadPanel();
        } catch (e) {
          ElMessage.error(e.message || '删除失败');
        }
      },
      findComment: function (commentId) {
        var id = Number(commentId) || 0;
        var list = this.comments || [];
        for (var i = 0; i < list.length; i++) {
          var c = list[i];
          if (Number(c.id) === id) return c;
          var replies = c.replies || [];
          for (var j = 0; j < replies.length; j++) {
            if (Number(replies[j].id) === id) return replies[j];
          }
        }
        return null;
      },
      openReply: function (comment) {
        this.replyComment = comment;
        this.replyText = '';
        this.replyAiStatus = '';
        this.replyVisible = true;
      },
      generateAiReply: async function () {
        if (!this.replyComment) return;
        this.replyAiLoading = true;
        this.replyAiStatus = 'AI 正在生成…';
        try {
          var pack = await CompanyAPI.aiReplyYoutubeComment(this.panelAccountId, this.replyComment.id, {
            templateCode: 'youtube_comment_reply'
          });
          var reply = String((pack && pack.reply) || '').trim();
          if (!reply) throw new Error('AI 未返回内容');
          this.replyText = reply;
          this.replyAiStatus = '已生成' + (pack.model ? ' · ' + pack.model : '') + '，可继续编辑';
          ElMessage.success('已生成回复草稿');
        } catch (e) {
          this.replyAiStatus = '';
          ElMessage.error(e.message || 'AI 生成失败');
        } finally {
          this.replyAiLoading = false;
        }
      },
      submitReply: async function () {
        var body = String(this.replyText || '').trim();
        if (!body) {
          ElMessage.warning('回复不能为空');
          return;
        }
        if (!this.replyComment) return;
        this.replySaving = true;
        try {
          await CompanyAPI.replyYoutubeComment(this.panelAccountId, this.replyComment.id, { text: body });
          ElMessage.success('已回复');
          this.replyVisible = false;
          this.loadVideoDetail();
        } catch (e) {
          ElMessage.error(e.message || '回复失败');
        } finally {
          this.replySaving = false;
        }
      },
      deleteComment: async function (comment) {
        try {
          await ElMessageBox.confirm('确认删除该评论？', '删除评论', {
            type: 'warning',
            confirmButtonText: '删除',
            cancelButtonText: '取消'
          });
        } catch (_) {
          return;
        }
        try {
          await CompanyAPI.deleteYoutubeComment(this.panelAccountId, comment.id);
          ElMessage.success('已删除评论');
          this.loadVideoDetail();
        } catch (e) {
          ElMessage.error(e.message || '删除失败');
        }
      },
      cmtAvatar: function (name, imgUrl) {
        var label = String(name || 'U').trim() || 'U';
        var initial = label.replace(/^@/, '').slice(0, 1).toUpperCase() || 'U';
        if (imgUrl) {
          return h('img', {
            class: 'yt-cmt-avatar',
            src: imgUrl,
            alt: '',
            onError: function (e) {
              var el = e && e.target;
              if (el) {
                el.style.display = 'none';
                if (el.nextSibling) el.nextSibling.style.display = 'inline-flex';
              }
            }
          });
        }
        return h('span', { class: 'yt-cmt-avatar is-fallback' }, initial);
      },
      renderCmtReply: function (r) {
        var self = this;
        return h('div', {
          class: 'yt-cmt-reply' + (r.isCreatorComment ? ' yt-cmt-reply--creator' : '')
        }, [
          this.cmtAvatar(r.authorDisplayName, r.authorProfileImageUrl),
          h('div', { class: 'yt-cmt-main' }, [
            h('div', { class: 'yt-cmt-head' }, [
              h('b', r.authorDisplayName || '用户'),
              r.isCreatorComment ? h('span', { class: 'yt-cmt-tag yt-cmt-tag--creator' }, '创作者') : null,
              h('span', { class: 'muted' }, sliceTime(r.publishedAt)),
              h('span', { class: 'yt-cmt-like' }, '👍 ' + ytFmtNum(r.likeCount))
            ]),
            h('div', { class: 'yt-cmt-body' }, r.textDisplay || r.textOriginal || ''),
            h('div', { class: 'yt-cmt-ops' }, [
              h(ElementPlus.ElButton, {
                size: 'small',
                link: true,
                type: 'danger',
                onClick: function () { self.deleteComment(r); }
              }, function () { return '删除'; })
            ])
          ])
        ]);
      },
      renderCmtTop: function (c) {
        var self = this;
        var isCreator = !!c.isCreatorComment;
        var replied = !!c.creatorReplied;
        var autoSt = String(c.autoHandleStatus || '');
        var statusTag = isCreator
          ? h('span', { class: 'yt-cmt-tag yt-cmt-tag--creator' }, '创作者评论')
          : (replied
            ? h('span', { class: 'yt-cmt-tag yt-cmt-tag--replied' }, '已回复')
            : h('span', { class: 'yt-cmt-tag yt-cmt-tag--pending' }, '未回复'));
        var autoTag = null;
        if (!isCreator && autoSt === 'deleted') {
          autoTag = h('span', { class: 'yt-cmt-tag yt-cmt-tag--deleted' }, '已自动删除');
        } else if (!isCreator && autoSt === 'replied') {
          autoTag = h('span', { class: 'yt-cmt-tag yt-cmt-tag--replied' }, '已自动回复');
        } else if (!isCreator && autoSt === 'error') {
          autoTag = h('span', {
            class: 'yt-cmt-tag yt-cmt-tag--pending',
            title: c.autoHandleNote || ''
          }, '自动处理失败');
        }
        var replies = (c.replies || []).map(function (r) { return self.renderCmtReply(r); });
        var cardClass = 'yt-cmt-card ' + (isCreator ? 'yt-cmt-card--creator' : 'yt-cmt-card--audience');
        if (!isCreator && !replied) cardClass += ' is-pending';
        if (!isCreator && replied) cardClass += ' is-replied';
        return h('article', { class: cardClass }, [
          this.cmtAvatar(c.authorDisplayName, c.authorProfileImageUrl),
          h('div', { class: 'yt-cmt-main' }, [
            h('div', { class: 'yt-cmt-head' }, [
              h('b', c.authorDisplayName || '用户'),
              statusTag,
              autoTag,
              h('span', { class: 'muted' }, sliceTime(c.publishedAt)),
              h('span', { class: 'yt-cmt-like' }, '👍 ' + ytFmtNum(c.likeCount)),
              c.moderationStatus ? h('span', { class: 'yt-chip' }, c.moderationStatus) : null
            ]),
            h('div', { class: 'yt-cmt-body' }, c.textDisplay || c.textOriginal || ''),
            h('div', { class: 'yt-cmt-ops' }, [
              !isCreator
                ? h(ElementPlus.ElButton, {
                  size: 'small',
                  link: true,
                  onClick: function () { self.openReply(c); }
                }, function () { return '回复'; })
                : null,
              h(ElementPlus.ElButton, {
                size: 'small',
                link: true,
                type: 'danger',
                onClick: function () { self.deleteComment(c); }
              }, function () { return '删除'; })
            ]),
            replies.length ? h('div', { class: 'yt-cmt-replies' }, replies) : null
          ])
        ]);
      },
      metaRows: function (rows) {
        return (rows || []).map(function (row) {
          var k = row[0];
          var val = row[1];
          var opts = row[2] || {};
          var vNode;
          if (opts.href) {
            vNode = h('a', { href: opts.href, target: '_blank', rel: 'noopener' }, String(val ?? '-'));
          } else {
            vNode = String(val ?? '-');
          }
          return h('div', { class: 'yt-meta-row' + (opts.block ? ' is-block' : '') }, [
            h('div', { class: 'yt-meta-k' }, k),
            h('div', { class: 'yt-meta-v' + (opts.mono ? ' is-mono' : '') }, [vNode])
          ]);
        });
      },
      renderList: function () {
        var self = this;
        return h('div', { class: 'vd-company-page yt-company-page yt-accounts-page' }, [
          pageHead('YouTube 账号', '本企业 YouTube 频道：添加、授权、数据面板与评论管理'),
          h('div', { class: 'panel' }, [
            panelHead('账号列表', h('div', { class: 'vd-company-filters yt-accounts-toolbar' }, [
              h('div', { class: 'left' }, [
                this.oauthConfigured
                  ? h(ElementPlus.ElTag, { type: 'success', effect: 'plain', size: 'small' }, function () { return 'OAuth 已配'; })
                  : h(ElementPlus.ElTag, { type: 'warning', effect: 'plain', size: 'small' }, function () { return 'OAuth 未配置' })
              ]),
              h('div', { class: 'right' }, [
                h(ElementPlus.ElInput, {
                  modelValue: self.keyword,
                  'onUpdate:modelValue': function (v) { self.keyword = v; },
                  placeholder: '名称/频道',
                  clearable: true,
                  style: 'width:160px',
                  onKeyup: function (e) { if (e.key === 'Enter') self.search(); }
                }),
                h(ElementPlus.ElButton, { onClick: function () { self.search(); } }, function () { return '搜索'; }),
                h(ElementPlus.ElButton, {
                  type: 'primary',
                  disabled: !self.oauthConfigured,
                  onClick: function () { self.openCreate(); }
                }, function () { return '+ 添加账号'; }),
                h(ElementPlus.ElButton, { onClick: function () { self.loadList(); } }, function () { return '刷新'; })
              ])
            ])),
            h(ElementPlus.ElTable, {
              data: self.items,
              vLoading: self.loading,
              stripe: true,
              style: 'width:100%',
              emptyText: self.oauthConfigured ? '暂无账号，请先添加并授权' : '平台未配置 OAuth，请联系运营'
            }, {
              default: function () {
                return [
                  h(ElementPlus.ElTableColumn, { prop: 'id', label: 'ID', width: 70 }),
                  h(ElementPlus.ElTableColumn, { prop: 'username', label: '备注名', minWidth: 110 }, {
                    default: function (scope) { return scope.row.username || '-'; }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '频道', minWidth: 150 }, {
                    default: function (scope) {
                      var r = scope.row;
                      return h('div', [
                        h('div', r.nickname || r.channelTitle || '-'),
                        r.channelCustomUrl
                          ? h('div', { class: 'muted', style: 'font-size:12px' }, r.channelCustomUrl)
                          : null
                      ]);
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { prop: 'channelId', label: 'Channel ID', minWidth: 140 }, {
                    default: function (scope) {
                      return h('div', [
                        scope.row.channelId || '-',
                        scope.row.channelCustomUrl
                          ? h('div', { class: 'muted', style: 'font-size:12px' }, scope.row.channelCustomUrl)
                          : null
                      ]);
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '授权', width: 100 }, {
                    default: function (scope) {
                      return h(ElementPlus.ElTag, {
                        type: self.authType(scope.row),
                        size: 'small'
                      }, function () { return self.authLabel(scope.row); });
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { label: 'Token刷新', minWidth: 140 }, {
                    default: function (scope) {
                      return sliceTime(scope.row.tokenRefreshedAt || scope.row.lastLoginAt || scope.row.updatedAt);
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '错误', minWidth: 140 }, {
                    default: function (scope) {
                      var err = scope.row.lastError || '';
                      return h('span', {
                        class: 'muted',
                        style: 'display:inline-block;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap',
                        title: err
                      }, err || '-');
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '操作', width: 260, fixed: 'right' }, {
                    default: function (scope) {
                      var row = scope.row;
                      return h('div', { class: 'vd-company-ops yt-acc-ops-cell', style: 'white-space:nowrap' }, [
                        h(ElementPlus.ElButton, {
                          size: 'small',
                          link: true,
                          onClick: function () { self.openEdit(row); }
                        }, function () { return '编辑'; }),
                        h(ElementPlus.ElButton, {
                          size: 'small',
                          link: true,
                          type: 'danger',
                          onClick: function () { self.deleteAccount(row); }
                        }, function () { return '删除'; }),
                        h(ElementPlus.ElDropdown, {
                          trigger: 'click',
                          onCommand: function (cmd) {
                            if (cmd === 'auth') self.startAuth(row);
                            else if (cmd === 'refresh') self.refreshToken(row);
                            else if (cmd === 'panel') self.openPanel(row);
                          }
                        }, {
                          default: function () {
                            return h(ElementPlus.ElButton, { size: 'small' }, function () { return '…'; });
                          },
                          dropdown: function () {
                            return h(ElementPlus.ElDropdownMenu, null, function () {
                              var items = [
                                h(ElementPlus.ElDropdownItem, { command: 'auth' }, function () { return '授权登录'; })
                              ];
                              if (row.authorized) {
                                items.push(h(ElementPlus.ElDropdownItem, { command: 'refresh' }, function () { return '刷新 Token'; }));
                              }
                              items.push(h(ElementPlus.ElDropdownItem, { command: 'panel' }, function () { return '数据面板'; }));
                              return items;
                            });
                          }
                        })
                      ]);
                    }
                  })
                ];
              }
            })
          ]),
          this.renderAccountDialog()
        ]);
      },
      renderAccountDialog: function () {
        var self = this;
        return h(ElementPlus.ElDialog, {
          modelValue: self.dialogVisible,
          'onUpdate:modelValue': function (v) { self.dialogVisible = v; },
          title: self.dialogMode === 'create' ? '添加 YouTube 账号' : '编辑 YouTube 账号',
          width: '480px',
          destroyOnClose: true
        }, {
          default: function () {
            return h(ElementPlus.ElForm, { labelPosition: 'top' }, function () {
              var fields = [
                h(ElementPlus.ElFormItem, { label: '备注名' }, function () {
                  return h(ElementPlus.ElInput, {
                    modelValue: self.form.username,
                    'onUpdate:modelValue': function (v) { self.form.username = v; },
                    placeholder: '便于识别，可空'
                  });
                }),
                h(ElementPlus.ElFormItem, { label: '昵称' }, function () {
                  return h(ElementPlus.ElInput, {
                    modelValue: self.form.nickname,
                    'onUpdate:modelValue': function (v) { self.form.nickname = v; },
                    placeholder: '授权后可自动回填频道名'
                  });
                })
              ];
              if (self.companies.length > 1) {
                fields.push(h(ElementPlus.ElFormItem, { label: '关联企业' }, function () {
                  return h(ElementPlus.ElSelect, {
                    modelValue: self.form.companyId,
                    'onUpdate:modelValue': function (v) { self.form.companyId = v; },
                    placeholder: '选择企业',
                    style: 'width:100%'
                  }, {
                    default: function () {
                      return self.companies.map(function (c) {
                        return h(ElementPlus.ElOption, {
                          key: c.id,
                          label: c.name,
                          value: c.id
                        });
                      });
                    }
                  });
                }));
              }
              fields.push(h(ElementPlus.ElFormItem, { label: '备注' }, function () {
                return h(ElementPlus.ElInput, {
                  modelValue: self.form.notes,
                  'onUpdate:modelValue': function (v) { self.form.notes = v; },
                  type: 'textarea',
                  rows: 2,
                  placeholder: '可选'
                });
              }));
              return fields;
            });
          },
          footer: function () {
            return [
              h(ElementPlus.ElButton, {
                onClick: function () { self.dialogVisible = false; }
              }, function () { return '取消'; }),
              h(ElementPlus.ElButton, {
                type: 'primary',
                loading: self.saving,
                onClick: function () { self.save(); }
              }, function () { return '保存'; })
            ];
          }
        });
      },
      renderPanel: function () {
        var self = this;
        var acc = self.panelAccount || {};
        var accountId = self.panelAccountId;
        var title = acc.channelTitle || acc.nickname || acc.username || ('账号 #' + accountId);
        var customUrl = String(acc.channelCustomUrl || '').trim();
        var handle = customUrl ? (customUrl.startsWith('@') ? customUrl : '@' + customUrl.replace(/^@/, '')) : '';
        var desc = String(acc.channelDescription || '').trim();
        var descShort = desc.length > 160 ? desc.slice(0, 160) + '…' : desc;
        var syncedAt = sliceTime(acc.channelStatsSyncedAt);
        if (syncedAt === '-') syncedAt = '尚未同步';
        var subLabel = acc.hiddenSubscriberCount ? '已隐藏' : ytFmtNum(acc.subscriberCount);
        var avatar = acc.channelThumbUrl
          ? h('img', { class: 'yt-ch-avatar', src: acc.channelThumbUrl, alt: '' })
          : h('div', { class: 'yt-ch-avatar yt-ch-avatar--ph' }, (title.replace(/^@/, '').slice(0, 1) || 'Y').toUpperCase());

        return h('div', { class: 'vd-company-page yt-company-page yt-accounts-page yt-panel-page', vLoading: self.loading }, [
          h('div', { class: 'panel' }, [
            panelHead('数据面板', h('div', { class: 'vd-company-filters yt-accounts-toolbar' }, [
              h('div', { class: 'left' }, [
                h(ElementPlus.ElButton, {
                  onClick: function () { self.backToList(); }
                }, function () { return '← 返回账号'; }),
                h('span', { class: 'muted' }, '数据面板 · ' + title),
                acc.authorized
                  ? h(ElementPlus.ElTag, { type: 'success', size: 'small' }, function () { return '已授权'; })
                  : h(ElementPlus.ElTag, { type: 'info', size: 'small' }, function () { return '未授权'; })
              ]),
              h('div', { class: 'right' }, [
                h(ElementPlus.ElInput, {
                  modelValue: self.panelKeyword,
                  'onUpdate:modelValue': function (v) { self.panelKeyword = v; },
                  placeholder: '标题/视频ID',
                  clearable: true,
                  style: 'width:160px',
                  onKeyup: function (e) { if (e.key === 'Enter') self.searchPanel(); }
                }),
                h(ElementPlus.ElButton, { onClick: function () { self.searchPanel(); } }, function () { return '搜索'; }),
                h(ElementPlus.ElButton, {
                  type: 'primary',
                  loading: self.pulling,
                  disabled: !acc.authorized,
                  onClick: function () { self.pullVideos(); }
                }, function () { return '拉取列表'; }),
                h(ElementPlus.ElButton, { onClick: function () { self.loadPanel(); } }, function () { return '刷新'; })
              ])
            ])),
            h('section', { class: 'yt-ch-card' }, [
              h('div', { class: 'yt-ch-card__main' }, [
                avatar,
                h('div', { class: 'yt-ch-card__info' }, [
                  h('div', { class: 'yt-ch-card__title-row' }, [
                    h('h3', { class: 'yt-ch-card__title' }, title),
                    acc.authorized
                      ? h('span', { class: 'yt-chip yt-chip--ok' }, '已授权')
                      : h('span', { class: 'yt-chip yt-chip--warn' }, '未授权')
                  ]),
                  h('div', { class: 'yt-ch-card__meta' }, [
                    handle ? h('span', handle) : null,
                    acc.channelId
                      ? h('span', { class: 'is-mono' }, acc.channelId)
                      : h('span', { class: 'muted' }, '暂无 Channel ID'),
                    acc.channelCountry ? h('span', acc.channelCountry) : null,
                    acc.channelPublishedAt
                      ? h('span', '开播 ' + String(acc.channelPublishedAt).slice(0, 10))
                      : null
                  ]),
                  descShort
                    ? h('p', { class: 'yt-ch-card__desc', title: desc }, descShort)
                    : h('p', { class: 'yt-ch-card__desc muted' }, '暂无频道简介，点击右侧拉取基础数据')
                ]),
                h('div', { class: 'yt-ch-card__actions' }, [
                  h(ElementPlus.ElButton, {
                    type: 'primary',
                    size: 'small',
                    loading: self.pulling,
                    disabled: !acc.authorized,
                    onClick: function () { self.pullStats(); }
                  }, function () { return '拉取基础数据'; }),
                  h('div', { class: 'yt-ch-card__sync muted' }, '同步于 ' + syncedAt)
                ])
              ]),
              h('div', { class: 'yt-ch-metrics' }, [
                h('div', { class: 'yt-ch-metric' }, [h('em', '订阅者'), h('b', String(subLabel))]),
                h('div', { class: 'yt-ch-metric' }, [h('em', '总浏览'), h('b', ytFmtNum(acc.channelViewCount))]),
                h('div', { class: 'yt-ch-metric' }, [h('em', '视频数'), h('b', ytFmtNum(acc.channelVideoCount))]),
                h('div', { class: 'yt-ch-metric' }, [h('em', '本地内容'), h('b', ytFmtNum(self.panelTotal))])
              ])
            ]),
            h(ElementPlus.ElTable, {
              data: self.panelVideos,
              stripe: true,
              style: 'width:100%;margin-top:8px',
              emptyText: '暂无内容，点击「拉取列表」从 YouTube 同步'
            }, {
              default: function () {
                return [
                  h(ElementPlus.ElTableColumn, { label: '封面', width: 90 }, {
                    default: function (scope) {
                      var v = scope.row;
                      return v.thumbnailUrl
                        ? h('img', {
                          src: v.thumbnailUrl,
                          alt: '',
                          style: 'width:72px;height:40px;object-fit:cover;border-radius:6px;background:#0f172a'
                        })
                        : h('span', { class: 'muted' }, '-');
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '标题', minWidth: 220 }, {
                    default: function (scope) {
                      var v = scope.row;
                      return h('div', [
                        h('b', v.title || '-'),
                        h('div', { class: 'muted', style: 'font-size:12px' },
                          (v.youtubeVideoId || '') + ' · ' + (v.privacyStatus || ''))
                      ]);
                    }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '时长', width: 80 }, {
                    default: function (scope) { return ytFmtDuration(scope.row.durationSeconds); }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '浏览', width: 90 }, {
                    default: function (scope) { return ytFmtNum(scope.row.viewCount); }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '点赞', width: 80 }, {
                    default: function (scope) { return ytFmtNum(scope.row.likeCount); }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '评论', width: 80 }, {
                    default: function (scope) { return ytFmtNum(scope.row.commentCount); }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '发布时间', width: 140 }, {
                    default: function (scope) { return sliceTime(scope.row.publishedAt); }
                  }),
                  h(ElementPlus.ElTableColumn, { label: '操作', width: 200, fixed: 'right' }, {
                    default: function (scope) {
                      var v = scope.row;
                      return h('div', { class: 'vd-company-ops', style: 'white-space:nowrap' }, [
                        h(ElementPlus.ElButton, {
                          size: 'small',
                          link: true,
                          onClick: function () { self.openVideo(v); }
                        }, function () { return '详情'; }),
                        v.watchUrl
                          ? h('a', {
                            class: 'el-button el-button--small is-link',
                            href: v.watchUrl,
                            target: '_blank',
                            rel: 'noopener',
                            style: 'margin-left:8px'
                          }, '打开')
                          : null,
                        h(ElementPlus.ElButton, {
                          size: 'small',
                          link: true,
                          type: 'danger',
                          onClick: function () { self.deleteVideo(v, false); }
                        }, function () { return '删除'; })
                      ]);
                    }
                  })
                ];
              }
            }),
            h('div', {
              class: 'pager',
              style: 'margin-top:12px;display:flex;justify-content:center;align-items:center;gap:12px'
            }, [
              h(ElementPlus.ElButton, {
                size: 'small',
                disabled: self.panelPage <= 1,
                onClick: function () {
                  self.panelPage = Math.max(1, self.panelPage - 1);
                  self.loadPanel();
                }
              }, function () { return '上一页'; }),
              h('span', { class: 'muted' }, self.panelPage + ' / ' + self.panelPages + ' · 共 ' + self.panelTotal),
              h(ElementPlus.ElButton, {
                size: 'small',
                disabled: self.panelPage >= self.panelPages,
                onClick: function () {
                  self.panelPage = self.panelPage + 1;
                  self.loadPanel();
                }
              }, function () { return '下一页'; })
            ])
          ])
        ]);
      },
      renderVideoDetail: function () {
        var self = this;
        var acc = self.videoAccount || self.panelAccount || {};
        var v = self.videoDetail || {};
        var accountId = self.panelAccountId;
        var videoId = self.panelVideoId;
        var channelLabel = acc.channelTitle || acc.nickname || acc.username || ('账号 #' + accountId);
        var privacy = String(v.privacyStatus || '').toLowerCase();
        var privacyTone = privacy === 'public' ? 'ok' : (privacy === 'unlisted' ? 'warn' : 'muted');
        var privacyLabel = privacy === 'public' ? '公开'
          : (privacy === 'unlisted' ? '不公开列出'
            : (privacy === 'private' ? '私密' : (v.privacyStatus || '未知')));
        var tags = (v.tags || []).filter(Boolean);
        var tagNodes = tags.length
          ? tags.slice(0, 12).map(function (t) { return h('span', { class: 'yt-tag' }, t); })
          : [h('span', { class: 'muted' }, '无标签')];

        var comments = self.comments || [];
        var creatorTops = comments.filter(function (c) { return !!c.isCreatorComment; });
        var audienceTops = comments.filter(function (c) { return !c.isCreatorComment; });
        var pendingCount = audienceTops.filter(function (c) { return !c.creatorReplied; }).length;

        var commentHtml;
        if (comments.length) {
          commentHtml = [
            h('div', { class: 'yt-cmt-section' }, [
              h('div', { class: 'yt-cmt-section__head' }, [
                h('h4', '观众评论'),
                h('span', { class: 'muted' }, audienceTops.length + ' 条 · 未回复 ' + pendingCount)
              ]),
              h('div', { class: 'yt-cmt-section__list' },
                audienceTops.length
                  ? audienceTops.map(function (c) { return self.renderCmtTop(c); })
                  : [h('div', { class: 'yt-cmt-empty yt-cmt-empty--sm' }, [h('div', { class: 'muted' }, '暂无观众顶层评论')])]
              )
            ]),
            h('div', { class: 'yt-cmt-section' }, [
              h('div', { class: 'yt-cmt-section__head' }, [
                h('h4', '创作者评论'),
                h('span', { class: 'muted' }, creatorTops.length + ' 条')
              ]),
              h('div', { class: 'yt-cmt-section__list' },
                creatorTops.length
                  ? creatorTops.map(function (c) { return self.renderCmtTop(c); })
                  : [h('div', { class: 'yt-cmt-empty yt-cmt-empty--sm' }, [h('div', { class: 'muted' }, '暂无创作者顶层评论')])]
              )
            ])
          ];
        } else {
          commentHtml = [
            h('div', { class: 'yt-cmt-empty' }, [
              h('div', { class: 'yt-cmt-empty__title' }, '暂无评论'),
              h('div', { class: 'muted' }, '点击右上角「拉取评论」从 YouTube 同步')
            ])
          ];
        }

        var rc = self.replyComment || {};

        return h('div', { class: 'yt-vd-page', vLoading: self.loading }, [
          h('div', { class: 'yt-vd-topbar' }, [
            h('div', { class: 'yt-vd-topbar__left' }, [
              h(ElementPlus.ElButton, {
                size: 'small',
                onClick: function () { self.backToPanel(); }
              }, function () { return '← 返回列表'; }),
              h('div', { class: 'yt-vd-crumb' }, [
                h('span', { class: 'yt-vd-crumb__ch' }, channelLabel),
                h('span', { class: 'yt-vd-crumb__sep' }, '/'),
                h('span', {
                  class: 'yt-vd-crumb__title',
                  title: v.title || ''
                }, v.title || ('内容 #' + videoId))
              ])
            ]),
            h('div', { class: 'yt-vd-topbar__right' }, [
              v.watchUrl
                ? h('a', {
                  class: 'el-button el-button--small',
                  href: v.watchUrl,
                  target: '_blank',
                  rel: 'noopener'
                }, 'YouTube 打开')
                : null,
              h(ElementPlus.ElButton, {
                size: 'small',
                loading: self.pulling,
                onClick: function () { self.pullComments(); }
              }, function () { return '拉取评论'; }),
              h(ElementPlus.ElButton, {
                size: 'small',
                onClick: function () { self.loadVideoDetail(); }
              }, function () { return '刷新'; }),
              h(ElementPlus.ElButton, {
                size: 'small',
                type: 'danger',
                onClick: function () { self.deleteVideo(v, true); }
              }, function () { return '删除视频'; })
            ])
          ]),
          h('section', { class: 'yt-vd-hero' }, [
            h('div', { class: 'yt-vd-hero__media' }, [
              v.thumbnailUrl
                ? h('img', { src: v.thumbnailUrl, alt: '' })
                : h('div', { class: 'yt-vd-hero__ph' }, '暂无封面'),
              h('span', { class: 'yt-vd-duration' }, ytFmtDuration(v.durationSeconds)),
              v.watchUrl
                ? h('a', {
                  class: 'yt-vd-play',
                  href: v.watchUrl,
                  target: '_blank',
                  rel: 'noopener',
                  title: '在 YouTube 打开'
                }, '▶')
                : null
            ]),
            h('div', { class: 'yt-vd-hero__body' }, [
              h('div', { class: 'yt-vd-badges' }, [
                h('span', { class: 'yt-chip yt-chip--' + privacyTone }, privacyLabel),
                v.status ? h('span', { class: 'yt-chip' }, v.status) : null,
                v.uploadStatus ? h('span', { class: 'yt-chip yt-chip--soft' }, v.uploadStatus) : null,
                h('span', { class: 'yt-chip yt-chip--soft' }, '#' + (v.id || videoId))
              ]),
              h('h2', { class: 'yt-vd-title' }, v.title || '未命名内容'),
              h('div', { class: 'yt-vd-sub' }, [
                h('span', channelLabel),
                v.publishedAt ? h('span', '· 发布 ' + sliceTime(v.publishedAt)) : null,
                v.youtubeVideoId ? h('span', { class: 'is-mono' }, '· ' + v.youtubeVideoId) : null
              ]),
              h('div', { class: 'yt-vd-metrics' }, [
                h('div', { class: 'yt-metric' }, [h('em', '浏览'), h('b', ytFmtNum(v.viewCount))]),
                h('div', { class: 'yt-metric' }, [h('em', '点赞'), h('b', ytFmtNum(v.likeCount))]),
                h('div', { class: 'yt-metric' }, [h('em', '评论'), h('b', ytFmtNum(v.commentCount))]),
                h('div', { class: 'yt-metric' }, [h('em', '收藏'), h('b', ytFmtNum(v.favoriteCount))])
              ]),
              h('div', { class: 'yt-vd-desc' }, [
                h('div', { class: 'yt-vd-desc__label' }, '描述'),
                h('div', { class: 'yt-vd-desc__text' }, v.description || '暂无描述')
              ]),
              h('div', { class: 'yt-vd-tags' }, tagNodes)
            ])
          ]),
          h('div', { class: 'yt-vd-layout' }, [
            h('div', { class: 'yt-vd-col-main' }, [
              h('section', { class: 'yt-vd-card' }, [
                h('header', { class: 'yt-vd-card__head' }, [h('h3', '内容信息')]),
                h('div', { class: 'yt-meta-grid' }, self.metaRows([
                  ['本地 ID', v.id],
                  ['YouTube Video ID', v.youtubeVideoId, { mono: true }],
                  ['频道 ID', v.channelId, { mono: true }],
                  ['分类', v.categoryId || '-'],
                  ['时长', ytFmtDuration(v.durationSeconds) + (v.durationIso ? ' (' + v.durationIso + ')' : '')],
                  ['可嵌入', v.embeddable ? '是' : '否'],
                  ['面向儿童', v.madeForKids ? '是' : '否'],
                  ['定时发布', v.publishAt || '-'],
                  ['最近同步', v.lastSyncedAt || '-'],
                  ['创建时间', v.createdAt || '-'],
                  ['更新时间', v.updatedAt || '-'],
                  ['观看链接', v.watchUrl || '-', v.watchUrl ? { href: v.watchUrl, mono: true } : { mono: true }]
                ]))
              ])
            ]),
            h('aside', { class: 'yt-vd-col-side' }, [
              h('section', { class: 'yt-vd-card yt-vd-card--comments' }, [
                h('header', { class: 'yt-vd-card__head' }, [
                  h('div', [
                    h('h3', '评论'),
                    h('p', { class: 'muted' }, '共 ' + comments.length + ' 条顶层评论')
                  ]),
                  h(ElementPlus.ElButton, {
                    size: 'small',
                    loading: self.pulling || self.commentsLoading,
                    onClick: function () { self.pullComments(); }
                  }, function () { return '同步'; })
                ]),
                h('div', { class: 'yt-cmt-list' }, commentHtml)
              ])
            ])
          ]),
          h(ElementPlus.ElDialog, {
            modelValue: self.replyVisible,
            'onUpdate:modelValue': function (val) { self.replyVisible = val; },
            title: '回复 YouTube 评论',
            width: '560px',
            destroyOnClose: true
          }, {
            default: function () {
              return h('div', { class: 'yt-reply-modal' }, [
                h('div', { class: 'yt-reply-modal__origin' }, [
                  h('div', { class: 'yt-reply-modal__origin-head' }, [
                    self.cmtAvatar(rc.authorDisplayName, rc.authorProfileImageUrl),
                    h('div', [
                      h('div', { class: 'yt-reply-modal__author' }, rc.authorDisplayName || '用户'),
                      h('div', { class: 'muted', style: 'font-size:12px' }, sliceTime(rc.publishedAt))
                    ])
                  ]),
                  h('div', { class: 'yt-reply-modal__origin-body' }, rc.textDisplay || rc.textOriginal || '')
                ]),
                h('div', { class: 'yt-reply-modal__form' }, [
                  h('div', { style: 'margin-bottom:10px' }, [
                    h('div', { style: 'font-size:13px;font-weight:600;margin-bottom:6px' }, 'AI 提示词'),
                    h('div', { class: 'yt-reply-modal__tpl-row' }, [
                      h('span', { class: 'muted', style: 'flex:1;font-size:12px' }, '默认：YouTube 评论回复'),
                      h(ElementPlus.ElButton, {
                        type: 'primary',
                        size: 'small',
                        loading: self.replyAiLoading,
                        onClick: function () { self.generateAiReply(); }
                      }, function () { return 'AI 生成'; })
                    ]),
                    h('div', { class: 'muted', style: 'margin-top:6px;font-size:12px' },
                      '使用「YouTube 评论回复」提示词；也可自行改下方内容后发送')
                  ]),
                  h('div', [
                    h('div', { style: 'font-size:13px;font-weight:600;margin-bottom:6px' }, '回复内容 *'),
                    h(ElementPlus.ElInput, {
                      modelValue: self.replyText,
                      'onUpdate:modelValue': function (val) { self.replyText = val; },
                      type: 'textarea',
                      rows: 6,
                      placeholder: '可手动输入，或点击「AI 生成」后编辑',
                      id: 'yt-reply-text'
                    }),
                    h('div', { class: 'yt-reply-modal__meta' }, [
                      h('span', { class: 'muted' }, self.replyAiStatus || ''),
                      h('span', { class: 'muted' }, String(self.replyText || '').trim().length + ' 字')
                    ])
                  ])
                ])
              ]);
            },
            footer: function () {
              return [
                h(ElementPlus.ElButton, {
                  onClick: function () { self.replyVisible = false; }
                }, function () { return '取消'; }),
                h(ElementPlus.ElButton, {
                  type: 'primary',
                  loading: self.replySaving,
                  onClick: function () { self.submitReply(); }
                }, function () { return '发送回复'; })
              ];
            }
          })
        ]);
      }
    },
    render: function () {
      if (this.pane === 'videos') return this.renderPanel();
      if (this.pane === 'video') return this.renderVideoDetail();
      return this.renderList();
    }
  };

  global.CompanyYoutubeAccountsView = YoutubeAccountsView;
})(window);
