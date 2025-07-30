<?php
require '../config/database.php'; // or the correct path

// Handle Delete Request
if (isset($_GET['delete_id'])) {
    try {
        $deleteStmt = $pdo->prepare("DELETE FROM partner_agreement WHERE agreement_id = ?");
        $deleteStmt->execute([$_GET['delete_id']]);
        // Redirect to avoid resubmission
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } catch (PDOException $e) {
        die("Delete failed: " . $e->getMessage());
    }
}
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
    agreement_id AS finqy_id,
    partner_id,
    partner_name,
    m_partner_name,
    rm_name,
    team,
    product_name,
    sub_product_name,
    agreement_type,
    cycle_name,
    partner_type,
    gst,
    tds,
    start_date,
    end_date,
    created_at,
    num_installments,
    agreement_pdf,      
    cheque_file         
    FROM partner_agreement
    ORDER BY created_at DESC";

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
$countSql = "SELECT COUNT(*) FROM partner_agreement";
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

<body>
        <div class="content" id="content">

    <!-- Add these modal containers -->
<div id="installmentModal" class="modal"></div>
<div id="cycleModal" class="modal"></div>
<div class="overlay" onclick="closeModals()"></div>
    <div class="header-container">
        <h2>Agreements List</h2>
        <a href="add_partner.php" class="add_aggrement_btn">Add New Agreement</a>
    </div>

    <div class="search-container">
        <input type="text" id="masterSearch" class="search-box" placeholder="Search all records..." onkeyup="filterTable()">
        <span class="search-icon" onclick="toggleFilterDropdown()">▼</span>
        <div class="filter-dropdown" id="filterDropdown">
            <div onclick="setSearchFilter('all')">All Fields</div>
            <div onclick="setSearchFilter('partner_name')">Partner Name</div>
            <div onclick="setSearchFilter('rm_name')">RM Name</div>
            <div onclick="setSearchFilter('team')">Team</div>
            <div onclick="setSearchFilter('product_name')">Product Name</div>
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

    <table border="1" class="agreements-table">
