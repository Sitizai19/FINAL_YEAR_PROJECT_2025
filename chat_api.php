<?php
// chat_api.php - Backend API for live chat functionality

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Database configuration
$host = 'localhost';
$dbname = 'petcare_db';
$username = 'root';
$password = 'zainab@0558';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'send_message':
        sendMessage($pdo);
        break;
    case 'get_conversations':
        getConversations($pdo);
        break;
    case 'get_messages':
        getMessages($pdo);
        break;
    case 'mark_as_read':
        markAsRead($pdo);
        break;
    case 'get_unread_count':
        getUnreadCount($pdo);
        break;
    case 'delete_conversation':
        deleteConversation($pdo);
        break;
    case 'clear_conversation':
        clearConversation($pdo);
        break;
    case 'delete_all_conversations':
        deleteAllConversations($pdo);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function sendMessage($pdo) {
    $conversationId = $_POST['conversation_id'] ?? null;
    $senderType = $_POST['sender_type'] ?? '';
    $messageText = $_POST['message_text'] ?? '';
    $userName = $_POST['user_name'] ?? '';
    $userEmail = $_POST['user_email'] ?? '';
    $senderId = $_POST['sender_id'] ?? null;
    
    // Debug logging
    error_log("sendMessage called with: conversation_id=$conversationId, sender_type=$senderType, message_text=$messageText, sender_id=$senderId");
    
    if (empty($messageText) || empty($senderType)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // If no conversation_id, create a new conversation
        if (!$conversationId) {
            // Generate unique user number for anonymous users
            $userNumber = generateUserNumber($pdo);
            $displayName = $userName ?: "Anonymous $userNumber";
            
            // Use 'open' instead of 'active' to match the enum('open','closed','pending')
            $stmt = $pdo->prepare("INSERT INTO chat_conversations (user_name, user_email, user_number, status) VALUES (?, ?, ?, 'open')");
            $stmt->execute([$displayName, $userEmail, $userNumber]);
            $conversationId = $pdo->lastInsertId();
        }
        
        // Insert the message
        $stmt = $pdo->prepare("INSERT INTO chat_messages (conversation_id, sender_type, sender_id, message_text) VALUES (?, ?, ?, ?)");
        $stmt->execute([$conversationId, $senderType, $senderId, $messageText]);
        $messageId = $pdo->lastInsertId();
        
        // Update conversation timestamp
        $stmt = $pdo->prepare("UPDATE chat_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$conversationId]);
        
        // Create admin notification for user messages (not admin messages)
        if ($senderType === 'user') {
            $stmt = $pdo->prepare("
                INSERT INTO admin_notifications (
                    notification_type, 
                    conversation_id, 
                    chat_message_id, 
                    sender_name, 
                    sender_email, 
                    title, 
                    message, 
                    is_read
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ");
            
            $notificationTitle = "New Chat Message";
            $notificationMessage = "New message from " . ($userName ?: "Anonymous") . ": " . substr($messageText, 0, 100) . (strlen($messageText) > 100 ? "..." : "");
            
            $stmt->execute([
                'chat',
                $conversationId,
                $messageId,
                $userName ?: "Anonymous",
                $userEmail,
                $notificationTitle,
                $notificationMessage
            ]);
        }
        
        $pdo->commit();
        
        error_log("Message sent successfully: conversation_id=$conversationId, message_id=$messageId");
        
        echo json_encode([
            'success' => true,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'message' => 'Message sent successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to send message: ' . $e->getMessage()]);
    }
}

function getConversations($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.user_name,
                c.user_email,
                c.user_number,
                c.status,
                c.created_at,
                c.updated_at,
                COUNT(CASE WHEN m.sender_type = 'user' AND m.is_read = FALSE THEN 1 END) as unread_count,
                (SELECT message_text FROM chat_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM chat_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_time
            FROM chat_conversations c
            LEFT JOIN chat_messages m ON c.id = m.conversation_id
            GROUP BY c.id
            ORDER BY c.updated_at DESC
        ");
        $stmt->execute();
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data
        foreach ($conversations as &$conv) {
            $conv['unread_count'] = (int)$conv['unread_count'];
            $conv['last_message_time'] = formatTime($conv['last_message_time']);
        }
        
        echo json_encode(['success' => true, 'conversations' => $conversations]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to get conversations: ' . $e->getMessage()]);
    }
}

function getMessages($pdo) {
    $conversationId = $_GET['conversation_id'] ?? '';
    
    if (empty($conversationId)) {
        echo json_encode(['success' => false, 'message' => 'Conversation ID required']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                m.id,
                m.sender_type,
                m.message_text,
                m.created_at,
                m.is_read,
                c.user_name
            FROM chat_messages m
            JOIN chat_conversations c ON m.conversation_id = c.id
            WHERE m.conversation_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$conversationId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data
        foreach ($messages as &$msg) {
            $msg['created_at'] = formatTime($msg['created_at']);
        }
        
        echo json_encode(['success' => true, 'messages' => $messages]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to get messages: ' . $e->getMessage()]);
    }
}

function markAsRead($pdo) {
    $conversationId = $_POST['conversation_id'] ?? '';
    $senderType = $_POST['sender_type'] ?? '';
    
    if (empty($conversationId) || empty($senderType)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE chat_messages SET is_read = TRUE WHERE conversation_id = ? AND sender_type = ?");
        $stmt->execute([$conversationId, $senderType]);
        
        echo json_encode(['success' => true, 'message' => 'Messages marked as read']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to mark as read: ' . $e->getMessage()]);
    }
}

function getUnreadCount($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_count
            FROM chat_messages m
            JOIN chat_conversations c ON m.conversation_id = c.id
            WHERE m.sender_type = 'user' AND m.is_read = FALSE AND c.status = 'open'
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'unread_count' => (int)$result['unread_count']]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to get unread count: ' . $e->getMessage()]);
    }
}

function generateUserNumber($pdo) {
    // Generate a random 4-digit number
    $userNumber = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    
    // Check if this number already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM chat_conversations WHERE user_number = ?");
    $stmt->execute([$userNumber]);
    $exists = $stmt->fetchColumn();
    
    // If it exists, generate a new one (with a small chance of collision, keep trying)
    while ($exists > 0) {
        $userNumber = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        $stmt->execute([$userNumber]);
        $exists = $stmt->fetchColumn();
    }
    
    return $userNumber;
}

function formatTime($timestamp) {
    if (!$timestamp) return '';
    
    try {
        $now = new DateTime();
        $time = new DateTime($timestamp);
        
        // Check if the DateTime object is valid
        if (!$time || $time->format('Y') === '1970') {
            return '';
        }
        
        $diff = $now->diff($time);
        
        if ($diff->days > 0) {
            return $time->format('M j, Y g:i A');
        } elseif ($diff->h > 0) {
            return $time->format('g:i A');
        } else {
            return $time->format('g:i A');
        }
    } catch (Exception $e) {
        error_log("Error formatting time: " . $e->getMessage() . " for timestamp: " . $timestamp);
        return '';
    }
}

function deleteConversation($pdo) {
    $conversationId = $_POST['conversation_id'] ?? '';
    
    if (empty($conversationId)) {
        echo json_encode(['success' => false, 'message' => 'Conversation ID required']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Delete chat notifications related to this conversation
        $stmt = $pdo->prepare("DELETE FROM admin_notifications WHERE conversation_id = ? AND notification_type = 'chat'");
        $stmt->execute([$conversationId]);
        
        // Delete messages (this will cascade due to foreign key)
        $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE conversation_id = ?");
        $stmt->execute([$conversationId]);
        
        // Delete the conversation
        $stmt = $pdo->prepare("DELETE FROM chat_conversations WHERE id = ?");
        $stmt->execute([$conversationId]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Conversation deleted successfully']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to delete conversation: ' . $e->getMessage()]);
    }
}

function clearConversation($pdo) {
    $conversationId = $_POST['conversation_id'] ?? '';
    
    if (empty($conversationId)) {
        echo json_encode(['success' => false, 'message' => 'Conversation ID required']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Delete chat notifications related to this conversation
        $stmt = $pdo->prepare("DELETE FROM admin_notifications WHERE conversation_id = ? AND notification_type = 'chat'");
        $stmt->execute([$conversationId]);
        
        // Delete all messages in the conversation
        $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE conversation_id = ?");
        $stmt->execute([$conversationId]);
        
        // Update conversation timestamp
        $stmt = $pdo->prepare("UPDATE chat_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$conversationId]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Conversation cleared successfully']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to clear conversation: ' . $e->getMessage()]);
    }
}

function deleteAllConversations($pdo) {
    try {
        $pdo->beginTransaction();
        
        // Delete all chat notifications
        $stmt = $pdo->prepare("DELETE FROM admin_notifications WHERE notification_type = 'chat'");
        $stmt->execute();
        
        // Delete all chat messages
        $stmt = $pdo->prepare("DELETE FROM chat_messages");
        $stmt->execute();
        
        // Delete all conversations
        $stmt = $pdo->prepare("DELETE FROM chat_conversations");
        $stmt->execute();
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'All conversations deleted successfully']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to delete all conversations: ' . $e->getMessage()]);
    }
}
?>
