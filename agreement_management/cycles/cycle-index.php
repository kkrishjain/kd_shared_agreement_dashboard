<?php
// cycles-index.php
include '../config/database.php';

// Define week and month labels
$weekLabels = [
    'this_week' => 'This Week',
    'next_week' => 'Next Week',
    'next_next_week' => 'Next to Next Week',
];

$monthLabels = [
    'this_month' => 'This Month',
    'next_month' => 'Next Month',
    'next_next_month' => 'Next to Next Month',
];

// Initialize variables
$defaultEntries = 10;
$cycles = [];
$totalPages = 1;
$entriesPerPage = $defaultEntries;

// Handle current page
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, $currentPage);

// Handle entries per page
$validEntries = [10, 25, 50, 100, 'all'];
$entriesPerPage = isset($_GET['entries_per_page']) && (in_array($_GET['entries_per_page'], $validEntries) || $_GET['entries_per_page'] === 'all')
                ? $_GET['entries_per_page']
                : $defaultEntries;

// Calculate offset
if ($entriesPerPage !== 'all') {
    $entriesPerPage = (int)$entriesPerPage;
    $offset = ($currentPage - 1) * $entriesPerPage;
} else {
    $offset = 0;
}

// Handle delete requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $cycleName = '';

    try {
        $stmt = $pdo->prepare("SELECT c_name FROM partner_cycles WHERE cycle_id = ? UNION SELECT c_name FROM partner_custom_cycle WHERE c_id = ?");
        $stmt->execute([$id, $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $cycleName = $result['c_name'] ?? 'Unknown Cycle';

        $stmt1 = $pdo->prepare("DELETE FROM partner_cycles WHERE cycle_id = ?");
        $stmt1->execute([$id]);
        $stmt2 = $pdo->prepare("DELETE FROM partner_custom_cycle WHERE c_id = ?");
        $stmt2->execute([$id]);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => "$cycleName has been deleted", 'id' => $id]);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error deleting cycle']);
        exit;
    }
}

// Get total number of entries
$totalQuery = "SELECT COUNT(*) AS total FROM partner_cycles";

$totalStmt = $pdo->query($totalQuery);
$totalResult = $totalStmt->fetch(PDO::FETCH_ASSOC);
$totalEntries = $totalResult['total'];

// Pagination settings
if ($entriesPerPage === 'all') {
    $totalPages = 1;
} else {
    $totalPages = max(1, ceil($totalEntries / $entriesPerPage));
}

// Main query
$query = "
    SELECT 
        c_name, c_b_start, c_b_end, c_pay_month, c_gst_month, 
        cycle_id AS id, c_type AS type
    FROM partner_cycles
    ORDER BY cycle_id DESC"; // Order by the ID which should represent creation order

if ($entriesPerPage !== 'all') {
    $query .= " LIMIT $entriesPerPage OFFSET $offset";
}

