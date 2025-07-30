<?php
require '../../config/database.php';

// AJAX deletion handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    try {
        $deleteId = $_POST['delete_id'];
        $stmt = $pdo->prepare("DELETE FROM payin_grid WHERE id = ?");
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

    // Main query for Life Cum Investment entries
    $sql = "SELECT 
            pg.id,
            DATE_FORMAT(pg.uploaded_at, '%d/%m/%Y') AS CreatedAt,
            pg.product_name AS Product,
            pg.subproduct_name AS Subproduct,
            b.br_name AS 'Broker Name',
            c.c_name AS 'Company Name',
            p.pl_name AS 'Plan Name',
            pg.ppt AS PPT,
            pg.pt AS PT,
            pg.brokerage_type AS 'Brokerage Type',
            pg.case_type AS 'Case Type',
            pg.target_amount AS Target,
            pg.no_of_tickets AS 'No. of Tickets',
            pg.applicable_percentage AS 'Applicable Percentage',
            pg.premium_from AS 'Premium From',
            pg.premium_to AS 'Premium To',
            CASE 
                WHEN pg.brokerage_type = 'Incentive' THEN DATE_FORMAT(pg.incentive_from, '%d/%m/%Y')
                WHEN pg.brokerage_type = 'Contest' THEN DATE_FORMAT(pg.contest_from, '%d/%m/%Y')
                ELSE DATE_FORMAT(pg.brokerage_from, '%d/%m/%Y')
            END AS 'Applicable From',
            CASE 
                WHEN pg.brokerage_type = 'Incentive' THEN DATE_FORMAT(pg.incentive_to, '%d/%m/%Y')
                WHEN pg.brokerage_type = 'Contest' THEN DATE_FORMAT(pg.contest_to, '%d/%m/%Y')
                ELSE DATE_FORMAT(pg.brokerage_to, '%d/%m/%Y')
            END AS 'Applicable To',
            pg.commission_statement AS 'Files'
            FROM payin_grid pg
            LEFT JOIN brokers b ON pg.broker_code = b.br_id
            LEFT JOIN companies c ON pg.company_code = c.c_id
            LEFT JOIN plans p ON pg.plan_code = p.pl_id
            WHERE pg.subproduct_name = 'Life Cum Investment'
            ORDER BY pg.uploaded_at DESC";

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

    // Total entries count (for Life Cum Investment only)
    $countSql = "SELECT COUNT(*) FROM payin_grid WHERE subproduct_name = 'Life Cum Investment'";
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
    <title>Life Cum Investment Payin Grid Management</title>
    <link rel="stylesheet" href="/agreement_management/navbar.css">
    <script src="/agreement_management/navbar.js"></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/agreement_management/navbar.php'; ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="../css/payin_index_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="content" id="content">
        <div class="header-container">
            <h2>Life Cum Investment Payin Entries</h2>
        </div>
        
        <div class="search-container">
            <input type="text" id="masterSearch" class="search-box" placeholder="Search all records..." onkeyup="filterTable()">
            <span class="search-icon" onclick="toggleFilterDropdown()">▼</span>
            <div class="filter-dropdown" id="filterDropdown" style="display: none;">
                <div onclick="setSearchFilter('all')">All Fields</div>
                <div onclick="setSearchFilter('Product')">Product</div>
                <div onclick="setSearchFilter('Subproduct')">Subproduct</div>
                <div onclick="setSearchFilter('Broker Name')">Broker Name</div>
                <div onclick="setSearchFilter('Company Name')">Company Name</div>
                <div onclick="setSearchFilter('Plan Name')">Plan Name</div>
                <div onclick="setSearchFilter('Brokerage Type')">Brokerage Type</div>
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
                        <th>CreatedAt</th>
                        <th>Product</th>
                        <th>Subproduct</th>
                        <th>Broker Name</th>
                        <th>Company Name</th>
                        <th>Plan Name</th>
                        <th>PPT</th>
                        <th>PT</th>
                        <th>Brokerage Type</th>
                        <th>Case Type</th>
                        <th>Target</th>
                        <th>No. of Tickets</th>
                        <th>Applicable Percentage</th>
                        <th>Premium From</th>
                        <th>Premium To</th>
                        <th>Applicable From</th>
                        <th>Applicable To</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($entries as $row): ?>
                    <tr id="row-<?= $row['id'] ?>">
                        <td><?= !empty($row['CreatedAt']) ? htmlspecialchars($row['CreatedAt']) : '-' ?></td>
                        <td><?= !empty($row['Product']) ? htmlspecialchars($row['Product']) : '-' ?></td>
                        <td><?= !empty($row['Subproduct']) ? htmlspecialchars($row['Subproduct']) : '-' ?></td>
                        <td><?= !empty($row['Broker Name']) ? htmlspecialchars($row['Broker Name']) : '-' ?></td>
                        <td><?= !empty($row['Company Name']) ? htmlspecialchars($row['Company Name']) : '-' ?></td>
                        <td><?= !empty($row['Plan Name']) ? htmlspecialchars($row['Plan Name']) : '-' ?></td>
                        <td><?= !empty($row['PPT']) ? htmlspecialchars($row['PPT']) : '-' ?></td>
                        <td><?= !empty($row['PT']) ? htmlspecialchars($row['PT']) : '-' ?></td>
                        <td><?= !empty($row['Brokerage Type']) ? htmlspecialchars($row['Brokerage Type']) : '-' ?></td>
                        <td><?= !empty($row['Case Type']) ? htmlspecialchars($row['Case Type']) : '-' ?></td>
                        <td><?= !empty($row['Target']) ? htmlspecialchars($row['Target']) : '-' ?></td>
                        <td><?= !empty($row['No. of Tickets']) ? htmlspecialchars($row['No. of Tickets']) : '-' ?></td>
                        <td><?= !empty($row['Applicable Percentage']) ? htmlspecialchars($row['Applicable Percentage']) : '-' ?></td>
                        <td><?= !empty($row['Premium From']) ? htmlspecialchars($row['Premium From']) : '-' ?></td>
                        <td><?= !empty($row['Premium To']) ? htmlspecialchars($row['Premium To']) : '-' ?></td>
                        <td><?= !empty($row['Applicable From']) ? htmlspecialchars($row['Applicable From']) : '-' ?></td>
                        <td><?= !empty($row['Applicable To']) ? htmlspecialchars($row['Applicable To']) : '-' ?></td>
                        <td class="action-buttons">
                            <a href="../public/edit_grid.php?id=<?= $row['id'] ?>" class="edit-btn">
                                <i class="fas fa-edit"></i>
                            </a>
                            
                            <button onclick="deleteEntry(<?= $row['id'] ?>)" class="delete-btn">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                            
                            <?php if (!empty($row['Files'])): ?>
                                <a href="download_commission.php?id=<?= $row['id'] ?>" class="download-btn"><i class="fa-solid fa-download"></i></a>
                            <?php endif; ?>
                        </td>
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
            // Pagination logic
            if ($totalPages <= 3) {
                for ($i = 1; $i <= $totalPages; $i++) {
                    ?>
                    <a href="?page=<?= $i ?>&limit=<?= $limit ?>" <?= $i == $page ? 'class="active"' : '' ?>>
                        <?= $i ?>
                    </a>
                    <?php
                }
            } else {
                for ($i = 1; $i <= min(3, $totalPages); $i++) {
                    ?>
                    <a href="?page=<?= $i ?>&limit=<?= $limit ?>" <?= $i == $page ? 'class="active"' : '' ?>>
                        <?= $i ?>
                    </a>
                    <?php
                }
                if ($totalPages > 5) {
                    ?>
                    <span class="ellipsis">...</span>
                    <?php
                }
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
            'Product': 1,
            'Subproduct': 2,
            'Broker Name': 3,
            'Company Name': 4,
            'Plan Name': 5,
            'Brokerage Type': 8
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
                    [1, 2, 3, 4, 5, 8].forEach(index => {
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