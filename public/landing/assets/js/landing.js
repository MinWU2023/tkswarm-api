(function () {
  'use strict';

  var DEFAULT_WIN = '/lastest.exe';
  var DEFAULT_MAC = '/lastest.dmg';

  function detectPlatform() {
    var ua = navigator.userAgent || '';
    if (/Mac|iPhone|iPad|iPod/i.test(ua)) return 'mac';
    return 'win';
  }

  var metaEl = document.getElementById('latest-meta');
  var hintEl = document.getElementById('env-hint');
  var btnWin = document.getElementById('btn-download-win');
  var btnMac = document.getElementById('btn-download-mac');
  var heroWin = document.getElementById('hero-download-win');
  var heroMac = document.getElementById('hero-download-mac');
  var carouselWin = document.getElementById('carousel-download-win');
  var carouselMac = document.getElementById('carousel-download-mac');

  function applyDownloadUrls(winUrl, macUrl) {
    var win = winUrl || DEFAULT_WIN;
    var mac = macUrl || DEFAULT_MAC;
    [btnWin, heroWin, carouselWin].forEach(function (el) {
      if (el) el.href = win;
    });
    [btnMac, heroMac, carouselMac].forEach(function (el) {
      if (el) el.href = mac;
    });
  }

  function highlightPlatform(platform) {
    var isMac = platform === 'mac';
    if (metaEl) {
      metaEl.textContent = isMac
        ? '已识别为 macOS · 推荐下载 .dmg'
        : '已识别为 Windows · 推荐下载 .exe';
    }
    if (hintEl) {
      hintEl.textContent = isMac
        ? '当前为 Mac，可优先下载 macOS 安装包；也可另存 Windows 版。'
        : '当前为 Windows，可优先下载 exe 安装包；也可另存 macOS 版。';
    }
    var winBtns = [btnWin, heroWin, carouselWin];
    var macBtns = [btnMac, heroMac, carouselMac];
    winBtns.forEach(function (el) {
      if (!el) return;
      el.classList.toggle('primary', !isMac);
      el.classList.toggle('ghost', isMac);
    });
    macBtns.forEach(function (el) {
      if (!el) return;
      el.classList.toggle('primary', isMac);
      el.classList.toggle('ghost', !isMac);
    });
  }

  function loadSiteConfig() {
    return fetch('/api/public/site-config', { headers: { Accept: 'application/json' } })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        var ok = json && (json.success === true || json.code === 0 || json.code === 200);
        var data = ok ? (json.data || json) : {};
        applyDownloadUrls(data.landingDownloadWinUrl, data.landingDownloadMacUrl);
      })
      .catch(function () {
        applyDownloadUrls(DEFAULT_WIN, DEFAULT_MAC);
      })
      .then(function () {
        highlightPlatform(detectPlatform());
      });
  }

  loadSiteConfig();

  // 移动端导航
  var toggle = document.getElementById('nav-toggle');
  var links = document.getElementById('nav-links');
  if (toggle && links) {
    toggle.addEventListener('click', function () {
      links.classList.toggle('is-open');
    });
    links.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        links.classList.remove('is-open');
      });
    });
  }

  // 当前锚点高亮
  var sections = ['capabilities', 'cando', 'matrix', 'workflow', 'scenarios', 'download', 'contact'];
  function updateActiveNav() {
    var y = window.scrollY + 120;
    var current = '';
    sections.forEach(function (id) {
      var el = document.getElementById(id);
      if (el && el.offsetTop <= y) current = id;
    });
    if (!links) return;
    links.querySelectorAll('a').forEach(function (a) {
      var href = a.getAttribute('href') || '';
      a.classList.toggle('is-active', href === '#' + current);
    });
  }
  window.addEventListener('scroll', updateActiveNav, { passive: true });
  updateActiveNav();

  // Hero 卖点轮播
  var tickerLines = Array.prototype.slice.call(document.querySelectorAll('.ticker-line'));
  if (tickerLines.length > 1) {
    var tickerIdx = 0;
    setInterval(function () {
      tickerLines[tickerIdx].classList.remove('is-on');
      tickerIdx = (tickerIdx + 1) % tickerLines.length;
      tickerLines[tickerIdx].classList.add('is-on');
    }, 3200);
  }

  // Hero 轮播图
  var carousel = document.getElementById('hero-carousel');
  if (carousel) {
    var track = document.getElementById('carousel-track');
    var slideEls = track ? Array.prototype.slice.call(track.children) : [];
    var dotBox = carousel.querySelector('.carousel-dots');
    var prevBtn = carousel.querySelector('.carousel-nav.prev');
    var nextBtn = carousel.querySelector('.carousel-nav.next');
    var cur = 0;
    var timer = null;
    var AUTOPLAY = 5000;
    var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function paint() {
      if (track) track.style.transform = 'translateX(' + (-cur * 100) + '%)';
      if (dotBox) {
        Array.prototype.forEach.call(dotBox.children, function (dot, i) {
          dot.classList.toggle('is-on', i === cur);
          dot.setAttribute('aria-selected', i === cur ? 'true' : 'false');
        });
      }
      slideEls.forEach(function (slide, i) {
        slide.setAttribute('aria-hidden', i === cur ? 'false' : 'true');
      });
    }

    function goTo(i) {
      var n = slideEls.length;
      if (!n) return;
      cur = ((i % n) + n) % n;
      paint();
    }

    function stopAuto() {
      if (timer) { clearInterval(timer); timer = null; }
    }

    function startAuto() {
      if (reduceMotion || slideEls.length < 2) return;
      stopAuto();
      timer = setInterval(function () { goTo(cur + 1); }, AUTOPLAY);
    }

    if (dotBox) {
      slideEls.forEach(function (_, i) {
        var dot = document.createElement('button');
        dot.type = 'button';
        dot.setAttribute('role', 'tab');
        dot.setAttribute('aria-label', '第 ' + (i + 1) + ' 张');
        dot.addEventListener('click', function () { goTo(i); startAuto(); });
        dotBox.appendChild(dot);
      });
    }

    if (prevBtn) prevBtn.addEventListener('click', function () { goTo(cur - 1); startAuto(); });
    if (nextBtn) nextBtn.addEventListener('click', function () { goTo(cur + 1); startAuto(); });

    carousel.addEventListener('mouseenter', stopAuto);
    carousel.addEventListener('mouseleave', startAuto);
    carousel.addEventListener('focusin', stopAuto);
    carousel.addEventListener('focusout', startAuto);

    var touchX = 0;
    carousel.addEventListener('touchstart', function (e) {
      touchX = e.touches[0].clientX;
      stopAuto();
    }, { passive: true });
    carousel.addEventListener('touchend', function (e) {
      var dx = e.changedTouches[0].clientX - touchX;
      if (Math.abs(dx) > 40) goTo(cur + (dx < 0 ? 1 : -1));
      startAuto();
    });
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stopAuto(); else startAuto();
    });

    goTo(0);
    startAuto();
  }

  // 区块入场
  var revealEls = Array.prototype.slice.call(document.querySelectorAll('.block, .hero-carousel, .cap-gallery'));
  if ('IntersectionObserver' in window && revealEls.length) {
    revealEls.forEach(function (el) { el.classList.add('reveal'); });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
    revealEls.forEach(function (el) { io.observe(el); });
  }

  // 能力 Tab
  document.querySelectorAll('[data-tabs]').forEach(function (root) {
    var buttons = Array.prototype.slice.call(root.querySelectorAll('[data-tab]'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-panel]'));
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-tab');
        buttons.forEach(function (b) { b.classList.toggle('is-on', b === btn); });
        panels.forEach(function (p) {
          p.classList.toggle('is-on', p.getAttribute('data-panel') === key);
        });
      });
    });
  });

  // 联系表单
  var form = document.getElementById('contact-form');
  var statusEl = document.getElementById('contact-status');
  var submitBtn = document.getElementById('contact-submit');

  function setStatus(msg, ok) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.classList.remove('is-ok', 'is-err');
    if (msg) statusEl.classList.add(ok ? 'is-ok' : 'is-err');
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      var payload = {
        name: String(fd.get('name') || '').trim(),
        company: String(fd.get('company') || '').trim(),
        phone: String(fd.get('phone') || '').trim(),
        email: String(fd.get('email') || '').trim(),
        accountScale: String(fd.get('accountScale') || '').trim(),
        message: String(fd.get('message') || '').trim(),
        source: 'landing',
        pageUrl: location.href
      };
      if (!payload.name || !payload.phone || !payload.message) {
        setStatus('请填写姓名、联系方式与需求说明', false);
        return;
      }
      if (submitBtn) submitBtn.disabled = true;
      setStatus('提交中…', true);
      fetch('/api/contact-inquiries', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload)
      })
        .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, j: j }; }); })
        .then(function (pack) {
          var ok = pack.ok && (pack.j.success === true || pack.j.code === 0 || pack.j.code === 200);
          if (!ok) throw new Error((pack.j && pack.j.message) || '提交失败，请稍后重试');
          setStatus((pack.j.data && pack.j.data.message) || pack.j.message || '提交成功，我们会尽快联系您', true);
          form.reset();
        })
        .catch(function (err) {
          setStatus(err.message || '网络异常，请稍后重试', false);
        })
        .finally(function () {
          if (submitBtn) submitBtn.disabled = false;
        });
    });
  }
})();
