<?php
set_time_limit(300);
error_reporting(E_ERROR | E_WARNING | E_PARSE);
ini_set('display_errors', 1);

require '../config/database.php';

define('TDS_PERCENT', 0); // Default TDS percent
define('GST_PERCENT', 0); // Default GST percent

header('Content-Type: application/json');

try {
    // Step 1: Fetch eligible health insurance policies with critical fields (without agreement data)
    $healthQuery = "SELECT 
                h.id AS lead_id,
                h.customer_name,
                h.ref,
                h.policy_number,
                h.policy_issued,
                h.policy_name,
                h.net_premium,
                c.cns_name AS insurance_company_name,
                h.company AS company_code,
                pi.pl_name AS plan_name,
                h.plan_name AS plan_code,
                h.policy_term AS pt,
                h.policy_broker AS broker_code,
                b.br_name AS broker_name,
                cup.name AS rm_name,
                cup.team AS team,
                h.status
            FROM health_insurance_form h
            INNER JOIN plans_insurance pi ON h.plan_name = pi.pl_id
            INNER JOIN company_names_standardized c ON pi.pl_c_id = c.cns_id AND c.cns_product = 'hi'
            LEFT JOIN brokers b ON h.policy_broker = b.br_id
            LEFT JOIN first_register fr ON h.ref = fr.refercode
            LEFT JOIN corporate_user_permission cup ON fr.addedBy = cup.id
            WHERE h.status = 'Policy Issued'
            AND h.policy_issued IS NOT NULL
            AND h.net_premium IS NOT NULL
            AND h.policy_term IS NOT NULL
            AND h.company IS NOT NULL
            AND h.plan_name IS NOT NULL
            AND h.policy_broker IS NOT NULL";
    
    $eligiblePolicies = $pdo->query($healthQuery);
    if ($eligiblePolicies === false) {
        $errorInfo = $pdo->errorInfo();
        throw new Exception("Error during policy fetch: Database error: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
    }
    $eligiblePolicies = $eligiblePolicies->fetchAll(PDO::FETCH_ASSOC);

    if (empty($eligiblePolicies)) {
        throw new Exception("No eligible health insurance policies found for recalculation");
    }

    // Extract unique broker-company keys for optimized queries
    $brokerCompanyKeys = [];
    foreach ($eligiblePolicies as $policy) {
        $key = $policy['broker_code'] . '|' . $policy['company_code'];
        $brokerCompanyKeys[$key] = true;
    }
    $brokerCompanyKeys = array_keys($brokerCompanyKeys);

    // Step 1.5: Fetch agreement data using ACTUAL broker-company pairs
    $agreementQuery = "SELECT broker_id, company_id, tds, gst FROM agreements 
                      WHERE CONCAT(broker_id, '|', company_id) IN ('" . implode("','", $brokerCompanyKeys) . "')";
    $agreementData = $pdo->query($agreementQuery)->fetchAll(PDO::FETCH_ASSOC);
    $agreementLookup = [];
    foreach ($agreementData as $row) {
        $key = $row['broker_id'] . '|' . $row['company_id'];
        $agreementLookup[$key] = [
            'tds' => $row['tds'] ?? TDS_PERCENT,
            'gst' => $row['gst'] ?? GST_PERCENT
        ];
    }

    // Step 2: Fetch grid data using ACTUAL broker/company/plan combinations
    $gridConditions = [];
    foreach ($eligiblePolicies as $policy) {
        $gridConditions[] = sprintf(
            "(broker_code = %d AND company_code = %d AND plan_code = %d)",
            $policy['broker_code'],
            $policy['company_code'],
            $policy['plan_code']
        );
    }
    $gridQuery = "SELECT * FROM ins_payin_grid_health 
                 WHERE " . implode(' OR ', $gridConditions);
    
    $gridData = $pdo->query($gridQuery);
    if ($gridData === false) {
        $errorInfo = $pdo->errorInfo();
        throw new Exception("Error during grid data fetch: Database error: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
    }
    $gridData = $gridData->fetchAll(PDO::FETCH_ASSOC);

    // Step 3: Organize grid data for quick lookup (structure unchanged)
    $gridLookup = [];
    foreach ($gridData as $row) {
        $key = implode('|', [
            $row['broker_code'],
            $row['company_code'],
            $row['plan_code'],
            $row['pt'],
            $row['brokerage_type'],
            $row['category']
        ]);
        $gridLookup[$key][] = $row;
    }

    // Step 4: Clear existing calculations
    $deleteResult = $pdo->exec("DELETE FROM payin_health_calculation");
    if ($deleteResult === false) {
        $errorInfo = $pdo->errorInfo();
        throw new Exception("Error during calculation cleanup: Database error: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
    }

    // Step 5: Prepare batch insert with updated fields
    $insertQuery = "INSERT INTO payin_health_calculation (
        lead_id,
        product,
        customer_name,
        broker_name,
        company_name,
        plan_name,
        pt,
        net_premium,
        base_percent,
        base_amount,
        reward_percent,
        reward_amount,
        total_payin_percent,
        payin_amount,
        tds_percent,
        tds_amount,
        gst_percent,
        gst_amount,
        gross_receipt,
        net_receipt,
        rm_name,
        team,
        policy_number,
        policy_issued,
        calculation_date,
        case_type,
        broker_id,
        company_id,
        plan_id,
        location,
        policy_name,
        status
    ) VALUES ";
    
    $values = [];
    $placeholders = [];
    $batchSize = 100;
    $counter = 0;

    foreach ($eligiblePolicies as $policy) {
        try {
            $netPremium = $policy['net_premium'] ?? 0;

            // Initialize all values to 0 by default
            $basePercent = 0;
            $rewardPercent = 0;
            $baseAmount = 0;
            $rewardAmount = 0;
            $totalPayinPercent = 0;
            $payinAmount = 0;
            $tdsAmount = 0;
            $gstAmount = 0;
            $grossReceipt = 0;
            $netReceipt = 0;
            
            // Get TDS and GST from agreement lookup
            $agreementKey = $policy['broker_code'] . '|' . $policy['company_code'];
            $tdsPercent = $agreementLookup[$agreementKey]['tds'] ?? TDS_PERCENT;
            $gstPercent = $agreementLookup[$agreementKey]['gst'] ?? GST_PERCENT;

            // Lookup grid data for both Base and Reward
            $keyBase = implode('|', [
                $policy['broker_code'], 
                $policy['company_code'], 
                $policy['plan_code'], 
                $policy['pt'], 
                'Base',
                'Base' // Assuming category is same as brokerage_type for this example
            ]);
            
            $keyReward = implode('|', [
                $policy['broker_code'], 
                $policy['company_code'], 
                $policy['plan_code'], 
                $policy['pt'], 
                'Reward',
                'Reward' // Assuming category is same as brokerage_type for this example
            ]);

            // Process Base commission percentage
            if (isset($gridLookup[$keyBase])) {
                foreach ($gridLookup[$keyBase] as $gridData) {
                    // Check all matching conditions
                    $premiumMatch = $netPremium >= ($gridData['premium_from'] ?? 0) && 
                                  $netPremium <= ($gridData['premium_to'] ?? PHP_FLOAT_MAX);
                    
                    $dateMatch = strtotime($policy['policy_issued']) >= strtotime($gridData['applicable_start'] ?? '1970-01-01') && 
                                strtotime($policy['policy_issued']) <= strtotime($gridData['applicable_end'] ?? '2099-12-31');
                    
                    $policyCombinationMatch = empty($gridData['policy_combination']) || 
                                            $gridData['policy_combination'] == ($policy['policy_combination'] ?? '');
                    
                    $caseTypeMatch = empty($gridData['case_type']) || 
                                   $gridData['case_type'] == ($policy['case_type'] ?? '');
                    
                    $locationMatch = empty($gridData['location']) || 
                                    $gridData['location'] == ($policy['location'] ?? '');
                    
                    if ($premiumMatch && $dateMatch && $policyCombinationMatch && 
                        $caseTypeMatch && $locationMatch) {
                        $basePercent = $gridData['applicable_percentage'] ?? 0;
                        break; // Use first matching rule
                    }
                }
            }

            // Process Reward commission percentage (same logic as Base)
            if (isset($gridLookup[$keyReward])) {
                foreach ($gridLookup[$keyReward] as $gridData) {
                    // Same matching logic as above
                    $premiumMatch = $netPremium >= ($gridData['premium_from'] ?? 0) && 
                                    $netPremium <= ($gridData['premium_to'] ?? PHP_FLOAT_MAX);
                    
                    $dateMatch = strtotime($policy['policy_issued']) >= strtotime($gridData['applicable_start'] ?? '1970-01-01') && 
                                strtotime($policy['policy_issued']) <= strtotime($gridData['applicable_end'] ?? '2099-12-31');
                    
                    $policyCombinationMatch = empty($gridData['policy_combination']) || 
                                            $gridData['policy_combination'] == ($policy['policy_combination'] ?? '');
                    
                    $caseTypeMatch = empty($gridData['case_type']) || 
                                   $gridData['case_type'] == ($policy['case_type'] ?? '');
                    
                    $locationMatch = empty($gridData['location']) || 
                                    $gridData['location'] == ($policy['location'] ?? '');
                    
                    if ($premiumMatch && $dateMatch && $policyCombinationMatch && 
                        $caseTypeMatch && $locationMatch) {
                        $rewardPercent = $gridData['applicable_percentage'] ?? 0;
                        break; // Use first matching rule
                    }
                }
            }

            // Calculate amounts based on percentages
            $baseAmount = $netPremium * $basePercent / 100;
            $rewardAmount = $netPremium * $rewardPercent / 100;
            $totalPayinPercent = $basePercent + $rewardPercent;
            $payinAmount = $baseAmount + $rewardAmount;
            $tdsAmount = $payinAmount * $tdsPercent / 100;
            $gstAmount = $payinAmount * $gstPercent / 100;
            $grossReceipt = $payinAmount - $tdsAmount + $gstAmount;
            $netReceipt = $payinAmount - $tdsAmount;

            // Add to batch insert
            $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?)";
            $values = array_merge($values, [
                $policy['lead_id'] ?? 0,
                'hi', // product (health insurance)
                $policy['customer_name'] ?? 'N/A',
                $policy['broker_name'] ?? 'Unknown Broker',
                $policy['insurance_company_name'] ?? 'N/A',
                $policy['plan_name'] ?? 'N/A',
                $policy['pt'] ?? 0,
                $netPremium,
                $basePercent,
                $baseAmount,
                $rewardPercent,
                $rewardAmount,
                $totalPayinPercent,
                $payinAmount,
                $tdsPercent,
                $tdsAmount,
                $gstPercent,
                $gstAmount,
                $grossReceipt,
                $netReceipt,
                $policy['rm_name'] ?? 'N/A',
                $policy['team'] ?? 'N/A',
                $policy['policy_number'] ?? 'N/A',
                $policy['policy_issued'] ?? date('Y-m-d'),
                date('Y-m-d H:i:s'), // calculation_date
                $policy['case_type'] ?? 'N/A', // case_type
                $policy['broker_code'] ?? 0, // broker_id
                $policy['company_code'] ?? 0, // company_id
                $policy['plan_code'] ?? 0, // plan_id
                $policy['location'] ?? 'N/A',
                $policy['policy_name'] ?? 'N/A',
                $policy['status'] ?? 'Calculated'
            ]);

            $counter++;

            // Execute batch insert when batch size is reached
            if ($counter >= $batchSize) {
                $sql = $insertQuery . implode(', ', $placeholders);
                $stmt = $pdo->prepare($sql);
                if (!$stmt) {
                    $errorInfo = $pdo->errorInfo();
                    throw new Exception("Batch prepare failed: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
                }
                
                if (!$stmt->execute($values)) {
                    $errorInfo = $stmt->errorInfo();
                    throw new Exception("Batch execute failed: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
                }
                
                $placeholders = [];
                $values = [];
                $counter = 0;
            }
        } catch (Exception $e) {
            error_log("Error processing policy ID " . ($policy['lead_id'] ?? 'unknown') . ": " . $e->getMessage());
            // Continue with next policy
        }
    }

    // Insert remaining rows
    if (!empty($placeholders)) {
        $sql = $insertQuery . implode(', ', $placeholders);
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            $errorInfo = $pdo->errorInfo();
            throw new Exception("Final prepare failed: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
        }
        
        if (!$stmt->execute($values)) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Final execute failed: SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Successfully recalculated ' . count($eligiblePolicies) . ' health insurance policies',
        'processed_count' => $counter
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Unexpected error: ' . $e->getMessage()]);
}