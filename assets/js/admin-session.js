(function () {
  const idleTimeout = 30 * 60 * 1000;
  let logoutTimer;

  function scheduleLogout() {
    window.clearTimeout(logoutTimer);
    logoutTimer = window.setTimeout(() => {
      window.location.replace('logout.php');
    }, idleTimeout);
  }

  ['click', 'keydown', 'pointerdown', 'touchstart', 'scroll'].forEach((eventName) => {
    window.addEventListener(eventName, scheduleLogout, { passive: true });
  });

  scheduleLogout();
})();
