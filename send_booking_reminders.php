<?php
// send_booking_reminders.php
// Scheduled task to send automatic booking reminders
// This script should be run daily via cron job

require_once 'booking_reminder_service.php';

// Set timezone
date_default_timezone_set('Asia/Kuala_Lumpur');

// Ensure logs directory exists and use absolute path for log file
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/reminder_log.txt';

// Log the start of the process
$logMessage = "[" . date('Y-m-d H:i:s') . "] Starting booking reminder process\n";
file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

try {
    // Process booking reminders
    $result = processBookingReminders();
    
    if ($result['success']) {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] Reminder process completed successfully. ";
        $logMessage .= "Total: {$result['total_processed']}, Success: {$result['success_count']}, Errors: {$result['error_count']}\n";
        
        // Log individual results
        foreach ($result['results'] as $emailResult) {
            $status = $emailResult['success'] ? 'SUCCESS' : 'ERROR';
            $logMessage .= "[" . date('Y-m-d H:i:s') . "] {$status} - Booking {$emailResult['booking_id']} ({$emailResult['booking_type']}) to {$emailResult['email']}: {$emailResult['message']}\n";
        }
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        // If running from command line, output results
        if (php_sapi_name() === 'cli') {
            echo "Booking Reminder Process Completed\n";
            echo "================================\n";
            echo "Total Processed: {$result['total_processed']}\n";
            echo "Successfully Sent: {$result['success_count']}\n";
            echo "Errors: {$result['error_count']}\n";
            echo "Time: " . date('Y-m-d H:i:s') . "\n";
            
            if ($result['error_count'] > 0) {
                echo "\nErrors:\n";
                foreach ($result['results'] as $emailResult) {
                    if (!$emailResult['success']) {
                        echo "- Booking {$emailResult['booking_id']}: {$emailResult['message']}\n";
                    }
                }
            }
        }
        
    } else {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] Reminder process failed: {$result['message']}\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        if (php_sapi_name() === 'cli') {
            echo "Error: {$result['message']}\n";
        }
    }
    
} catch (Exception $e) {
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Fatal error in reminder process: " . $e->getMessage() . "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    
    if (php_sapi_name() === 'cli') {
        echo "Fatal Error: " . $e->getMessage() . "\n";
    }
}

// Logs directory ensured earlier
?>









