<?php
require '../../config/database.php';
session_start();
if (isset($_SESSION['flash_success'])) unset($_SESSION['flash_success']);
if (isset($_SESSION['flash_error'])) unset($_SESSION['flash_error']);

if (isset($_GET['delete_id'])) {
    try {
        $deleteStmt = $pdo->prepare("DELETE FROM ins_bank_master m WHERE id = ?");
        $deleteStmt->execute([$_GET['delete_id']]);
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } catch (PDOException $e) {
        die("Delete failed: " . $e->getMessage());
    }
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Date range filter setup
    $from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
    $to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';
    
    $default_limit = 10;
    $allowed_limits = [10, 25, 50, 100, 'all'];
    $limit = isset($_GET['limit']) && in_array($_GET['limit'], $allowed_limits) 
            ? $_GET['limit'] 
            : $default_limit;

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($limit !== 'all') ? ($page - 1) * (int)$limit : 0;

    $date_column = 'transaction_date';

$sql = "SELECT 
            m.id,
            m.entity,
            m.mode_of_payment,
            m.other_mode_detail,
            DATE_FORMAT(m.$date_column, '%d-%m-%Y') AS formatted_date,
            DATE_FORMAT(m.$date_column, '%b-%Y') AS month_year,
            m.ref_no,
            CASE WHEN m.entry_type = 'Debit' THEN m.amount ELSE NULL END AS debit_amount,
            CASE WHEN m.entry_type = 'Credit' THEN m.amount ELSE NULL END AS credit_amount,
            b.br_name AS broker_name,
            m.transaction_type_id,
            fr.rname AS partner_name,
            br.entity_name AS entiny_name,
            m.partner_id,
            m.transaction_category,
            m.invoice_mapping,
            m.gst_mapping
        FROM ins_bank_master m
        LEFT JOIN brokers b ON m.broker_id = b.br_id
        LEFT JOIN first_register fr ON m.partner_id = fr.refercode
        LEFT JOIN billing_repository br ON m.entity = br.id";
        
$where = [];
$params = [];
if ($from_date) {
    $where[] = "DATE(m.$date_column) >= :from_date";
    $params[':from_date'] = $from_date;
}
if ($to_date) {
    $where[] = "DATE(m.$date_column) <= :to_date";
    $params[':to_date'] = $to_date;
}

$sql .= " ORDER BY m.transaction_date DESC";

if (!empty($where)) {
    $sql = str_replace("ORDER BY", "WHERE " . implode(" AND ", $where) . " ORDER BY", $sql);
}

    if ($limit !== 'all') {
        $sql .= " LIMIT :limit OFFSET :offset";
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    if ($limit !== 'all') {
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countSql = "SELECT COUNT(*) FROM ins_bank_master AS m ";
    if (!empty($where)) {
        $countSql .= " WHERE " . implode(" AND ", $where);
    }
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalTransactions = $countStmt->fetchColumn();

    $totalSql = "SELECT 
                    SUM(CASE WHEN entry_type = 'Credit' THEN amount ELSE 0 END) AS total_credit,
                    SUM(CASE WHEN entry_type = 'Debit' THEN amount ELSE 0 END) AS total_debit
                 FROM ins_bank_master AS m";
    if (!empty($where)) {
        $totalSql .= " WHERE " . implode(" AND ", $where);
    }
    $totalStmt = $pdo->prepare($totalSql);
    foreach ($params as $key => $value) {
        $totalStmt->bindValue($key, $value);
    }
    $totalStmt->execute();
    $totals = $totalStmt->fetch(PDO::FETCH_ASSOC);
    $totalCredit = $totals['total_credit'] ?? 0;
    $totalDebit = $totals['total_debit'] ?? 0;
    $runningBalance = $totalCredit - $totalDebit;

    $totalPages = ($limit !== 'all') ? ceil($totalTransactions / (int)$limit) : 1;
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    die("Database error: " . $e->getMessage() . " Please check if the column '$date_column' exists in the ins_bank_master table.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bank Transaction Management</title>
    <link rel="stylesheet" href="/agreement_management/navbar.css">
    <script src="/agreement_management/navbar.js"></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/agreement_management/navbar.php'; ?>
    <link rel="stylesheet" href="../css/bank_index_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
    .filter-container label {
        margin-right: 10px;
    }
    .filter-container button {
        padding: 5px 10px;
        background-color: #4CAF50;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        margin-right: 5px;
    }
    .filter-container button.clear-btn {
        background-color: #f44336;
    }
    </style>
</head>
<body>
    <div class="content" id="content">
        <div id="transactionModal" class="modal"></div>
        <div class="overlay" onclick="closeModals()"></div>

        <div class="header-container">
            <h2>Bank Transactions</h2>
            <div class="add-btns">
                <a href="add_bankmaster.php" class="add_aggrement_btn">Single entry</a>
                <a href="add_bank_entry.php" class="add_aggrement_btn">Bulk entry</a>
            </div>
        </div>

        <div class="search-container">
            <input type="text" id="masterSearch" class="search-box" placeholder="Search all transactions..." onkeyup="filterTable()">
            <span class="search-icon" onclick="toggleFilterDropdown()">▼</span>
            <div class="filter-dropdown" id="filterDropdown">
                <div onclick="setSearchFilter('all')">All Fields</div>
                <div onclick="setSearchFilter('entity')">Entity</div>
                <div onclick="setSearchFilter('partner_name')">Partner Name</div>
                <div onclick="setSearchFilter('broker_name')">Broker</div>
                <div onclick="setSearchFilter('transaction_category')">Transaction Type</div>
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
            <div class="filter-container">
                <label>From:
                    <input type="date" id="fromDateFilter" value="<?= htmlspecialchars($from_date) ?>">
                </label>
                <label>To:
                    <input type="date" id="toDateFilter" value="<?= htmlspecialchars($to_date) ?>">
                </label>
                <button onclick="applyDateRangeFilter()">Apply</button>
                <button class="clear-btn" onclick="clearDateRangeFilter()">Clear</button>
            </div>
            <div class="summary-box">
                Total Transactions: <?= $totalTransactions ?> | 
                Total Credit: ₹<?= number_format($totalCredit,0 ) ?> | 
                Total Debit: ₹<?= number_format($totalDebit, 0) ?> | 
                Running Balance: ₹<?= number_format($runningBalance, 0) ?>
            </div>
        </div>
<div style="overflow-x: auto; max-height: 70vh;">
    
            <table border="1" class="agreements-table">
                <thead>
                    <tr>
                        <th>Entity</th>
                        <th>Mode of Payment</th>
                        <th>Date</th>
                        <th>Month</th>
                        <th>Ref. No.</th>
                        <th>Debit Amount</th>
                        <th>Credit Amount</th>
                        <th>Broker</th>
                        <th>Transaction ID</th>
                        <th>Partner ID</th>
                        <th>Partner Name</th>
                        <th>Type of Transaction</th>
                        <th>Invoice Mapping</th>
                        <th>GST Mapping</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($transactions) > 0): ?>
                        <?php foreach ($transactions as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['entiny_name']) ?></td>
                                <td>
                                    <?php
                                    if ($row['mode_of_payment'] === 'Other' && !empty($row['other_mode_detail'])) {
                                        echo htmlspecialchars($row['other_mode_detail']);
                                    } else {
                                        echo htmlspecialchars($row['mode_of_payment']);
                                    }
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($row['formatted_date']) ?></td>
                                <td><?= htmlspecialchars($row['month_year']) ?></td>
                                <td><?= htmlspecialchars($row['ref_no']) ?></td>
                                <td>
                                    <?= $row['debit_amount'] ?   number_format($row['debit_amount'], ) : '-' ?>
                                </td>
                                <td>
                                    <?= $row['credit_amount'] ?   number_format($row['credit_amount'],0): '-' ?>
                                </td>
                                <td><?= htmlspecialchars($row['broker_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['id']) ?></td>
                                <td><?= htmlspecialchars($row['partner_id']) ?></td>
                                <td><?= htmlspecialchars($row['partner_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['transaction_category']) ?></td>
                                <td><?= htmlspecialchars($row['invoice_mapping']) ?></td>
                                <td><?= htmlspecialchars($row['gst_mapping']) ?></td>
                                <td>
                                    <a href="edit_bankmaster.php?id=<?= $row['id'] ?>">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="#" onclick="confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['entity'])) ?>')" style="margin-left: 8px;">
                                        <i class="fas fa-trash" style="color: #dc3545;"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="15">No transactions found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
</div>

        <?php if ($limit !== 'all' && $totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&limit=<?= $limit ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>">Previous</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?>&limit=<?= $limit ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>" <?= $i == $page ? 'class="active"' : '' ?>>
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&limit=<?= $limit ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function updateLimit(newLimit) {
            const url = new URL(window.location.href);
            url.searchParams.set('limit', newLimit);
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }

        function applyDateRangeFilter() {
            const fromDate = document.getElementById('fromDateFilter').value;
            const toDate = document.getElementById('toDateFilter').value;
            const url = new URL(window.location.href);
            
            if (fromDate) {
                url.searchParams.set('from_date', fromDate);
            } else {
                url.searchParams.delete('from_date');
            }
            
            if (toDate) {
                url.searchParams.set('to_date', toDate);
            } else {
                url.searchParams.delete('to_date');
            }
            
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }

        // NEW FUNCTION TO CLEAR DATE FILTERS
        function clearDateRangeFilter() {
            // Clear the input fields
            document.getElementById('fromDateFilter').value = '';
            document.getElementById('toDateFilter').value = '';
            
            // Remove date parameters from URL and reload
            const url = new URL(window.location.href);
            url.searchParams.delete('from_date');
            url.searchParams.delete('to_date');
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }

        let currentFilter = 'all';
        const columnMap = {
            'entity': 0,
            'partner_name': 10,
            'broker_name': 7,
            'transaction_category': 11
        };

        function setSearchFilter(filterType) {
            currentFilter = filterType;
            document.getElementById('activeFilter').textContent = 
                filterType === 'all' ? 'All Fields' : document.querySelector(`#filterDropdown div[onclick="setSearchFilter('${filterType}')"]`).textContent;
            document.getElementById('filterDropdown').classList.remove('show');
            filterTable();
        }

        function toggleFilterDropdown() {
            document.getElementById('filterDropdown').classList.toggle('show');
        }

        function filterTable() {
            const searchTerm = document.getElementById('masterSearch').value.toLowerCase();
            const rows = document.querySelectorAll(".agreements-table tbody tr");

            rows.forEach(row => {
                let matchFound = false;
                const cells = row.querySelectorAll('td');

                if (currentFilter === 'all') {
                    [0, 1, 2, 3, 4, 7, 8, 9, 10, 11].forEach(index => {
                        if (cells[index].textContent.toLowerCase().includes(searchTerm)) {
                            matchFound = true;
                        }
                    });
                } else {
                    const columnIndex = columnMap[currentFilter];
                    if (columnIndex !== undefined && cells[columnIndex].textContent.toLowerCase().includes(searchTerm)) {
                        matchFound = true;
                    }
                }

                row.style.display = matchFound ? '' : 'none';
            });
        }

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.search-container')) {
                document.getElementById('filterDropdown').classList.remove('show');
            }
        });

        function closeModals() {
            document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
            document.querySelector('.overlay').style.display = 'none';
        }

        function confirmDelete(id, entity) {
            if (confirm(`Do you want to delete transaction for ${entity}?`)) {
                window.location.href = `?delete_id=${id}`;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.pagination a').forEach(link => {
                const url = new URL(link.href);
                url.searchParams.set('limit', <?= $limit ?>);
                url.searchParams.set('from_date', '<?= $from_date ?>');
                url.searchParams.set('to_date', '<?= $to_date ?>');
                link.href = url.toString();
            });
        });
    </script>
</body>
</html>