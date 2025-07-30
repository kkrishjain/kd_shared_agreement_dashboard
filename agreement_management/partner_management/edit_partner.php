<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require '../config/database.php';
$agreement_id = $_GET['id'] ?? null;
if (!$agreement_id) die("Error: Agreement ID is required");

try {
    // Fetch agreement details
    $stmt = $pdo->prepare("SELECT * FROM partner_agreement WHERE agreement_id = ?");
    $stmt->execute([$agreement_id]);
    $agreement = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$agreement) die("Error: Agreement not found");

    $installment_start_date = $agreement['installment_start_date'] ?? null;
    $installment_end_date = $agreement['installment_end_date'] ?? null;

    // Fetch installments if Advanced
    $installments = [];
    if ($agreement['agreement_type'] === 'Advance') {
        $installmentStmt = $pdo->prepare("SELECT * FROM installments WHERE agreement_id = ?");
        $installmentStmt->execute([$agreement_id]);
        $installments = $installmentStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch related data
    $products = $pdo->query("SELECT p_id, p_name FROM products WHERE p_status = '1'")->fetchAll();

    // Check if current product is still available
    $cycles = $pdo->query("SELECT cycle_id, c_name FROM partner_cycles")->fetchAll();
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $_SESSION['post_data'] = $_POST;
        $errors = [];

        // Validate Partner Type
        $partner_type = $_POST['partner_type'] ?? '';
        if (!in_array($partner_type, ['GST', 'Non-GST'])) {
            $errors[] = "Invalid partner type selection";
        }

        // Validate agreement type
        $agreement_type = $_POST['agreement_type'] ?? '';
        if (!in_array($agreement_type, ['Advance', 'Payout'])) {
            $errors[] = "Invalid agreement type";
        }

        // Validate Partner
        $correct_partner_id = $agreement['partner_id'];
        $correct_partner_name = $agreement['partner_name'];
        $rm_name = $agreement['rm_name'];
        $team = $agreement['team'];

        // FIX: Initialize m_stmt properly
        $m_partner_id = trim($_POST['m_partner_id'] ?? '');
        $m_partner_name = trim($_POST['m_partner_name'] ?? '');
        $m_stmt = $pdo->prepare("SELECT refercode, rname FROM first_register WHERE refercode = ? OR rname = ?");
        $m_stmt->execute([$m_partner_id, $m_partner_name]);
        $m_partner = $m_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$m_partner) {
            $errors[] = "Main Partner not found";
        } else {
            $correct_m_partner_id = $m_partner['refercode'];
            $correct_m_partner_name = $m_partner['rname'];
        }


        $num_installments = (int)($_POST['num_installments'] ?? 0);
        $installment_start_date = $_POST['installment_start_date'] ?? null;
        $installment_end_date = $_POST['installment_end_date'] ?? null;

