<?php
// notifications.php
// Handle admin notifications for new bookings

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
    $conn = getConnection();
} catch (Exception $e) {
    respond(false, null, 'Database connection failed: ' . $e->getMessage());
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'get_notifications') {
        // Get all notifications for admin with detailed booking and chat information
        $stmt = $conn->prepare("
            SELECT 
                n.*,
                CASE 
                    WHEN n.notification_type = 'booking' THEN
                        CASE 
                            WHEN n.booking_type = 'service' THEN CONCAT('New Service Booking: ', ps.service_name)
                            WHEN n.booking_type = 'hotel' THEN CONCAT('New Hotel Booking: ', ps.service_name)
                            ELSE n.title
                        END
                    WHEN n.notification_type = 'chat' THEN
                        CONCAT('💬 ', n.title, ' from ', n.sender_name)
                    ELSE n.title
                END as display_title,
                CASE 
                    WHEN n.notification_type = 'booking' THEN
                        CASE 
                            WHEN n.booking_type = 'service' THEN CONCAT('Customer ', CONCAT(u.first_name, ' ', u.last_name), ' booked ', ps.service_name, ' for ', p.name, ' on ', COALESCE(sb.booking_date, 'N/A'))
                            WHEN n.booking_type = 'hotel' THEN CONCAT('Customer ', CONCAT(u.first_name, ' ', u.last_name), ' booked hotel room ', COALESCE(hb.room_code, 'N/A'), ' for ', p.name, ' (Check-in: ', COALESCE(hb.checkin_date, 'N/A'), ')')
                            ELSE n.message
                        END
                    WHEN n.notification_type = 'chat' THEN
                        CONCAT('💬 ', n.message, ' (Conversation #', n.conversation_id, ')')
                    ELSE n.message
                END as display_message
            FROM admin_notifications n
            LEFT JOIN users u ON n.user_id = u.id
            LEFT JOIN pets p ON n.pet_id = p.id
            LEFT JOIN pet_services ps ON n.service_id = ps.id
            LEFT JOIN service_bookings sb ON n.booking_id = sb.id AND n.booking_type = 'service'
            LEFT JOIN hotel_bookings hb ON n.booking_id = hb.id AND n.booking_type = 'hotel'
            ORDER BY n.created_at DESC
            LIMIT 50
        ");
        $stmt->execute();
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        respond(true, $notifications, 'Notifications retrieved successfully');
        
    } else if ($action === 'mark_as_read') {
        $notificationId = $_POST['notification_id'] ?? null;
        
        if (!$notificationId) {
            respond(false, null, 'Notification ID is required');
        }
        
        $stmt = $conn->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = ?");
        $stmt->execute([$notificationId]);
        
        respond(true, null, 'Notification marked as read');
        
    } else if ($action === 'mark_all_as_read') {
        $stmt = $conn->prepare("UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0");
        $stmt->execute();
        
        respond(true, null, 'All notifications marked as read');
        
    } else if ($action === 'mark_chat_notifications_as_read') {
        $conversationId = $_POST['conversation_id'] ?? null;
        
        if (!$conversationId) {
            respond(false, null, 'Conversation ID is required');
        }
        
        $stmt = $conn->prepare("UPDATE admin_notifications SET is_read = 1 WHERE conversation_id = ? AND notification_type = 'chat'");
        $stmt->execute([$conversationId]);
        
        respond(true, null, 'Chat notifications marked as read');
        
    } else if ($action === 'clear_all_notifications') {
        // Delete all notifications from the database
        $stmt = $conn->prepare("DELETE FROM admin_notifications");
        $stmt->execute();
        
        $deletedCount = $stmt->rowCount();
        respond(true, ['deleted_count' => $deletedCount], 'All notifications cleared successfully');
        
    } else if ($action === 'check_new_notifications') {
        // Check if there are any unread notifications
        $stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM admin_notifications WHERE is_read = 0");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        respond(true, ['has_new' => $result['unread_count'] > 0], 'Check completed');
        
    } else {
        respond(false, null, 'Invalid action');
    }
    
} catch (Exception $e) {
    respond(false, null, 'Error: ' . $e->getMessage());
}
?>
