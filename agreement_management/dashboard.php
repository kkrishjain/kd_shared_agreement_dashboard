<!DOCTYPE html>
<html>
<head>
     <link rel="stylesheet" href="/agreement_management/navbar.css">
    <script src="/agreement_management/navbar.js"></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/agreement_management/navbar.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Dashboard-specific styles */
        body {
            background: #fff;
            margin: 0;
            height: 100vh;
            overflow: hidden;
            font-family: Arial, sans-serif;
        }

        .content {
            margin-left: 50px;
            padding: 70px 20px;
            height: 100vh;
            overflow-y: overlay;
            transition: margin-left 0.3s;
            
            scrollbar-width: thin;
            scrollbar-color: #888 #f1f1f1;
        }

        .content::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .content::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
            border: 2px solid #f1f1f1;
        }

        .content::-webkit-scrollbar-thumb:hover {
            background: #666;
        }

        .animation-container {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 90%;
            max-width: 800px;
            z-index: 1;
            pointer-events: none;
        }

        #text-path {
            fill: none;
            stroke: #ff2d55;
            stroke-width: 2;
            stroke-dasharray: 1000;
            stroke-dashoffset: 1000;
            animation: draw 3s linear forwards;
        }

        @keyframes draw { to { stroke-dashoffset: 0; } }

        .loading {
            display: none; /* Important - starts hidden */
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1003;
            color: #3498db; /* Add color for visibility */
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        tr:hover {
            background-color: #f5f5f5;
        }

        .alert {
            padding: 20px;
            background: #ffe7e6;
            color: #d8000c;
            border-radius: 8px;
            margin: 20px;
        }

        @media (max-width: 768px) {
            .content {
                margin-left: 0 !important;
                padding: 90px 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading" id="loading">
        <i class="fas fa-spinner fa-spin fa-3x"></i>
    </div>

    <!-- Include Navbar Component -->

    <!-- Content Area -->
    <div class="content" id="content">
        <!-- Home Animation -->
        <div class="animation-container">
            <svg viewBox="0 0 800 200" preserveAspectRatio="xMidYMid meet">
                <text id="text-path" x="50%" y="60%" font-family="Arial" font-size="120"
                      font-weight="bold" text-anchor="middle" dominant-baseline="middle">
                    INSURANCE
                </text>
            </svg>
        </div>
    </div>

    <script>
        const APP_BASE = '/finqy/agreement_management';

        async function loadContent(url, title) {
            const loading = document.getElementById('loading');
            try {
                // Show loading spinner
                loading.style.display = 'block';
                
                const response = await fetch(url);
                const html = await response.text();
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                const newContent = tempDiv.querySelector('#contentContainer')?.innerHTML || html;
                
                document.getElementById('content').innerHTML = newContent;
                document.title = title;
                window.history.pushState({ url, title }, title, url);
                initDynamicContent();
            } catch (error) {
                console.error('Loading failed:', error);
            } finally {
                // Hide loading spinner regardless of success/error
                loading.style.display = 'none';
            }
        }

        function initDynamicContent() {
            document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.addEventListener('click', handleDelete);
            });
            
            document.getElementById('masterSearch')?.addEventListener('input', handleSearch);
            document.querySelector('form')?.addEventListener('submit', handleFormSubmit);
            initSmoothScroll(document.getElementById('content'));
        }

        function initSmoothScroll(container) {
            container?.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href');
                    const target = document.querySelector(targetId);
                    if (target) {
                        const targetPos = target.getBoundingClientRect().top + container.scrollTop;
                        smoothScrollTo(container, targetPos, 800);
                    }
                });
            });
        }

        function smoothScrollTo(element, to, duration) {
            const start = element.scrollTop;
            const change = to - start;
            const startTime = performance.now();
            
            function animateScroll(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                element.scrollTop = start + change * easeInOutQuad(progress);
                
                if (progress < 1) {
                    requestAnimationFrame(animateScroll);
                }
            }

            function easeInOutQuad(t) {
                return t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;
            }

            requestAnimationFrame(animateScroll);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const text = document.getElementById('text-path');
            if (text) {
                text.style.animation = 'none';
                requestAnimationFrame(() => {
                    text.style.animation = 'draw 3s linear forwards';
                });
            }

            initSmoothScroll(document.getElementById('content'));
        });

        window.addEventListener('popstate', (event) => {
            if (event.state) {
                loadContent(event.state.url, event.state.title);
            }
        });
    </script>
</body>
</html>