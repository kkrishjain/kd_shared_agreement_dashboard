<?php
session_start();
require '../config/database.php';


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Constants
const ALLOWED_MIME_TYPES = [
    'agreement' => ['application/pdf'],
    'cheque' => ['application/pdf', 'image/jpeg', 'image/png', 'image/gif']
];
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

// Initialize variables
$error = $success = '';
$partners = $products = $sub_products = $cycles = [];
$br_id = $_GET['br_id'] ?? null;

try {
    // Fetch initial data
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT refercode AS finqy_id, rname AS partner_name FROM first_register");
    $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT p_id, p_name FROM products WHERE p_status = '1'");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT cycle_id, c_name FROM partner_cycles");
    $cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT sp_id, sp_name FROM sub_products WHERE sp_status = '1' AND sp_p_id = 4");
    $sub_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Validate CSRF token
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid CSRF token");
        }

        // Validate required fields
        $required = [
            'agreement_type', 'br_id', 'br_name', 'm_br_id', 'm_br_name', 
            'sub_product_id', 'start_date', 'end_date', 'gst', 'tds', 'partner_type'
        ];
        foreach ($required as $field) {
            if (empty(trim($_POST[$field] ?? ''))) {
                throw new Exception("Required field missing: " . htmlspecialchars($field));
            }
        }

        // Validate partner type and GST
        $partnerType = $_POST['partner_type'];
        $gst = ($partnerType === 'Non-GST') ? 0 : (float)$_POST['gst'];
        $tds = (float)$_POST['tds'];
        if ($partnerType === 'GST' && ($gst < 0 || $gst > 100)) {
            throw new Exception("GST must be between 0 and 100");
        }
        if ($tds < 0 || $tds > 100) {
            throw new Exception("TDS must be between 0 and 100");
        }

        // Validate product (optional)
        $productId = $_POST['product_id'] ?? null;
        $product = null;
        if (!empty($productId)) {
            $product = fetchProduct($productId);
            if (!$product) {
                throw new Exception("Invalid product selected");
            }
        }

        // Validate subproduct
        $subproductId = $_POST['sub_product_id'] ?? null;
        $subproduct = null;
        if (!empty($subproductId)) {
            $subproduct = fetchSubProduct($subproductId);
            if (!$subproduct) {
                throw new Exception("Invalid sub product selected");
            }
        }

        // Process agreement type
        $agreementType = $_POST['agreement_type'];
        if (!in_array($agreementType, ['Advance', 'Payout'])) {
            throw new Exception("Invalid agreement type");
        }

        // Process cycle data for Payout
        $cycleData = null;
        if ($agreementType === 'Payout') {
            if (empty($_POST['cycle_id'])) {
                throw new Exception("Cycle ID is required for Payout agreement");
            }
            $cycleData = fetchCycle($_POST['cycle_id']);
        }

        // Validate dates
        validateDateRange($_POST['start_date'], $_POST['end_date']);

        // Process files
        $agreementFile = processUploadedFile('agreement_pdf', ALLOWED_MIME_TYPES['agreement']);
        $chequeFile = ($agreementType === 'Advance') ? processUploadedFile('cheque_file', ALLOWED_MIME_TYPES['cheque']) : null;

        // Start transaction
        $pdo->beginTransaction();

        // Insert agreement
        $agreementId = insertAgreement([
            'type' => $agreementType,
            'partner_id' => $_POST['br_id'],
            'partner_name' => $_POST['br_name'],
            'm_partner_id' => $_POST['m_br_id'],
            'm_partner_name' => $_POST['m_br_name'],
            'product_id' => $product['p_id'] ?? null,
            'product_name' => $product['p_name'] ?? 'Not Assigned',
            'sub_product_id' => $subproduct['sp_id'] ?? null,
            'sub_product_name' => $subproduct['sp_name'] ?? 'Not Assigned',
            'partner_type' => $partnerType,
            'start_date' => $_POST['start_date'],
            'end_date' => $_POST['end_date'],
            'gst' => $gst,
            'tds' => $tds,
            'agreement_pdf' => $agreementFile,
            'cheque_file' => $chequeFile,
            'cycle_id' => $cycleData['cycle_id'] ?? null,
            'cycle_name' => $cycleData['c_name'] ?? null,
            'num_installments' => ($agreementType === 'Advance') ? ($_POST['num_installments'] ?? 0) : 0,
            'rm_name' => $_POST['rm_name'] ?? '',
            'team' => $_POST['team'] ?? '',
            'installment_start_date' => ($agreementType === 'Advance') ? ($_POST['installment_start_date'] ?? null) : null,
            'installment_end_date' => ($agreementType === 'Advance') ? ($_POST['installment_end_date'] ?? null) : null
        ]);

        if ($agreementType === 'Advance') {
            $requiredInstallmentFields = ['num_installments', 'installment_start_date', 'installment_end_date'];
            foreach ($requiredInstallmentFields as $field) {
                if (empty(trim($_POST[$field] ?? ''))) {
                    throw new Exception("Required field missing: " . htmlspecialchars($field));
                }
            }
            validateDateRange($_POST['installment_start_date'], $_POST['installment_end_date']);
        }

        $pdo->commit();
        $_SESSION['success'] = "Agreement created successfully!";
        header("Location: index.php");
        exit();

    } catch (Exception $e) {
        error_log("Agreement Error: " . $e->getMessage());
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}


