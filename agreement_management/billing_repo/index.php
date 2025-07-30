<?php
require '../config/database.php'; // Adjust path as needed

// Handle Delete Request
// Handle Delete Request
if (isset($_GET['delete_id'])) {
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // First delete associated emails
        $deleteEmailsStmt = $pdo->prepare("DELETE FROM billing_repository_email WHERE repository_id = ?");
        $deleteEmailsStmt->execute([$_GET['delete_id']]);
        
        // Then delete the repository entry
        $deleteStmt = $pdo->prepare("DELETE FROM billing_repository WHERE id = ?");
        $deleteStmt->execute([$_GET['delete_id']]);
        
        // Commit the transaction
        $pdo->commit();
        
        // Redirect to avoid resubmission
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } catch (PDOException $e) {
        // Roll back in case of error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die("Delete failed: " . $e->getMessage());
    }
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Dynamic pagination setup
    $default_limit = 10;
    $allowed_limits = [10, 25, 50, 100, 'all'];
    $limit = isset($_GET['limit']) && in_array($_GET['limit'], $allowed_limits) 
            ? $_GET['limit'] 
            : $default_limit;

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($limit !== 'all') ? ($page - 1) * (int)$limit : 0;

    // Main query
    $sql = "SELECT 
        br.id,
        br.repository_type,
        br.entity_name,
        b.br_name AS broker_name,
        c.c_name AS company_name,
        br.gst_no,
        br.address,
        br.email,
        br.tan_no,
        br.created_at,
        br.company_all
    FROM billing_repository br
    LEFT JOIN brokers b ON br.broker_id = b.br_id AND b.br_status = '1'
    LEFT JOIN companies c ON br.company_id = c.c_id AND c.c_status = '1'
    ORDER BY br.created_at DESC";

    if ($limit !== 'all') {
        $sql .= " LIMIT :limit OFFSET :offset";
    }

    $stmt = $pdo->prepare($sql);
    if ($limit !== 'all') {
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $repositories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total repositories count
    $countSql = "SELECT COUNT(*) FROM billing_repository";
    $totalRepositories = $pdo->query($countSql)->fetchColumn();

    // Total pages calculation
    $totalPages = ($limit !== 'all') ? ceil($totalRepositories / (int)$limit) : 1;

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Billing Repository Management</title>
    <link rel="stylesheet" href="/agreement_management/navbar.css">
    <script src="/agreement_management/navbar.js"></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/agreement_management/navbar.php'; ?>
    <link rel="stylesheet" href="./css/billing_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
</head>
<body>
    <!-- Add modal containers -->
    <div id="emailModal" class="modal"></div>
    <div class="overlay" onclick="closeModals()"></div>
    
    <div class="content" id="content">
        <div class="header-container">
            <h2>Billing Repositories List</h2>
            <a href="add_repo.php" class="add_aggrement_btn">Add New Repository</a>
        </div>

        <div class="search-container">
            <input type="text" id="masterSearch" class="search-box" placeholder="Search repositories..." onkeyup="filterTable()">
            <span class="search-icon" onclick="toggleFilterDropdown()">▼</span>
            <div class="filter-dropdown" id="filterDropdown">
                <div onclick="setSearchFilter('all')">All Fields</div>
                <div onclick="setSearchFilter('entity_name')">Entity Name</div>
                <div onclick="setSearchFilter('broker_name')">Broker Name</div>
                <div onclick="setSearchFilter('company_name')">Company Name</div>
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
            <div class="total-repositories">
                Total Repositories: <?= $totalRepositories ?>
            </div>
        </div>

            <div style="overflow-x: auto; max-height: 70vh;">

        <table border="1" class="repositories-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Billing Type</th>
                    <th>Entity</th>
                    <th>Broker</th>
                    <th>Principal Company Name</th>
                    <th>GST Numbers</th>
                    <th>Addresses</th>
                    <th>Email ID</th>
                    <th>TAN No</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($repositories as $row): ?>
                    <tr>
                        <td>
                            <?php
                            if (!empty($row['created_at'])) {
                                try {
                                    $date = new DateTime($row['created_at']);
                                    echo htmlspecialchars($date->format('d-m-Y | H:i:s'));
                                } catch (Exception $e) {
                                    echo htmlspecialchars($row['created_at']);
                                }
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
                        <td><?= htmlspecialchars(ucfirst($row['repository_type'])) ?></td>
                        <td><?= htmlspecialchars($row['entity_name'] ?: 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['broker_name'] ?: 'N/A') ?></td>
                        <td>
                            <?php
                            $companyDisplay = 'N/A';
                            if ($row['company_all'] === 'all' || $row['company_all'] == 1) {
                                $companyDisplay = 'All Companies';
                            } elseif (!empty($row['company_name'])) {
                                $companyDisplay = $row['company_name'];
                            }
                            echo htmlspecialchars($companyDisplay);
                            ?>
                        </td>                        
                        <td><?= htmlspecialchars($row['gst_no'] ?: 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['address'] ?: 'N/A') ?></td>
                        <td class="email-click" data-repository="<?= $row['id'] ?>">
                            <?php 
                            // Count emails for this repository
                            $emailCount = 0;
                            try {
                                $emailStmt = $pdo->prepare("SELECT COUNT(*) FROM billing_repository_email WHERE repository_id = ?");
                                $emailStmt->execute([$row['id']]);
                                $emailCount = $emailStmt->fetchColumn();
                            } catch (PDOException $e) {
                                // Silently fail, just show 0
                            }
                            echo $emailCount > 0 ? $emailCount . ' email(s)' : 'N/A';
                            ?>
                        </td>
                        <td><?= htmlspecialchars($row['tan_no'] ?: 'N/A') ?></td>
                        <td>
                            <a href="edit_repo.php?id=<?= $row['id'] ?>">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="#" onclick="confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['entity_name'] ?: $row['company_name'] ?: 'Repository')) ?>')" style="margin-left: 8px;">
                                <i class="fas fa-trash"></i>
                            </a>
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

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?>&limit=<?= $limit ?>" <?= $i == $page ? 'class="active"' : '' ?>>
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&limit=<?= $limit ?>">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <script>
            function confirmDelete(id, name) {
    if (confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) {
        window.location.href = `?delete_id=${id}`;
    }
}
            function updateLimit(newLimit) {
                const url = new URL(window.location.href);
                url.searchParams.set('limit', newLimit);
                url.searchParams.delete('page');
                window.location.href = url.toString();
            }

            let currentFilter = 'all';
            const columnMap = {
                'entity_name': 2,
                'broker_name': 3,
                'company_name': 4
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
                const rows = document.querySelectorAll(".repositories-table tbody tr");

                rows.forEach(row => {
                    let matchFound = false;
                    const cells = row.querySelectorAll('td');

                    if (currentFilter === 'all') {
                        // Search columns 1-8 (Billing Type, Entity, Broker, Company, GST, Address, Email, TAN)
                        [1, 2, 3, 4, 5, 6, 7, 8].forEach(index => {
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

document.querySelectorAll('.email-click').forEach(element => {
    element.addEventListener('click', async function() {
        const originalHTML = this.innerHTML;
        this.innerHTML = '<div class="loading"></div>';
        
        try {
            const repositoryId = this.dataset.repository;
            const response = await fetch(`./get_emails.php?repository_id=${repositoryId}`);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Failed to fetch emails');
            }
            
            const emails = result.data || [];
            const modalContent = emails.length > 0 
                ? `<table border="1" style="width: 100%; margin-top: 20px;">
                    <thead>
                        <tr>
                            <th>Email Address</th>
                            <th>Added On</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${emails.map(email => `
                            <tr>
                                <td>${email.email || '-'}</td>
                                <td>${email.created_at_formatted || 'N/A'}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                   </table>`
                : '<p>No emails found for this repository</p>';

            const modal = document.getElementById('emailModal');
            modal.innerHTML = `
                <div class="modal-content">
                    <div style="text-align: right">
                        <button class="modal-close-btn" title="Close">
                            <i class="fa fa-times"></i>
                        </button>
                    </div>
                    <h2>Associated Emails (${result.count || 0})</h2>
                    ${modalContent}
                </div>
            `;
            
            modal.style.display = 'block';
            document.querySelector('.overlay').style.display = 'block';
            
        } catch (error) {
            console.error('Error:', error);
            const modal = document.getElementById('emailModal');
            modal.innerHTML = `
                <div class="modal-content">
                    <div style="text-align: right">
                        <button class="modal-close-btn" title="Close">
                            <i class="fa fa-times"></i>
                        </button>
                    </div>
                    <h2>Error Loading Emails</h2>
                    <p>${error.message}</p>
                    <p>Please try again or contact support.</p>
                </div>
            `;
            modal.style.display = 'block';
            document.querySelector('.overlay').style.display = 'block';
        } finally {
            this.innerHTML = originalHTML;
        }
    });
});

// Event delegation for close button and overlay
document.addEventListener('click', function(event) {
    // Close button or its icon
    if (event.target.closest('.modal-close-btn') || event.target.classList.contains('fa-times')) {
        closeModals();
    }
    // Overlay click
    if (event.target.classList.contains('overlay')) {
        closeModals();
    }
});

function closeModals() {
    document.getElementById('emailModal').style.display = 'none';
    document.querySelector('.overlay').style.display = 'none';
}
        </script>
    </div>
</body>
</html>