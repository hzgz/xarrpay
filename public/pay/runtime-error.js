(function () {
  'use strict';

  window.addEventListener('unhandledrejection', function (event) {
    var reason = event && event.reason ? event.reason : {};
    var config = reason.config || {};
    var url = String(config.url || '');
    if (url.indexOf('/api/order/qrcode') === -1) {
      return;
    }

    event.preventDefault();
    var response = reason.response || {};
    var data = response.data || {};
    var message = String(data.message || reason.message || '获取支付信息失败');
    var notice = document.getElementById('xarr-pay-error-notice');
    if (!notice) {
      notice = document.createElement('div');
      notice.id = 'xarr-pay-error-notice';
      notice.style.cssText = [
        'position:fixed',
        'left:50%',
        'top:24px',
        'z-index:9999',
        'max-width:calc(100vw - 32px)',
        'padding:12px 18px',
        'transform:translateX(-50%)',
        'border:1px solid #fbc4c4',
        'border-radius:4px',
        'background:#fef0f0',
        'color:#f56c6c',
        'font-size:14px',
        'line-height:1.5',
        'box-shadow:0 4px 12px rgba(0,0,0,.08)',
      ].join(';');
      document.body.appendChild(notice);
    }
    notice.textContent = message;
  });
})();
