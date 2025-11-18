<?php
// booking_reminder_service.php
// Service for sending automatic booking reminder emails

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer autoloader
require_once 'phpmailer/PHPMailer-master/src/Exception.php';
require_once 'phpmailer/PHPMailer-master/src/PHPMailer.php';
require_once 'phpmailer/PHPMailer-master/src/SMTP.php';
require_once 'config/database.php';

class BookingReminderService {
    private $smtpHost = 'smtp.gmail.com';
    private $smtpUsername = 'catswell848@gmail.com';
    private $smtpPassword = 'xrap tjsz geyh gtfo';
    private $smtpPort = 587;
    private $fromEmail = 'catswell848@gmail.com';
    private $fromName = 'Catswell Pet Care';
    
    public function sendBookingReminders() {
        try {
            $conn = getConnection();
            
            // Check if database connection is available
            if (!$conn) {
                throw new Exception('Database connection failed: could not establish connection to database');
            }
            
            // Get bookings that need reminders (same-day and next-day appointments/check-ins)
            $reminderBookings = $this->getBookingsNeedingReminders($conn);
            
            $results = [];
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($reminderBookings as $booking) {
                $result = $this->sendReminderEmail($booking);
                $results[] = $result;
                
                if ($result['success']) {
                    $successCount++;
                    // Mark reminder as sent
                    $this->markReminderSent($conn, $booking['booking_id'], $booking['booking_type']);
                } else {
                    $errorCount++;
                }
            }
            
            return [
                'success' => true,
                'total_processed' => count($reminderBookings),
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'results' => $results
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error processing reminders: ' . $e->getMessage()
            ];
        }
    }
    
    private function getBookingsNeedingReminders($conn) {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        // Get service bookings that need reminders (both today and tomorrow)
        $serviceBookingsToday = $this->getServiceBookingsNeedingReminders($conn, $today);
        $serviceBookingsTomorrow = $this->getServiceBookingsNeedingReminders($conn, $tomorrow);
        
        // Get hotel bookings that need reminders (both today and tomorrow)
        $hotelBookingsToday = $this->getHotelBookingsNeedingReminders($conn, $today);
        $hotelBookingsTomorrow = $this->getHotelBookingsNeedingReminders($conn, $tomorrow);
        
        return array_merge($serviceBookingsToday, $serviceBookingsTomorrow, $hotelBookingsToday, $hotelBookingsTomorrow);
    }
    
