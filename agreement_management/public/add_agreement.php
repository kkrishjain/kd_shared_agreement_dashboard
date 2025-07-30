<?php
include '../config/database.php';
$br_id = $_GET['br_id'] ?? null;
$brokers = $pdo->query("SELECT br_id, br_name FROM brokers")->fetchAll(PDO::FETCH_ASSOC);
$companies = $pdo->query("SELECT c_id, c_name FROM companies")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate file types
    $allowedAgreementTypes = ['application/pdf'];
    
    $agreementFileType = $_FILES['agreement_file']['type'];
    
    if (!in_array($agreementFileType, $allowedAgreementTypes)) {
        die("Error: Agreement file must be a PDF");
    }
    

    // Validate dates
    $start_date = new DateTime($_POST['start_date']);
    $end_date = new DateTime($_POST['end_date']);
    if ($end_date < $start_date) {
        die("Error: End date cannot be before start date");
    }

// Agreement data
$broker_id = $_POST['br_id'];
$broker_name = $_POST['br_name'];
$company_id = $_POST['company_id'];
$transaction_in_name_of = $_POST['transaction_in_name_of']; 
$start_date = $_POST['start_date'];
$end_date = $_POST['end_date'];
$num_cycles = $_POST['num_cycles'];
$gst = $_POST['gst'];
$tds = $_POST['tds'];
$biz_mis = $_POST['biz_mis'];
$com_statement = $_POST['com_statement'];
$frequency = $_POST['frequency'];
$mis_type = $_POST['mis_type'];

// File upload
$agreement_file = file_get_contents($_FILES['agreement_file']['tmp_name']);

// Insert agreement
$stmt = $pdo->prepare("INSERT INTO agreements (broker_id, broker_name, company_id,transaction_in_name_of, start_date, end_date, num_cycles, gst, tds, agreement_file, biz_mis, com_statement, frequency, mis_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$broker_id, $broker_name, $company_id,$transaction_in_name_of, $start_date, $end_date, $num_cycles, $gst, $tds, $agreement_file, $biz_mis, $com_statement, $frequency, $mis_type]);
$agreement_id = $pdo->lastInsertId(); // ADD THIS LINE

// Insert SPOCs
if (!empty($_POST['spoc_name'])) {
    foreach ($_POST['spoc_name'] as $key => $spoc_name) {
        $pdo->prepare("INSERT INTO spocs (agreement_id, name, number, email, department, source, designation, invoice_cc) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $agreement_id, // Now properly defined
                $spoc_name,
                $_POST['spoc_number'][$key],
                $_POST['spoc_email'][$key],
                $_POST['spoc_department'][$key],
                $_POST['spoc_source'][$key],
                $_POST['spoc_designation'][$key],
                $_POST['invoice_cc'][$key],
            ]);
    }
}

