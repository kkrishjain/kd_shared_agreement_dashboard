<?php
require '../config/database.php';

try {
    // Pagination
    $countQuery = "SELECT COUNT(*) FROM payout_calculation";
    $totalEntries = $pdo->query($countQuery)->fetchColumn();

    $default_limit = 10;
    $allowed_limits = [10, 25, 50, 100, 'all'];
    $limit = in_array($_GET['limit'] ?? null, $allowed_limits) ? $_GET['limit'] : $default_limit;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($limit !== 'all') ? ($page - 1) * (int)$limit : 0;

    $selectQuery = "SELECT * FROM payout_calculation ORDER BY lead_id";
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
    <title>Payout Calculation</title>
    <link rel="stylesheet" href="/agreement_management/navbar.css">
    <script src="/agreement_management/navbar.js"></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/agreement_management/navbar.php'; ?>
    <link rel="stylesheet" href="./css/payout_cal_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .content {
            margin-left: 40px;
            transition: margin-left 0.3s ease;
        }
        .content.shifted {
            margin-left: 270px;
        }
        .records-control {
            margin: 15px 0;
            display: flex;
            gap: 20px;
            align-items: center;
        }
        .limit-selector select {
            padding: 5px 10px;
        }
        .pagination {
            margin: 20px 0;
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 8px 12px;
            background: #f0f0f0;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
        }
        .pagination a.active {
            background: #3498db;
            color: white;
        }
        .pagination span.ellipsis {
            background: none;
            padding: 8px 6px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 14px;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background-color: #e9ecef;
            position: sticky;
            top: 0;
        }
        .search-container {
            margin: 15px 0;
            position: relative;
        }
        .search-box {
            padding: 8px;
            width: 300px;
        }
        .download-btn {
            color: #fff;
            background-color: #28a745;
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
        }
        .download-btn:hover {
            background-color: #218838;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        .numeric {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .sticky-header {
            position: sticky;
            top: 0;
            background: white;
            z-index: 100;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .export-btn {
            background-color: #17a2b8;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-left: 10px;
        }
        .export-btn:hover {
            background-color: #138496;
        }
        .filter-dropdown {
            position: absolute;
            background: white;
            border: 1px solid #ddd;
            z-index: 100;
            display: none;
        }
        .filter-dropdown div {
            padding: 8px 12px;
            cursor: pointer;
        }
        .filter-dropdown div:hover {
            background-color: #f0f0f0;
        }
        .active-filter {
            margin-left: 10px;
            font-style: italic;
            color: #666;
        }
        .search-icon {
            cursor: pointer;
            margin-left: 5px;
        }
        .recalculate-btn {
            background-color: #ffc107;
            color: #333;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-left: 10px;
        }
        .recalculate-btn:hover {
            background-color: #e0a800;
        }
        .recalculate-btn:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        #loadingMessage {
            display: none;
            color: #3498db;
            margin-left: 10px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <h2>Payout Calculation</h2>
        
        <div class="search-container">
            <input type="text" id="masterSearch" class="search-box" placeholder="Search all records..." onkeyup="filterTable()">
            <span class="search-icon" onclick="toggleFilterDropdown()">▼</span>
            <div class="filter-dropdown" id="filterDropdown">
                <div onclick="setSearchFilter('all')">All Fields</div>
                <div onclick="setSearchFilter('customer_name')">Customer Name</div>
                <div onclick="setSearchFilter('partner_name')">Partner Name</div>
                <div onclick="setSearchFilter('company_name')">Company Name</div>
                <div onclick="setSearchFilter('plan_name')">Plan Name</div>
                <div onclick="setSearchFilter('policy_no')">Policy No</div>
            </div>
            <span id="activeFilter" class="active-filter">All Fields</span>
            <button class="export-btn" onclick="exportToExcel()">
                <i class="fas fa-file-excel"></i> Export to Excel
            </button>
            <button id="recalculateBtn" class="recalculate-btn" onclick="triggerRecalculation()">
                <i class="fas fa-sync"></i> Recalculate
            </button>
            <span id="loadingMessage">Request Submitted - Processing...</span>
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
                <!-- <th>Product</th> -->
                <th>Sub Product</th>
                <th>Policy No</th>
                <th>Customer Name</th>
                <th>Partner ID</th>
                <th>Partner Name</th>
                <th>Company Name</th>
                <th>Plan Name</th>
                <th>PT/PPT</th>
                <th>Net Premium</th>
                <th>Policy Issued Date</th> <!-- Updated header -->
                <th>Payout %</th>
                <th>Payout Amount</th>
                <th>TDS %</th>
                <th>TDS Amount</th>
                <th>GST %</th>
                <th>GST Amount</th>
                <th>Gross Receipt</th>
                <th>Net Receipt</th>
                <th>Team</th>
                <th>RM Name</th>
                <th>PIVC Status</th>
                <th>SV Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($paginatedResults as $row): ?>
                <?php
                // Format the date: Convert to DDMMYYYY
                $policyIssued = '';
                if (!empty($row['policy_issued'])) {
                    try {
                        $date = new DateTime($row['policy_issued']);
                        $policyIssued = $date->format('d-m-Y');
                    } catch (Exception $e) {
                        // Fallback to original value if conversion fails
                        $policyIssued = $row['policy_issued'];
                    }
                }
                ?>
                <tr>
                    <td class="lead-id-cell">LCI<?= str_pad($row['lead_id'], 6, '0', STR_PAD_LEFT) ?></td>                            
                    <td><?= htmlspecialchars($row['sub_product']) ?></td>
                    <td><?= htmlspecialchars($row['policy_no']) ?></td>
                    <td><?= htmlspecialchars($row['customer_name']) ?></td>
                    <td><?= htmlspecialchars($row['partner_id']) ?></td>
                    <td><?= htmlspecialchars($row['partner_name']) ?></td>
                    <td><?= htmlspecialchars($row['company_name']) ?></td>
                    <td><?= htmlspecialchars($row['plan_name']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($row['pt_ppt']) ?></td>
                    <td class="numeric"><?= number_format(round($row['net_premium']), 0) ?></td>
                    <td><?= htmlspecialchars($policyIssued) ?></td> <!-- Updated date display -->
                    <td class="numeric"><?= number_format($row['payout_percent'], 2) ?>%</td>
                    <td class="numeric"><?= number_format(round($row['payout_amount']), 0) ?></td>
                    <td class="numeric"><?= number_format($row['tds_percent'], 2) ?>%</td>
                    <td class="numeric"><?= number_format(round($row['tds_amount']), 0) ?></td>
                    <td class="numeric"><?= number_format($row['gst_percent'], 2) ?>%</td>
                    <td class="numeric"><?= number_format(round($row['gst_amount']), 0) ?></td>
                    <td class="numeric"><?= number_format(round($row['gross_receipt']), 0) ?></td>
                    <td class="numeric"><?= number_format(round($row['net_receipt']), 0) ?></td>
                    <td><?= htmlspecialchars($row['team']) ?></td>
                    <td><?= htmlspecialchars($row['rm_name']) ?></td>
                    <td><?= htmlspecialchars($row['pivc_status']) ?></td>
                    <td><?= htmlspecialchars($row['sv_status']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($paginatedResults)): ?>
                <tr>
                    <td colspan="23" class="text-center">No matching records found</td> <!-- Update colspan to 23 -->
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
                'customer_name': 4,
                'partner_name': 5,
                'company_name': 6,
                'plan_name': 7,
                'policy_no': 3
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
                        for (let i = 0; i < cells.length; i++) {
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
                const btn = document.getElementById('recalculateBtn');
                const loadingMessage = document.getElementById('loadingMessage');
                btn.disabled = true;
                loadingMessage.style.display = 'inline';

                fetch('recalculate_payout.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Recalculation completed successfully!');
                        window.location.reload();
                    } else {
                        alert('Error during recalculation: ' + data.message);
                        btn.disabled = false;
                        loadingMessage.style.display = 'none';
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                    btn.disabled = false;
                    loadingMessage.style.display = 'none';
                });
            }

            function exportToExcel() {
                let csv = '';
                const headers = [
                    'Lead ID', 'Sub Product', 'Policy No', 'Customer Name',
                    'Partner Name', 'Company Name', 'Plan Name', 'PT/PPT',
                    'Net Premium', 'Policy Issued Date', 'Payout %', 'Payout Amount',
                    'TDS %', 'TDS Amount', 'GST %', 'GST Amount', 'Gross Receipt',
                    'Net Receipt', 'Team', 'RM Name', 'PIVC status', 'SV status'
                ];
                csv += headers.join(',') + '\r\n';
                
            <?php foreach ($paginatedResults as $row): ?>
                csv += [
                    '<?= addslashes('LCI' . str_pad($row['lead_id'], 6, '0', STR_PAD_LEFT)) ?>',
                    
                    '"<?= addslashes($row['sub_product']) ?>"',
                    '"<?= addslashes($row['policy_no']) ?>"',
                    '"<?= addslashes($row['customer_name']) ?>"',
                    '"<?= addslashes($row['partner_name']) ?>"',
                    '"<?= addslashes($row['company_name']) ?>"',
                    '"<?= addslashes($row['plan_name']) ?>"',
                    '"<?= addslashes($row['pt_ppt']) ?>"',
                    <?= round($row['net_premium']) ?>,
                    '"<?= addslashes($row['policy_issued']) ?>"',
                    <?= $row['payout_percent'] ?>,
                    <?= round($row['payout_amount']) ?>,
                    <?= $row['tds_percent'] ?>,
                    <?= round($row['tds_amount']) ?>,
                    <?= $row['gst_percent'] ?>,
                    <?= round($row['gst_amount']) ?>,
                    <?= round($row['gross_receipt']) ?>,
                    <?= round($row['net_receipt']) ?>,
                    '"<?= addslashes($row['team']) ?>"',
                    '"<?= addslashes($row['rm_name']) ?>"',
                    '"<?= addslashes($row['pivc_status']) ?>"',
                    '"<?= addslashes($row['sv_status']) ?>"'
                ].join(',') + '\r\n';
            <?php endforeach; ?>
                
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.setAttribute('href', url);
                link.setAttribute('download', 'payout_calculations_<?= date('Y-m-d') ?>.csv');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
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