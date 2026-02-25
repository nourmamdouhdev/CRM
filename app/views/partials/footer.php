    </main>
  </div>

  <script>
    const sidebar = document.getElementById('sidebar');
    const btn = document.getElementById('toggleSidebar');

    if (sidebar && localStorage.getItem('sidebar') === 'min') {
      sidebar.classList.add('min');
    }

    btn?.addEventListener('click', () => {
      if (!sidebar) return;
      sidebar.classList.toggle('min');
      localStorage.setItem('sidebar', sidebar.classList.contains('min') ? 'min' : 'full');
    });
  </script>
</body>
</html>
