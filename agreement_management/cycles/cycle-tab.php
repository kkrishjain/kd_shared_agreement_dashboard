<?php
// cycle-tab.php
include '../config/database.php';

// Function to sanitize input
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $data;
}

// Function to validate date format for custom cycles
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo->beginTransaction();

        // Sanitize and validate frequency
        $allowedFrequencies = ['weekly', 'monthly', 'custom'];
        $frequency = isset($_POST['cycle_frequency']) ? sanitizeInput($_POST['cycle_frequency']) : '';
        if (!in_array($frequency, $allowedFrequencies)) {
            die("Error: Invalid cycle frequency.");
        }

        // Sanitize and validate cycle name
        $cycleName = isset($_POST['cycle_name']) ? sanitizeInput($_POST['cycle_name']) : '';
        if (!preg_match('/^[a-zA-Z0-9\s]+$/', $cycleName) || empty($cycleName)) {
            die("Error: Cycle name can only contain letters, numbers and spaces.");
        }

        // Validate and sanitize number of cycles
        $numCycles = isset($_POST['num_cycles']) ? (int)$_POST['num_cycles'] : 0;
        if ($frequency === 'custom') {
            if ($numCycles < 1 || $numCycles > 50) {
                die("Error: Number of cycles must be between 1 and 50.");
            }
        } else {
            $numCycles = 1; // Force 1 cycle for weekly/monthly
        }

        // Prepare SQL statement with placeholders
        $insertStmt = $pdo->prepare("
            INSERT INTO partner_cycles (
                c_name, c_type,
                c_b_start, c_b_end, 
                c_pay, c_pay_month, 
                c_gst_start, c_gst_month
            ) VALUES (
                ?, ?, ?, ?, 
                ?, ?, ?, ?
            )
        ");

        for ($i = 0; $i < $numCycles; $i++) {
            // Validate array indexes exist
            if (!isset(
                $_POST['business_start_day'][$i],
                $_POST['business_end_day'][$i],
                $_POST['payout_cycle'][$i],
                $_POST['payout_period'][$i],
                $_POST['gst_start_day'][$i],
                $_POST['gst_month'][$i]
            )) {
                die("Error: Missing required cycle parameters.");
            }

            // Handle date conversion based on frequency
            if ($frequency === 'custom') {
                // Validate date format
                $startDate = sanitizeInput($_POST['business_start_day'][$i]);
                $endDate = sanitizeInput($_POST['business_end_day'][$i]);
                
                if (!validateDate($startDate) || !validateDate($endDate)) {
                    die("Error: Invalid date format for custom cycle.");
                }
                
                // Convert from yyyy-mm-dd (HTML date input) to dd/mm/yyyy
                $businessStartDay = DateTime::createFromFormat('Y-m-d', $startDate)->format('d/m/Y');
                $businessEndDay = DateTime::createFromFormat('Y-m-d', $endDate)->format('d/m/Y');
                
                // Validate date order
                $startTimestamp = DateTime::createFromFormat('Y-m-d', $startDate)->getTimestamp();
                $endTimestamp = DateTime::createFromFormat('Y-m-d', $endDate)->getTimestamp();
                if ($endTimestamp < $startTimestamp) {
                    die("Error: End date cannot be before start date.");
                }
            } else {
                // For weekly/monthly, sanitize raw values (day names or numbers)
                $businessStartDay = sanitizeInput($_POST['business_start_day'][$i]);
                $businessEndDay = sanitizeInput($_POST['business_end_day'][$i]);
                
                // Additional validation for weekly/monthly
                if ($frequency === 'weekly') {
                    $validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    if (!in_array($businessStartDay, $validDays)) {
                        die("Error: Invalid start day for weekly cycle.");
                    }
                    if (!in_array($businessEndDay, $validDays)) {
                        die("Error: Invalid end day for weekly cycle.");
                    }
                } elseif ($frequency === 'monthly') {
                    $businessStartDay = (int)$businessStartDay;
                    $businessEndDay = (int)$businessEndDay;
                    if ($businessStartDay < 1 || $businessStartDay > 31) {
                        die("Error: Invalid start day for monthly cycle.");
                    }
                    if ($businessEndDay < 1 || $businessEndDay > 31) {
                        die("Error: Invalid end day for monthly cycle.");
                    }
                }
            }

            // Sanitize and validate other fields
            $payoutCycle = sanitizeInput($_POST['payout_cycle'][$i]);
            $payoutPeriod = sanitizeInput($_POST['payout_period'][$i]);
            $gstStartDay = (int)$_POST['gst_start_day'][$i];
            $gstMonth = sanitizeInput($_POST['gst_month'][$i]);
            
            // Validate payout cycle based on frequency
            if ($frequency === 'weekly') {
                $validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                if (!in_array($payoutCycle, $validDays)) {
                    die("Error: Invalid payout day for weekly cycle.");
                }
            } else {
                $payoutCycle = (int)$payoutCycle;
                if ($payoutCycle < 1 || $payoutCycle > 31) {
                    die("Error: Payout cycle day must be between 1 and 31.");
                }
            }
            
            // Validate payout period
            $validPeriods = $frequency === 'weekly' 
                ? ['this_week', 'next_week', 'next_next_week']
                : ['this_month', 'next_month', 'next_next_month'];
                
            if (!in_array($payoutPeriod, $validPeriods)) {
                die("Error: Invalid payout period.");
            }
            
            // Validate GST fields
            if ($gstStartDay < 1 || $gstStartDay > 31) {
                die("Error: GST start day must be between 1 and 31.");
            }
            
            $validGstMonths = ['this_month', 'next_month', 'next_next_month'];
            if (!in_array($gstMonth, $validGstMonths)) {
                die("Error: Invalid GST month.");
            }

            $params = [
                $cycleName,
                $frequency,
                $businessStartDay,
                $businessEndDay,
                $payoutCycle,
                $payoutPeriod,
                $gstStartDay,
                $gstMonth
            ];

            $insertStmt->execute($params);
        }

        $pdo->commit();
        echo '<script>loadContent("/agreement_management/cycles/cycle-index.php", "Cycles Management")</script>';
        exit();     

    } catch (PDOException $e) {
        $pdo->rollBack();
        die("Error saving cycles: " . $e->getMessage());
    }
}
?>


