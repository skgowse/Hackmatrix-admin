    </main>
</div>

<script>
    // Responsive Mobile Sidebar Toggle
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function(e) {
            sidebar.classList.toggle('active');
            e.stopPropagation();
        });
        
        // Close sidebar on tapping outside (mobile)
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 1024 && !sidebar.contains(e.target) && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });
    }

    // Dynamic Toast Notification helper
    function showToast(message, type = 'success') {
        // Remove existing toast if any
        const existingToast = document.querySelector('.toast-notification');
        if (existingToast) {
            existingToast.remove();
        }
        
        const toast = document.createElement('div');
        toast.className = `toast-notification toast-${type}`;
        toast.style.cssText = `
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 16px 24px;
            border-radius: 12px;
            color: white;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5);
            z-index: 9999;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
        `;
        
        let bgColor = '#10b981'; // success
        if (type === 'danger' || type === 'error') bgColor = '#ef4444';
        if (type === 'warning') bgColor = '#f59e0b';
        if (type === 'info') bgColor = '#3b82f6';
        
        toast.style.backgroundColor = bgColor;
        toast.innerHTML = message;
        
        document.body.appendChild(toast);
        
        // Animate in
        setTimeout(() => {
            toast.style.transform = 'translateY(0)';
            toast.style.opacity = '1';
        }, 50);
        
        // Auto remove
        setTimeout(() => {
            toast.style.transform = 'translateY(100px)';
            toast.style.opacity = '0';
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 4000);
    }

    // Hide menu bar on scroll down, show on scroll up
    let lastScrollTop = 0;
    const menuBar = document.querySelector('.menu-toggle-bar');
    
    if (menuBar) {
        window.addEventListener('scroll', function() {
            let scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            
            if (scrollTop > 50) {
                if (scrollTop > lastScrollTop) {
                    // Scrolling down - hide menu bar
                    menuBar.classList.add('scroll-hidden');
                } else {
                    // Scrolling up - show menu bar
                    menuBar.classList.remove('scroll-hidden');
                }
            } else {
                // Near the top - always show
                menuBar.classList.remove('scroll-hidden');
            }
            lastScrollTop = scrollTop <= 0 ? 0 : scrollTop;
        }, { passive: true });
    }
</script>
</body>
</html>