if ($agreement_type === 'Advance') {
    // Validate installments
    $num_installments = (int)($_POST['num_installments'] ?? 0);
    
    if ($num_installments < 0) {
        $errors[] = "Number of installments must be at least 1";
    }

    if (!$installment_start_date || !$installment_end_date) {
        $errors[] = "Installment start and end dates are required";
    }
} else {
    $num_installments = 0;  // or null depending on your DB schema
}

        // File validation
        $allowedAgreementTypes = ['application/pdf'];
        $allowedChequeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'];
        
        if (!empty($_FILES['agreement_pdf']['tmp_name'])) {
            if (!in_array($_FILES['agreement_pdf']['type'], $allowedAgreementTypes)) {
                $errors[] = "Agreement must be PDF";
            }
        }
        
        if (!empty($_FILES['cheque_file']['tmp_name'])) {
            if (!in_array($_FILES['cheque_file']['type'], $allowedChequeTypes)) {
                $errors[] = "Cheque file must be PDF/image";
            }
        }

        // Redirect on errors
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            header("Location: edit_partner.php?id=$agreement_id");
            exit;
        }
        // Handle file updates
        function handleFileUpdate($field, $currentValue) {
            return (!empty($_FILES[$field]['tmp_name'])) 
                ? file_get_contents($_FILES[$field]['tmp_name'])
                : $currentValue;
        }
        
        $agreement_pdf = handleFileUpdate('agreement_pdf', $agreement['agreement_pdf']);
        $cheque_file = handleFileUpdate('cheque_file', $agreement['cheque_file']);

        // Update main agreement
        $updateStmt = $pdo->prepare("UPDATE partner_agreement SET
            agreement_type=?, partner_id=?, partner_name=?, partner_type=?, product_id=?, product_name=?, sub_product_name=?,
            start_date=?, end_date=?, gst=?, tds=?, agreement_pdf=?, cheque_file=?,
            cycle_id=?, cycle_name=?, num_installments=?, rm_name=?, team=?, installment_start_date = ?, installment_end_date = ?, m_partner_id=?, m_partner_name=? 
            WHERE agreement_id=?"
        );

        // Get product name
        $product_stmt = $pdo->prepare("SELECT p_name FROM products WHERE p_id = ?");
        $product_stmt->execute([$product_id]); // Use validated $product_id
        $product_name = $product_stmt->fetchColumn() ?? '';


        // Get cycle name if Payout
        $cycle_id = null;
        $cycle_name = null;
        if ($agreement_type === 'Payout' && !empty($_POST['cycle_id'])) {
            $cycle_stmt = $pdo->prepare("SELECT c_name FROM partner_cycles WHERE cycle_id = ?");
            $cycle_stmt->execute([$_POST['cycle_id']]);
            $cycle_name = $cycle_stmt->fetchColumn() ?? '';
            $cycle_id = $_POST['cycle_id'];
        }
        if ($agreement_type === 'Payout') {
            $installment_start_date = null;
            $installment_end_date = null;
        }
        $updateStmt->execute([
            $agreement_type,
            $correct_partner_id,
            $correct_partner_name,
            $partner_type,
            $agreement['product_id'],
            $agreement['product_name'],
            $_POST['sub_product_name'],
            $_POST['start_date'],
            $_POST['end_date'],
            $_POST['gst'],
            $_POST['tds'],
            $agreement_pdf,
            $cheque_file,
            $cycle_id,
            $cycle_name,
            $num_installments,
            $rm_name,
            $team,
            $installment_start_date, 
            $installment_end_date,
            $correct_m_partner_id,      // ADDED
            $correct_m_partner_name,
            $agreement_id
        ]);

     // Update installments if Advance
        if ($agreement_type === 'Advance') {
            $pdo->prepare("DELETE FROM installments WHERE agreement_id = ?")->execute([$agreement_id]);
            $start_dates = $_POST['start_date_installment'] ?? [];
            $end_dates = $_POST['end_date_installment'] ?? [];
            
            for ($i=0; $i<$num_installments; $i++) {
                if (!empty($start_dates[$i]) && !empty($end_dates[$i])) {
                    $pdo->prepare("INSERT INTO installments (agreement_id, start_date, end_date) VALUES (?,?,?)")
                        ->execute([$agreement_id, $start_dates[$i], $end_dates[$i]]);
                }
            }
        }

        header("Location: index.php?updated=1");
        exit;
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Retrieve errors from session
$errors = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);
?>
<!DOCTYPE html>
<html>
<head>
        <link rel="stylesheet" href="./css/edit_style.css">

    <title>Edit Agreement</title>
    
  <!-- <link rel="stylesheet" href="/css/style.css"> -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
 <script>
        var existingInstallments = <?= json_encode($installments) ?>;
        // Auto-fill RM and Team when Partner ID/Name changes
        function autofillPartnerDetails() {
            const partnerIdField = document.querySelector('input[name="partner_id"]');
            const partnerNameField = document.querySelector('input[name="partner_name"]');
            const rmField = document.querySelector('input[name="rm_name"]');
            const teamField = document.querySelector('input[name="team"]');

            const fetchDetails = (value, type) => {
                fetch(`fetch_id.php?type=${type}&value=${encodeURIComponent(value)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data) {
                            if (type === 'id') partnerNameField.value = data.rname || '';
                            else if (type === 'name') partnerIdField.value = data.refercode || '';
                            rmField.value = data.rm_name || '';
                            teamField.value = data.team || '';
                        }
                    })
                    .catch(err => console.error('Fetch error:', err));
            };

            partnerIdField.addEventListener('input', () => fetchDetails(partnerIdField.value, 'id'));
            partnerNameField.addEventListener('input', () => fetchDetails(partnerNameField.value, 'name'));
        }

        function toggleSections(agreementType) {
            const advancedSection = document.getElementById('advanced-section');
            const payoutSection = document.getElementById('payout-section');
            
            advancedSection.style.display = (agreementType === 'Advance') ? 'block' : 'none';
            payoutSection.style.display = (agreementType === 'Payout') ? 'block' : 'none';
        }
function setupAutocompleteForMainPartner() {
    const finqyIdField = document.querySelector('input[name="m_partner_id"]');
    const partnerNameField = document.querySelector('input[name="m_partner_name"]');
    const datalist = document.getElementById('mainPartnerSuggestions');

    function fetchSuggestions(value, fieldType) {
        if (value.length < 2) {
            datalist.innerHTML = '';
            return;
        }

        fetch(`fetch_suggestions.php?query=${encodeURIComponent(value)}&type=${fieldType}&section=main`)
            .then(response => response.json())
            .then(data => {
                datalist.innerHTML = '';
                data.forEach(item => {
                    const option = document.createElement('option');
                    option.value = fieldType === 'id' ? item.refercode : item.rname;
                    option.dataset.refercode = item.refercode;
                    option.dataset.rname = item.rname;
                    option.textContent = `${item.refercode} - ${item.rname}`;
                    datalist.appendChild(option);
                });
            })
            .catch(error => console.error('Error fetching suggestions:', error));
    }

    // Handle input events with debounce
    let timeout;
    finqyIdField.addEventListener('input', (e) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => fetchSuggestions(e.target.value, 'id'), 300);
    });

    partnerNameField.addEventListener('input', (e) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => fetchSuggestions(e.target.value, 'name'), 300);
    });

    const updateFieldsFromSelection = (selectedValue, isIdField) => {
        const selectedOption = Array.from(datalist.options).find(option => 
            option.value === selectedValue || 
            (isIdField ? option.dataset.refercode === selectedValue : option.dataset.rname === selectedValue)
        );
        
        if (selectedOption) {
            if (isIdField) {
                finqyIdField.value = selectedOption.dataset.refercode;
                partnerNameField.value = selectedOption.dataset.rname;
            } else {
                partnerNameField.value = selectedOption.dataset.rname;
                finqyIdField.value = selectedOption.dataset.refercode;
            }
        }
    };

    finqyIdField.addEventListener('change', () => {
        updateFieldsFromSelection(finqyIdField.value, true);
    });
    
    partnerNameField.addEventListener('change', () => {
        updateFieldsFromSelection(partnerNameField.value, false);
    });
}

// Add this to the DOMContentLoaded event listener
document.addEventListener('DOMContentLoaded', () => {
    // ... existing code ...
    setupAutocompleteForMainPartner();
});

function autofillMainPartnerDetails() {
    const finqyIdField = document.querySelector('input[name="m_partner_id"]');
    const partnerNameField = document.querySelector('input[name="m_partner_name"]');

    const fetchDetails = (value, type) => {
        fetch(`fetch_id.php?type=${type}&value=${encodeURIComponent(value)}&section=main`)
            .then(response => response.json())
            .then(data => {
                if (data) {
                    if (type === 'id') partnerNameField.value = data.rname || '';
                    if (type === 'name') finqyIdField.value = data.refercode || '';
                }
            })
            .catch(error => console.error('Error fetching main partner:', error));
    };

    finqyIdField.addEventListener("input", () => fetchDetails(finqyIdField.value, 'id'));
    partnerNameField.addEventListener("input", () => fetchDetails(partnerNameField.value, 'name'));
}

// Add this to the DOMContentLoaded event listener
document.addEventListener('DOMContentLoaded', () => {
    // ... existing code ...
    autofillMainPartnerDetails();
});

function generateInstallmentDates() {
    const num = parseInt(document.getElementById('num_installments').value);
    const container = document.getElementById('installments_container');
    container.innerHTML = '';

    for (let i = 0; i < num; i++) {
        const div = document.createElement('div');
        div.className = 'cycle-section';
        const existingStart = existingInstallments[i] ? existingInstallments[i].start_date : '';
        const existingEnd = existingInstallments[i] ? existingInstallments[i].end_date : '';
        div.innerHTML = `
            <h4>Installment ${i+1}</h4>
            <div class="col-2">
                <label>Start Date: 
                    <input type="date" name="start_date_installment[]"
                           value="${existingStart}"> <!-- Removed onchange handler -->
                </label>
                <label>End Date: 
                    <input type="date" name="end_date_installment[]"
                           value="${existingEnd}">
                </label>
            </div>`;
        container.appendChild(div);
    }
}

        function updateGSTField() {
            const partnerType = document.querySelector('select[name="partner_type"]').value;
            const gstField = document.getElementById('gst_percentage');
            
            if (partnerType === 'GST') {
                gstField.removeAttribute('readonly');
                gstField.required = true;
                gstField.style.backgroundColor = '';
            } else {
                gstField.value = 0;
                gstField.setAttribute('readonly', 'readonly');
                gstField.required = false;
                gstField.style.backgroundColor = '#f5f5f5';
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            autofillPartnerDetails();
            toggleSections('<?= $agreement['agreement_type'] ?>');
            updateGSTField(); // Set initial GST field state
            
            // Add change listener to partner type dropdown
            document.querySelector('select[name="partner_type"]').addEventListener('change', function() {
                updateGSTField();
            });
            
            <?php if ($agreement['agreement_type'] === 'Advance'): ?>
                document.getElementById('num_installments').value = <?= $agreement['num_installments'] ?>;
                generateInstallmentDates();
            <?php endif; ?>
        });
    </script>
</head>
<body>
    <div class="container">
             <?php if (!empty($errors)): ?>
            <div class="error-alert">
                <?php foreach ($errors as $error): ?>
                    <p><?= $error ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <h2>Edit Agreement #<?= $agreement_id ?></h2>
        <a href="index.php" class="back-btn">← Back</a>

        <form method="POST" enctype="multipart/form-data" action="edit_partner.php?id=<?= $agreement_id ?>">
            <!-- Agreement Type -->
<!-- Update Agreement Type Section -->
<div class="form-section">
    <h3>Agreement Type</h3>
    <input type="hidden" name="agreement_type" value="<?= $agreement['agreement_type'] ?>">
    <input type="text" value="<?= $agreement['agreement_type'] ?>" disabled>
</div>

<!-- Update Main Partner Details Section -->
<div class="form-section">
    <h3>Main Partner Details</h3>
    <div class="col-2">
        <!-- Partner ID -->
        <div>
            <label>Finqy ID
                <input type="text" name="m_partner_id" 
                       value="<?= htmlspecialchars($agreement['m_partner_id']) ?>"
                       list="mainPartnerSuggestions">
            </label>
        </div>
        <!-- Partner Name -->
        <div>
            <label>Partner Name
                <input type="text" name="m_partner_name" 
                       value="<?= htmlspecialchars($agreement['m_partner_name']) ?>"
                       list="mainPartnerSuggestions">
            </label>
        </div>
    </div>
</div>

<!-- Update Partner Details Section -->
<div class="form-section">
    <h3>Partner Details</h3>
    <div class="col-2">
        <!-- Partner ID -->
        <div>
            <label>Finqy ID
                <input type="text" value="<?= htmlspecialchars($agreement['partner_id']) ?>" readonly>
            </label>
            <input type="hidden" name="partner_id" value="<?= $agreement['partner_id'] ?>">
        </div>
        <!-- Partner Name -->
        <div>
            <label>Partner Name
                <input type="text" value="<?= htmlspecialchars($agreement['partner_name']) ?>" readonly>
            </label>
            <input type="hidden" name="partner_name" value="<?= $agreement['partner_name'] ?>">
        </div>
       <div>
            <label>RM Name
                <input type="text" name="rm_name" value="<?= htmlspecialchars($agreement['rm_name']) ?>" readonly>
            </label>
            <input type="hidden" name="rm_name" value="<?= $agreement['rm_name'] ?>">
        </div>
        <div>
            <label>Team
                <input type="text" name="team"  value="<?= htmlspecialchars($agreement['team']) ?>" readonly>
            </label>
            <input type="hidden" name="team" value="<?= $agreement['team'] ?>">
        </div>
    
        <div>
            <label>Product Name
                <input type="text" name="product_name" value="<?= htmlspecialchars($agreement['product_name']) ?>" readonly>
            </label>
            <input type="hidden" name="product_id" value="<?= $agreement['product_id'] ?>">
        </div>

        <!-- Add Sub-Product Field -->
        <div>
            <label>Sub-Product:
                <input type="text" name="sub_product_name" 
                    value="<?= htmlspecialchars($agreement['sub_product_name'] ?? '') ?>" readonly>
            </label>
        </div>
        <!-- NEW PARTNER TYPE FIELD -->
        <div>
            <label>Type Of Partner:
                <select name="partner_type" required>
                    <option value="">Select Type</option>
                    <option value="GST" <?= ($agreement['partner_type'] ?? '') === 'GST' ? 'selected' : '' ?>>GST</option>
                    <option value="Non-GST" <?= ($agreement['partner_type'] ?? '') === 'Non-GST' ? 'selected' : '' ?>>Non-GST</option>
                </select>
            </label>
        </div>
    </div>
</div>

<!-- Update Agreement Period -->
<div class="form-section">
    <h3>Agreement Period</h3>
    <div class="col-2">
        <label>Start Date: 
            <input type="date" value="<?= $agreement['start_date'] ?>" readonly>
            <input type="hidden" name="start_date" value="<?= $agreement['start_date'] ?>">
        </label>
        <label>End Date: 
            <input type="date" value="<?= $agreement['end_date'] ?>" readonly>
            <input type="hidden" name="end_date" value="<?= $agreement['end_date'] ?>">
        </label>
    </div>
</div>

<!-- Update Financial Details -->
<div class="form-section">
    <h3>Financial Details</h3>
    <div class="col-2">
            <label>GST (%): 
                <input type="number" name="gst" id="gst_percentage" 
                       value="<?= $agreement['gst'] ?>" 
                       <?= ($agreement['partner_type'] === 'Non-GST' ? 'readonly' : '') ?>>
            </label>
            <label>TDS (%): 
                <input type="number" name="tds" 
                       value="<?= $agreement['tds'] ?>" readonly
                       style="background-color: #f5f5f5;">
            </label>
    </div>
</div>

<!-- Update Document Uploads to Show Info -->
<!-- Update Document Uploads to Show Info -->
<div class="form-section">
    <h3>Documents</h3>
    <div class="col-2">
        <label>Agreement PDF: 
            <em>File already uploaded (cannot modify)</em>
        </label>
        <?php if ($agreement['agreement_type'] !== 'Payout'): ?>
            <label>Cheque File: 
                <em>File already uploaded (cannot modify)</em>
            </label>
        <?php endif; ?>
    </div>
</div>
            <!-- Advanced Section -->
        <div class="form-section conditional-section" id="advanced-section">
            <h3>Installments</h3>
            <div class="form-group">
                <label for="num_installments">Number of Installments</label>
                <input type="number" name="num_installments" class="form-control" min="0" value="<?= htmlspecialchars($agreement['num_installments'] ?? '') ?>">            </div>
            <div class="form-group">
                <label for="installment_start_date">Installment Start Date</label>
                <input type="date" name="installment_start_date" class="form-control" value="<?= htmlspecialchars($installment_start_date ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="installment_end_date">Installment End Date</label>
                <input type="date" name="installment_end_date" class="form-control" value="<?= htmlspecialchars($installment_end_date ?? '') ?>">
            </div>
            <div id="installments_container"></div>
        </div>
            <!-- Payout Section -->
            <div class="form-section conditional-section" id="payout-section">
                <h3>Payout Cycle</h3>
                <select name="cycle_id" <?= $agreement['agreement_type'] === 'Payout' ? 'required' : '' ?>>
                    <option value="">Select Cycle</option>
                    <?php foreach ($cycles as $cycle): ?>
                        <option value="<?= $cycle['cycle_id'] ?>" 
                            <?= $cycle['cycle_id'] == $agreement['cycle_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cycle['c_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="save-btn">Save Changes</button>
                <a href="index.php" class="cancel-btn">Cancel</a>
            </div>
            <datalist id="mainPartnerSuggestions"></datalist>
        </form>
    </div>
</body>
</html>