    private function getServiceBookingsNeedingReminders($conn, $targetDate) {
        $stmt = $conn->prepare("
            SELECT 
                sb.id as booking_id,
                sb.booking_date,
                sb.booking_time,
                sb.booking_reference,
                sb.total_amount,
                sb.payment_method,
                sb.additional_notes,
                sb.booking_status,
                sb.additional_pets,
                u.first_name, u.last_name, u.email, u.phone_number,
                p.id as pet_id,
                p.name as pet_name, p.breed as pet_breed,
                ps.service_name, ps.service_category, ps.price as service_price,
                'service' as booking_type,
                CASE 
                    WHEN sb.reminder_sent = 1 THEN 1 
                    ELSE 0 
                END as reminder_sent
            FROM service_bookings sb
            LEFT JOIN users u ON sb.user_id = u.id
            LEFT JOIN pets p ON sb.pet_id = p.id
            LEFT JOIN pet_services ps ON sb.service_id = ps.id
            WHERE sb.booking_date = ? 
            AND sb.booking_status IN ('confirmed', 'pending')
            AND (sb.reminder_sent IS NULL OR sb.reminder_sent = 0)
            AND u.email IS NOT NULL
        ");
        
        $stmt->execute([$targetDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getHotelBookingsNeedingReminders($conn, $targetDate) {
        $stmt = $conn->prepare("
            SELECT 
                hb.id as booking_id,
                hb.checkin_date as booking_date,
                hb.checkin_time as booking_time,
                hb.checkout_date,
                hb.checkout_time,
                hb.booking_reference,
                hb.total_amount,
                hb.payment_method,
                hb.additional_notes,
                hb.booking_status,
                hb.room_code,
                hb.nights_count,
                hb.additional_pets,
                u.first_name, u.last_name, u.email, u.phone_number,
                p.id as pet_id,
                p.name as pet_name, p.breed as pet_breed,
                ps.service_name, ps.service_category, ps.price as service_price,
                hr.category_id,
                CASE 
                    WHEN hr.category_id = 1 THEN 'Classic Suite'
                    WHEN hr.category_id = 2 THEN 'Deluxe Suite'
                    WHEN hr.category_id = 3 THEN 'Executive Suite'
                    WHEN hr.category_id = 4 THEN 'Studio Suite'
                    ELSE 'Unknown'
                END as room_category,
                'hotel' as booking_type,
                CASE 
                    WHEN hb.reminder_sent = 1 THEN 1 
                    ELSE 0 
                END as reminder_sent
            FROM hotel_bookings hb
            LEFT JOIN users u ON hb.user_id = u.id
            LEFT JOIN pets p ON hb.pet_id = p.id
            LEFT JOIN pet_services ps ON hb.service_id = ps.id
            LEFT JOIN hotel_rooms hr ON hb.room_code = hr.room_code
            WHERE hb.checkin_date = ? 
            AND hb.booking_status IN ('confirmed', 'pending')
            AND (hb.reminder_sent IS NULL OR hb.reminder_sent = 0)
            AND u.email IS NOT NULL
        ");
        
        $stmt->execute([$targetDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function sendReminderEmail($bookingData) {
        try {
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUsername;
            $mail->Password = $this->smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;
            
            // Recipients
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($bookingData['email']);
            
            // Generate email content based on booking type
            $emailContent = $this->generateReminderEmail($bookingData);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $emailContent['subject'];
            $mail->Body = $emailContent['body'];
            $mail->AltBody = strip_tags($emailContent['body']);
            
            $mail->send();
            return [
                'success' => true, 
                'booking_id' => $bookingData['booking_id'],
                'booking_type' => $bookingData['booking_type'],
                'email' => $bookingData['email'],
                'message' => 'Reminder email sent successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'booking_id' => $bookingData['booking_id'],
                'booking_type' => $bookingData['booking_type'],
                'email' => $bookingData['email'],
                'message' => 'Email could not be sent. Error: ' . $e->getMessage()
            ];
        }
    }
    
    private function generateReminderEmail($bookingData) {
        $bookingType = $bookingData['booking_type'];
        $customerName = trim(($bookingData['first_name'] ?? '') . ' ' . ($bookingData['last_name'] ?? ''));
        $customerName = $customerName ?: 'Valued Customer';
        
        // Handle multiple pets - get all pet names from additional_pets if available
        $petNamesList = [];
        
        // Check if we have direct pet names list (for testing)
        if (isset($bookingData['all_pet_names']) && is_array($bookingData['all_pet_names'])) {
            $petNamesList = $bookingData['all_pet_names'];
        } elseif (isset($bookingData['additional_pets']) && !empty($bookingData['additional_pets']) && $bookingData['additional_pets'] !== 'null' && $bookingData['additional_pets'] !== NULL) {
            $additionalPetIds = json_decode($bookingData['additional_pets'], true);
            if (is_array($additionalPetIds) && !empty($additionalPetIds)) {
                // Add primary pet first
                if (isset($bookingData['pet_id']) && isset($bookingData['pet_name'])) {
                    $petNamesList[] = $bookingData['pet_name'];
                }
                // Get names for additional pets from database
                try {
                    $dbConn = getConnection();
                    foreach ($additionalPetIds as $petId) {
                        $stmt = $dbConn->prepare("SELECT name FROM pets WHERE id = ?");
                        $stmt->execute([$petId]);
                        $additionalPet = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($additionalPet) {
                            $petNamesList[] = $additionalPet['name'];
                        }
                    }
                } catch (Exception $e) {
                    // Fallback to just the primary pet
                    if (!isset($petNamesList[0])) {
                        $petNamesList = [$bookingData['pet_name'] ?? 'your pet'];
                    }
                }
            } else {
                $petNamesList = [$bookingData['pet_name'] ?? 'your pet'];
            }
        } else {
            $petNamesList = [$bookingData['pet_name'] ?? 'your pet'];
        }
        
        $petCount = count($petNamesList);
        $petName = $petNamesList[0] ?? 'your pet';
        $allPetsDisplay = implode(', ', $petNamesList);
        
        $serviceName = $bookingData['service_name'] ?? 'Service';
        $bookingReference = $bookingData['booking_reference'] ?? '';
        
        // Check if this is a same-day or next-day reminder
        $bookingDate = $bookingData['booking_date'] ?? date('Y-m-d');
        $today = date('Y-m-d');
        $isSameDay = ($bookingDate === $today);
        
        $reminderType = $isSameDay ? 'Today' : 'Tomorrow';
        $subject = "Reminder: Your PetCare Appointment {$reminderType} | {$bookingReference}";
        
        if ($bookingType === 'hotel') {
            $body = $this->generateHotelReminderEmail($bookingData, $customerName, $petName, $allPetsDisplay, $petCount, $serviceName, $bookingReference, $isSameDay);
        } else {
            $body = $this->generateServiceReminderEmail($bookingData, $customerName, $petName, $allPetsDisplay, $petCount, $serviceName, $bookingReference, $isSameDay);
        }
        
        return ['subject' => $subject, 'body' => $body];
    }
    
    private function generateHotelReminderEmail($bookingData, $customerName, $petName, $allPetsDisplay, $petCount, $serviceName, $bookingReference, $isSameDay = false) {
        $checkinDate = $this->formatDate($bookingData['booking_date'] ?? '[Check-in Date]');
        $checkoutDate = $this->formatDate($bookingData['checkout_date'] ?? '[Check-out Date]');
        $checkinTime = $bookingData['booking_time'] ?? '[Check-in Time]';
        $checkoutTime = $bookingData['checkout_time'] ?? '[Check-out Time]';
        $roomCode = $bookingData['room_code'] ?? '[Room Code]';
        $roomCategory = $bookingData['room_category'] ?? '[Room Category]';
        $nightsCount = $bookingData['nights_count'] ?? '[Nights]';
        
        $reminderText = $isSameDay ? 'Today\'s' : 'Tomorrow\'s';
        $timeframe = $isSameDay ? 'today' : 'tomorrow';
        
        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 10px 10px 0 0; text-align: center;'>
                <h1 style='margin: 0; font-size: 28px;'>Catswell Pet Care Centre</h1>
                <p style='margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;'>Hotel Check-in Reminder</p>
            </div>
            
            <div style='background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>
                <div style='background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107;'>
                    <h3 style='margin: 0; color: #856404;'> {$reminderText} Check-in!</h3>
                    <p style='margin: 5px 0 0 0; color: #856404;'>Reference: <strong>{$bookingReference}</strong></p>
                </div>
                
                <h2 style='color: #333; margin-top: 0;'>Dear {$customerName},</h2>
                
                <p style='font-size: 16px; line-height: 1.6; color: #555;'>
                    This is a friendly reminder that " . ($petCount > 1 ? "<strong>{$allPetsDisplay}</strong>" : "<strong>{$petName}</strong>") . " has a hotel check-in scheduled for <strong>{$timeframe}</strong>! We're excited to welcome " . ($petCount > 1 ? "your furry friends" : "your furry friend") . " to our luxury cat hotel.
                </p>
                
                <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #667eea;'>
                    <h3 style='margin-top: 0; color: #333;'>Check-in Details:</h3>
                    <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>
                        <div>
                            <p style='margin: 5px 0;'><strong>Check-in Date:</strong><br>{$checkinDate}</p>
                            <p style='margin: 5px 0;'><strong>Check-in Time:</strong><br>{$checkinTime}</p>
                            <p style='margin: 5px 0;'><strong>Room Code:</strong><br>{$roomCode}</p>
                            <p style='margin: 5px 0;'><strong>Room Category:</strong><br>{$roomCategory}</p>
                        </div>
                        <div>
                            <p style='margin: 5px 0;'><strong>Check-out Date:</strong><br>{$checkoutDate}</p>
                            <p style='margin: 5px 0;'><strong>Check-out Time:</strong><br>{$checkoutTime}</p>
                            <p style='margin: 5px 0;'><strong>Total Nights:</strong><br>{$nightsCount}</p>
                            <p style='margin: 5px 0;'><strong>" . ($petCount > 1 ? "Pets" : "Pet") . ":" . ($petCount > 1 ? " ({$petCount})" : "") . "</strong><br>{$allPetsDisplay}</p>
                        </div>
                    </div>
                </div>
                
                <div style='background: #cce5ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #007bff;'>
                    <h4 style='margin-top: 0; color: #004085;'>Check-in Preparation:</h4>
                    <ul style='color: #004085; margin: 0;'>
                        <li>Please arrive 15 minutes before check-in time</li>
                        <li>Bring your pet's vaccination records</li>
                        <li>Pack your pet's favorite toys and bedding</li>
                        <li>Inform us of any special dietary requirements</li>
                        <li>Emergency contact information will be collected</li>
                    </ul>
                </div>
                
                <div style='background: #d1ecf1; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #17a2b8;'>
                    <h4 style='margin-top: 0; color: #0c5460;'> What to Expect:</h4>
                    <ul style='color: #0c5460; margin: 0;'>
                        <li>Luxury accommodation with climate control</li>
                        <li>Daily exercise and playtime</li>
                        <li>Professional care and monitoring</li>
                        <li>Regular updates on your pet's wellbeing</li>
                    </ul>
                </div>
                
                <p style='font-size: 16px; line-height: 1.6; color: #555;'>
                    We look forward to providing " . ($petCount > 1 ? "all your pets" : "<strong>{$petName}</strong>") . " with a comfortable and enjoyable stay!
                </p>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='#' style='background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 5px;'>Contact Us</a>
                    <a href='#' style='background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 5px;'>View Booking</a>
                </div>
                
                <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
                
                <p style='font-size: 14px; color: #666; text-align: center;'>
                    This is an automated reminder from Catswell Pet Care.<br>
                    If you have any questions, please don't hesitate to contact us.
                </p>
            </div>
        </div>";
    }
    
    private function generateServiceReminderEmail($bookingData, $customerName, $petName, $allPetsDisplay, $petCount, $serviceName, $bookingReference, $isSameDay = false) {
        $bookingDate = $this->formatDate($bookingData['booking_date'] ?? '[Booking Date]');
        $bookingTime = $bookingData['booking_time'] ?? '[Booking Time]';
        $serviceCategory = $bookingData['service_category'] ?? '';
        
        $reminderText = $isSameDay ? 'Today\'s' : 'Tomorrow\'s';
        $timeframe = $isSameDay ? 'today' : 'tomorrow';
        
        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 30px; border-radius: 10px 10px 0 0; text-align: center;'>
                <h1 style='margin: 0; font-size: 28px;'>Catswell Pet Care</h1>
                <p style='margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;'>Service Appointment Reminder</p>
            </div>
            
            <div style='background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>
                <div style='background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107;'>
                    <h3 style='margin: 0; color: #856404;'> {$reminderText} Appointment!</h3>
                    <p style='margin: 5px 0 0 0; color: #856404;'>Reference: <strong>{$bookingReference}</strong></p>
                </div>
                
                <h2 style='color: #333; margin-top: 0;'>Dear {$customerName},</h2>
                
                <p style='font-size: 16px; line-height: 1.6; color: #555;'>
                    This is a friendly reminder that " . ($petCount > 1 ? "<strong>{$allPetsDisplay}</strong>" : "<strong>{$petName}</strong>") . " has a <strong>{$serviceName}</strong> appointment scheduled for <strong>{$timeframe}</strong>! We're excited to provide excellent care for " . ($petCount > 1 ? "your beloved pets" : "your beloved pet") . ".
                </p>
                
                <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;'>
                    <h3 style='margin-top: 0; color: #333;'>Appointment Details:</h3>
                    <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>
                        <div>
                            <p style='margin: 5px 0;'><strong>Appointment Date:</strong><br>{$bookingDate}</p>
                            <p style='margin: 5px 0;'><strong>Appointment Time:</strong><br>{$bookingTime}</p>
                            <p style='margin: 5px 0;'><strong>Service:</strong><br>{$serviceName}</p>
                            <p style='margin: 5px 0;'><strong>Category:</strong><br>{$serviceCategory}</p>
                        </div>
                        <div>
                            <p style='margin: 5px 0;'><strong>" . ($petCount > 1 ? "Pets" : "Pet") . ":" . ($petCount > 1 ? " ({$petCount})" : "") . "</strong><br>{$allPetsDisplay}</p>
                            <p style='margin: 5px 0;'><strong>Booking Reference:</strong><br>{$bookingReference}</p>
                            <p style='margin: 5px 0;'><strong>Status:</strong><br>Confirmed</p>
                        </div>
                    </div>
                </div>
                
                <div style='background: #cce5ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #007bff;'>
                    <h4 style='margin-top: 0; color: #004085;'>Important Reminders:</h4>
                    <ul style='color: #004085; margin: 0;'>
                        <li>Please arrive 10 minutes before your scheduled appointment</li>
                        <li>Bring your pet's vaccination records if applicable</li>
                        <li>Inform us of any special requirements or concerns</li>
                        <li>If you need to reschedule, please contact us immediately</li>
                    </ul>
                </div>
                
                <div style='background: #d1ecf1; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #17a2b8;'>
                    <h4 style='margin-top: 0; color: #0c5460;'>What to Expect:</h4>
                    <ul style='color: #0c5460; margin: 0;'>
                        <li>Professional and caring service</li>
                        <li>Clean and safe environment</li>
                        <li>Experienced staff trained in pet care</li>
                        <li>Regular updates on your pet's progress</li>
                    </ul>
                </div>
                
                <p style='font-size: 16px; line-height: 1.6; color: #555;'>
                    We look forward to providing excellent care for " . ($petCount > 1 ? "<strong>{$allPetsDisplay}</strong>" : "<strong>{$petName}</strong>") . "!
                </p>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='#' style='background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 5px;'>Contact Us</a>
                    <a href='#' style='background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 5px;'>View Booking</a>
                </div>
                
                <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
                
                <p style='font-size: 14px; color: #666; text-align: center;'>
                    This is an automated reminder from Catswell Pet Care.<br>
                    If you have any questions, please don't hesitate to contact us.
                </p>
            </div>
        </div>";
    }
    
    private function markReminderSent($conn, $bookingId, $bookingType) {
        try {
            $tableName = ($bookingType === 'hotel') ? 'hotel_bookings' : 'service_bookings';
            $stmt = $conn->prepare("UPDATE {$tableName} SET reminder_sent = 1, reminder_sent_at = NOW() WHERE id = ?");
            $stmt->execute([$bookingId]);
        } catch (Exception $e) {
            error_log("Failed to mark reminder as sent for booking {$bookingId}: " . $e->getMessage());
        }
    }
    
    private function formatDate($dateString) {
        // Handle placeholder values
        if (strpos($dateString, '[') !== false) {
            return $dateString;
        }
        
        // Try to parse the date and format it as DD/MM/YYYY
        try {
            $date = new DateTime($dateString);
            return $date->format('d/m/Y');
        } catch (Exception $e) {
            // If parsing fails, return the original string
            return $dateString;
        }
    }
}

// Function to process booking reminders
function processBookingReminders() {
    $reminderService = new BookingReminderService();
    return $reminderService->sendBookingReminders();
}
?>






