<?php
require_once 'config/database.php';

// Helper function to send JSON response
function respond($success, $data = null, $message = '') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

try {
    $pdo = getConnection();
} catch (Exception $e) {
    respond(false, null, 'Database connection failed: ' . $e->getMessage());
}

// Category mapping
$CATEGORY_MAP = [
    'classic' => ['id' => 1, 'label' => 'Classic Suite'],
    'deluxe' => ['id' => 2, 'label' => 'Deluxe Suite'],
    'executive' => ['id' => 3, 'label' => 'Executive Suite'],
    'day_care' => ['id' => 4, 'label' => 'Studio Suite']
];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Endpoints:
// - action=list                GET all rooms
// - action=list_categories     GET category list
// - action=add_rooms           POST category, count
// - action=update_room         POST code, status, occupants, note
// - action=delete_room         POST code

if ($action === 'list') {
    $stmt = $pdo->query('SELECT room_code, category_id, status, notes FROM hotel_rooms ORDER BY category_id, room_code');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate occupants from active bookings for each room
    $occupantQuery = $pdo->prepare('
        SELECT 
            COUNT(*) as booking_count,
            SUM(
                CASE 
                    WHEN hb.additional_pets IS NOT NULL AND hb.additional_pets != "null" AND hb.additional_pets != ""
                    THEN JSON_LENGTH(hb.additional_pets) + 1
                    ELSE 1
                END
            ) as occupant_count
        FROM hotel_bookings hb 
        WHERE hb.room_code = ? 
        AND hb.booking_status IN ("pending", "confirmed", "accepted", "in_progress")
        AND hb.admin_deleted = 0
    ');
    
    $mapped = array_map(function($r) use ($CATEGORY_MAP, $occupantQuery){
        $key = 'classic';
        foreach ($CATEGORY_MAP as $k => $meta) {
            if ((int)$r['category_id'] === (int)$meta['id']) { $key = $k; break; }
        }
        
        // Calculate current occupants from bookings
        $occupantQuery->execute([$r['room_code']]);
        $result = $occupantQuery->fetch(PDO::FETCH_ASSOC);
        $occupants = (int)($result['occupant_count'] ?? 0);
        
        return [
            'id' => $r['room_code'],
            'displayId' => $r['room_code'],
            'category' => $key,
            'status' => $r['status'],
            'occupants' => $occupants,
            'note' => $r['notes'] ?? ''
        ];
    }, $rows);
    respond(true, $mapped);
}

if ($action === 'list_categories') {
    $categories = [];
    foreach ($CATEGORY_MAP as $key => $data) {
        $categories[] = [
            'key' => $key,
            'label' => $data['label']
        ];
    }
    respond(true, $categories);
}

if ($action === 'add_rooms') {
    $category = $_POST['category'] ?? '';
    $count = (int)($_POST['count'] ?? 0);
    
    if (!$category || !isset($CATEGORY_MAP[$category]) || $count <= 0) {
        respond(false, null, 'Invalid payload');
    }
    
    $categoryId = $CATEGORY_MAP[$category]['id'];
    
    // Special handling for Studio Suite (day_care) - use 'S' prefix
    if ($category === 'day_care') {
        $prefix = 'S';
    } else {
        $prefix = strtoupper(substr($category, 0, 1));
    }
    
    // Find all existing room codes for this category
    $stmt = $pdo->prepare("SELECT room_code FROM hotel_rooms WHERE room_code LIKE ? ORDER BY room_code");
    $stmt->execute([$prefix . '%']);
    $existingRooms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Find the next available numbers
    $nextNumbers = [];
    $currentNumber = 1;
    $needed = $count;
    
    while ($needed > 0) {
        $roomCode = $prefix . sprintf('%02d', $currentNumber);
        if (!in_array($roomCode, $existingRooms)) {
            $nextNumbers[] = $roomCode;
            $needed--;
        }
        $currentNumber++;
        
        // Safety check to prevent infinite loop
        if ($currentNumber > 999) {
            respond(false, null, 'Too many rooms for this category');
        }
    }
    
    $stmt = $pdo->prepare('INSERT INTO hotel_rooms (room_code, category_id, status, occupants, notes) VALUES (?, ?, ?, ?, ?)');
    
    $newRooms = [];
    foreach ($nextNumbers as $roomCode) {
        $stmt->execute([$roomCode, $categoryId, 'available', 0, '']);
        $newRooms[] = [
            'id' => $roomCode,
            'displayId' => $roomCode,
            'category' => $category,
            'status' => 'available',
            'occupants' => 0,
            'note' => ''
        ];
    }
    
    respond(true, $newRooms);
}

if ($action === 'update_room') {
    $code = $_POST['code'] ?? '';
    $status = $_POST['status'] ?? '';
    $note = $_POST['note'] ?? '';
    
    if (!$code || !in_array($status, ['available','occupied','maintenance'], true)) {
        respond(false, null, 'Invalid payload');
    }
    
    // Calculate current occupants from active bookings
    $stmt = $pdo->prepare('
        SELECT COUNT(DISTINCT hb.pet_id) as occupant_count 
        FROM hotel_bookings hb 
        WHERE hb.room_code = ? 
        AND hb.booking_status IN ("pending", "confirmed", "accepted", "in_progress")
        AND hb.admin_deleted = 0
    ');
    $stmt->execute([$code]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $occupants = (int)($result['occupant_count'] ?? 0);
    
    // Only update status and notes - occupants is auto-calculated from bookings
    $stmt = $pdo->prepare('UPDATE hotel_rooms SET status = ?, occupants = ?, notes = ? WHERE room_code = ?');
    $stmt->execute([$status, $occupants, $note, $code]);
    
    respond(true, ['code' => $code, 'occupants' => $occupants]);
}

if ($action === 'delete_room') {
    $code = $_POST['code'] ?? '';
    
    if (!$code) {
        respond(false, null, 'Room code is required');
    }
    
    // Check if room exists
    $stmt = $pdo->prepare('SELECT id FROM hotel_rooms WHERE room_code = ?');
    $stmt->execute([$code]);
    if (!$stmt->fetch()) {
        respond(false, null, 'Room not found');
    }
    
    // Delete the room
    $stmt = $pdo->prepare('DELETE FROM hotel_rooms WHERE room_code = ?');
    $stmt->execute([$code]);
    
    if ($stmt->rowCount() > 0) {
        respond(true, ['code' => $code], 'Room deleted successfully');
    } else {
        respond(false, null, 'Failed to delete room');
    }
}

// Default response for unknown actions
respond(false, null, 'Unknown action');
?>