// Helper functions
function fetchProduct($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT p_id, p_name FROM products WHERE p_id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fetchSubProduct($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT sp_id, sp_name FROM sub_products WHERE sp_id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fetchCycle($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT cycle_id, c_name FROM partner_cycles WHERE cycle_id = ?");
    $stmt->execute([$id]);
    $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
    return $cycle ? $cycle : throw new Exception("Invalid cycle ID");
}

function validateDateRange($start, $end) {
    $startDate = new DateTime($start);
    $endDate = new DateTime($end);
    
    if ($startDate > $endDate) {
        throw new Exception("End date cannot be before start date");
    }
}

function processUploadedFile($field, $allowedTypes) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload error for $field");
    }

    if ($_FILES[$field]['size'] > MAX_FILE_SIZE) {
        throw new Exception("File too large. Maximum size: 5MB");
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES[$field]['tmp_name']);

    if (!in_array($mime, $allowedTypes)) {
        throw new Exception("Invalid file type for $field");
    }

    return file_get_contents($_FILES[$field]['tmp_name']);
}

// function processInstallments($postData) {
//     $installments = [];
//     $num = (int)($postData['num_installments'] ?? 0);
    
//     if ($num < 1) {
//         throw new Exception("Invalid number of installments");
//     }

//     $starts = $postData['start_date_instalment'] ?? [];
//     $ends = $postData['end_date_instalment'] ?? [];

//     if (count($starts) !== $num || count($ends) !== $num) {
//         throw new Exception("Installment dates mismatch");
//     }

//     for ($i = 0; $i < $num; $i++) {
//         if (empty($starts[$i]) || empty($ends[$i])) {
//             throw new Exception("Missing dates for installment " . ($i + 1));
//         }
//         $installments[] = [
//             'start' => $starts[$i],
//             'end' => $ends[$i]
//         ];
//     }

//     return $installments;
// }

function insertAgreement($data) {
    global $pdo;

    // Handle Non-GST partners
    $gst = ($data['partner_type'] === 'Non-GST') ? 0 : $data['gst'];
    
    $stmt = $pdo->prepare("
        INSERT INTO partner_agreement (
            agreement_type, partner_id, partner_name, m_partner_id, m_partner_name, 
            product_id, product_name, sub_product_id, sub_product_name, partner_type,
            start_date, end_date, gst, tds, agreement_pdf, cheque_file,
            cycle_id, cycle_name, num_installments, rm_name, team, 
            installment_start_date, installment_end_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $data['type'],
        $data['partner_id'],
        $data['partner_name'],
        $data['m_partner_id'],
        $data['m_partner_name'],
        $data['product_id'] ?? null,
        $data['product_name'] ?? 'Not Assigned',
        $data['sub_product_id'] ?? null,
        $data['sub_product_name'] ?? 'Not Assigned',
        $data['partner_type'],
        $data['start_date'],
        $data['end_date'],
        $gst,
        $data['tds'],
        $data['agreement_pdf'],
        $data['cheque_file'],
        $data['cycle_id'],
        $data['cycle_name'],
        $data['num_installments'],
        $data['rm_name'] ?? '',
        $data['team'] ?? '',
        $data['installment_start_date'],
        $data['installment_end_date']
    ]);

    return $pdo->lastInsertId();
}

