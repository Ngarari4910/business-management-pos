(function () {
  const idleTimeout = 30 * 60 * 1000;
  const heartbeatInterval = 60 * 1000;
  const heartbeatUrl = 'session_heartbeat.php';
  let logoutTimer;
  let heartbeatTimer;
  let lastHeartbeatAt = 0;

  function markUserActive() {
    scheduleLogout();
    const now = Date.now();
    if (now - lastHeartbeatAt < 30000) {
      return;
    }
    lastHeartbeatAt = now;
    refreshSessionHeartbeat();
  }

  function refreshSessionHeartbeat() {
    fetch(heartbeatUrl, {
      method: 'GET',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).then((response) => {
      if (!response.ok) {
        throw new Error('Session heartbeat failed');
      }
      return response.json();
    }).then((payload) => {
      if (!payload || payload.ok === false) {
        throw new Error(payload && payload.error ? payload.error : 'Session expired');
      }
      scheduleLogout();
    }).catch(() => {
      window.location.replace('logout.php?return=cashier');
    });
  }

  function scheduleLogout() {
    window.clearTimeout(logoutTimer);
    logoutTimer = window.setTimeout(() => {
      window.location.replace('logout.php?return=cashier');
    }, idleTimeout);
  }

  ['click', 'keydown', 'pointerdown', 'touchstart', 'scroll', 'input', 'change', 'mousemove'].forEach((eventName) => {
    window.addEventListener(eventName, markUserActive, { passive: true });
  });

  if (heartbeatTimer) {
    window.clearInterval(heartbeatTimer);
  }
  refreshSessionHeartbeat();
  heartbeatTimer = window.setInterval(() => {
    lastHeartbeatAt = 0;
    refreshSessionHeartbeat();
  }, heartbeatInterval);
  scheduleLogout();
})();
