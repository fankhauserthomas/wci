<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function sanitizeHexColor(?string $value): ?string {
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $trimmed)) {
        throw new InvalidArgumentException('Ungültiger Farbwert.');
    }

    if (strlen($trimmed) === 4) {
        $trimmed = '#' . $trimmed[1] . $trimmed[1] . $trimmed[2] . $trimmed[2] . $trimmed[3] . $trimmed[3];
    }

    return strtoupper($trimmed);
}

try {
    if ($mysqli->connect_error) {
        throw new RuntimeException('Database connection failed: ' . $mysqli->connect_error);
    }

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);
    if (!is_array($payload)) {
        $payload = $_POST ?? [];
    }

    $scope = $payload['scope'] ?? 'detail';
    $updates = $payload['updates'] ?? [];

    if (!is_array($updates) || empty($updates)) {
        throw new InvalidArgumentException('Keine Updates angegeben.');
    }

    $setParts = [];
    $bindTypes = '';
    $bindValues = [];

    if (array_key_exists('color', $updates)) {
        $color = sanitizeHexColor($updates['color'] ?? null);
        if ($color === null) {
            $setParts[] = 'col = NULL';
        } else {
            $setParts[] = 'col = ?';
            $bindTypes .= 's';
            $bindValues[] = $color;
        }
    }

    if (array_key_exists('arr_id', $updates)) {
        $arrIdRaw = $updates['arr_id'];
        if ($arrIdRaw === null || $arrIdRaw === '') {
            $setParts[] = 'arr = NULL';
        } else {
            $arrId = (int)$arrIdRaw;
            $setParts[] = 'arr = ?';
            $bindTypes .= 'i';
            $bindValues[] = $arrId;
        }
    }

    if (array_key_exists('anzahl', $updates)) {
        $amount = (int)$updates['anzahl'];
        if ($amount < 0) {
            $amount = 0;
        }
        $setParts[] = 'anz = ?';
        $bindTypes .= 'i';
        $bindValues[] = $amount;
    }

    // Handle dx and dy fields for sticky notes
    if (array_key_exists('dx', $updates)) {
        $dx = (int)$updates['dx'];
        $setParts[] = 'dx = ?';
        $bindTypes .= 'i';
        $bindValues[] = $dx;
    }

    if (array_key_exists('dy', $updates)) {
        $dy = (int)$updates['dy'];
        $setParts[] = 'dy = ?';
        $bindTypes .= 'i';
        $bindValues[] = $dy;
    }

    // Handle note field for sticky notes
    if (array_key_exists('note', $updates)) {
        $note = trim($updates['note']);
        if ($note === '') {
            $setParts[] = 'note = NULL';
        } else {
            $setParts[] = 'note = ?';
            $bindTypes .= 's';
            $bindValues[] = $note;
        }
    }

    if (empty($setParts)) {
        throw new InvalidArgumentException('Keine gültigen Felder für Update angegeben.');
    }

    if ($scope === 'reservation') {
        $resId = isset($payload['res_id']) ? (int)$payload['res_id'] : 0;
        if ($resId <= 0) {
            throw new InvalidArgumentException('res_id ist erforderlich.');
        }

        $setClause = implode(', ', $setParts);
        $sql = "UPDATE AV_ResDet SET $setClause WHERE resid = ?";
        $bindTypes .= 'i';
        $bindValues[] = $resId;
    } else {
        $detailId = isset($payload['detail_id']) ? (int)$payload['detail_id'] : 0;
        if ($detailId <= 0) {
            throw new InvalidArgumentException('detail_id ist erforderlich.');
        }

        $setClause = implode(', ', $setParts);
        $sql = "UPDATE AV_ResDet SET $setClause WHERE ID = ? LIMIT 1";
        $bindTypes .= 'i';
        $bindValues[] = $detailId;
    }

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare fehlgeschlagen: ' . $mysqli->error);
    }

    if ($bindTypes !== '') {
        $bindParams = [];
        $bindParams[] = $bindTypes;
        foreach ($bindValues as $key => $value) {
            $bindParams[] = &$bindValues[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Ausführung fehlgeschlagen: ' . $stmt->error);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    triggerAutoSync('update_room_detail_attributes');

    echo json_encode([
        'success' => true,
        'rows_affected' => $affected
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