?>
<!DOCTYPE html>
<html>
<head>
        <link rel="stylesheet" href="./css/add_style.css">

    <title>Add Agreement</title>
    
    <script>
        function toggleGSTField() {
            const partnerType = document.querySelector('select[name="partner_type"]');
            const gstInput = document.querySelector('input[name="gst"]');
            
            if (partnerType.value === "Non-GST") {
                gstInput.value = "0";
                gstInput.readOnly = true;
                gstInput.style.backgroundColor = "#f5f5f5";
            } else {
                gstInput.readOnly = false;
                gstInput.style.backgroundColor = "";
            }
        }

        function toggleSections(agreementType) {
    const advancedSection = document.getElementById('advanced-section');
    const payoutSection = document.getElementById('payout-section');
    const chequeField = document.querySelector('input[name="cheque_file"]').closest('label');
    const cycleSelect = document.querySelector('select[name="cycle_id"]');
    // Reset all conditional fields
     advancedSection.style.display = (agreementType === 'Advance') ? 'block' : 'none';
    payoutSection.style.display = (agreementType === 'Payout') ? 'block' : 'none';
        cycleSelect.required = false;
    cycleSelect.disabled = true;

    // Toggle required attributes
    document.querySelectorAll('#advanced-section [required]').forEach(el => el.required = false);
    document.querySelectorAll('#payout-section [required]').forEach(el => el.required = false);

    if (agreementType === 'Advance') {
        advancedSection.style.display = 'block';
        chequeField.style.display = 'block';
        document.querySelectorAll('#advanced-section [required]').forEach(el => el.required = true);
    } 
    else if (agreementType === 'Payout') {
        payoutSection.style.display = 'block';
        chequeField.style.display = 'none';
                // cycleSelect.required = true;
        // cycleSelect.disabled = false;
        document.querySelectorAll('#payout-section [required]').forEach(el => el.required = true);
    }
       if (agreementType === 'Payout') {
        cycleSelect.disabled = false; // Allow submission
        cycleSelect.required = true;  // Enforce selection
    } else {
        cycleSelect.disabled = true;
        cycleSelect.required = false;
    }
}
        function validateForm() {
    const agreementType = document.getElementById('agreement_type');
    if (agreementType.value === "") {
        alert("Please select Agreement Type first");
        agreementType.focus();
        return false;
    }
    return validateFiles();
}
function enableFormFields() {
    const agreementType = document.getElementById('agreement_type');
    const allFields = document.querySelectorAll('input, select, button, textarea');
    
    allFields.forEach(field => {
        if(field.id !== 'agreement_type') {
            // Enable only if agreement type is selected
            field.disabled = agreementType.value === '';
            
            // Special handling for date inputs that get enabled later
            if(field.name === 'end_date') {
                field.disabled = !document.querySelector('input[name="start_date"]').value;
            }
        }
    });
    
    // Re-initialize date validation
    updateDateLimits();
    toggleGSTField();
    toggleSections(document.getElementById('agreement_type').value);
}

document.addEventListener("DOMContentLoaded", function () {
    // Disable all fields initially
    const allFields = document.querySelectorAll('input, select, button, textarea');
    allFields.forEach(field => {
        if(field.id !== 'agreement_type') {
            field.disabled = true;
            field.addEventListener('click', function(e) {
                if(this.disabled) {
                    alert('Please select Agreement Type first');
                    document.getElementById('agreement_type').focus();
                    e.preventDefault();
                }
            });
        }
    });
    
    // Initialize other functions
    setupAutocomplete();
    autofillPartnerDetails();
    autofillMainPartnerDetails();
    updateDateLimits();
    toggleSections('');
       document.querySelector('input[name="start_date"]').addEventListener('change', updateDateLimits);

    // Add input event listener to agreement type
    document.getElementById('agreement_type').addEventListener('change', enableFormFields);
});

