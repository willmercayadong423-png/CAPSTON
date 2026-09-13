// Apply dark mode immediately before render (avoids flash)
(function () {
    if (localStorage.getItem('hehms-theme') === 'dark')
        document.documentElement.setAttribute('data-theme', 'dark');
})();
 