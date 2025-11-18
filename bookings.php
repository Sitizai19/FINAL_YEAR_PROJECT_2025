<?php
// bookings.php
// Handle booking form submissions from booking_services.html

require_once 'config/database.php';
require_once 'booking_email_service.php';

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

// Helper function to convert 12-hour format time to 24-hour format
// Converts "12:00 PM" to "12:00:00", "1:00 AM" to "01:00:00", etc.
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

try {
    $conn = getConnection();
} catch (Exception $e) {
    respond(false, null, 'Database connection failed: ' . $e->getMessage());
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
if ($action === 'create_booking') {
    // Validate required fields
    $requiredFields = ['pet_id', 'service_id', 'payment_method'];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            respond(false, null, "Missing required field: {$field}");
        }
    }
    
    // Get user ID from session
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    
    if (!$userId) {
        respond(false, null, 'Please log in to make a booking.');
    }
    
    // Get service details
    $serviceId = $_POST['service_id'];
    $stmt = $conn->prepare("SELECT * FROM pet_services WHERE id = ?");
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$service) {
        respond(false, null, 'Service not found');
    }
    
    // Get pet IDs (support both single pet and multiple pets)
    $petIds = [];
    
    if (isset($_POST['pet_ids']) && is_array($_POST['pet_ids'])) {
        $petIds = $_POST['pet_ids'];
    } elseif (isset($_POST['pet_ids']) && is_string($_POST['pet_ids'])) {
        // Handle JSON string from frontend
        $decoded = json_decode($_POST['pet_ids'], true);
        if (is_array($decoded)) {
            $petIds = $decoded;
        }
    } elseif (isset($_POST['pet_id'])) {
        $petIds = [$_POST['pet_id']];
    }
    
    if (empty($petIds)) {
        respond(false, null, 'No pets selected');
    }
    
    // Validate pets belong to user
    $placeholders = str_repeat('?,', count($petIds) - 1) . '?';
    $stmt = $conn->prepare("SELECT id FROM pets WHERE id IN ({$placeholders}) AND user_id = ?");
    $stmt->execute(array_merge($petIds, [$userId]));
    $validPets = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($validPets) !== count($petIds)) {
        respond(false, null, 'Invalid pet selection');
    }
    
    // Calculate total amount
    $totalAmount = floatval($_POST['total_amount'] ?? 0);
    if ($totalAmount <= 0) {
        respond(false, null, 'Invalid total amount');
    }
    
    // Generate booking reference in consistent format
    if ($service['service_category'] === 'CAT HOTEL') {
        $bookingReference = 'HT-' . date('Y') . '-' . strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 6));
    } else {
        $bookingReference = 'SRV-' . date('Y') . '-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }
    
        // Prepare booking data
        $bookingData = [
            'user_id' => $userId,
            'service_id' => $serviceId,
            'pet_id' => $petIds[0], // Use first pet (service_bookings only supports single pet)
            'total_amount' => $totalAmount,
            'payment_method' => $_POST['payment_method'],
            'booking_status' => 'pending',
            'booking_date' => $_POST['booking_date'] ?? null,
            'booking_time' => $_POST['booking_time'] ?? null,
            'additional_notes' => $_POST['additional_notes'] ?? '',
            'booking_reference' => $bookingReference
        ];
        
        // Store additional pets if more than one is selected
        if (count($petIds) > 1) {
            $additionalPets = array_slice($petIds, 1); // Get all pets except the first one
            // Convert pet IDs to integers for proper JSON storage
            $additionalPets = array_map('intval', $additionalPets);
            $bookingData['additional_pets'] = json_encode($additionalPets);
        }
    
    // Add service-specific fields
    if ($service['service_category'] === 'CAT HOTEL') {
        // Remove service booking fields that don't exist in hotel_bookings table
        unset($bookingData['booking_date']);
        unset($bookingData['booking_time']);
        
        // Add hotel-specific fields
        $bookingData['checkin_date'] = $_POST['checkin_date'] ?? null;
        $bookingData['checkout_date'] = $_POST['checkout_date'] ?? null;
        
        // Convert 12-hour format to 24-hour format for database compatibility
        $checkinTimeRaw = $_POST['checkin_time'] ?? null;
        $checkoutTimeRaw = $_POST['checkout_time'] ?? null;
        $bookingData['checkin_time'] = $checkinTimeRaw ? convert12To24Hour($checkinTimeRaw) : null;
        $bookingData['checkout_time'] = $checkoutTimeRaw ? convert12To24Hour($checkoutTimeRaw) : null;
        
        $bookingData['nights_count'] = intval($_POST['nights_count'] ?? 0);
        $bookingData['room_code'] = $_POST['room_code'] ?? null;
        
        // Ensure additional_pets is set for hotel bookings
        if (count($petIds) > 1 && !isset($bookingData['additional_pets'])) {
            $additionalPets = array_slice($petIds, 1);
            // Convert pet IDs to integers for proper JSON storage
            $additionalPets = array_map('intval', $additionalPets);
            $bookingData['additional_pets'] = json_encode($additionalPets);
        }
        
        // Validate Cat Hotel specific fields
        if (empty($bookingData['checkin_date']) || empty($bookingData['checkout_date'])) {
            respond(false, null, 'Check-in and check-out dates are required for Cat Hotel services');
        }
        
        if (empty($bookingData['room_code'])) {
            respond(false, null, 'Room selection is required for Cat Hotel services');
        }
        
        // Validate room availability
        $stmt = $conn->prepare("SELECT hr.status, hr.category_id FROM hotel_rooms hr WHERE hr.room_code = ?");
        $stmt->execute([$bookingData['room_code']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room || $room['status'] !== 'available') {
            respond(false, null, 'Selected room is not available');
        }
        
        // Validate pet count based on room category
        // Classic Suite (category_id = 1): 1-2 pets
        // Deluxe Suite (category_id = 2): 3-4 pets
        // Executive Suite (category_id = 3): 5-7 pets
        // Studio Suite (category_id = 4): 1 pet only
        $petCount = count($petIds);
        $roomCategoryId = $room['category_id'];
        
        if ($roomCategoryId == 1 && $petCount > 2) {
            respond(false, null, 'Classic Suite can only accommodate up to 2 pets. Please select a larger room type.');
        } elseif ($roomCategoryId == 2) {
            if ($petCount < 3 || $petCount > 4) {
                respond(false, null, 'Deluxe Suite accommodates 3-4 pets. Please select an appropriate room type.');
            }
        } elseif ($roomCategoryId == 3) {
            if ($petCount < 5 || $petCount > 7) {
                respond(false, null, 'Executive Suite accommodates 5-7 pets. Please select an appropriate room type.');
            }
        } elseif ($roomCategoryId == 4 && $petCount > 1) {
            respond(false, null, 'Studio Suite can only accommodate 1 pet. Please select a different room type for multiple pets.');
        }
        
    } else {
        $bookingData['booking_date'] = $_POST['booking_date'] ?? null;
        $bookingTimeRaw = $_POST['booking_time'] ?? null;
        
        // Validate regular service fields
        if (empty($bookingData['booking_date']) || empty($bookingTimeRaw)) {
            respond(false, null, 'Booking date and time are required');
        }
        
        // Convert 12-hour format to 24-hour format for database compatibility
        // This ensures it works whether the column is TIME or VARCHAR
        // If database column is VARCHAR, we can store "12:00 PM" directly
        // If database column is TIME, we need "12:00:00" format
        // For now, we'll convert to 24-hour format to ensure compatibility
        // TODO: After running migration to VARCHAR, we can store 12-hour format directly
        $bookingData['booking_time'] = convert12To24Hour($bookingTimeRaw);
        
        if (empty($bookingData['booking_time'])) {
            respond(false, null, 'Invalid booking time format');
        }
        
        // Check time slot availability to prevent over-booking
        try {
            // Get service capacity for this time slot
            $availableTimeSlots = null;
            if ($service['available_time_slots']) {
                $availableTimeSlots = json_decode($service['available_time_slots'], true);
            }
            
            // Match booking time (12-hour format like "2:00 PM") with available slots
            // Use the original format for matching (available_time_slots are stored in 12-hour format)
            $matchedTimeSlot = $bookingTimeRaw;
            
            // Try matching with variations (with/without leading zero)
            if ($availableTimeSlots && !isset($availableTimeSlots[$matchedTimeSlot])) {
                // Try matching with different formats
                $timeVariations = [
                    $matchedTimeSlot,
                    str_replace(':', ':0', $matchedTimeSlot), // Add zero if missing
                ];
                
                // Try normalizing the format
                $normalized = trim($matchedTimeSlot);
                if (preg_match('/(\d{1,2}):(\d{2})\s*(AM|PM)/i', $normalized, $matches)) {
                    $hour = intval($matches[1]);
                    $minute = $matches[2];
                    $ampm = strtoupper($matches[3]);
                    $timeVariations[] = sprintf('%d:%02d %s', $hour, $minute, $ampm);
                    $timeVariations[] = sprintf('%02d:%02d %s', $hour, $minute, $ampm);
                }
                
                foreach ($timeVariations as $variant) {
                    if (isset($availableTimeSlots[$variant])) {
                        $matchedTimeSlot = $variant;
                        break;
                    }
                }
            }
            
            if ($availableTimeSlots && isset($availableTimeSlots[$matchedTimeSlot])) {
                $maxCapacity = intval($availableTimeSlots[$matchedTimeSlot]);
                
                // Count existing bookings for this time slot on this date
                // Capacity refers to COUNT OF BOOKINGS, not total pets per booking
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as booked_count 
                    FROM service_bookings 
                    WHERE service_id = ? 
                    AND booking_date = ? 
                    AND booking_time = ? 
                    AND booking_status IN ('pending', 'confirmed', 'accepted', 'in_progress')
                    AND admin_deleted = 0
                ");
                // Use the original 12-hour format for matching, but the converted 24-hour format is stored in bookingData
                // For comparison in the query, we need to match against what's stored in the database
                // Since we're converting to 24-hour format before storing, we should compare using 24-hour format
                // But wait - if the database column is still TIME type, existing records are in 24-hour format
                // If it's VARCHAR, they might be in 12-hour format. This is tricky.
                // For now, we'll use the converted 24-hour format for matching
                // TODO: After migration to VARCHAR, update this to use 12-hour format consistently
                $stmt->execute([$serviceId, $bookingData['booking_date'], $bookingData['booking_time']]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $bookedCount = intval($result['booked_count']);
                
                // Check if adding one more booking would exceed capacity
                // Each booking counts as 1, regardless of number of pets
                if ($bookedCount >= $maxCapacity) {
                    respond(false, null, "This time slot is full. Only {$maxCapacity} booking(s) allowed at this time.");
                }
            }
        } catch (Exception $e) {
            // Log error but don't block booking if capacity check fails
            error_log("Time slot availability check failed: " . $e->getMessage());
        }
    }
    
    // Handle receipt upload
    $receiptPath = null;
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/receipts/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileExtension = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
        $fileName = 'receipt_' . time() . '_' . uniqid() . '.' . $fileExtension;
        $filePath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['receipt']['tmp_name'], $filePath)) {
            $receiptPath = $filePath;
            $bookingData['receipt_file_path'] = $receiptPath;
            $bookingData['receipt_uploaded_at'] = date('Y-m-d H:i:s');
        }
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Determine which table to insert into based on service category
        $tableName = '';
        if ($service['service_category'] === 'CAT HOTEL') {
            $tableName = 'hotel_bookings';
        } else {
            // For GROOMING SERVICE and A LA CARTE SERVICE
            $tableName = 'service_bookings';
        }
        
        // Insert booking
        $insertFields = array_keys($bookingData);
        $placeholders = ':' . implode(', :', $insertFields);
        $sql = "INSERT INTO {$tableName} (" . implode(', ', $insertFields) . ") VALUES ({$placeholders})";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($bookingData);
        $bookingId = $conn->lastInsertId();
        
        // Multiple pets are now supported: first pet is stored in pet_id, additional pets in additional_pets JSON column
        
        // Update room status if it's a Cat Hotel booking
        if ($service['service_category'] === 'CAT HOTEL' && $bookingData['room_code']) {
            $stmt = $conn->prepare("UPDATE hotel_rooms SET status = 'occupied', occupants = ? WHERE room_code = ?");
            $stmt->execute([count($petIds), $bookingData['room_code']]);
        }
        
        // Create admin notification for new booking
        $bookingType = ($service['service_category'] === 'CAT HOTEL') ? 'hotel' : 'service';
        $title = ($bookingType === 'hotel') ? 'New Hotel Booking' : 'New Service Booking';
        $message = ($bookingType === 'hotel') 
            ? "New hotel booking created for {$bookingData['room_code']}"
            : "New service booking created for {$service['service_name']}";
        
        $stmt = $conn->prepare("
            INSERT INTO admin_notifications (notification_type, booking_id, user_id, pet_id, service_id, booking_type, title, message) 
            VALUES ('booking', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $bookingId,
            $userId,
            $petIds[0], // Use first pet ID
            $serviceId,
            $bookingType,
            $title,
            $message
        ]);
        
        // Commit transaction
        $conn->commit();
        
        // Send booking confirmation email
        try {
            // Get customer information for email
            $stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get pet information for all pets
            $allPetNames = [];
            foreach ($petIds as $petId) {
                $stmt = $conn->prepare("SELECT name FROM pets WHERE id = ?");
                $stmt->execute([$petId]);
                $pet = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($pet) {
                    $allPetNames[] = $pet['name'];
                }
            }
            
            // Prepare email data
            $emailData = [
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'booking_type' => $bookingType,
                'first_name' => $customer['first_name'] ?? '',
                'last_name' => $customer['last_name'] ?? '',
                'customer_email' => $customer['email'] ?? '',
                'pet_name' => implode(', ', $allPetNames), // Multiple pet names
                'pet_names_list' => $allPetNames, // Array of pet names
                'service_name' => $service['service_name'],
                'service_category' => $service['service_category'],
                'total_amount' => $totalAmount,
                'payment_method' => $bookingData['payment_method'],
                'booking_date' => $bookingData['booking_date'] ?? null,
                'booking_time' => $bookingData['booking_time'] ?? null,
                'additional_notes' => $bookingData['additional_notes'] ?? ''
            ];
            
            // Add hotel-specific data if it's a hotel booking
            if ($bookingType === 'hotel') {
                $emailData['checkin_date'] = $bookingData['checkin_date'] ?? null;
                $emailData['checkout_date'] = $bookingData['checkout_date'] ?? null;
                $emailData['checkin_time'] = $bookingData['checkin_time'] ?? null;
                $emailData['checkout_time'] = $bookingData['checkout_time'] ?? null;
                $emailData['room_code'] = $bookingData['room_code'] ?? '';
                $emailData['nights_count'] = $bookingData['nights_count'] ?? 0;
                
                // Get room category
                if ($bookingData['room_code']) {
                    $stmt = $conn->prepare("
                        SELECT CASE 
                            WHEN category_id = 1 THEN 'Classic Suite'
                            WHEN category_id = 2 THEN 'Deluxe Suite'
                            WHEN category_id = 3 THEN 'Executive Suite'
                            WHEN category_id = 4 THEN 'Studio Suite'
                            ELSE 'Unknown'
                        END as room_category
                        FROM hotel_rooms WHERE room_code = ?
                    ");
                    $stmt->execute([$bookingData['room_code']]);
                    $roomInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                    $emailData['room_category'] = $roomInfo['room_category'] ?? 'Unknown';
                }
            }
            
            // Send email
            $emailResult = sendBookingConfirmationEmail($emailData);
            
            // Log email result (optional - you can remove this if you don't want to log)
            if (!$emailResult['success']) {
                error_log("Failed to send booking confirmation email for booking {$bookingId}: " . $emailResult['message']);
            }
            
        } catch (Exception $emailError) {
            // Don't fail the booking if email fails, just log the error
            error_log("Email sending error for booking {$bookingId}: " . $emailError->getMessage());
        }
        
        respond(true, [
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'total_amount' => $totalAmount,
            'payment_method' => $bookingData['payment_method']
        ], 'Booking created successfully');
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
    
} elseif ($action === 'get_booking') {
    $bookingId = $_GET['booking_id'] ?? '';
    if (empty($bookingId)) {
        respond(false, null, 'Booking ID required');
    }
    
    // First try to find in service_bookings
    $stmt = $conn->prepare("
        SELECT 
            sb.id, sb.booking_date, sb.booking_time, sb.booking_reference, sb.total_amount,
            sb.payment_method, sb.additional_notes, sb.booking_status,
            sb.user_id, sb.pet_id, sb.service_id, sb.additional_pets,
            sb.created_at, sb.updated_at, sb.receipt_file_path,
            u.first_name, u.last_name, u.email, u.phone_number,
            p.name as pet_name,
            p.breed as pet_breed,
            ps.service_name, ps.service_category, ps.price as service_price,
            'service' as booking_type
        FROM service_bookings sb
        LEFT JOIN users u ON sb.user_id = u.id
        LEFT JOIN pets p ON sb.pet_id = p.id
        LEFT JOIN pet_services ps ON sb.service_id = ps.id
        WHERE sb.id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If not found in service_bookings, try hotel_bookings
    if (!$booking) {
        $stmt = $conn->prepare("
            SELECT 
                hb.*,
                u.first_name, u.last_name, u.email, u.phone_number,
                p.name as pet_name,
                p.breed as pet_breed,
                ps.service_name, ps.service_category, ps.price as service_price,
                hr.room_code, hr.category_id,
                CASE 
                    WHEN hr.category_id = 1 THEN 'Classic Suite'
                    WHEN hr.category_id = 2 THEN 'Deluxe Suite'
                    WHEN hr.category_id = 3 THEN 'Executive Suite'
                    WHEN hr.category_id = 4 THEN 'Studio Suite'
                    ELSE 'Unknown'
                END as room_category,
                'hotel' as booking_type
            FROM hotel_bookings hb
            LEFT JOIN users u ON hb.user_id = u.id
            LEFT JOIN pets p ON hb.pet_id = p.id
            LEFT JOIN pet_services ps ON hb.service_id = ps.id
            LEFT JOIN hotel_rooms hr ON hb.room_code = hr.room_code
            WHERE hb.id = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$booking) {
        respond(false, null, 'Booking not found');
    }
    
    // Fetch additional pets for this booking with full details
    $allPetsInfo = [];
    // Add primary pet ID
    $primaryPetId = $booking['pet_id'];
    $allPetsInfo[] = $primaryPetId;
    
    // Add additional pets if any
    if (!empty($booking['additional_pets']) && $booking['additional_pets'] !== 'null' && $booking['additional_pets'] !== NULL) {
        $additionalPetIds = json_decode($booking['additional_pets'], true);
        if (is_array($additionalPetIds) && !empty($additionalPetIds)) {
            $additionalPetIds = array_map('intval', $additionalPetIds);
            $allPetsInfo = array_merge($allPetsInfo, $additionalPetIds);
        }
    }
    
    // Fetch full details for all pets
    if (!empty($allPetsInfo)) {
        $placeholders = str_repeat('?,', count($allPetsInfo) - 1) . '?';
        $stmt2 = $conn->prepare("
            SELECT id, name, breed, age, gender, weight, spayed_neutered, medical_type, medical_condition, special_notes, vaccine_name, vaccine_date, pet_category, photo_path, created_at 
            FROM pets 
            WHERE id IN ({$placeholders})
        ");
        $stmt2->execute($allPetsInfo);
        $allPetsFullDetails = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $booking['all_pets'] = $allPetsFullDetails;
    } else {
        $booking['all_pets'] = [];
    }
    
    respond(true, $booking);
    
} elseif ($action === 'get_user_bookings') {
    // Get user ID from session
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    
    if (!$userId) {
        respond(false, null, 'Please log in to view bookings.');
    }
    
    // Query service bookings excluding user_deleted
    $stmt = $conn->prepare("
        SELECT 
            sb.*,
            p.name as pet_name,
            p.age as pet_age,
            p.gender as pet_gender,
            p.weight as pet_weight,
            p.breed as pet_breed,
            p.photo_path as pet_photo_path,
            p.pet_category,
            ps.service_name,
            ps.service_category,
            ps.description as service_description,
            ps.price_per_unit,
            ps.duration_minutes,
            ps.image_url,
            'service' as booking_type
        FROM service_bookings sb
        LEFT JOIN pets p ON sb.pet_id = p.id
        LEFT JOIN pet_services ps ON sb.service_id = ps.id
        WHERE sb.user_id = ? 
        AND (sb.user_deleted = 0 OR sb.user_deleted IS NULL)
        ORDER BY sb.created_at DESC
    ");
    $stmt->execute([$userId]);
    $serviceBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Query hotel bookings excluding user_deleted
    $stmt = $conn->prepare("
        SELECT 
            hb.*,
            p.name as pet_name,
            p.age as pet_age,
            p.gender as pet_gender,
            p.weight as pet_weight,
            p.breed as pet_breed,
            p.photo_path as pet_photo_path,
            p.pet_category,
            hr.room_category,
            hr.price_per_night as service_price,
            'hotel' as booking_type
        FROM hotel_bookings hb
        LEFT JOIN pets p ON hb.pet_id = p.id
        LEFT JOIN hotel_rooms hr ON hb.room_code = hr.room_code
        WHERE hb.user_id = ? 
        AND (hb.user_deleted = 0 OR hb.user_deleted IS NULL)
        ORDER BY hb.created_at DESC
    ");
    $stmt->execute([$userId]);
    $hotelBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine both types of bookings
    $allBookings = array_merge($serviceBookings, $hotelBookings);
    
    // Sort by created_at
    usort($allBookings, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    respond(true, $allBookings);
    
} elseif ($action === 'cancel_booking') {
    $bookingId = $_POST['booking_id'] ?? '';
    if (empty($bookingId)) {
        respond(false, null, 'Booking ID required');
    }
    
    // Get user ID from session
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    
    if (!$userId) {
        respond(false, null, 'Please log in to cancel booking.');
    }
    
    // Verify booking belongs to user and get booking data - check both tables
    $stmt = $conn->prepare("SELECT id, booking_type, room_code FROM service_bookings WHERE id = ? AND user_id = ?");
    $stmt->execute([$bookingId, $userId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        $stmt = $conn->prepare("SELECT id, booking_type, room_code FROM hotel_bookings WHERE id = ? AND user_id = ?");
        $stmt->execute([$bookingId, $userId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            respond(false, null, 'Booking not found or access denied');
        }
        $tableName = 'hotel_bookings';
        $bookingType = 'hotel';
    } else {
        $tableName = 'service_bookings';
        $bookingType = $booking['booking_type'] ?? 'service';
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Update booking status
        $stmt = $conn->prepare("UPDATE {$tableName} SET booking_status = 'cancelled' WHERE id = ?");
        $stmt->execute([$bookingId]);
        
        // Free up room if it's a Cat Hotel booking
        if (!empty($booking['room_code'])) {
            $stmt = $conn->prepare("UPDATE hotel_rooms SET status = 'available', occupants = 0 WHERE room_code = ?");
            $stmt->execute([$booking['room_code']]);
        }
        
        // Check if cancellation notification already exists for this booking
        $checkStmt = $conn->prepare("SELECT id FROM admin_notifications WHERE notification_type = 'booking' AND booking_id = ? AND title = 'Booking Cancelled'");
        $checkStmt->execute([$bookingId]);
        $existingNotification = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Only create notification if it doesn't already exist
        if (!$existingNotification) {
            $stmt = $conn->prepare("INSERT INTO admin_notifications (notification_type, booking_id, user_id, booking_type, title, message) VALUES ('booking', ?, ?, ?, 'Booking Cancelled', 'Your booking has been cancelled successfully.')");
            $stmt->execute([$bookingId, $userId, $bookingType]);
        }
        
        $conn->commit();
        respond(true, null, 'Booking cancelled successfully');
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
    
} elseif ($action === 'delete_booking') {
    // User action to delete a booking (mark as user_deleted, admin can still see it)
    $bookingId = $_POST['booking_id'] ?? '';
    if (empty($bookingId)) {
        respond(false, null, 'Booking ID required');
    }
    
    // Get user ID from session
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    
    if (!$userId) {
        respond(false, null, 'Please log in to delete booking.');
    }
    
    // Determine which table to use
    $bookingType = $_POST['booking_type'] ?? 'service';
    
    if ($bookingType === 'hotel') {
        $tableName = 'hotel_bookings';
    } else {
        $tableName = 'service_bookings';
    }
    
    // Verify booking belongs to user
    $stmt = $conn->prepare("SELECT id FROM {$tableName} WHERE id = ? AND user_id = ?");
    $stmt->execute([$bookingId, $userId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        respond(false, null, 'Booking not found or access denied');
    }
    
    // Check if user_deleted column exists, if not add it
    try {
        // Check if column exists
        $columnExists = false;
        $stmt = $conn->prepare("SHOW COLUMNS FROM {$tableName} LIKE 'user_deleted'");
        $stmt->execute();
        if ($stmt->fetch()) {
            $columnExists = true;
        }
        
        // If column doesn't exist, add it
        if (!$columnExists) {
            $conn->exec("ALTER TABLE {$tableName} ADD COLUMN user_deleted TINYINT(1) DEFAULT 0");
        }
    } catch (PDOException $e) {
        // Column already exists or error adding, ignore
    }
    
    // Mark as user_deleted
    $stmt = $conn->prepare("UPDATE {$tableName} SET user_deleted = 1 WHERE id = ?");
    $stmt->execute([$bookingId]);
    
    respond(true, null, 'Booking deleted successfully');
    
} elseif ($action === 'update_status') {
    // Admin action to update booking status
    $bookingId = $_POST['booking_id'] ?? '';
    $newStatus = $_POST['new_status'] ?? '';
    
    if (empty($bookingId) || empty($newStatus)) {
        respond(false, null, 'Booking ID and new status required');
    }
    
    // Validate status
    $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    if (!in_array($newStatus, $validStatuses)) {
        respond(false, null, 'Invalid status. Must be one of: ' . implode(', ', $validStatuses));
    }
    
    // Determine which table to update based on booking type
    $bookingType = $_POST['booking_type'] ?? '';
    $tableName = '';
    $roomCodeField = '';
    
    if ($bookingType === 'hotel') {
        $tableName = 'hotel_bookings';
        $roomCodeField = 'room_code';
    } elseif ($bookingType === 'service') {
        $tableName = 'service_bookings';
        $roomCodeField = null;
    } else {
        // Try to determine booking type by checking both tables
        $stmt = $conn->prepare("SELECT 'hotel' as type FROM hotel_bookings WHERE id = ?");
        $stmt->execute([$bookingId]);
        if ($stmt->fetch()) {
            $tableName = 'hotel_bookings';
            $roomCodeField = 'room_code';
        } else {
            $stmt = $conn->prepare("SELECT 'service' as type FROM service_bookings WHERE id = ?");
            $stmt->execute([$bookingId]);
            if ($stmt->fetch()) {
                $tableName = 'service_bookings';
                $roomCodeField = null;
            } else {
                respond(false, null, 'Booking not found');
            }
        }
    }
    
    // Get booking details
    $stmt = $conn->prepare("SELECT * FROM {$tableName} WHERE id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        respond(false, null, 'Booking not found');
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Update booking status
        $stmt = $conn->prepare("UPDATE {$tableName} SET booking_status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $bookingId]);
        
        // Update room status if it's a Cat Hotel booking
        if ($roomCodeField && $booking[$roomCodeField]) {
            if ($newStatus === 'confirmed') {
                // Room becomes occupied when booking is confirmed
                $stmt = $conn->prepare("UPDATE hotel_rooms SET status = 'occupied' WHERE room_code = ?");
                $stmt->execute([$booking[$roomCodeField]]);
            } elseif ($newStatus === 'completed' || $newStatus === 'cancelled') {
                // Room becomes available when booking is completed or cancelled
                $stmt = $conn->prepare("UPDATE hotel_rooms SET status = 'available', occupants = 0 WHERE room_code = ?");
                $stmt->execute([$booking[$roomCodeField]]);
            }
        }
        
        // Create notification for user
        $notificationType = 'booking_' . $newStatus;
        $title = ucfirst($newStatus) . ' Booking';
        $message = '';
        
        switch ($newStatus) {
            case 'confirmed':
                $message = 'Your booking has been confirmed by our team. Please prepare for your appointment.';
                break;
            case 'completed':
                $message = 'Your service has been completed. Thank you for choosing our services!';
                break;
            case 'cancelled':
                $message = 'Your booking has been cancelled. If you have any questions, please contact us.';
                break;
            default:
                $message = 'Your booking status has been updated to ' . $newStatus . '.';
        }
        
        // Note: We'll need to create an admin_notifications table if it doesn't exist
        // For now, we'll skip the notification creation
        
        $conn->commit();
        respond(true, null, 'Booking status updated successfully');
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
    
} elseif ($action === 'admin_list') {
    // Admin action to list all bookings (both service and hotel bookings)
    
    // Get service bookings
    $stmt = $conn->prepare("
        SELECT 
            sb.*,
            u.first_name, u.last_name, u.email, u.phone_number,
            p.name as pet_name,
            p.breed as pet_breed,
            ps.service_name, ps.service_category, ps.price as service_price,
            'service' as booking_type
        FROM service_bookings sb
        LEFT JOIN users u ON sb.user_id = u.id
        LEFT JOIN pets p ON sb.pet_id = p.id
        LEFT JOIN pet_services ps ON sb.service_id = ps.id
        WHERE sb.admin_deleted = 0
        ORDER BY sb.created_at DESC
    ");
    $stmt->execute();
    $serviceBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get hotel bookings
    $stmt = $conn->prepare("
        SELECT 
            hb.*,
            u.first_name, u.last_name, u.email, u.phone_number,
            p.name as pet_name,
            p.breed as pet_breed,
            ps.service_name, ps.service_category, ps.price as service_price,
            hr.room_code, hr.category_id,
            CASE 
                WHEN hr.category_id = 1 THEN 'Classic Suite'
                WHEN hr.category_id = 2 THEN 'Deluxe Suite'
                WHEN hr.category_id = 3 THEN 'Executive Suite'
                WHEN hr.category_id = 4 THEN 'Studio Suite'
                ELSE 'Unknown'
            END as room_category,
            'hotel' as booking_type
        FROM hotel_bookings hb
        LEFT JOIN users u ON hb.user_id = u.id
        LEFT JOIN pets p ON hb.pet_id = p.id
        LEFT JOIN pet_services ps ON hb.service_id = ps.id
        LEFT JOIN hotel_rooms hr ON hb.room_code = hr.room_code
        WHERE hb.admin_deleted = 0
        ORDER BY hb.created_at DESC
    ");
    $stmt->execute();
    $hotelBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine and format all bookings
    $allBookings = array_merge($serviceBookings, $hotelBookings);
    
    // Sort by creation date (newest first)
    usort($allBookings, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    // Format the data for admin display
    $formattedBookings = array_map(function($booking) {
        return [
            'id' => $booking['id'],
            'booking_reference' => $booking['booking_reference'],
            'owner_name' => trim($booking['first_name'] . ' ' . $booking['last_name']),
            'email' => $booking['email'],
            'owner_phone' => $booking['phone_number'],
            'pet_name' => $booking['pet_name'],
            'pet_breed' => $booking['pet_breed'],
            'service_name' => $booking['service_name'],
            'service_category' => $booking['service_category'],
            'booking_date' => $booking['booking_date'] ?? null,
            'booking_time' => $booking['booking_time'] ?? null,
            'checkin_date' => $booking['checkin_date'] ?? null,
            'checkout_date' => $booking['checkout_date'] ?? null,
            'nights_count' => $booking['nights_count'] ?? null,
            'room_code' => $booking['room_code'] ?? null,
            'room_category' => $booking['room_category'] ?? null,
            'payment_method' => $booking['payment_method'],
            'total_amount' => $booking['total_amount'],
            'booking_status' => $booking['booking_status'],
            'receipt_file_path' => $booking['receipt_file_path'],
            'additional_notes' => $booking['additional_notes'],
            'admin_notes' => null,  // Notes are fetched separately via booking_notes.php
            'booking_type' => $booking['booking_type'],
            'created_at' => $booking['created_at']
        ];
    }, $allBookings);
    
    respond(true, $formattedBookings);
    
} elseif ($action === 'admin_service_bookings') {
    // Admin action to list only service bookings (not hotel bookings)
    
    $stmt = $conn->prepare("
        SELECT 
            sb.*,
            u.first_name, u.last_name, u.email, u.phone_number,
            p.name as pet_name,
            p.breed as pet_breed,
            ps.service_name, ps.service_category, ps.price as service_price
        FROM service_bookings sb
        LEFT JOIN users u ON sb.user_id = u.id
        LEFT JOIN pets p ON sb.pet_id = p.id
        LEFT JOIN pet_services ps ON sb.service_id = ps.id
        WHERE sb.admin_deleted = 0
        ORDER BY sb.created_at DESC
    ");
    $stmt->execute();
    $serviceBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch additional pets for each booking with full details
    foreach ($serviceBookings as &$booking) {
        $allPetsInfo = [];
        // Add primary pet with full details
        $primaryPetId = $booking['pet_id'];
        $allPetsInfo[] = $primaryPetId;
        
        // Add additional pets if any
        if (!empty($booking['additional_pets']) && $booking['additional_pets'] !== 'null' && $booking['additional_pets'] !== NULL) {
            $additionalPetIds = json_decode($booking['additional_pets'], true);
            if (is_array($additionalPetIds) && !empty($additionalPetIds)) {
                $additionalPetIds = array_map('intval', $additionalPetIds);
                $allPetsInfo = array_merge($allPetsInfo, $additionalPetIds);
            }
        }
        
        // Fetch full details for all pets
        if (!empty($allPetsInfo)) {
            $placeholders = str_repeat('?,', count($allPetsInfo) - 1) . '?';
            $stmt2 = $conn->prepare("
                SELECT id, name, breed, age, gender, weight, spayed_neutered, medical_type, medical_condition, special_notes, vaccine_name, vaccine_date, pet_category, photo_path, created_at 
                FROM pets 
                WHERE id IN ({$placeholders})
            ");
            $stmt2->execute($allPetsInfo);
            $allPetsFullDetails = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $booking['all_pets'] = $allPetsFullDetails;
        } else {
            $booking['all_pets'] = [];
        }
    }
    unset($booking);
    
    // Format the data for admin display
    $formattedBookings = array_map(function($booking) {
        return [
            'id' => $booking['id'],
            'booking_reference' => $booking['booking_reference'],
            'owner_name' => trim($booking['first_name'] . ' ' . $booking['last_name']),
            'email' => $booking['email'],
            'owner_phone' => $booking['phone_number'],
            'pet_name' => $booking['pet_name'],
            'pet_breed' => $booking['pet_breed'],
            'all_pets' => $booking['all_pets'] ?? [],
            'service_name' => $booking['service_name'],
            'service_category' => $booking['service_category'],
            'booking_date' => $booking['booking_date'] ?? null,
            'booking_time' => $booking['booking_time'] ?? null,
            'payment_method' => $booking['payment_method'],
            'total_amount' => $booking['total_amount'],
            'booking_status' => $booking['booking_status'],
            'receipt_file_path' => $booking['receipt_file_path'],
            'additional_notes' => $booking['additional_notes'],
            'admin_notes' => null,  // Notes are fetched separately via booking_notes.php
            'booking_type' => 'service',
            'created_at' => $booking['created_at'],
            'updated_at' => $booking['updated_at']
        ];
    }, $serviceBookings);
    
    respond(true, $formattedBookings);
    
} elseif ($action === 'delete') {
    // Admin action to soft delete a booking (mark as admin_deleted)
    $bookingId = $_POST['booking_id'] ?? '';
    if (empty($bookingId)) {
        respond(false, null, 'Booking ID is required');
    }
    
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // First, check if booking exists in either table
        $stmt = $conn->prepare("SELECT id, 'service' as booking_type FROM service_bookings WHERE id = ? AND admin_deleted = 0");
        $stmt->execute([$bookingId]);
        $serviceBooking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$serviceBooking) {
            $stmt = $conn->prepare("SELECT id, 'hotel' as booking_type FROM hotel_bookings WHERE id = ? AND admin_deleted = 0");
            $stmt->execute([$bookingId]);
            $hotelBooking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$hotelBooking) {
                $conn->rollBack();
                respond(false, null, 'Booking not found or already deleted');
            }
            $booking = $hotelBooking;
        } else {
            $booking = $serviceBooking;
        }
        
        // Soft delete by setting admin_deleted = 1 instead of actually deleting
        if ($booking['booking_type'] === 'service') {
            $stmt = $conn->prepare("UPDATE service_bookings SET admin_deleted = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE hotel_bookings SET admin_deleted = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        }
        $stmt->execute([$bookingId]);
        
        // Note: We keep notifications and booking notes as they might be useful for reference
        // The booking is only hidden from admin view, not actually deleted
        
        // Commit transaction
        $conn->commit();
        
        respond(true, null, 'Booking deleted successfully (hidden from admin view)');
        
    } catch (Exception $e) {
        $conn->rollBack();
        respond(false, null, 'Error deleting booking: ' . $e->getMessage());
    }
    
} else if ($action === 'get_hotel_bookings') {
    // Get all hotel bookings with customer and pet information
    $stmt = $conn->prepare("
        SELECT 
            hb.*,
            CONCAT(u.first_name, ' ', u.last_name) as customer_name,
            u.email as customer_email,
            u.phone_number as customer_phone,
            p.name as pet_name,
            p.breed as pet_breed,
            p.age as pet_age,
            p.gender as pet_gender,
            p.weight as pet_weight,
            p.spayed_neutered as pet_spayed_neutered,
            p.medical_type as pet_medical_type,
            p.medical_condition as pet_medical_condition,
            p.special_notes as pet_special_notes,
            p.vaccine_name as pet_vaccine,
            p.vaccine_date as pet_vaccine_date,
            p.pet_category as pet_category,
            p.photo_path as pet_photo,
            ps.service_name,
            ps.service_category,
            hr.category_id,
            CASE 
                WHEN hr.category_id = 1 THEN 'Classic Suite'
                WHEN hr.category_id = 2 THEN 'Deluxe Suite'
                WHEN hr.category_id = 3 THEN 'Executive Suite'
                WHEN hr.category_id = 4 THEN 'Studio Suite'
                ELSE 'Unknown'
            END as room_category
        FROM hotel_bookings hb
        LEFT JOIN users u ON hb.user_id = u.id
        LEFT JOIN pets p ON hb.pet_id = p.id
        LEFT JOIN pet_services ps ON hb.service_id = ps.id
        LEFT JOIN hotel_rooms hr ON hb.room_code = hr.room_code
        WHERE hb.admin_deleted = 0
        ORDER BY hb.created_at DESC
    ");
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch additional pets for each hotel booking with full details
    foreach ($bookings as &$booking) {
        $allPetsInfo = [];
        // Add primary pet ID
        $primaryPetId = $booking['pet_id'];
        $allPetsInfo[] = $primaryPetId;
        
        // Add additional pets if any
        if (!empty($booking['additional_pets']) && $booking['additional_pets'] !== 'null' && $booking['additional_pets'] !== NULL) {
            $additionalPetIds = json_decode($booking['additional_pets'], true);
            if (is_array($additionalPetIds) && !empty($additionalPetIds)) {
                $additionalPetIds = array_map('intval', $additionalPetIds);
                $allPetsInfo = array_merge($allPetsInfo, $additionalPetIds);
            }
        }
        
        // Fetch full details for all pets
        if (!empty($allPetsInfo)) {
            $placeholders = str_repeat('?,', count($allPetsInfo) - 1) . '?';
            $stmt2 = $conn->prepare("
                SELECT id, name, breed, age, gender, weight, spayed_neutered, medical_type, medical_condition, special_notes, vaccine_name, vaccine_date, pet_category, photo_path, created_at 
                FROM pets 
                WHERE id IN ({$placeholders})
            ");
            $stmt2->execute($allPetsInfo);
            $allPetsFullDetails = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $booking['all_pets'] = $allPetsFullDetails;
        } else {
            $booking['all_pets'] = [];
        }
        // Add booking_type to each booking
        $booking['booking_type'] = 'hotel';
    }
    unset($booking);
    
    respond(true, $bookings, 'Hotel bookings retrieved successfully');
    
} else if ($action === 'update_hotel_references') {
    // Update existing hotel booking references to new format
    try {
        // Get all hotel bookings with old SRV- format
        $stmt = $conn->prepare("SELECT id, booking_reference FROM hotel_bookings WHERE booking_reference LIKE 'SRV-%'");
        $stmt->execute();
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $updated = 0;
        foreach ($bookings as $booking) {
            // Generate new HT-YYYY-RANDOM format
            $year = date('Y');
            $randomLetters = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 6));
            $newReference = "HT-{$year}-{$randomLetters}";
            
            // Update the booking reference
            $updateStmt = $conn->prepare("UPDATE hotel_bookings SET booking_reference = ? WHERE id = ?");
            $updateStmt->execute([$newReference, $booking['id']]);
            $updated++;
        }
        
        respond(true, ['updated' => $updated], "Updated {$updated} hotel booking references successfully");
        
    } catch (Exception $e) {
        respond(false, null, 'Error updating hotel booking references: ' . $e->getMessage());
    }
    
} else {
    respond(false, null, 'Invalid action');
}

} catch (Exception $e) {
    respond(false, null, 'Error: ' . $e->getMessage());
}
