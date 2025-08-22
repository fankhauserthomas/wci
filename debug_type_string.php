<?php
$typeString = "issiiiiiissssss";
echo "Type string: $typeString\n";
echo "Length: " . strlen($typeString) . "\n";
echo "Character by character:\n";
for ($i = 0; $i < strlen($typeString); $i++) {
    echo ($i + 1) . ": " . $typeString[$i] . "\n";
}

echo "\nCorrect type string for 16 parameters:\n";
$types = ['i', 's', 's', 'i', 'i', 'i', 'i', 'i', 'i', 's', 's', 's', 's', 's', 's', 's'];
echo "Should be: " . implode('', $types) . "\n";
echo "Length: " . count($types) . "\n";
?>
