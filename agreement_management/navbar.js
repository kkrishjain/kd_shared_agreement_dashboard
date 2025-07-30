// Navbar Toggle - MODIFIED
function toggleNav() {
    const navbar = document.querySelector('.navbar');
    navbar.classList.toggle('active');
    
    // Toggle icon color class
    document.querySelector('.nav-toggle').classList.toggle('active');
}

// Submenu Handling
function toggleSubmenu(e, element) {
    e.preventDefault();
    e.stopPropagation();
    
    // Toggle the clicked item
    element.classList.toggle('active');
    
    // Find the submenu and toggle it
    const submenu = element.nextElementSibling;
    if (submenu) {
        submenu.classList.toggle('active');
    }
    
    // Update the dropdown icon
    const icon = element.querySelector('.dropdown-icon');
    if (icon) {
        icon.style.transform = element.classList.contains('active') 
            ? 'rotate(90deg)'
            : 'rotate(0deg)';
    }
}

// Close navbar when clicking outside
document.addEventListener('click', (e) => {
    const navbar = document.querySelector('.navbar');
    const toggle = e.target.closest('.nav-toggle');
    const insideNavbar = e.target.closest('.navbar');

    if (!insideNavbar && !toggle) {
        navbar.classList.remove('active');
    }
});

// Initialize Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    // Set active states based on current page
    const currentPath = window.location.pathname;
    
    // Add click handlers for parent items
    document.querySelectorAll('.nav-item.parent').forEach(item => {
    item.addEventListener('click', function(e) {
        // Only prevent default if clicking on the parent element itself
        if (e.target === this || !e.target.closest('a')) {
            toggleSubmenu(e, this);
        }
    });
    // Scroll to active item if exists
    const activeItem = document.querySelector('.nav-item.active');
    if (activeItem) {
        // Scroll to active item with some offset
        activeItem.scrollIntoView({ 
            block: 'center',
            behavior: 'auto'
        });
    }
});
    
});