function autofillMainPartnerDetails() {
    const finqyIdField = document.getElementById("m_br_id");
    const partnerNameField = document.getElementById("m_br_name");

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

function autofillPartnerDetails() {
    const finqyIdField = document.getElementById("br_id");
    const partnerNameField = document.getElementById("br_name");
    const rmField = document.getElementById("rm_name");
    const teamField = document.getElementById("team");
    const productField = document.getElementById("product_name");
    const productIdField = document.getElementById("product_id");

    const fetchDetails = (value, type) => {
        fetch(`fetch_id.php?type=${type}&value=${encodeURIComponent(value)}&section=partner`)
            .then(response => response.json())
            .then(data => {
                if (data && data.refercode) {
                    // Autofill Partner Name/FINQy ID
                    if (type === 'id') partnerNameField.value = data.rname || '';
                    if (type === 'name') finqyIdField.value = data.refercode || '';

                    // Autofill RM and Team
                    rmField.value = data.rm_name || '';
                    teamField.value = data.team || '';

                    // Fetch product details if available
                    const productId = data.product;
                    if (productId) {
                        fetch(`get_product.php?product_id=${productId}`)
                            .then(response => response.json())
                            .then(productData => {
                                if (productData && !productData.error) {
                                    productIdField.value = productData.p_id;
                                    productField.value = productData.p_name;
                                } else {
                                    productIdField.value = '';
                                    productField.value = 'Not Assigned';
                                }
                            })
                            .catch(error => console.error('Error fetching product:', error));
                    } else {
                        productIdField.value = '';
                        productField.value = 'Not Assigned';
                    }
                } else {
                    // Clear fields if no data found
                    if (type === 'id') partnerNameField.value = '';
                    if (type === 'name') finqyIdField.value = '';
                    rmField.value = '';
                    teamField.value = '';
                    productIdField.value = '';
                    productField.value = 'Not Assigned';
                }
            })
            .catch(error => console.error('Error fetching partner details:', error));
    };

    finqyIdField.addEventListener("input", () => fetchDetails(finqyIdField.value, 'id'));
    partnerNameField.addEventListener("input", () => fetchDetails(partnerNameField.value, 'name'));
}

function updateDateLimits() {
    const startDate = document.querySelector('input[name="start_date"]');
    const endDate = document.querySelector('input[name="end_date"]');
    
    if (startDate.value) {
        endDate.min = startDate.value;
        endDate.disabled = false;
    } else {
        endDate.disabled = true;
    }
}

        function updateCycleDateLimits(container) {
            const cycleContainers = container.querySelectorAll('.cycle-section');
            
            cycleContainers.forEach((cycleDiv, index) => {
                const bcStart = cycleDiv.querySelector('input[name="bc_start_date[]"]');
                const bcEnd = cycleDiv.querySelector('input[name="bc_end_date[]"]');
                const icStart = cycleDiv.querySelector('input[name="ic_start_date[]"]');
                const icEnd = cycleDiv.querySelector('input[name="ic_end_date[]"]');
                const pcStart = cycleDiv.querySelector('input[name="pc_start_date[]"]');
                const pcEnd = cycleDiv.querySelector('input[name="pc_end_date[]"]');
                
                if (bcStart) {
                    bcStart.addEventListener('change', function() {
                        if (this.value) {
                            bcEnd.min = this.value;
                            bcEnd.disabled = false;
                        } else {
                            bcEnd.disabled = true;
                        }
                    });
                }
                
                if (icStart) {
                    icStart.addEventListener('change', function() {
                        if (this.value) {
                            icEnd.min = this.value;
                            icEnd.disabled = false;
                        } else {
                            icEnd.disabled = true;
                        }
                    });
                }
                
                if (pcStart) {
                    pcStart.addEventListener('change', function() {
                        if (this.value) {
                            pcEnd.min = this.value;
                            pcEnd.disabled = false;
                        } else {
                            pcEnd.disabled = true;
                        }
                    });
                }
            });
        }

        function validateDateOrder() {
            const start = new Date(document.querySelector('input[name="start_date"]').value);
            const end = new Date(document.querySelector('input[name="end_date"]').value);
            
            if (start > end) {
                alert("End date cannot be before start date");
                return false;
            }
            return true;
        }
        // Add this date validation function
        function updateInstallmentEndDate(index) {
            const startDate = document.querySelectorAll('input[name="start_date_instalment[]"]')[index];
            const endDate = document.querySelectorAll('input[name="end_date_instalment[]"]')[index];
            
            if (startDate.value) {
                endDate.min = startDate.value;
                endDate.disabled = false;
            } else {
                endDate.disabled = true;
            }
        }
        function validateFiles() {
            const agreementType = document.getElementById('agreement_type');
            if (agreementType.value === "") {
                alert("Please select Agreement Type first");
                agreementType.focus();
                return false;
            }
            const agreementFile = document.querySelector('input[name="agreement_pdf"]');
            const chequeFile = document.querySelector('input[name="cheque_file"]');

            if (agreementFile.files.length > 0) {
                const file = agreementFile.files[0];
                if (file.type !== 'application/pdf') {
                    alert('Agreement file must be a PDF');
                    return false;
                }
            }
            if (chequeFile.files.length > 0) {
                const file = chequeFile.files[0];
                const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Cheque file must be PDF or image (JPEG, PNG, GIF)');
                    return false;
                }
            }
            
            return true;
        }
        