<thead>
  <tr>
      <th>Created At</th>
      <th>Main Partner</th>
      <th>Finqy ID</th>
      <th>Partner Name</th>
      <th>RM Name</th>
      <th>Team</th>
      <th>Product</th>
      <th>Sub Product</th>
      <th>Type of Agreement</th>
      <th>Cycle Name/ Installments</th>
      <th>Type Of Partner</th>
      <th>GST(%)</th>
      <th>TDS(%)</th>
      <th>Validity</th>
      <th>Actions</th>
      <!-- <th>Agreement Download</th>
      <th>Blank Cheque Download</th> -->
      <th>Files</th>
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


        // In the table row loop
        if ($startDate && $endDate) {
            try {
                $start = new DateTime($startDate);
                $end = new DateTime($endDate);
                
                // Add day calculation
                $interval = $start->diff($end);
                $days = $interval->days;
                
                // Convert days to approximate months/years
                $years = floor($days / 365);
                $months = floor(($days % 365) / 30);
                $days_remainder = $days - ($years * 365) - ($months * 30);

                $validityParts = [];
                if ($years > 0) $validityParts[] = "$years y";
                if ($months > 0) $validityParts[] = "$months m";
                if ($days_remainder > 0) $validityParts[] = "$days_remainder d";
                
                $validityYears = !empty($validityParts) ? implode(' ', $validityParts) : '0 d';
                
            } catch (Exception $e) {
                $validityYears = 'Invalid dates';
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
                    <td><?= htmlspecialchars($row['m_partner_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['partner_id']) ?></td>
                    <td><?= htmlspecialchars($row['partner_name']) ?></td>
                    <td><?= htmlspecialchars($row['rm_name']) ?></td>
                    <td><?= htmlspecialchars($row['team']) ?></td>
                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                    <td><?= htmlspecialchars($row['sub_product_name']) ?></td>
                    <td><?= htmlspecialchars($row['agreement_type']) ?></td>
                    <td class="<?= strtolower($row['agreement_type']) === 'advance' ? 'installment-click' : ''; ?>" 
                        <?php if (strtolower($row['agreement_type']) === 'advance'): ?>
                            data-installments="<?= $row['num_installments'] ?>"
                            data-start="<?= $row['start_date'] ?>"
                            data-end="<?= $row['end_date'] ?>"
                        <?php endif; ?>
                    >
                        <?= strtolower($row['agreement_type']) === 'advance' 
                            ? 'Installments: ' . htmlspecialchars($row['num_installments']) 
                            : htmlspecialchars($row['cycle_name']) ?>
                    </td>
                    <td>
                        <?php 
                        if (isset($row['partner_type']) && in_array($row['partner_type'], ['GST', 'Non-GST'])) {
                            echo htmlspecialchars($row['partner_type']);
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td><?= htmlspecialchars($row['gst']) ?></td>
                    <td><?= htmlspecialchars($row['tds']) ?></td>
                    <td><?= htmlspecialchars($validityYears) ?></td> <!-- Reuse existing validity logic -->
                    <td>
                    <a href="edit_partner.php?id=<?= $row['finqy_id'] ?>">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="#" onclick="confirmDelete(<?= $row['finqy_id'] ?>, '<?= htmlspecialchars(addslashes($row['partner_name'])) ?>')" style="margin-left: 8px;">
                        <i class="fas fa-trash" style="color: #dc3545;"></i>
                    </a>
                    </td>
                  

                
<!-- Agreement Download -->
<td>
  <?php if (!empty($row['agreement_pdf'])): ?>
    <div class="file-buttons">
      <!-- Agreement Button -->
      <a href="download.php?id=<?= $row['finqy_id'] ?>&type=agreement" 
         class="download-btn" 
         data-tooltip="Agreement">
        <i class="fas fa-download"></i>
      </a>

      <?php if (strtolower($row['agreement_type']) === 'Advance' && !empty($row['cheque_file'])): ?>
        <!-- Cheque Button -->
        <a href="download.php?id=<?= $row['finqy_id'] ?>&type=cheque" 
           class="download-btn" 
           data-tooltip="Cheque">
          <i class="fas fa-download"></i>
        </a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    No files
  <?php endif; ?>
</td>

  </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div> <!-- End of overflow-x div -->
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

    <script>
        console.log('Script loaded'); // Should appear before "DOM fully loaded"
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
            'partner_name': 2, // Partner Name (3rd column)
            'rm_name': 3,     // RM Name (4th column)
            'team': 4,        // Team (5th column)
            'product_name': 5 // Product (6th column)
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
    const rows = document.querySelectorAll(".agreements-table tbody tr"); // Fixed selector

    rows.forEach(row => {
        let matchFound = false;
        const cells = row.querySelectorAll('td');

        if (currentFilter === 'all') {
            // Search columns 0-6 (Created At, Finqy ID, Partner, RM, Team, Product, Agreement Type)
            [0, 1, 2, 3, 4, 5, 6].forEach(index => {
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

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.search-container')) {
                document.getElementById('filterDropdown').classList.remove('show');
            }
        });

        // Existing modal functions
    // Existing modal functions
    function closeModals() {
        document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
        document.querySelector('.overlay').style.display = 'none';
    }

    // Installment Click Handler (MOVE THIS TO THE BOTTOM)
document.addEventListener('DOMContentLoaded', function() {
  
    // Installment Click Handler
    document.querySelectorAll('.installment-click').forEach(element => {
        element.addEventListener('click', function() {
            console.log('Installment element clicked');
            
            const installments = this.dataset.installments;
            const startDate = this.dataset.start;
            const endDate = this.dataset.end;

            // Validate data
            if (!installments || !startDate || !endDate) {
                console.warn('Missing installment data:', this.dataset);
                return;
            }

            // Safely handle dates
            const startStr = new Date(startDate).toLocaleDateString();
            const endStr = new Date(endDate).toLocaleDateString();

            const modalContent = `
                <div class="modal-content">
                    <button class="modal-close-btn" onclick="closeModals()" title="Close">
                        <i class="fa fa-times"></i>
                    </button>
                    <h2>Installment Details</h2>
                    <p>Number of Installments: ${installments}</p>
                    <p>Start Date: ${startStr}</p>
                    <p>End Date: ${endStr}</p>
                </div>
            `;

            document.getElementById('installmentModal').innerHTML = modalContent;
            document.getElementById('installmentModal').style.display = 'block';
            document.querySelector('.overlay').style.display = 'block';
        });
    });
         });

function confirmDelete(id, partnerName) {
    if (confirm(`Do you want to delete agreement for ${partnerName}?`)) {
        window.location.href = `?delete_id=${id}`;
    }
}
    </script>
    </div>
</body>
</html>