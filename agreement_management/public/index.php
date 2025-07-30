<?php
require '../config/database.php'; // or the correct path

// Increase memory limit temporarily (optional but recommended)
ini_set('memory_limit', '768M');

try {
   // $pdo = new PDO("mysql:host=e2e-116-195.ssdcloudindia.net;dbname=finqy_dev", "dev_read", "finQY@22025#");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Dynamic pagination setup
    $default_limit = 10;
    $allowed_limits = [10, 25, 50, 100, 'all']; // Include 'all'
    $limit = isset($_GET['limit']) && in_array($_GET['limit'], $allowed_limits) 
            ? $_GET['limit'] 
            : $default_limit;

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($limit !== 'all') ? ($page - 1) * (int)$limit : 0;

    // Main query (NO LIMIT/OFFSET initially)
 // Main query (NO LIMIT/OFFSET initially)
    $sql = "SELECT 
        a.broker_id, 
        a.broker_name, 
        c.c_name,
        a.transaction_in_name_of,
        a.agreement_id,
        a.agreement_file, 
        a.commission_file,
        a.gst,
        a.tds,
        a.created_at,
        a.start_date, 
        a.end_date,
        a.biz_mis, 
        a.com_statement,
        a.frequency,
        a.mis_type,
        (SELECT COUNT(*) FROM agreement_cycles WHERE agreement_id = a.agreement_id) AS num_cycles
        FROM agreements a
        LEFT JOIN brokers b ON b.br_id = a.broker_id
        LEFT JOIN companies c ON c.c_id = a.company_id
        ORDER BY a.created_at DESC";


    // Add LIMIT/OFFSET only if not 'all'
    if ($limit !== 'all') {
        $sql .= " LIMIT :limit OFFSET :offset";
    }

    $stmt = $pdo->prepare($sql);
    if ($limit !== 'all') {
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $agreements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // [ADD THIS] Get total agreements count with same JOIN conditions
    $countSql = "SELECT COUNT(*) 
                FROM brokers b 
                INNER JOIN agreements a ON b.br_id = a.broker_id
                INNER JOIN companies c ON a.company_id = c_id";
    $totalAgreements = $pdo->query($countSql)->fetchColumn();

    // Total pages calculation
    $totalPages = ($limit !== 'all') ? ceil($totalAgreements / (int)$limit) : 1;

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agreement Management</title>
    <link rel="stylesheet" href="/agreement_management/navbar.css">
    <script src="/agreement_management/navbar.js"></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/agreement_management/navbar.php'; ?>
    <link rel="stylesheet" href="./css/index_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<body>
    <!-- Include Navbar -->

    <!-- Add these modal containers -->
<div id="cycleModal" class="modal"></div>
<div id="spocModal" class="modal"></div>
<div class="overlay" onclick="closeModals()"></div>

<!-- Content Container -->
    <div class="content" id="content" styles="margin-left: 50px;">
        <div class="header-container">
            <div>
                <h1>Agreement Management</h1>
                <h2>Agreements List</h2>
            </div>
            <a href="add_agreement.php" class="add_aggrement_btn">Add New Agreement</a>
        </div>

    <div class="search-container">
        <input type="text" id="masterSearch" class="search-box" placeholder="Search all records..." onkeyup="filterTable()">
        <span class="search-icon" onclick="toggleFilterDropdown()">▼</span>
        <div class="filter-dropdown" id="filterDropdown">
            <div onclick="setSearchFilter('all')">All Fields</div>
            <div onclick="setSearchFilter('br_name')">Broker Name</div>
            <div onclick="setSearchFilter('c_name')">Company Name</div>
            <div onclick="setSearchFilter('gst')">GST</div>
            <div onclick="setSearchFilter('tds')">TDS</div>
            <div onclick="setSearchFilter('num_cycles')">Number of Cycles</div>
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
        <div class="total-agreements">
            Total Agreements: <?= $totalAgreements ?>
        </div>
    </div>

    <div style="overflow-x: auto; max-height: 70vh;">
    <table border="1" id="agreementsTable">
        <thead>
        <tr>
            <th>Created At</th>
            <th>Broker Name</th>
            <th>Company Name</th>
            <th>Transaction in Name of</th>
            <th>Number of Cycles</th>
            <th>GST (%)</th>
            <th>TDS (%)</th>
            <th>Validity (Years)</th>
            <th>Due In (Days)</th>
            <th>Business MIS</th>
            <th>Commission Statement</th>
            <th>Frequency</th>
            <th>MIS Type</th>
            <th>SPOC</th>
            <th>Edit</th>
            <th>File</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($agreements as $row): ?>
        <?php
        // Calculate Validity and Due Days
        $startDate = !empty($row['start_date']) ? $row['start_date'] : null;
        $endDate = !empty($row['end_date']) ? $row['end_date'] : null;
        $validityYears = 'N/A';
        $dueDays = 'N/A';

        if ($startDate && $endDate) {
            try {
                $start = new DateTime($startDate);
                $end = new DateTime($endDate);
                $interval = $start->diff($end);
                
                // Calculate Validity in Years and Months
                $years = $interval->y;
                $months = $interval->m;
                $validityParts = [];
                if ($years > 0) {
                    $validityParts[] = $years . ' y' . ($years != 1 ? '' : '');
                }
                if ($months > 0) {
                    $validityParts[] = $months . ' m' . ($months != 1 ? '' : '');
                }
                $validityYears = !empty($validityParts) ? implode(' ', $validityParts) : '0 m';

                // Calculate Due Days
                $today = new DateTime();
                $diff = $end->getTimestamp() - $today->getTimestamp();
                $dueDays = (int) floor($diff / (60 * 60 * 24));
        
            } catch (Exception $e) {
                // Invalid dates, keep as N/A
            }
        }
        ?>
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
                    <td><?= htmlspecialchars($row['broker_name']) ?></td>
                    <td><?= !empty($row['c_name']) ? htmlspecialchars($row['c_name']) : 'N/A' ?></td>
                    <td><?= !empty($row['transaction_in_name_of']) ? htmlspecialchars($row['transaction_in_name_of']) : 'N/A' ?></td> 
                    <td class="cycle-click" data-agreement="<?= $row['agreement_id'] ?>">
                        <?= htmlspecialchars($row['num_cycles']) ?>
                    </td>
                    <td><?= htmlspecialchars($row['gst']) ?></td>
                    <td><?= htmlspecialchars($row['tds']) ?></td>
                    <td><?= htmlspecialchars($validityYears) ?></td>
                    <td><?= ($dueDays >= 0) ? htmlspecialchars($dueDays) : 'Expired' ?></td>
                    <td><?= htmlspecialchars($row ['biz_mis']) ?></td>
                    <td><?= htmlspecialchars($row ['com_statement']) ?></td>
                    <td><?= htmlspecialchars($row ['frequency']) ?></td>
                    <td><?= htmlspecialchars($row ['mis_type']) ?></td>

                    <td class="spoc-click" data-agreement="<?= $row['agreement_id'] ?>">
                        <i class="fas fa-eye" title="View SPOCs"></i>
                    </td>
                    <td>
                    <?php if (!empty($row['agreement_id'])): ?>
                        <a href="edit_agreement.php?id=<?= $row['agreement_id'] ?>"><i class="fas fa-edit"></i>
                        </a>
                    <?php else: ?>
                        <a href="add_agreement.php?broker_id=<?= $row['broker_id'] ?>">Create Agreement</a>
                    <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($row['agreement_file'])): ?>
                            <a href="download.php?id=<?= $row['agreement_id'] ?>&type=agreement"><i class="fas fa-download"></i>
                            </a>
                        <?php else: ?>
                            No file
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php if ($limit !== 'all' && $totalPages > 1): ?>
<!-- Pagination -->
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

<!-- Include Navbar JavaScript -->
    
    <script>
            function updateLimit(newLimit) {
            const url = new URL(window.location.href);
            url.searchParams.set('limit', newLimit);
            url.searchParams.delete('page'); // Reset to first page
            window.location.href = url.toString();
        }

        // Update pagination links to preserve limit
        document.querySelectorAll('.pagination a').forEach(link => {
            const url = new URL(link.href);
            url.searchParams.set('limit', <?= $limit ?>);
            link.href = url.toString();
        });
        // Search functionality
        let currentFilter = 'all';
        const columnMap = {
            'br_name': 1,
            'c_name': 2,
            'num_cycles': 3,
            'gst': 4,
            'tds': 5,
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
            const rows = document.querySelectorAll("#agreementsTable tbody tr");

            rows.forEach(row => {
                let matchFound = false;
                const cells = row.querySelectorAll('td');

                if (currentFilter === 'all') {
                    // Search all searchable columns (excluding file columns and actions)
                    [0,1, 2, 3, 4, 5, 6, 7].forEach(index => {
                        if (cells[index].textContent.toLowerCase().includes(searchTerm)) {
                            matchFound = true;
                        }
                    });
                } else {
                    // Search specific column
                    const columnIndex = columnMap[currentFilter];
                    if (columnIndex !== undefined && cells[columnIndex].textContent.toLowerCase().includes(searchTerm)) {
                        matchFound = true;
                    }
                }

                row.style.display = matchFound ? '' : 'none';
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.search-container')) {
                document.getElementById('filterDropdown').classList.remove('show');
            }
        });

        // Existing modal functions
        function closeModals() {
            document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
            document.querySelector('.overlay').style.display = 'none';
        }
// Cycle click handler
document.querySelectorAll('.cycle-click').forEach(element => {
    element.addEventListener('click', async function() {
        const originalHTML = this.innerHTML;
        this.innerHTML = '<div class="loading"></div>';
        
        try {
            const agreementId = this.dataset.agreement;
            const response = await fetch(`get_cycles.php?agreement_id=${agreementId}`);
            const cycles = await response.json();

            // Month display mapping
            const monthDisplay = {
                'current': 'This Month',
                'next': 'Next Month',
                'next_next': 'Next to Next Month'
            };

            const modalContent = cycles.length > 0 
                ? cycles.map((cycle, index) => `
                    <h3>Cycle ${index + 1}</h3>
                    <p>Business cycle: ${cycle.business_start_day} to ${cycle.business_end_day}</p>
                    <p>Invoice cycle: ${cycle.invoice_day} of ${monthDisplay[cycle.invoice_month] || cycle.invoice_month}</p>
                    <p>Payment cycle: ${cycle.payment_day}</p>
                    <p>GST cycle: ${cycle.gst_start_day}  of ${monthDisplay[cycle.gst_month] || cycle.gst_month}</p></p>
                    <hr>
                `).join('')
                : '<p>No cycles found</p>';

            document.getElementById('cycleModal').innerHTML = `
                <div class="modal-content">
                    <button class="modal-close-btn" onclick="closeModals()" title="Close">
                        <i class="fa fa-times"></i>
                    </button>
                    <h2>Cycle Details</h2>
                    ${modalContent}
                </div>
            `;
            document.getElementById('cycleModal').style.display = 'block';
            document.querySelector('.overlay').style.display = 'block';
        } catch (error) {
            console.error('Error:', error);
            alert('Failed to load cycle details');
        } finally {
            this.innerHTML = originalHTML;
        }
    });
});

        // SPOC click handler
        document.querySelectorAll('.spoc-click').forEach(element => {
            element.addEventListener('click', async function() {
                const originalHTML = this.innerHTML;
                this.innerHTML = '<div class="loading"></div>';
                
                try {
                    const agreementId = this.dataset.agreement;
                    console.log('Fetching SPOCs for agreement:', agreementId);
                    
                    const response = await fetch(`get_spocs.php?agreement_id=${agreementId}`);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const spocs = await response.json();
                    console.log('Received SPOC data:', spocs);

                    const modalContent = spocs.length > 0 
                        ? `<table border="1" style="width: 100%">
                            <tr>
                                <th>Name</th>
                                <th>Contact No</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th>Designation</th>
                                <th>Source</th>
                                <th>Invoice CC</th>
                            </tr>
                            ${spocs.map(spoc => `
                                <tr>
                                    <td>${spoc.name || '-'}</td>
                                    <td>${spoc.number || '-'}</td>
                                    <td>${spoc.email || '-'}</td>
                                    <td>${spoc.department || '-'}</td>
                                    <td>${spoc.designation || '-'}</td>
                                    <td>${spoc.source || '-'}</td>
                                    <td>${spoc.invoice_cc || '-'}</td>
                                </tr>
                            `).join('')}
                           </table>`
                        : '<p>No SPOCs found</p>';

                        const modal = document.getElementById('spocModal');
                        modal.innerHTML = `
                            <div class="modal-content">
                                <button class="modal-close-btn" onclick="closeModals()" title="Close">
                                    <i class="fa fa-times"></i>
                                </button>
                                <h2>SPOC Details</h2>
                                ${modalContent}
                            </div>
                        `;
                    modal.style.display = 'block';
                    document.querySelector('.overlay').style.display = 'block';
                } catch (error) {
                    console.error('Error fetching SPOCs:', error);
                    alert('Failed to load SPOC details: ' + error.message);
                } finally {
                    this.innerHTML = originalHTML;
                }
            });
        });
    </script>
</body>
</html>