try {
    $stmt = $pdo->query($query);
    $cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <title>Cycles</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="./css/cycle_index_style.css">
    
</head>
<body>
<!-- Include Navbar -->
<?php include '../navbar.php'; ?>

<!-- Content Container -->
<div class="content" id="content">
    <div id="notification" class="notification"></div>

    <div class="container">
        <div class="header">
            <h2>Cycles</h2>
            <a href="cycle-tab.php" class="add-cycle-btn">
                <i class="bi bi-plus-lg"></i> Add Cycle
            </a>
        </div>

        <form method="POST">
            <div class="search-container">
                <input type="text" id="masterSearch" placeholder="Search..."
                    style="width: 300px; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                <select id="searchField" style="padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                    <option value="all">All Fields</option>
                    <option value="0">Cycle Name</option>
                    <option value="1">Business Cycle From</option>
                    <option value="2">Business Cycle To</option>
                    <option value="3">Payment Cycle</option>
                    <option value="4">GST Cycle</option>
                </select>
                <div class="records-control">
                    <div class="limit-selector">
                        <label>Show: 
                            <select id="entriesPerPage" onchange="updateEntriesPerPage(this)" 
                                style="padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                <?php foreach ([10, 25, 50, 100] as $option): ?>
                                <option value="<?= $option ?>" <?= $entriesPerPage == $option ? 'selected' : '' ?>>
                                    <?= $option ?> entries
                                </option>
                                <?php endforeach; ?>
                                <option value="all" <?= $entriesPerPage === 'all' ? 'selected' : '' ?>>
                                    All entries
                                </option>
                            </select>
                        </label>
                    </div>
                    <div class="total-agreements">
                        Total Cycles: <?= $totalEntries ?>
                    </div>
                </div>
            </div>

                                        <div style="overflow-x: auto; max-height: 70vh;">

                        <table border="1" class="agreements-table">
                            <thead>
                                <tr>
                                    <th>Cycle Name</th>
                                    <th>Business Cycle From</th>
                                    <th>Business Cycle To</th>
                                    <th>Payment Cycle</th>
                                    <th>GST Cycle</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cycles as $cycle): ?>
                                <tr>
                                    <td><?= htmlspecialchars($cycle['c_name']) ?></td>
                                    <td><?= htmlspecialchars($cycle['c_b_start']) ?></td>
                                    <td><?= htmlspecialchars($cycle['c_b_end']) ?></td>
                                    <td>
                                        <?php 
                                        // Use human-readable labels for payout cycles
                                        if ($cycle['type'] === 'weekly') {
                                            echo htmlspecialchars($weekLabels[$cycle['c_pay_month']] ?? $cycle['c_pay_month']);
                                        } else {
                                            // Handle monthly and custom cycles
                                            echo htmlspecialchars($monthLabels[$cycle['c_pay_month']] ?? $cycle['c_pay_month']);
                                        }
                                        ?>
                                    </td>
                                    <td><?= htmlspecialchars($monthLabels[$cycle['c_gst_month']] ?? $cycle['c_gst_month']) ?></td>
                                    <td>
                                        <button class="delete-btn" title="Delete" 
                                                data-cycle-name="<?= htmlspecialchars($cycle['c_name']) ?>"
                                                data-id="<?= htmlspecialchars($cycle['id']) ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($currentPage > 1): ?>
        <a href="?page=<?= $currentPage - 1 ?>&entries_per_page=<?= $entriesPerPage ?>">Previous</a>
    <?php endif; ?>

    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?page=<?= $i ?>&entries_per_page=<?= $entriesPerPage ?>" 
        <?= $i == $currentPage ? 'class="active"' : '' ?>>
            <?= $i ?>
        </a>
    <?php endfor; ?>

    <?php if ($currentPage < $totalPages): ?>
        <a href="?page=<?= $currentPage + 1 ?>&entries_per_page=<?= $entriesPerPage ?>">Next</a>
    <?php endif; ?>
</div>
<?php endif; ?>

</form>
</div>
</div>

<!-- Include navbar JavaScript -->
<script src="../navbar.js"></script>

<script>
    // Scroll to top functionality
    window.onscroll = function() {
        scrollFunction();
    };

    function scrollFunction() {
        const scrollToTopBtn = document.getElementById("scrollToTopBtn");
        if (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) {
            scrollToTopBtn.style.display = "block";
        } else {
            scrollToTopBtn.style.display = "none";
        }
    }

    function scrollToTop() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    // Initialize table functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Delete functionality
        // Update delete functionality
            document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const confirmed = confirm(`Delete ${btn.dataset.cycleName}?`);
                    if(confirmed) {
                        try {
                            const formData = new FormData();
                            formData.append('delete_id', btn.dataset.id);
                            
                            const response = await fetch('cycle-index.php', {
                                method: 'POST',
                                body: formData
                            });

                            const result = await response.json();
                            if(result.success) {
                                btn.closest('tr').remove();
                                showNotification(result.message, 'success');
                            }
                        } catch (error) {
                            showNotification('Error deleting cycle', 'error');
                        }
                    }
                });
            });

        // Search functionality
        document.getElementById('masterSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const searchField = document.getElementById('searchField').value;
            const rows = document.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const cells = row.getElementsByTagName('td');
                let match = false;

                if(searchField === 'all') {
                    match = Array.from(cells).some(cell =>
                        cell.textContent.toLowerCase().includes(searchTerm)
                    );
                } else {
                    const index = parseInt(searchField);
                    match = cells[index].textContent.toLowerCase().includes(searchTerm);
                }

                row.style.display = match ? '' : 'none';
            });
        });
    });

    function showNotification(message, type) {
        const notification = document.getElementById('notification');
        notification.textContent = message;
        notification.className = `notification ${type}`;
        notification.style.display = 'block';

        setTimeout(() => {
            notification.style.display = 'none';
        }, 3000);
    }

    function updateEntriesPerPage(select) {
        const url = new URL(window.location);
        url.searchParams.set('entries_per_page', select.value);
        if (select.value === 'all') {
            url.searchParams.delete('page');
        } else {
            url.searchParams.set('page', 1);
        }
        window.location.href = url.toString();
    }
</script>
</body>
</html>