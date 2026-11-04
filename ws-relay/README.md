# TkSwarm 租约 WSS 中继

占用方登录并上传环境包、释放占用后，PHP 通知本中继，中继只推给 FIFO 队首的主机。
node / linuxbrowse 主动连入，被动接收，不再长挂请求。

## 部署（与 PHP 同机）

```bash
cd ws-relay && npm install --omit=dev
TKSWARM_RELAY_SECRET=换成随机长串 node server.js   # 默认监听 127.0.0.1:8998
```

建议用 systemd / pm2 常驻。

## Nginx 反代（在 vhost 的 server 里加）

```nginx
location /ws-relay {
    proxy_pass http://127.0.0.1:8998/ws;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
}
```

对外用 HTTPS 站点时即为 `wss://你的域名/ws-relay`。

## PHP 侧配置（二选一）

**推荐（宝塔）**：在 `ws-relay/.env` 写配置，PHP 会自动读取（不依赖 php-fpm 传 env）：

```bash
cp /www/wwwroot/tkswarm-api/ws-relay/.env.example /www/wwwroot/tkswarm-api/ws-relay/.env
# 编辑 .env，SECRET 与 pm2 中继一致
```

也可仍用 php-fpm `env[...]`（须关闭 clear_env）：

| 变量 | 说明 |
| --- | --- |
| `TKSWARM_RELAY_SECRET` | 与中继相同；签发连接票据、校验 /notify |
| `TKSWARM_RELAY_NOTIFY_URL` | 默认 `http://127.0.0.1:8998/notify` |
| `TKSWARM_RELAY_PUBLIC_URL` | 客户端连接地址，如 `wss://tkswarm-api.dyyweb.com/ws-relay` |

## 客户端（node / linuxbrowse）

不需要额外配置，会通过 `/api/node/pack-relay/ticket` 换票据并取得 PHP 下发的地址。
也可用 `TKSWARM_RELAY_URL` 在本机覆盖。
只有 WSS 一条路：旧的 `/pack-lease/wait` 服务端长请求已删除。中继未部署或连不上时，等待方会直接报错"等待环境释放失败：WSS 中继不可用"，不会再退回挂起等待。

## 行为说明

- 队列顺序以数据库 `pack_wait_queue` 为准；中继不存队列。
- 只推队首；其余等待方等下一次释放。
- 断线重连后会重新入队补漏；占用方崩溃未释放时，在租约到期时刻再入队一次。
