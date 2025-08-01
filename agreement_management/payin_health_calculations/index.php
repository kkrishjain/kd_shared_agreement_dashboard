<?php
require '../config/database.php';

// Define constants for TDS and GST percentages
define('TDS_PERCENT', 0); // Will be overridden by agreement values
define('GST_PERCENT', 0); // Will be overridden by agreement values

try {
    // For pagination, we'll use the database table
    $countQuery = "SELECT COUNT(*) FROM payin_health_calculation";
    $totalEntries = $pdo->query($countQuery)->fetchColumn();

    // Pagination setup
    $default_limit = 10;
    $allowed_limits = [10, 25, 50, 100, 'all'];
    $limit = isset($_GET['limit']) && in_array($_GET['limit'], $allowed_limits) 
            ? $_GET['limit'] 
            : $default_limit;

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($limit !== 'all') ? ($page - 1) * (int)$limit : 0;

    // Fetch paginated results from database with all fields
    $selectQuery = "SELECT 
        lead_id, policy_number, product, customer_name, broker_name, 
        company_name, plan_name, pt, case_type, net_premium, 
        base_percent, base_amount, reward_percent, reward_amount, 
        total_payin_percent, payin_amount, tds_percent, tds_amount, 
        gst_percent, gst_amount, gross_receipt, net_receipt, 
        team, policy_name, policy_issued, calculation_date, 
        broker_id, company_id, plan_id, location, status, rm_name
    FROM payin_health_calculation ORDER BY lead_id";
    
    if ($limit !== 'all') {
        $selectQuery .= " LIMIT :limit OFFSET :offset";
    }
    
    $selectStmt = $pdo->prepare($selectQuery);
    if ($limit !== 'all') {
        $selectStmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $selectStmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    }
    $selectStmt->execute();
    $paginatedResults = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalPages = ($limit !== 'all') ? ceil($totalEntries / (int)$limit) : 1;

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payin Health Calculations</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/agreement_management/navbar.css">
    <script src="/agreement_management/navbar.js"></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/agreement_management/navbar.php'; ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="./css/payin_cal_style.css">
</head>
<body>
    <div class="content" id="content">
        <h2>Payin Health Calculations</h2>
        
        <div class="search-container">
            <input type="text" id="masterSearch" class="search-box" placeholder="Search all records..." onkeyup="filterTable()">
            <span class="search-icon" onclick="toggleFilterDropdown()">▼</span>
            <div class="filter-dropdown" id="filterDropdown" style="display: none;">
                <div onclick="setSearchFilter('all')">All Fields</div>
                <div onclick="setSearchFilter('customer_name')">Customer Name</div>
                <div onclick="setSearchFilter('broker_name')">Broker Name</div>
                <div onclick="setSearchFilter('company_name')">Company Name</div>
                <div onclick="setSearchFilter('plan_name')">Plan Name</div>
                <div onclick="setSearchFilter('policy_number')">Policy No</div>
            </div>
            <span id="activeFilter" class="active-filter">All Fields</span>
            <button class="export-btn" onclick="exportToExcel()">
                <i class="fas fa-file-excel"></i> Export to Excel
            </button>
            <button class="recalculate-btn" onclick="triggerRecalculation()">
                <i class="fas fa-sync-alt"></i> Recalculate
            </button>
            <span class="loading" id="loadingSpinner">Request submitted...</span>
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
                        <th>Lead ID</th>
                        <th>Policy No</th>
                        <th>Product</th>
                        <th>Customer Name</th>
                        <th>Broker Name</th>
                        <th>Company Name</th>
                        <th>Plan Name</th>
                        <th>PT</th>
                        <th>Policy Type</th>
                        <th>Net Premium</th>
                        <th>Base %</th>
                        <th>Base Amount</th>
                        <th>Reward %</th>
                        <th>Reward Amount</th>
                        <th>Total Payin %</th>
                        <th>Payin Amount</th>
                        <th>TDS %</th>
                        <th>TDS Amount</th>
                        <th>GST %</th>
                        <th>GST Amount</th>
                        <th>Gross Receipt</th>
                        <th>Net Receipt</th>
                        <th>Team</th>
                        <th>Policy Name</th>
                        <th>Policy Issued</th>
                        <th>Calculation Date</th>
                        <th>Broker ID</th>
                        <th>Company ID</th>
                        <th>Plan ID</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>RM Name</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paginatedResults as $row): ?>
                        <tr>
                            <td class="lead-id-cell">HI<?= str_pad($row['lead_id'], 6, '0', STR_PAD_LEFT) ?></td>
                            <td><?= htmlspecialchars($row['policy_number']) ?></td>
                            <td><?= htmlspecialchars($row['product']) ?></td>
                            <td><?= htmlspecialchars($row['customer_name']) ?></td>
                            <td><?= htmlspecialchars($row['broker_name']) ?></td>
                            <td><?= htmlspecialchars($row['company_name']) ?></td>
                            <td><?= htmlspecialchars($row['plan_name']) ?></td>
                            <td class="text-center"><?= htmlspecialchars($row['pt']) ?></td>
                            <td><?= htmlspecialchars($row['case_type']) ?></td>
                            <td class="numeric"><?= number_format($row['net_premium'], 2) ?></td>
                            <td class="numeric"><?= number_format($row['base_percent'], 2) ?>%</td>
                            <td class="numeric"><?= number_format($row['base_amount'], 0) ?></td>
                            <td class="numeric"><?= number_format($row['reward_percent'], 2) ?>%</td>
                            <td class="numeric"><?= number_format($row['reward_amount'], 0) ?></td>
                            <td class="numeric"><?= number_format($row['total_payin_percent'], 2) ?>%</td>
                            <td class="numeric"><?= number_format($row['payin_amount'], 0) ?></td>
                            <td class="numeric"><?= number_format($row['tds_percent'], 2) ?>%</td>
                            <td class="numeric"><?= number_format($row['tds_amount'], 0) ?></td>
                            <td class="numeric"><?= number_format($row['gst_percent'], 2) ?>%</td>
                            <td class="numeric"><?= number_format($row['gst_amount'], 0) ?></td>
                            <td class="numeric"><?= number_format($row['gross_receipt'], 2) ?></td>
                            <td class="numeric"><?= number_format($row['net_receipt'], 2) ?></td>
                            <td><?= htmlspecialchars($row['team']) ?></td>
                            <td><?= htmlspecialchars($row['policy_name']) ?></td>
                            <td><?= htmlspecialchars($row['policy_issued']) ?></td>
                            <td><?= htmlspecialchars($row['calculation_date']) ?></td>
                            <td><?= htmlspecialchars($row['broker_id']) ?></td>
                            <td><?= htmlspecialchars($row['company_id']) ?></td>
                            <td><?= htmlspecialchars($row['plan_id']) ?></td>
                            <td><?= htmlspecialchars($row['location']) ?></td>
                            <td><?= htmlspecialchars($row['status']) ?></td>
                            <td><?= htmlspecialchars($row['rm_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($paginatedResults)): ?>
                        <tr>
                            <td colspan="32" class="text-center">No matching records found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($limit !== 'all' && $totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>&limit=<?= $limit ?>">Previous</a>
            <?php endif; ?>

            <?php
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
            let currentFilter = 'all';
            const columnMap = {
                'customer_name': 3,
                'broker_name': 4,
                'company_name': 5,
                'plan_name': 6,
                'policy_number': 1,
            };

            function setSearchFilter(filterType) {
                currentFilter = filterType;
                document.getElementById('activeFilter').textContent = 
                    filterType === 'all' ? 'All Fields' : filterType.replace('_', ' ');
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
                        for (let i = 1; i < cells.length; i++) {
                            if (cells[i].textContent.toLowerCase().includes(searchTerm)) {
                                matchFound = true;
                                break;
                            }
                        }
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

            function triggerRecalculation() {
                const recalculateBtn = document.querySelector('.recalculate-btn');
                const loadingSpinner = document.getElementById('loadingSpinner');
                
                recalculateBtn.disabled = true;
                loadingSpinner.style.display = 'inline';
                
                $.ajax({
                    url: 'recalculate_health.php',
                    method: 'POST',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            window.location.reload();
                        } else {
                            alert('Error during recalculation: ' + (response.error || 'Unknown error'));
                            recalculateBtn.disabled = false;
                            loadingSpinner.style.display = 'none';
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('Error during recalculation: ' + error);
                        recalculateBtn.disabled = false;
                        loadingSpinner.style.display = 'none';
                    }
                });
            }

            function exportToExcel() {
                $.ajax({
                    url: 'export_health.php',
                    method: 'GET',
                    success: function(response) {
                        const blob = new Blob([response], { type: 'text/csv;charset=utf-8;' });
                        const url = URL.createObjectURL(blob);
                        const link = document.createElement('a');
                        link.setAttribute('href', url);
                        link.setAttribute('download', 'payin_health_calculations_<?= date('Y-m-d') ?>.csv');
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    },
                    error: function(xhr, status, error) {
                        alert('Error exporting to Excel: ' + error);
                    }
                });
            }

            document.addEventListener('click', function(event) {
                if (!event.target.closest('.search-container')) {
                    document.getElementById('filterDropdown').style.display = 'none';
                }
            });
        </script>
    </div>
</body>
</html>