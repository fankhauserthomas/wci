<?php
/**
 * Test Script für Quota Split Logging
 * 
 * Simuliert ein mehrtägige Quota Split Szenario mit detailliertem Logging
 */

// Simuliere eine mehrtägige Quota die aufgeteilt wird
// Beispiel: Original Quota 2025-01-01 bis 2025-01-10, neue Quota überschreibt 2025-01-04 bis 2025-01-06

require_once __DIR__ . '/hrs/hrs_write_quota_v3.php';

// Mock Scenario
echo "🧪 TESTING QUOTA SPLIT LOGIC WITH EXTENSIVE LOGGING\n";
echo "====================================================\n\n";

// Original Quota Days: 2025-01-01 bis 2025-01-10 (10 Tage)
$originalQuotaDays = [
    '2025-01-01', '2025-01-02', '2025-01-03', '2025-01-04', '2025-01-05',
    '2025-01-06', '2025-01-07', '2025-01-08', '2025-01-09', '2025-01-10'
];

// Neue Quota überschreibt nur: 2025-01-04 bis 2025-01-06 (3 Tage) 
$intersectionDays = ['2025-01-04', '2025-01-05', '2025-01-06'];

echo "📅 Original Quota Days: " . implode(', ', $originalQuotaDays) . "\n";
echo "❌ Days to be overwritten: " . implode(', ', $intersectionDays) . "\n\n";

// Test die neue Logik
echo "🧪 Testing new logic (direct negative selection):\n";

// Erstelle Set für schnelle Lookup der zu überschreibenden Tage
$intersectionSet = array_fill_keys($intersectionDays, true);
echo "🔍 Intersection set keys: " . implode(', ', array_keys($intersectionSet)) . "\n";

// Sammle ALLE Tage die behalten werden sollen (alles außer intersections)
$daysToPreserve = [];
foreach ($originalQuotaDays as $day) {
    if (!isset($intersectionSet[$day])) {
        $daysToPreserve[] = $day;
        echo "✅ PRESERVING day: $day\n";
    } else {
        echo "❌ REMOVING day: $day (found in intersection set)\n";
    }
}

echo "\n💾 FINAL days to preserve: " . implode(', ', $daysToPreserve) . "\n\n";

// Test buildSegmentsFromDayList Logik
echo "🧩 Testing buildSegmentsFromDayList():\n";

// Mock the buildSegmentsFromDayList function
function testBuildSegmentsFromDayList(array $dayList) {
    echo "🧩 buildSegmentsFromDayList() called with " . count($dayList) . " days: " . implode(', ', $dayList) . "\n";
    
    $segments = [];
    if (empty($dayList)) {
        echo "🧩 → Empty dayList, returning 0 segments\n";
        return $segments;
    }

    $normalized = array_values(array_unique($dayList));
    sort($normalized);
    echo "🧩 → Normalized/sorted days: " . implode(', ', $normalized) . "\n";

    $currentSegment = [];
    $lastDate = null;

    foreach ($normalized as $dateStr) {
        if (empty($currentSegment)) {
            $currentSegment[] = $dateStr;
            $lastDate = $dateStr;
            echo "🧩 → Started first segment with: $dateStr\n";
            continue;
        }

        $expectedNext = date('Y-m-d', strtotime($lastDate . ' +1 day'));
        echo "🧩 → Processing $dateStr: last=$lastDate, expected=$expectedNext, match=" . ($dateStr === $expectedNext ? 'YES' : 'NO') . "\n";
        
        if ($dateStr === $expectedNext) {
            $currentSegment[] = $dateStr;
            $lastDate = $dateStr;
            echo "🧩 → Added to current segment: " . implode(', ', $currentSegment) . "\n";
        } else {
            $segmentToClose = [
                'start' => $currentSegment[0],
                'end' => $currentSegment[count($currentSegment) - 1],
                'dates' => $currentSegment
            ];
            $segments[] = $segmentToClose;
            echo "🧩 → Closed segment: {$segmentToClose['start']} → {$segmentToClose['end']} (Days: " . implode(', ', $segmentToClose['dates']) . ")\n";
            
            $currentSegment = [$dateStr];
            $lastDate = $dateStr;
            echo "🧩 → Started new segment with: $dateStr\n";
        }
    }

    if (!empty($currentSegment)) {
        $finalSegment = [
            'start' => $currentSegment[0],
            'end' => $currentSegment[count($currentSegment) - 1],
            'dates' => $currentSegment
        ];
        $segments[] = $finalSegment;
        echo "🧩 → Final segment: {$finalSegment['start']} → {$finalSegment['end']} (Days: " . implode(', ', $finalSegment['dates']) . ")\n";
    }

    echo "🧩 → TOTAL SEGMENTS CREATED: " . count($segments) . "\n";
    foreach ($segments as $idx => $seg) {
        echo "🧩 →   Segment " . ($idx + 1) . ": {$seg['start']} → {$seg['end']} (" . count($seg['dates']) . " days)\n";
    }

    return $segments;
}

$segmentsToPreserve = testBuildSegmentsFromDayList($daysToPreserve);

echo "\n🎯 EXPECTED RESULT:\n";
echo "Expected 2 segments:\n";
echo "  Segment 1: 2025-01-01 → 2025-01-03 (Days: 2025-01-01, 2025-01-02, 2025-01-03)\n";
echo "  Segment 2: 2025-01-07 → 2025-01-10 (Days: 2025-01-07, 2025-01-08, 2025-01-09, 2025-01-10)\n\n";

echo "🧪 ACTUAL RESULT:\n";
if (count($segmentsToPreserve) == 2) {
    $seg1 = $segmentsToPreserve[0];
    $seg2 = $segmentsToPreserve[1];
    echo "✅ SUCCESS: Got 2 segments as expected\n";
    echo "  Segment 1: {$seg1['start']} → {$seg1['end']} (Days: " . implode(', ', $seg1['dates']) . ")\n";
    echo "  Segment 2: {$seg2['start']} → {$seg2['end']} (Days: " . implode(', ', $seg2['dates']) . ")\n";
    
    // Check if front and back parts are preserved
    $expectedFront = ['2025-01-01', '2025-01-02', '2025-01-03'];
    $expectedBack = ['2025-01-07', '2025-01-08', '2025-01-09', '2025-01-10'];
    
    if ($seg1['dates'] === $expectedFront && $seg2['dates'] === $expectedBack) {
        echo "✅ PERFECT: Both front and back parts preserved correctly!\n";
    } else {
        echo "❌ MISMATCH: Segments don't match expected values\n";
    }
} else {
    echo "❌ FAILURE: Got " . count($segmentsToPreserve) . " segments, expected 2\n";
    foreach ($segmentsToPreserve as $idx => $seg) {
        echo "  Segment " . ($idx + 1) . ": {$seg['start']} → {$seg['end']} (Days: " . implode(', ', $seg['dates']) . ")\n";
    }
}

echo "\n🎉 Test completed!\n";