function setupAutocomplete() {
    // Create autocomplete for main partner fields (only first_register table)
    setupAutocompleteForPair(
        document.getElementById("m_br_id"),
        document.getElementById("m_br_name"),
        document.getElementById("mainPartnerSuggestions"),
        "main"  // This tells the backend to only use first_register table
    );
    
    // Create autocomplete for regular partner fields (all tables)
    setupAutocompleteForPair(
        document.getElementById("br_id"),
        document.getElementById("br_name"),
        document.getElementById("partnerSuggestions"),
        "partner"
    );
}

function setupAutocompleteForPair(finqyIdField, partnerNameField, datalist, section) {
    let lastSelectedValue = null;
    let isProcessingSelection = false;
    let selectedSource = null;

    function fetchSuggestions(value, fieldType) {
        if (value.length < 2) {
            datalist.innerHTML = '';
            return;
        }

        fetch(`fetch_suggestions.php?query=${encodeURIComponent(value)}&type=${fieldType}&section=${section}`)
            .then(response => response.json())
            .then(data => {
                datalist.innerHTML = '';
                data.forEach(item => {
                    const option = document.createElement('option');
                    option.value = fieldType === 'id' ? item.refercode : item.rname;
                    option.dataset.refercode = item.refercode;
                    option.dataset.rname = item.rname;
                    option.dataset.source = item.source;
                    
                    let sourceIndicator = '';
                    if (section === 'partner') {
                        switch(item.source) {
                            case 'partner': sourceIndicator = '👤 Partner'; break;
                            case 'connector': sourceIndicator = '🔌 Connector'; break;
                            case 'team': sourceIndicator = '👥 Team'; break;
                            default: sourceIndicator = item.source;
                        }
                    }
                    
                    option.textContent = section === 'partner' 
                        ? `${item.refercode} - ${item.rname} (${sourceIndicator})`
                        : `${item.refercode} - ${item.rname}`;
                    datalist.appendChild(option);
                });
            })
            .catch(error => console.error('Error fetching suggestions:', error));
    }

    let timeout;
    finqyIdField.addEventListener('input', (e) => {
        if (isProcessingSelection) return;
        clearTimeout(timeout);
        timeout = setTimeout(() => fetchSuggestions(e.target.value, 'id'), 300);
    });

    partnerNameField.addEventListener('input', (e) => {
        if (isProcessingSelection) return;
        clearTimeout(timeout);
        timeout = setTimeout(() => fetchSuggestions(e.target.value, 'name'), 300);
    });

     const updateFieldsFromSelection = (selectedValue, isIdField) => {
        if (selectedValue === lastSelectedValue) return;
        
        isProcessingSelection = true;
        lastSelectedValue = selectedValue;

        const selectedOption = Array.from(datalist.options).find(option => 
            option.value === selectedValue || 
            (isIdField ? option.dataset.refercode === selectedValue : option.dataset.rname === selectedValue)
        );
        
        if (selectedOption) {
            selectedSource = selectedOption.dataset.source;
            
            // Always update both fields with the selected values
            finqyIdField.value = selectedOption.dataset.refercode;
            partnerNameField.value = selectedOption.dataset.rname;

            // For partner entries, fetch additional details
            if (selectedSource === 'partner') {
                fetch(`fetch_id.php?type=id&value=${encodeURIComponent(selectedOption.dataset.refercode)}&section=partner`)
                    .then(response => response.json())
                    .then(data => {
                        if (data && data.refercode) {
                            // Update RM and team fields
                            document.getElementById("rm_name").value = data.rm_name || '';
                            document.getElementById("team").value = data.team || '';
                            
                            // Update product information if available
                            const productId = data.product;
                            if (productId) {
                                fetch(`get_product.php?product_id=${productId}`)
                                    .then(response => response.json())
                                    .then(productData => {
                                        if (productData && !productData.error) {
                                            document.getElementById("product_id").value = productData.p_id;
                                            document.getElementById("product_name").value = productData.p_name;
                                        }
                                    });
                            }
                        }
                    });
            } else {
                // For connector/team entries, we'll preserve the selected names
                // and clear the additional fields since we don't have that data
                document.getElementById("rm_name").value = '';
                document.getElementById("team").value = '';
                document.getElementById("product_id").value = '';
                document.getElementById("product_name").value = 'Not Assigned';
                
                // For connector/team entries, we need to ensure we're showing the correct name
                // This is already handled by setting partnerNameField.value above
            }
        }
        
        isProcessingSelection = false;
    };

    finqyIdField.addEventListener('change', () => {
        if (!isProcessingSelection) {
            updateFieldsFromSelection(finqyIdField.value, true);
        }
    });
    
    partnerNameField.addEventListener('change', () => {
        if (!isProcessingSelection) {
            updateFieldsFromSelection(partnerNameField.value, false);
        }
    });
}
    </script>
