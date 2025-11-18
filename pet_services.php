<?php
// Pet Services Management
// File: pet_services.php

require_once 'config/database.php';

// Helper function to convert 12-hour format to 24-hour format
function convert12To24Hour($time12) {
    if (empty($time12)) {
        return null;
    }
    
    // If already in 24-hour format or doesn't contain AM/PM, return as is
    $time12 = trim($time12);
    if (!preg_match('/(\d{1,2}):(\d{2})\s*(AM|PM)/i', $time12, $matches)) {
        // If no AM/PM found, assume it's already in correct format
        return $time12;
    }
    
    $hour = intval($matches[1]);
    $minute = intval($matches[2]);
    $ampm = strtoupper($matches[3]);
    
    // Convert to 24-hour format
    if ($ampm === 'PM' && $hour != 12) {
        $hour = $hour + 12;
    } elseif ($ampm === 'AM' && $hour == 12) {
        $hour = 0;
    }
    
    // Format as HH:MM:SS
    return sprintf('%02d:%02d:00', $hour, $minute);
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Debug logging
error_log("Pet Services API - Method: $method, Action: $action");
error_log("POST data: " . print_r($_POST, true));

try {
    $conn = getConnection();
    
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
    
    switch($method) {
        case 'GET':
            switch($action) {
                case 'list':
                    $stmt = $conn->prepare("SELECT * FROM pet_services ORDER BY service_name");
                    $stmt->execute();
                    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $services]);
                    break;
                    
                case 'get':
                    $id = $_GET['id'] ?? 0;
                    $stmt = $conn->prepare("SELECT * FROM pet_services WHERE id = ?");
                    $stmt->execute([$id]);
                    $service = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $service]);
                    break;
                    
                case 'categories':
                    $stmt = $conn->prepare("SELECT DISTINCT service_category FROM pet_services WHERE service_category IS NOT NULL AND service_category != '' ORDER BY service_category");
                    $stmt->execute();
                    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    echo json_encode(['success' => true, 'categories' => $categories]);
                    break;
                    
                case 'check_availability':
                    // Check real-time availability of time slots for a service
                    $serviceId = $_GET['service_id'] ?? 0;
                    $bookingDate = $_GET['booking_date'] ?? '';
                    
                    if (!$serviceId || !$bookingDate) {
                        echo json_encode(['success' => false, 'message' => 'Service ID and booking date are required']);
                        break;
                    }
                    
                    // Get service with its time slot capacity
                    $stmt = $conn->prepare("SELECT available_time_slots FROM pet_services WHERE id = ?");
                    $stmt->execute([$serviceId]);
                    $service = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$service) {
                        echo json_encode(['success' => false, 'message' => 'Service not found']);
                        break;
                    }
                    
                    $availableTimeSlots = json_decode($service['available_time_slots'], true);
                    
                    if (!$availableTimeSlots) {
                        echo json_encode(['success' => false, 'message' => 'No time slots configured for this service']);
                        break;
                    }
                    
                    // Count existing bookings for each time slot on this date
                    $availability = [];
                    foreach ($availableTimeSlots as $timeSlot => $maxCapacity) {
                    // Count existing bookings for this time slot
                    // Capacity refers to COUNT OF BOOKINGS, not total pets per booking
                    // Convert 12-hour format time slot to 24-hour format for database comparison
                    // (bookings are stored in 24-hour format in the database)
                    $timeSlot24Hour = convert12To24Hour($timeSlot);
                    
                    $stmt = $conn->prepare("
                        SELECT COUNT(*) as booked_count 
                        FROM service_bookings 
                        WHERE service_id = ? 
                        AND booking_date = ? 
                        AND booking_time = ? 
                        AND booking_status IN ('pending', 'confirmed', 'accepted', 'in_progress')
                        AND admin_deleted = 0
                    ");
                    $stmt->execute([$serviceId, $bookingDate, $timeSlot24Hour]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $bookedCount = intval($result['booked_count']);
                    
                    $availableSlots = max(0, $maxCapacity - $bookedCount);
                    
                    $availability[$timeSlot] = [
                        'max_capacity' => $maxCapacity,
                        'booked' => $bookedCount,
                        'available' => $availableSlots,
                        'full' => $availableSlots <= 0
                    ];
                    }
                    
                    echo json_encode(['success' => true, 'availability' => $availability]);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid GET action: ' . $action]);
            }
            break;
            
        case 'POST':
            switch($action) {
                case 'create':
                    $service_name = $_POST['service_name'] ?? '';
                    $service_category = $_POST['service_category'] ?? '';
                    $duration_minutes = $_POST['duration_minutes'] ?? 0;
                    $duration_unit = $_POST['duration_unit'] ?? 'minutes';
                    $cat_hotel_category = $_POST['cat_hotel_category'] ?? '';
                    $breed_notes = $_POST['breed_notes'] ?? '';
                    $service_notes = $_POST['notes'] ?? '';
                    $image_url = $_POST['image_url'] ?? '';
                    
                    // Handle available time slots with capacity
                    $available_time_slots = null;
                    if (isset($_POST['available_time_slots']) && is_array($_POST['available_time_slots']) && 
                        isset($_POST['slot_capacity']) && is_array($_POST['slot_capacity'])) {
                        
                        $timeSlotsWithCapacity = [];
                        foreach ($_POST['available_time_slots'] as $timeSlot) {
                            $capacity = isset($_POST['slot_capacity'][$timeSlot]) ? intval($_POST['slot_capacity'][$timeSlot]) : 2;
                            $timeSlotsWithCapacity[$timeSlot] = $capacity;
                        }
                        $available_time_slots = json_encode($timeSlotsWithCapacity);
                    }
                    
                    // Handle multiple prices
                    $price_data = null;
                    if (isset($_POST['price_data'])) {
                        $price_data = $_POST['price_data'];
                    }
                    
                    // Calculate legacy price field for backward compatibility
                    $price = 0;
                    if ($price_data) {
                        $prices = json_decode($price_data, true);
                        if (is_array($prices) && count($prices) > 0) {
                            $price = $prices[0]['price'] ?? 0;
                        }
                    }
                    
                    // Validate required fields
                    if (empty($service_name) || empty($service_category)) {
                        echo json_encode(['success' => false, 'message' => 'Service name and category are required']);
                        break;
                    }
                    
                    if (!$price_data) {
                        echo json_encode(['success' => false, 'message' => 'At least one price is required']);
                        break;
                    }
                    
                    $stmt = $conn->prepare("INSERT INTO pet_services (service_name, service_category, price, price_data, duration_minutes, duration_unit, cat_hotel_category, breed_notes, notes, image_url, available_time_slots) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$service_name, $service_category, $price, $price_data, $duration_minutes, $duration_unit, $cat_hotel_category, $breed_notes, $service_notes, $image_url, $available_time_slots]);
                    
                    echo json_encode(['success' => true, 'message' => 'Service created successfully', 'id' => $conn->lastInsertId()]);
                    break;
                    
                case 'update':
                    $id = $_POST['id'] ?? 0;
                    $service_name = $_POST['service_name'] ?? '';
                    $service_category = $_POST['service_category'] ?? '';
                    $duration_minutes = $_POST['duration_minutes'] ?? 0;
                    $duration_unit = $_POST['duration_unit'] ?? 'minutes';
                    $cat_hotel_category = $_POST['cat_hotel_category'] ?? '';
                    $breed_notes = $_POST['breed_notes'] ?? '';
                    $service_notes = $_POST['notes'] ?? '';
                    
                    // Handle available time slots with capacity
                    $available_time_slots = null;
                    if (isset($_POST['available_time_slots']) && is_array($_POST['available_time_slots']) && 
                        isset($_POST['slot_capacity']) && is_array($_POST['slot_capacity'])) {
                        
                        $timeSlotsWithCapacity = [];
                        foreach ($_POST['available_time_slots'] as $timeSlot) {
                            $capacity = isset($_POST['slot_capacity'][$timeSlot]) ? intval($_POST['slot_capacity'][$timeSlot]) : 2;
                            $timeSlotsWithCapacity[$timeSlot] = $capacity;
                        }
                        $available_time_slots = json_encode($timeSlotsWithCapacity);
                    }
                    
                    // Handle multiple prices
                    $price_data = null;
                    if (isset($_POST['price_data'])) {
                        $price_data = $_POST['price_data'];
                    }
                    
                    // Calculate legacy price field for backward compatibility
                    $price = 0;
                    if ($price_data) {
                        $prices = json_decode($price_data, true);
                        if (is_array($prices) && count($prices) > 0) {
                            $price = $prices[0]['price'] ?? 0;
                        }
                    }
                    
                    if (empty($id) || empty($service_name) || empty($service_category)) {
                        echo json_encode(['success' => false, 'message' => 'ID, service name and category are required']);
                        break;
                    }
                    
                    if (!$price_data) {
                        echo json_encode(['success' => false, 'message' => 'At least one price is required']);
                        break;
                    }
                    
                    // Check if image_url is provided in the form data
                    if (isset($_POST['image_url'])) {
                        $image_url = $_POST['image_url'];
                        $stmt = $conn->prepare("UPDATE pet_services SET service_name = ?, service_category = ?, price = ?, price_data = ?, duration_minutes = ?, duration_unit = ?, cat_hotel_category = ?, breed_notes = ?, notes = ?, image_url = ?, available_time_slots = ? WHERE id = ?");
                        $stmt->execute([$service_name, $service_category, $price, $price_data, $duration_minutes, $duration_unit, $cat_hotel_category, $breed_notes, $service_notes, $image_url, $available_time_slots, $id]);
                    } else {
                        // Don't update image_url if not provided
                        $stmt = $conn->prepare("UPDATE pet_services SET service_name = ?, service_category = ?, price = ?, price_data = ?, duration_minutes = ?, duration_unit = ?, cat_hotel_category = ?, breed_notes = ?, notes = ?, available_time_slots = ? WHERE id = ?");
                        $stmt->execute([$service_name, $service_category, $price, $price_data, $duration_minutes, $duration_unit, $cat_hotel_category, $breed_notes, $service_notes, $available_time_slots, $id]);
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'Service updated successfully']);
                    break;
                    
                case 'delete':
                    $id = $_POST['id'] ?? 0;
                    
                    if (empty($id)) {
                        echo json_encode(['success' => false, 'message' => 'ID is required for deletion']);
                        break;
                    }
                    
                    $stmt = $conn->prepare("DELETE FROM pet_services WHERE id = ?");
                    $stmt->execute([$id]);
                    
                    echo json_encode(['success' => true, 'message' => 'Service deleted successfully']);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid POST action: ' . $action]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid method: ' . $method]);
    }
    
} catch(Exception $e) {
    error_log("Pet Services API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