<!DOCTYPE html>
<html>
<head>
    <title>Manage Cycles</title>
     <link rel="stylesheet" href="/agreement_management/navbar.css">
    <script src="/agreement_management/navbar.js"></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/agreement_management/navbar.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="./css/cycle_add_style.css">

     
</head>
<body>
    <!-- Include Navbar -->

    <!-- Content Container -->
    <div class="content" id="content">

 <div class="container">
        <div class="header">
            <div style="display: flex; align-items: center; gap: 10px;">
        <button onclick="window.location.href='/agreement_management/cycles/cycle-index.php'" 
                style="background:#3498db; padding: 8px 15px; display: flex; align-items: center; gap: 5px;">
            <i class="fas fa-arrow-left"></i> Back
        </button>
            <h2>Manage Cycles</h2>
        </div>
        </div>

        <form method="POST">
            <div class="form-section">
                <h3>Cycles</h3>

                <!-- Add Cycle Name Field -->
                <div class="cycle-name" style="margin-top: 15px;">
                    <label>Cycle Name:
                        <input type="text"
                            name="cycle_name"
                            placeholder="Enter cycle name"
                            maxlength="50"
                            pattern="[a-zA-Z0-9\s]+"
                            title="Only letters and numbers are allowed"
                            required>
                    </label>
                </div>

                <div class="cycle-frequency-options">
                    <label>Cycle Frequency:
                        <select name="cycle_frequency" id="cycle_frequency" required>
                            <option value="" selected disabled>Select Frequency</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="custom">Custom</option>
                        </select>
                    </label>
                </div>

                <div class="cycle-count-container" id="cycle_count_container">
                    <label>Number of Cycles:
                        <input type="number" id="num_cycles" name="num_cycles" min="1" max="50" required>
                    </label>
                </div>

                <div id="cycles_container"></div>

                <button type="submit">
                     Save Cycles
                </button>
            </div>
        </form>
    </div>
    </div>

    <!-- Include navbar JavaScript -->

    <script>
    // Constants and Core Functions
    const DAYS_OF_WEEK = [
        { value: 1, label: 'Monday' },
        { value: 2, label: 'Tuesday' },
        { value: 3, label: 'Wednesday' },
        { value: 4, label: 'Thursday' },
        { value: 5, label: 'Friday' },
        { value: 6, label: 'Saturday' },
        { value: 7, label: 'Sunday' }
    ];

    // Global validation function
    window.validateDatePair = function(input) {
        // Only validate if we're changing the end date
        if (input.name !== 'business_end_day[]') return;

        const group = input.closest('.input-group');
        const startInput = group.querySelector('input[name="business_start_day[]"]');
        const endInput = group.querySelector('input[name="business_end_day[]"]');

        if (!startInput || !endInput || !startInput.value || !endInput.value) return;

        const startDate = new Date(startInput.value);
        const endDate = new Date(endInput.value);

        if (endDate < startDate) {
            alert('End date cannot be less than start date.');
            endInput.value = startInput.value;
        }
    };

    // Add this function with the other utility functions
    function validateMonthlyDayPair(input) {
        // Only validate if we're changing the end date
        if (input.name !== 'business_end_day[]') return;

        const group = input.closest('.input-group');
        const startInput = group.querySelector('select[name="business_start_day[]"]');
        const endInput = group.querySelector('select[name="business_end_day[]"]');

        if (!startInput || !endInput) return;

        const startDay = parseInt(startInput.value);
        const endDay = parseInt(endInput.value);

        if (endDay < startDay) {
            alert('End day cannot be less than start day for monthly cycles.');
            endInput.value = startInput.value;
        }
    }

    function createDayDropdown(name, selectedValue) {
        return `
            <select name="${name}" required>
                ${DAYS_OF_WEEK.map(day => `
                    <option value="${day.label}" ${day.label === selectedValue ? 'selected' : ''}>
                        ${day.label}
                    </option>
                `).join('')}
            </select>
        `;
    }

    function createMonthDayDropdown(name, selectedValue = '') {
        let options = '';
        for(let day = 1; day <= 31; day++) {
            const selected = day == selectedValue ? 'selected' : '';
            options += `<option value="${day}" ${selected}>${day}</option>`;
        }
        return `<select name="${name}" required>${options}</select>`;
    }

    function getDayName(dayValue) {
        const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        return days[dayValue - 1];
    }

    function toggleCycleCountVisibility() {
        const container = document.getElementById('cycle_count_container');
        const frequencySelect = document.getElementById('cycle_frequency');
        container.classList.toggle('visible', frequencySelect.value === 'custom');
    }

    function handleCycleFrequency() {
        const frequencySelect = document.getElementById('cycle_frequency');
        const numCyclesInput = document.getElementById('num_cycles');
        
        switch(frequencySelect.value) {
            case 'weekly':
            case 'monthly':
                numCyclesInput.value = 1;
                break;
            case 'custom':
                numCyclesInput.value = '';
                break;
        }
        toggleCycleCountVisibility();
        generateCycleInputs();
    }

    function generateCycleInputs() {
        const container = document.getElementById("cycles_container");
        const frequencySelect = document.getElementById('cycle_frequency');
        const numCyclesInput = document.getElementById('num_cycles');
        const frequency = frequencySelect.value;
        
        container.innerHTML = "";
        const numCycles = parseInt(numCyclesInput.value) || 0;

        for (let i = 0; i < numCycles; i++) {
            const isWeekly = frequency === 'weekly';
            const isMonthly = frequency === 'monthly';

            // Business Cycle HTML
            const businessCycleHTML = isWeekly ? `
                <div class="input-group business-cycle">
                    <div>
                        <label>Business Start Day</label>
                        ${createDayDropdown('business_start_day[]', '')}
                    </div>
                    <div>
                        <label>Business End Day</label>
                        ${createDayDropdown('business_end_day[]', '')}
                    </div>
                </div>
            ` : isMonthly ? `
            <div class="input-group">
                <div>
                    <label>Business Start Day</label>
                    ${createMonthDayDropdown('business_start_day[]', '')}
                </div>
                <div>
                    <label>Business End Day</label>
                    ${createMonthDayDropdown('business_end_day[]', '')}
                </div>
            </div>
        ` : `
                <div class="input-group">
                    <div>
                        <label>Business Start Date</label>
                        <input type="date" name="business_start_day[]" onchange="validateDatePair(this)" required>
                    </div>
                    <div>
                        <label>Business End Date</label>
                        <input type="date" name="business_end_day[]" onchange="validateDatePair(this)" required>
                    </div>
                </div>
            `;

            // Payout Cycle HTML
            const payoutCycleHTML = `
                <div class="input-group">
                    <div>
                        <label>Payout Cycle</label>
                        ${isWeekly ? createDayDropdown('payout_cycle[]', '') :
                        isMonthly ? createMonthDayDropdown('payout_cycle[]', '') : `
                        <input type="number" name="payout_cycle[]" min="1" max="31" required>
                        `}
                    </div>
                    <div>
                        <label>Payout ${isWeekly ? 'Week' : 'Month'}</label>
                        <select name="payout_period[]" required>
                            ${isWeekly ? `
                                <option value="this_week">This Week</option>
                                <option value="next_week">Next Week</option>
                                <option value="next_next_week">Next to Next Week</option>
                            ` : isMonthly ? `
                                <option value="this_month">This Month</option>
                                <option value="next_month">Next Month</option>
                                <option value="next_next_month">Next to Next Month</option>
                            ` : `
                                <option value="this_month">This Month</option>
                                <option value="next_month">Next Month</option>
                                <option value="next_next_month">Next to Next Month</option>
                            `}
                        </select>
                    </div>
                </div>`;

            // GST Cycle HTML
            const gstCycleHTML = `
                <div class="input-group">
                    <div>
                        <label>GST Start Day</label>
                        <input type="number" name="gst_start_day[]" min="1" max="31" required>
                    </div>
                    <div>
                        <label>GST Month</label>
                        <select name="gst_month[]" required>
                            <option value="this_month">This Month</option>
                            <option value="next_month">Next Month</option>
                            <option value="next_next_month">Next to Next Month</option>
                        </select>
                    </div>
                </div>`;

            container.innerHTML += `
                <div class="cycle-section">
                    <h4>Cycle ${i + 1}</h4>
                    ${businessCycleHTML}
                    ${payoutCycleHTML}
                    ${gstCycleHTML}
                </div>
            `;

            // Add this new block right after the innerHTML addition
            if (isMonthly) {
                const cycleSections = container.querySelectorAll('.cycle-section');
                const currentSection = cycleSections[cycleSections.length - 1];
                
                const startSelect = currentSection.querySelector('select[name="business_start_day[]"]');
                const endSelect = currentSection.querySelector('select[name="business_end_day[]"]');
                
                endSelect.addEventListener('change', function() {
                    validateMonthlyDayPair(this);
                });
            }
        }
    }

    // Initialization
    function initializeCycleForm() {
        const frequencySelect = document.getElementById('cycle_frequency');
        const numCyclesInput = document.getElementById('num_cycles');

        // Event Listeners
        frequencySelect.addEventListener('change', handleCycleFrequency);
        numCyclesInput.addEventListener('input', generateCycleInputs);

        // Initial Setup
        handleCycleFrequency();
    }

    // Start the application
    document.addEventListener('DOMContentLoaded', initializeCycleForm);

    // Form Submission Handler
    document.querySelector('form').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);

        fetch('cycle-tab.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const scripts = doc.getElementsByTagName('script');
            
            Array.from(scripts).forEach(script => {
                eval(script.innerHTML);
            });
        })
        .catch(error => console.error('Error:', error));
    });

    // Content Loading System
    function loadContent(url, title) {
        fetch(url)
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newContent = doc.getElementById('contentContainer').innerHTML;
                
                document.getElementById('contentContainer').innerHTML = newContent;
                document.title = title;
                window.history.pushState({}, title, url);
                initDynamicContent();
            })
            .catch(error => {
                console.error('Error loading content:', error);
                window.location.href = url;
            });
    }

    function initDynamicContent() {
        if (document.querySelector('.table-responsive')) {
            initializeCycleIndex();
        }
        if (document.getElementById('cycle_frequency')) {
            initializeCycleForm();
        }
    }

    window.addEventListener('popstate', () => {
        loadContent(window.location.pathname, document.title);
    });
    // Add this to your initializeCycleForm() function or create a new function
document.querySelector('input[name="cycle_name"]').addEventListener('input', function(e) {
    // Remove any special characters (keep only letters, numbers, and spaces)
    this.value = this.value.replace(/[^a-zA-Z0-9\s]/g, '');
});
</script>
    
</body>
</html>