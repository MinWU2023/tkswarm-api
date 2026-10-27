@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"

echo ================================================
echo   浏览器环境归属 / 绑定数据维护
echo ================================================
echo.
echo 说明：
echo   - 环境列表来自数据库 account_browser_envs
echo   - 仅在本机浏览器创建、未入库的环境，离线时看不到
echo   - owner_user_id 为环境绑定到哪个登录用户
echo.

where php >nul 2>&1
if errorlevel 1 (
  echo [提示] 未检测到 php 命令，将尝试用浏览器打开维护页...
  echo        请确保 API 站点可访问，例如 http://127.0.0.1/clear-browser-env-owner.php
  start "" "http://127.0.0.1/clear-browser-env-owner.php"
  pause
  exit /b 0
)

echo 当前统计：
php "%~dp0clear-browser-env-owner.php" --stats
echo.
echo 请选择操作（输入数字后回车）：
echo   1 = reset-owner  清空全部 owner_user_id（置 0）
echo   2 = sync-owner   按账号 owner_id 重新同步归属
echo   3 = rebuild      从 accounts 表回填环境绑定
echo   4 = clear-all    清空全部环境绑定（危险）
echo   0 = 仅打开 Web 页面
echo.
set /p CHOICE=请输入:

if "%CHOICE%"=="0" (
  start "" "http://127.0.0.1/clear-browser-env-owner.php"
  pause
  exit /b 0
)
if "%CHOICE%"=="1" set ACTION=reset-owner
if "%CHOICE%"=="2" set ACTION=sync-owner
if "%CHOICE%"=="3" set ACTION=rebuild
if "%CHOICE%"=="4" set ACTION=clear-all

if not defined ACTION (
  echo 无效选择。
  pause
  exit /b 1
)

echo.
echo 将执行：%ACTION%
set /p OK=确认执行？输入 YES 继续:
if /i not "%OK%"=="YES" (
  echo 已取消。
  pause
  exit /b 0
)

php "%~dp0clear-browser-env-owner.php" %ACTION% --confirm
echo.
pause
