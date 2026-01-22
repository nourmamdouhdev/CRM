    </main>
  </div>

  <script>
    const sidebar = document.getElementById('sidebar');
    const btn = document.getElementById('toggleSidebar');

    // restore state
    if (localStorage.getItem('sidebar') === 'min') {
      sidebar.classList.add('min');
      sidebar.style.width = '80px';
    }

    btn?.addEventListener('click', () => {
      if (sidebar.classList.contains('min')) {
        sidebar.classList.remove('min');
        sidebar.style.width = '270px';
        localStorage.setItem('sidebar', 'full');
      } else {
        sidebar.classList.add('min');
        sidebar.style.width = '80px';
        localStorage.setItem('sidebar', 'min');
      }
    });
  </script>
</body>
</html>