</head>

<body>
<a href="index.php" class="back-btn">← Back</a>
    <h2>Add Agreement</h2>
    <div class="form-section">
    <?php if (!empty($error)) : ?>
        <div class="error" style="color: red; padding: 10px; margin: 10px 0; border: 1px solid red;">
            Error: <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm() && validateDateOrder()">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <h3>Agreement Type</h3>
            <label>Type:
                <select name="agreement_type" id="agreement_type" required 
                        onchange="enableFormFields(); toggleSections(this.value)">
                    <option value="">Select Agreement Type</option>
                    <option value="Advance">Advance</option>
                    <option value="Payout">Payout</option>
                </select>
            </label>
        </div>
        <div class="form-section">
            <h3>Main Partner Details</h3>
            <div class="form-section col-2">
            <?php if ($br_id) : 
                ?>
                <input type="hidden" name="br_id" id="br_id" value="<?= $partner_data['finqy_id'] ?? '' ?>">
                <input type="hidden" name="br_name" id="br_name" value="<?= $partner_data['partner_name'] ?? '' ?>

                <div class="broker-info">
                <p>Finqy ID: <?= $partner_data['finqy_id'] ?? '' ?></p>
                <p>Partner Name: <?= $partner_data['partner_name'] ?? '' ?></p>
                </div>
            <?php else : ?>
                <div>
                    <label>Finqy ID: 
                        <input type="text" name="m_br_id" id="m_br_id" required list="mainPartnerSuggestions">
                    </label>
                </div>
                <div>
                    <label>Partner Name: 
                        <input type="text" name="m_br_name" id="m_br_name" required list="mainPartnerSuggestions">
                        <datalist id="mainPartnerSuggestions"></datalist>
                    </label>
                </div>
            <?php endif; ?>
            </div>

        <div class="form-section">
            <h3>Partner Details</h3>
            <div class="form-section col-2">
            <?php if ($br_id) : ?>
            <input type="hidden" name="br_id" id="br_id" value="<?= $partner_data['finqy_id'] ?? '' ?>">
            <input type="hidden" name="br_name" id="br_name" value="<?= $partner_data['partner_name'] ?? '' ?>">
            <input type="hidden" name="product_id" id="product_id" value="<?= $product_id ?>">
            <div class="broker-info">
                <p>Finqy ID: <?= $partner_data['finqy_id'] ?? '' ?></p>
                <p>Partner Name: <?= $partner_data['partner_name'] ?? '' ?></p>
                <p>Product: <?= htmlspecialchars($product_name) ?></p>
            </div>
            <?php else : ?>
                <div>
                    <label>Finqy ID: 
                        <input type="text" name="br_id" id="br_id" required list="partnerSuggestions">
                    </label>
                </div>
                <div>
                    <label>Partner Name: 
                        <!-- Added datalist to Partner Name field -->
                        <input type="text" name="br_name" id="br_name" required list="partnerSuggestions">
                        <datalist id="partnerSuggestions"></datalist>
                    </label>
                </div>
            <?php endif; ?>
            <div>
                <label>RM Name:
                    <input type="text" name="rm_name" id="rm_name" readonly>
                </label>
            </div>
             <div>
                <label>Team: 
                    <input type="text" name="team" id="team" readonly>
                </label>
            </div>
   
        
            <div class="">
                <label>Product: 
                    <input type="text" id="product_name" readonly>
                    <input type="hidden" name="product_id" id="product_id">
                </label>
            </div>

            <div class="">
                <label>Sub Product:
                    <select name="sub_product_id" required>
                        <option value="">Select Product</option>
                        <?php foreach ($sub_products as $subproduct): ?>
                            <option value="<?= $subproduct['sp_id'] ?>">
                                <?= htmlspecialchars($subproduct['sp_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <!-- Inside the partner details section after the sub product field -->
            <div style="grid-column: span 2;">
                <label>Type Of Partner:
                    <select name="partner_type" required onchange="toggleGSTField()">
                        <option value="">Select Type</option>
                        <option value="GST">GST</option>
                        <option value="Non-GST">Non-GST</option>
                    </select>
                </label>
            </div>
    </div>

        <div class="form-section">
            <h3>Agreement Period</h3>
            <div class="col-2">
                <label>Start Date: <input type="date" name="start_date" required></label>
                <label>End Date: <input type="date" name="end_date" required disabled></label>
            </div>
        </div>

        <div class="form-section">
            <h3>Financial Details</h3>
            <div class="col-2">
                <label>GST (%): 
                    <input type="number" name="gst" id="gst_field" step="0.01" min="0" max="100" required>
                </label>
                <label>TDS (%): 
                    <input type="number" name="tds" step="0.01" min="0" max="100" required>
                </label>
            </div>
        </div>

        <div class="form-section">
            <h3>Document Uploads</h3>
            <div class="col-2">
                <label>Agreement File (PDF only): 
                    <input type="file" name="agreement_pdf" accept="application/pdf" required>
                </label>
                <label>Blank Cheque (PDF/Image): 
                    <input type="file" name="cheque_file" accept="application/pdf, image/*">
                </label>
            </div>
        </div>

        <div class="form-section conditional-section" id="advanced-section">
            <h3>Repayment Installments</h3>
            <label>Number of Installments: 
                <input type="number" id="num_installments" name="num_installments" 
                    min="1" max="99" required>
            </label>
            <div class="col-2">
                <label>Installment Start Date: 
                    <input type="date" name="installment_start_date" required>
                </label>
                <label>Installment End Date: 
                    <input type="date" name="installment_end_date" required>
                </label>
            </div>
        </div>
        <div class="form-section conditional-section" id="payout-section">
            <h3>Partner Cycles</h3>
            <label>Select Cycle:
                <select name="cycle_id" required>
                    <option value="">Select a Cycle</option>
                    <?php foreach ($cycles as $cycle): ?>
                        <option value="<?= $cycle['cycle_id'] ?>">
                            <?= htmlspecialchars($cycle['c_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>


       
        <button type="submit">Save Agreement</button>
        <datalist id="partnerSuggestions"></datalist>
    </form>

    <script>
// Initialize validation on page load
document.addEventListener("DOMContentLoaded", function () {
    // Initialize GST field state
    toggleGSTField();
    
    // Add partner type change handler
    document.querySelector('select[name="partner_type"]')
        .addEventListener('change', toggleGSTField);
});
</script>
</body>
</html>