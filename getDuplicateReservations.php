<?php
// getDuplicateReservations.php - Optimized duplicate reservation detection

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require 'config.php';

// Set memory limit higher for this operation
ini_set('memory_limit', '256M');
set_time_limit(60); // Max 60 seconds

try {
    // Parameters
    $days_ahead = min((int)($_GET['days'] ?? 90), 180); // Limit to 180 days max
    $min_similarity = (float)($_GET['min_similarity'] ?? 0.75);
    $max_results = 100; // Limit results to prevent memory issues
    
    // Get active reservations with optimization
    $sql = "
    SELECT 
        r.id,
        r.anreise,
        r.abreise, 
        TRIM(IFNULL(r.nachname, '')) AS nachname,
        TRIM(IFNULL(r.vorname, '')) AS vorname,
        TRIM(IFNULL(r.email, '')) AS email,
        (r.betten + r.dz + r.lager + r.sonder) AS anzahl_personen
    FROM `AV-Res` r
    WHERE r.storno = 0 
    AND r.anreise >= CURDATE()
    AND r.anreise <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
    AND (r.nachname IS NOT NULL AND r.nachname != '')
    AND (r.betten + r.dz + r.lager + r.sonder) > 0
    ORDER BY r.anreise, r.nachname, r.vorname
    LIMIT 500
    ";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $days_ahead);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reservations = [];
    while ($row = $result->fetch_assoc()) {
        $reservations[] = $row;
    }
    
    $total_reservations = count($reservations);
    
    // Optimized duplicate detection - process in chunks
    $duplicates = [];
    $comparison_count = 0;
    $max_comparisons = 10000; // Limit comparisons
    
    for ($i = 0; $i < $total_reservations && $comparison_count < $max_comparisons; $i++) {
        $res1 = $reservations[$i];
        
        // Only compare with next 50 reservations to limit memory usage
        $end_j = min($total_reservations, $i + 51);
        
        for ($j = $i + 1; $j < $end_j && $comparison_count < $max_comparisons; $j++) {
            $res2 = $reservations[$j];
            $comparison_count++;
            
            // Quick pre-filter to skip obviously different reservations
            if (!quickPreFilter($res1, $res2)) {
                continue;
            }
            
            // Calculate detailed similarity
            $similarity = calculateSimilarity($res1, $res2);
            
            if ($similarity['total_score'] >= $min_similarity) {
                $duplicates[] = [
                    'res1' => $res1,
                    'res2' => $res2,
                    'similarity' => $similarity,
                    'confidence' => min(100, round($similarity['total_score'] * 100))
                ];
                
                // Limit results to prevent memory issues
                if (count($duplicates) >= $max_results) {
                    break 2;
                }
            }
        }
    }
    
    // Sort by confidence (highest first)
    usort($duplicates, function($a, $b) {
        return $b['confidence'] - $a['confidence'];
    });
    
    echo json_encode([
        'success' => true,
        'data' => array_slice($duplicates, 0, $max_results),
        'parameters' => [
            'days_ahead' => $days_ahead,
            'min_similarity' => $min_similarity,
            'total_reservations' => $total_reservations,
            'duplicate_pairs' => count($duplicates),
            'comparisons_made' => $comparison_count,
            'limited_results' => count($duplicates) >= $max_results
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Duplicate reservations error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fehler beim Laden der Doppelreservierungen: ' . $e->getMessage()
    ]);
}

function quickPreFilter($res1, $res2) {
    // Quick checks to eliminate obvious non-matches
    
    // Check if names are completely different (first letter)
    if (!empty($res1['nachname']) && !empty($res2['nachname'])) {
        $first1 = strtolower(substr($res1['nachname'], 0, 1));
        $first2 = strtolower(substr($res2['nachname'], 0, 1));
        // Allow some flexibility but skip completely different starting letters
        if (abs(ord($first1) - ord($first2)) > 5) {
            return false;
        }
    }
    
    // Check date proximity (must be within 30 days of each other)
    $date1 = new DateTime($res1['anreise']);
    $date2 = new DateTime($res2['anreise']);
    $days_diff = abs($date1->diff($date2)->days);
    if ($days_diff > 30) {
        return false;
    }
    
    // Check if guest counts are vastly different
    $guest_diff = abs($res1['anzahl_personen'] - $res2['anzahl_personen']);
    $max_guests = max($res1['anzahl_personen'], $res2['anzahl_personen']);
    if ($max_guests > 0 && ($guest_diff / $max_guests) > 0.75) {
        return false;
    }
    
    return true;
}

function calculateSimilarity($res1, $res2) {
    $scores = [];
    $weights = [];
    
    // 1. Name similarity (40% weight) - optimized
    $name1 = strtolower(trim($res1['nachname'] . ' ' . $res1['vorname']));
    $name2 = strtolower(trim($res2['nachname'] . ' ' . $res2['vorname']));
    
    if (!empty($name1) && !empty($name2) && strlen($name1) > 1 && strlen($name2) > 1) {
        // Use optimized similarity for shorter strings
        if (strlen($name1) < 20 && strlen($name2) < 20) {
            $name_sim = calculateOptimizedSimilarity($name1, $name2);
            // Also check reversed names
            $name1_rev = strtolower(trim($res1['vorname'] . ' ' . $res1['nachname']));
            $name2_rev = strtolower(trim($res2['vorname'] . ' ' . $res2['nachname']));
            if (!empty($name1_rev) && !empty($name2_rev)) {
                $name_sim_rev = calculateOptimizedSimilarity($name1, $name2_rev);
                $name_sim_rev2 = calculateOptimizedSimilarity($name1_rev, $name2);
                $name_sim = max($name_sim, $name_sim_rev, $name_sim_rev2);
            }
        } else {
            // For very long names, use simpler comparison
            $name_sim = ($name1 === $name2) ? 1.0 : 0.0;
        }
        
        $scores['name'] = $name_sim;
        $weights['name'] = 0.4;
    }
    
    // 2. Email similarity (30% weight) - optimized
    if (!empty($res1['email']) && !empty($res2['email'])) {
        $email1 = strtolower(trim($res1['email']));
        $email2 = strtolower(trim($res2['email']));
        
        if ($email1 === $email2) {
            $email_sim = 1.0;
        } else if (strlen($email1) < 50 && strlen($email2) < 50) {
            $email_sim = calculateOptimizedSimilarity($email1, $email2);
        } else {
            $email_sim = 0.0;
        }
        
        $scores['email'] = $email_sim;
        $weights['email'] = 0.3;
    }
    
    // 3. Date overlap/proximity (20% weight) - optimized
    $date_score = calculateDateSimilarityOptimized($res1, $res2);
    $scores['dates'] = $date_score;
    $weights['dates'] = 0.2;
    
    // 4. Guest count similarity (10% weight)
    if ($res1['anzahl_personen'] > 0 && $res2['anzahl_personen'] > 0) {
        $guest_diff = abs($res1['anzahl_personen'] - $res2['anzahl_personen']);
        $max_guests = max($res1['anzahl_personen'], $res2['anzahl_personen']);
        $guest_sim = 1 - ($guest_diff / $max_guests);
        $scores['guests'] = max(0, $guest_sim);
        $weights['guests'] = 0.1;
    }
    
    // Calculate weighted average
    $total_score = 0;
    $total_weight = 0;
    foreach ($scores as $key => $score) {
        if (isset($weights[$key])) {
            $total_score += $score * $weights[$key];
            $total_weight += $weights[$key];
        }
    }
    
    if ($total_weight > 0) {
        $total_score = $total_score / $total_weight;
    }
    
    return [
        'total_score' => $total_score,
        'details' => $scores,
        'weights' => $weights
    ];
}

function calculateOptimizedSimilarity($str1, $str2) {
    if (empty($str1) || empty($str2)) return 0;
    if ($str1 === $str2) return 1.0;
    
    $len1 = strlen($str1);
    $len2 = strlen($str2);
    
    if ($len1 == 0 || $len2 == 0) return 0;
    
    // For very short strings, use exact match
    if ($len1 < 3 || $len2 < 3) {
        return ($str1 === $str2) ? 1.0 : 0.0;
    }
    
    // For longer strings, use Levenshtein but with limits
    if ($len1 > 30 || $len2 > 30) {
        // Truncate very long strings
        $str1 = substr($str1, 0, 30);
        $str2 = substr($str2, 0, 30);
        $len1 = 30;
        $len2 = 30;
    }
    
    $distance = levenshtein($str1, $str2);
    $max_len = max($len1, $len2);
    
    return 1 - ($distance / $max_len);
}

function calculateDateSimilarityOptimized($res1, $res2) {
    // Use string comparison for better performance
    $start1 = $res1['anreise'];
    $end1 = $res1['abreise'];
    $start2 = $res2['anreise'];
    $end2 = $res2['abreise'];
    
    // Check for exact match
    if ($start1 === $start2 && $end1 === $end2) {
        return 1.0;
    }
    
    // Convert to DateTime objects only when needed
    $start1_dt = new DateTime($start1);
    $end1_dt = new DateTime($end1);
    $start2_dt = new DateTime($start2);
    $end2_dt = new DateTime($end2);
    
    // Check for overlap
    $overlap_start = max($start1_dt, $start2_dt);
    $overlap_end = min($end1_dt, $end2_dt);
    
    if ($overlap_start <= $overlap_end) {
        $overlap_days = $overlap_start->diff($overlap_end)->days + 1;
        $total1_days = $start1_dt->diff($end1_dt)->days + 1;
        $total2_days = $start2_dt->diff($end2_dt)->days + 1;
        $avg_total_days = ($total1_days + $total2_days) / 2;
        
        return min(1.0, $overlap_days / $avg_total_days);
    }
    
    // Check proximity (within 7 days)
    $gap1 = min(
        abs($start1_dt->diff($start2_dt)->days),
        abs($start1_dt->diff($end2_dt)->days),
        abs($end1_dt->diff($start2_dt)->days),
        abs($end1_dt->diff($end2_dt)->days)
    );
    
    if ($gap1 <= 7) {
        return max(0, 1 - ($gap1 / 7)) * 0.5;
    }
    
    return 0;
}
?>