// Insert cycles
for ($i = 0; $i < $num_cycles; $i++) {
    $pdo->prepare("INSERT INTO agreement_cycles (
        agreement_id, 
        business_start_day, 
        business_end_day,
        invoice_day,
        invoice_month,
        payment_day, 
        gst_start_day, 
        gst_month
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")  // 8 placeholders for 8 columns
        ->execute([
            $agreement_id,
            $_POST['business_start_day'][$i],
            $_POST['business_end_day'][$i],
            $_POST['invoice_day'][$i],
            $_POST['invoice_month'][$i],
            $_POST['payment_day'][$i],  // Added this value
            $_POST['gst_start_day'][$i],
            $_POST['gst_month'][$i]
        ]);
}

    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
        <link rel="stylesheet" href="./css/add_style.css">

    <title>Add Agreement</title>
    <script>
         const companies = <?php echo json_encode(array_column($companies, 'c_name', 'c_id')); ?>;
        let selectedCompanyName = "";

        function autofillBrokerDetails() {
            var brokers = <?php echo json_encode($brokers); ?>;
            var brokerIDField = document.getElementById("br_id");
            var brokerNameField = document.getElementById("br_name");
            var transactionField = document.getElementById("transaction_in_name_of");
            var companySelect = document.getElementById("company_id");
            
            // Function to update transaction field based on broker name
            function updateTransactionField() {
                const brokerName = brokerNameField ? brokerNameField.value.toLowerCase() : "";
                
                if (brokerName === 'policysafe' || brokerName === 'trusttech') {
                    // Use selected company name for these brokers
                    transactionField.value = selectedCompanyName;
                    transactionField.readOnly = true;
                } else {
                    // Clear and make editable for other brokers
                    transactionField.value = "";
                    transactionField.readOnly = false;
                }
            }
            
            // Update transaction field when company changes
            companySelect.addEventListener("change", function() {
                const companyId = this.value;
                selectedCompanyName = companies[companyId] || "";
                updateTransactionField();
            });
            
            if (brokerIDField && brokerNameField) {
                brokerIDField.addEventListener("input", function() {
                    var broker = brokers.find(b => b.br_id == this.value);
                    if (broker) {
                        brokerNameField.value = broker.br_name;
                        updateTransactionField();
                    }
                });
                
                brokerNameField.addEventListener("input", function() {
                    var broker = brokers.find(b => b.br_name == this.value);
                    if (broker) {
                        brokerIDField.value = broker.br_id;
                        updateTransactionField();
                    }
                });
            }
            
            // Initialize transaction field if broker is pre-selected
            <?php if ($br_id && isset($broker_data)): ?>
                updateTransactionField();
            <?php endif; ?>
        }

        function addSPOCFields() {
            const spocCount = document.getElementById("spoc_count").value;
            const container = document.getElementById("spoc_container");
            container.innerHTML = "";
            
            for (let i = 0; i < spocCount; i++) {
        const spocDiv = document.createElement('div');
        spocDiv.className = 'form-section';
        spocDiv.innerHTML = `
            <h4>SPOC ${i+1}</h4>
                        <div class="col-2">
                            <label>Name:    <input type="text" name="spoc_name[]" 
                                pattern="[A-Za-z ]+" 
                                oninput="this.value = this.value.replace(/[^A-Za-z ]/g, '')"
                                title="Only alphabets and spaces allowed" 
                                required>
                            </label>
                           <label>Contact No: 
                                <input type="text" name="spoc_number[]" 
                                    pattern="\\d{10}" 
                                    maxlength="10" 
                                    oninput="this.value = this.value.replace(/\\D/g, '')" 
                                    title="Enter 10 digit mobile number" required>
                            </label>
                            <label>Email: <input type="email" name="spoc_email[]" required>
                            </label>
                            <label>Department: <input type="text" name="spoc_department[]" 
                                pattern="[A-Za-z ]+" 
                                oninput="this.value = this.value.replace(/[^A-Za-z ]/g, '')"
                                title="Only alphabets and spaces allowed" 
                                required>
                            </label>
                            <label>Source: <select name="spoc_source[]" required>
                            <option value="" disabled selected>Select Source</option>
                            <option value="Internal">Internal</option>
                            <option value="External">External</option>
                            </select></label>
                            <label>Designation: <input type="text" name="spoc_designation[]"  
                                pattern="[A-Za-z ]+" 
                                oninput="this.value = this.value.replace(/[^A-Za-z ]/g, '')"
                                title="Only alphabets and spaces allowed" 
                                required>
                            </label>
                             <label>Invoice CC: <select name="invoice_cc[]" required>
                            <option value="" disabled selected>Select</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                            </select></label>
                        </div>
                    </div>
                `;
                container.appendChild(spocDiv);
            }
 // Attach email validation to new SPOC email fields
 container.querySelectorAll('input[name="spoc_email[]"]').forEach(input => {
        input.addEventListener('input', emailValidationHandler);        
    });
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

        function validateFiles() {
            const agreementFile = document.querySelector('input[name="agreement_file"]');
            const commissionFile = document.querySelector('input[name="commission_file"]');
            
            if (agreementFile.files.length > 0) {
                const file = agreementFile.files[0];
                if (file.type !== 'application/pdf') {
                    alert('Agreement file must be a PDF');
                    return false;
                }
            }
        
            
            return true;
        }
        
        // Modified cycle section generation
        document.addEventListener("DOMContentLoaded", () => {
            const startDate = document.querySelector('input[name="start_date"]');
            const endDate = document.querySelector('input[name="end_date"]');
            
            endDate.disabled = true; // Initial state
            startDate.addEventListener('change', updateDateLimits);

                        // Store cycle data when deleting
                        let cycleData = [];

            document.getElementById("num_cycles").addEventListener("change", function() {
                // Clamp the value between 1 and 10
                const numCycles = Math.min(10, Math.max(1, parseInt(this.value) || 1));
                this.value = numCycles;  // Ensure input field shows clamped value
                
                const container = document.getElementById("cycles_container");
                container.innerHTML = "";
                
                for (let i = 0; i < numCycles; i++) {
                    container.innerHTML += `
                        <div class="cycle-section">
                            <h4>Cycle ${i+1}</h4>
                            <div class="">
                                <div class="cycle-input-group">
                                   <label>Business Cycle:
                                    <input type="number" name="business_start_day[]" 
                                        min="1"  // Always enforce minimum 1
                                        max="31" 
                                        data-cycle-index="${i}" 
                                        required>
                                    to 
                                    <input type="number" name="business_end_day[]" 
                                        min="1"  // Always enforce minimum 1
                                        max="31" 
                                        data-cycle-index="${i}" 
                                        required>
                                </label>
                                </div>
                                <div class="cycle-input-group">
                                    <label>Invoice Cycle:
                                        <input type="number" name="invoice_day[]" min="1" max="31" required>
                                        <select name="invoice_month[]" required>
                                            <option value="current">This Month</option>
                                            <option value="next">Next Month</option>
                                            <option value="next_next">Next to Next Month</option>
                                        </select>
                                    </label>
                                </div>
                                <div class="cycle-input-group">
                                    <label>Payment Cycle:
                                        <input type="number" name="payment_day[]" min="1" max="31" required>
                                    </label>
                                </div>
                                <div class="cycle-input-group">
                                    <label>GST Cycle:
                                    <input type="number" name="gst_start_day[]" min="1" max="31" required>
                                        <select name="gst_month[]" required>
                                            <option value="current">This Month</option>
                                            <option value="next">Next Month</option>
                                            <option value="next_next">Next to Next Month</option>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                const validateCycle = (index) => {
            const startInput = container.querySelector(`input[name="business_start_day[]"][data-cycle-index="${index}"]`);
            const endInput = container.querySelector(`input[name="business_end_day[]"][data-cycle-index="${index}"]`);

            // Reset previous errors
            startInput.setCustomValidity('');
            endInput.setCustomValidity('');
            
                // Validate minimum value
            if (startInput.value < 1) {
                startInput.setCustomValidity('Start day must be at least 1');
                startInput.reportValidity();
                return false;
            }

            // Validate current cycle
            if (parseInt(endInput.value) <= parseInt(startInput.value)) {
                endInput.setCustomValidity('End day must be greater than start day');
                endInput.reportValidity();
                return false;
            }

            // Validate against previous cycle
            if (index > 0) {
                const prevEndInput = container.querySelector(`input[name="business_end_day[]"][data-cycle-index="${index - 1}"]`);
                const minStart = parseInt(prevEndInput.value) + 1;
                
                if (parseInt(startInput.value) <= parseInt(prevEndInput.value)) {
                    startInput.setCustomValidity(`Must start after previous cycle (day ${minStart})`);
                    startInput.reportValidity();
                    return false;
                }
            }

            return true;
        };

        const updateSubsequentCycles = (index) => {
            const endInput = container.querySelector(`input[name="business_end_day[]"][data-cycle-index="${index}"]`);
            const nextIndex = index + 1;
            const nextStartInput = container.querySelector(`input[name="business_start_day[]"][data-cycle-index="${nextIndex}"]`);

            if (nextStartInput) {
                nextStartInput.min = parseInt(endInput.value) + 1;
                validateCycle(nextIndex);
                updateSubsequentCycles(nextIndex);
            }
        };

        // Add event listeners
        container.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener('change', function() {
                const index = parseInt(this.dataset.cycleIndex);
                if (validateCycle(index)) {
                    updateSubsequentCycles(index);
                }
            });
        });
                updateCycleDateLimits(container);
            });
        });
    </script>




</head>

<body onload="autofillBrokerDetails()">
<a href="index.php" class="back-btn">← Back</a>
    <h2>Add Agreement</h2>
    <form method="POST" enctype="multipart/form-data" onsubmit="return validateFiles()">

        <div class="form-section">
            <h3>Broker Details</h3>
            <div class="form-section col-2">
                <?php if ($br_id) :
                    $broker_stmt = $pdo->prepare("SELECT * FROM brokers WHERE br_id = ?");
                    $broker_stmt->execute([$br_id]);
                    $broker_data = $broker_stmt->fetch();
                ?>
            </div>
                <input type="hidden" name="br_id" value="<?= $br_id ?>">
                <div class="broker-info">
                    <p>Broker ID: <?= $broker_data['br_id'] ?></p>
                    <p>Broker Name: <?= $broker_data['br_name'] ?></p>
                </div>
            <?php else : ?>
                <div>
                    <label>Broker ID: 
                        <input type="number" name="br_id" id="br_id" required>
                    </label>
                </div>
                <div>
                    <label>Broker Name: 
                        <input type="text" name="br_name" id="br_name" required>
                    </label>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-section">
            <label>Company:
            <select name="company_id" id="company_id" required>
                <option value="">Select Company</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?= $company['c_id'] ?>"><?= $company['c_name'] ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        </div>

        <div class="form-section">
            <label>Transaction in name of:
                <input type="text" id="transaction_in_name_of" name="transaction_in_name_of" 
                    pattern="[A-Za-z0-9\s.,&()-]+" 
                    title="Only letters, numbers, and basic punctuation allowed"
                    required>
            </label>
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
                <label>GST (%): <input type="text" name="gst" pattern="\d*" oninput="this.value = this.value.replace(/\D/g, '')" max="100" required></label>
                <label>TDS (%): <input type="text" name="tds" pattern="\d*" oninput="this.value = this.value.replace(/\D/g, '')" max="100" required></label> 
            </div>
        </div>

        <div class="form-section">
            <h3>Document Uploads</h3>
            <div class="col-2">
                <label>Agreement File (PDF only): 
                    <input type="file" name="agreement_file" accept="application/pdf" required>
                </label>
            </div>
        </div>

        <div class="form-section">
            <h3>Communication Details</h3>
            <div class="col-2">
                <label>Biz MIS: <input type="email" name="biz_mis"  oninput="emailValidationHandler.call(this)" required></label>
                <label>Commission Statement: <input type="email" name="com_statement"  oninput="emailValidationHandler.call(this)" required></label>
                <label>Frequency:
                    <select name="frequency" required>
                        <option value="" disabled selected>Select Frequency</option>
                        <option value="Weekly">Weekly</option>
                        <option value="Daily">Daily</option>
                        <option value="Monthly">Monthly</option>
                        <option value="Fortnightly">Fortnightly</option>
                    </select>
                </label>
                <label>MIS Type:
                    <select name="mis_type" required>
                        <option value="" disabled selected>Select MIS type</option>
                        <option value="Monthly">Monthly</option>
                        <option value="Yearly">Yearly</option>
                    </select>
                </label>
            </div>
        </div>
        <div class="form-section">
            <h3>Cycles</h3>
            <label>Number of Cycles: <input type="number" id="num_cycles" name="num_cycles" min="1" max="10" required></label>
            <div id="cycles_container"></div>
        </div>

        <div class="form-section">
            <h3>SPOCs (Single Point of Contact)</h3>
            <label>Number of SPOCs: <input type="number" id="spoc_count" min="1" max="5" onchange="addSPOCFields()"></label>
            <div id="spoc_container"></div>
        </div>

        <button type="submit">Save Agreement</button>
    </form>

    <script>
// Add this function to handle email validation

function setupEmailValidation() {
    // Handle static email fields (biz_mis/com_statement)
    document.querySelectorAll('input[name="biz_mis"], input[name="com_statement"]').forEach(input => {
        input.removeEventListener('input', emailValidationHandler); // Prevent duplicates
        input.addEventListener('input', emailValidationHandler);
    });


    // Handle dynamic SPOC emails
    document.querySelectorAll('input[name="spoc_email[]"]').forEach(emailInput => {
        const newInput = emailInput.cloneNode(true);
        emailInput.replaceWith(newInput);
        newInput.addEventListener("input", emailValidationHandler);
    });
}

// Separate validation handler
function emailValidationHandler() {
    const value = this.value.trim();
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (!emailPattern.test(value)) {
        this.setCustomValidity("Invalid email. Must follow format: user@domain.com");
    } else {
        this.setCustomValidity("");
    }
    this.reportValidity();
}

// Initialize validation on page load
document.addEventListener("DOMContentLoaded", function () {
    setupEmailValidation();
    autofillBrokerDetails();
    updateDateLimits();
    

});
</script>


</body>
</html>