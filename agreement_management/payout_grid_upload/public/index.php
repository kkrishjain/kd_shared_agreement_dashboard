<?php
require '../../config/database.php';

// Add this at the top of your PHP code to handle AJAX deletion requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    try {
        $deleteId = $_POST['delete_id'];
        $stmt = $pdo->prepare("DELETE FROM payout_grid WHERE id = ?");
        $stmt->execute([$deleteId]);
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

try {
    // Pagination setup
    $default_limit = 10;
    $allowed_limits = [10, 25, 50, 100, 'all'];
    $limit = isset($_GET['limit']) && in_array($_GET['limit'], $allowed_limits) 
            ? $_GET['limit'] 
            : $default_limit;

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($limit !== 'all') ? ($page - 1) * (int)$limit : 0;

    // Main query with join for partner name
    $sql = "SELECT 
            pg.id,
            DATE_FORMAT(pg.created_at, '%d/%m/%Y') AS 'Created At',
            pg.partner_finqy_id AS 'Partner Finqy ID',
            fr.rname AS 'Partner Name',
            c.c_name AS 'Company Name',
            p.pl_name AS 'Plan Name',
            pg.ppt AS PPT,
            pg.pt AS PT,
            pg.case_type AS 'Case Type',
            DATE_FORMAT(pg.brokerage_from, '%d/%m/%Y') AS 'Applicable From',
            DATE_FORMAT(pg.brokerage_to, '%d/%m/%Y') AS 'Applicable To',
            pg.premium_from AS 'Premium From',
            pg.premium_to AS 'Premium To',
            pg.applicable_percentage AS 'Applicable Percentage',
            pg.commission_statement AS 'Files'
            FROM payout_grid pg
            LEFT JOIN first_register fr ON pg.partner_finqy_id = fr.refercode
            LEFT JOIN companies c ON pg.company_code = c.c_id
            LEFT JOIN plans p ON pg.plan_code = p.pl_id
            ORDER BY pg.created_at DESC";

    if ($limit !== 'all') {
        $sql .= " LIMIT :limit OFFSET :offset";
    }

    $stmt = $pdo->prepare($sql);
    if ($limit !== 'all') {
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total entries count
    $countSql = "SELECT COUNT(*) FROM payout_grid";
    $totalEntries = $pdo->query($countSql)->fetchColumn();

    // Total pages calculation
    $totalPages = ($limit !== 'all') ? ceil($totalEntries / (int)$limit) : 1;

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payout Grid Management</title>
    <link rel="stylesheet" href="/agreement_management/navbar.css">
    <script src="/agreement_management/navbar.js"></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/agreement_management/navbar.php'; ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <link rel="stylesheet" href="../css/payout_index_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
</head>
<body>
    <div class="content" id="content">
    <div class="header-container">
        <h2>Payout Grid Entries</h2>
        <a href="add_payoutgrid.php" class="add_aggrement_btn">Upload Grid</a>
    </div>
    <div class="search-container">
        <input type="text" id="masterSearch" class="search-box" placeholder="Search all records..." onkeyup="filterTable()">
        <span class="search-icon" onclick="toggleFilterDropdown()">▼</span>
        <div class="filter-dropdown" id="filterDropdown" style="display: none;">
            <div onclick="setSearchFilter('all')">All Fields</div>
            <div onclick="setSearchFilter('Partner Finqy ID')">Partner Finqy ID</div>
            <div onclick="setSearchFilter('Partner Name')">Partner Name</div>
            <div onclick="setSearchFilter('Company Name')">Company Name</div>
            <div onclick="setSearchFilter('Plan Name')">Plan Name</div>
            <div onclick="setSearchFilter('Case Type')">Case Type</div>
        </div>
        <span id="activeFilter" class="active-filter">All Fields</span>
    </div>

    <div class="records-control">
        <div class="limit-selector">
            <label>Show: 
                <select onchange="updateLimit(this.value)">
                    <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                    <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                    <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                    <option value="all" <?= $limit === 'all' ? 'selected' : '' ?>>All</option>
                </select> entries
            </label>
        </div>
        <div class="total-entries">
            Total Entries: <?= $totalEntries ?>
        </div>
    </div>

        <div style="overflow-x: auto; max-height: 70vh;">

    <table class="data-table">
        <thead>
            <tr>
                <?php foreach ($entries[0] ?? [] as $key => $value): ?>
                    <?php if ($key === 'id') continue; // Skip id column ?>
                    <?php if ($key === 'Files'): ?>
                        <th>Action</th>
                    <?php endif; ?>
                    <th><?= htmlspecialchars($key) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>

        <tbody>
        <?php foreach ($entries as $row): ?>
            <tr id="row-<?= $row['id'] ?>">
                <?php foreach ($row as $key => $value): ?>
                    <?php if ($key === 'id') continue; ?>
                    <?php if ($key === 'Files'): ?>
                        <td class="action-buttons">
                            <a href="../public/edit_payoutgrid.php?id=<?= $row['id'] ?>" class="edit-btn">
                                <i class="fas fa-edit"></i>
                            </a>
                            
                            <button onclick="deleteEntry(<?= $row['id'] ?>)" class="delete-btn" >
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                    <?php endif; ?>
                    <td>
                        <?php if ($key === 'Files' && !empty($value)): ?>
                            <div class="com_statement">
                                <a href="download_commission.php?id=<?= $row['id'] ?>" class="download-btn"><i class="fa-solid fa-download"></i></a>
                            </div>
                        <?php elseif ($key === 'Files' && empty($value)): ?>
                            -
                        <?php else: ?>
                            <?= !empty($value) ? htmlspecialchars($value) : '-' ?>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
    </table>
    </div>

    <?php if ($limit !== 'all' && $totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&limit=<?= $limit ?>">Previous</a>
        <?php endif; ?>

        <?php
        // Show all pages if totalPages <= 3
        if ($totalPages <= 3) {
            for ($i = 1; $i <= $totalPages; $i++) {
                ?>
                <a href="?page=<?= $i ?>&limit=<?= $limit ?>" <?= $i == $page ? 'class="active"' : '' ?>>
                    <?= $i ?>
                </a>
                <?php
            }
        } else {
            // Show first 3 pages
            for ($i = 1; $i <= min(3, $totalPages); $i++) {
                ?>
                <a href="?page=<?= $i ?>&limit=<?= $limit ?>" <?= $i == $page ? 'class="active"' : '' ?>>
                    <?= $i ?>
                </a>
                <?php
            }
            // Show ellipsis if there are pages between 3 and the last 2
            if ($totalPages > 5) {
                ?>
                <span class="ellipsis">...</span>
                <?php
            }
            // Show last 2 pages
            for ($i = max(4, $totalPages - 1); $i <= $totalPages; $i++) {
                ?>
                <a href="?page=<?= $i ?>&limit=<?= $limit ?>" <?= $i == $page ? 'class="active"' : '' ?>>
                    <?= $i ?>
                </a>
                <?php
            }
        }
        ?>

        <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&limit=<?= $limit ?>">Next</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <script>
        function deleteEntry(id) {
            if (!confirm('Are you sure you want to delete this entry?')) {
                return;
            }

            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: { delete_id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        alert('Error deleting entry: ' + (response.error || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Error deleting entry. Please try again.');
                }
            });
        }

        let currentFilter = 'all';
        const columnMap = {
            'Partner Finqy ID': 1,
            'Partner Name': 2,
            'Company Code': 3,
            'Plan Code': 4,
            'Case Type': 7,
        };

        function setSearchFilter(filterType) {
            currentFilter = filterType;
            document.getElementById('activeFilter').textContent = 
                filterType === 'all' ? 'All Fields' : filterType;
            document.getElementById('filterDropdown').style.display = 'none';
            filterTable();
        }

        function toggleFilterDropdown() {
            const dropdown = document.getElementById('filterDropdown');
            dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        }

        function filterTable() {
            const searchTerm = document.getElementById('masterSearch').value.toLowerCase();
            const rows = document.querySelectorAll(".data-table tbody tr");

            rows.forEach(row => {
                let matchFound = false;
                const cells = row.querySelectorAll('td');

                if (currentFilter === 'all') {
                    [0, 1, 2, 3, 4, 5, 8, 9].forEach(index => {
                        if (cells[index].textContent.toLowerCase().includes(searchTerm)) {
                            matchFound = true;
                        }
                    });
                } else {
                    const columnIndex = columnMap[currentFilter];
                    if (cells[columnIndex].textContent.toLowerCase().includes(searchTerm)) {
                        matchFound = true;
                    }
                }

                row.style.display = matchFound ? '' : 'none';
            });
        }

        function updateLimit(newLimit) {
            const url = new URL(window.location.href);
            url.searchParams.set('limit', newLimit);
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.search-container')) {
                document.getElementById('filterDropdown').style.display = 'none';
            }
        });
    </script>
    </div>
</body>
</html>