<?php
// booking_email_service.php
// Service for sending booking confirmation emails

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer autoloader
require_once 'phpmailer/PHPMailer-master/src/Exception.php';
require_once 'phpmailer/PHPMailer-master/src/PHPMailer.php';
require_once 'phpmailer/PHPMailer-master/src/SMTP.php';

class BookingEmailService {
    private $smtpHost = 'smtp.gmail.com';
    private $smtpUsername = 'catswell848@gmail.com';
    private $smtpPassword = 'xrap tjsz geyh gtfo';
    private $smtpPort = 587;
    private $fromEmail = 'catswell848@gmail.com';
    private $fromName = 'Catswell Pet Care';
    
    public function sendBookingConfirmation($bookingData) {
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
            $mail->addAddress($bookingData['customer_email']);
            
            // Generate email content based on booking type
            $emailContent = $this->generateBookingConfirmationEmail($bookingData);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $emailContent['subject'];
            $mail->Body = $emailContent['body'];
            $mail->AltBody = strip_tags($emailContent['body']);
            
            $mail->send();
            return ['success' => true, 'message' => 'Booking confirmation email sent successfully'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Email could not be sent. Error: ' . $e->getMessage()];
        }
    }
    
    private function generateBookingConfirmationEmail($bookingData) {
        $bookingType = $bookingData['booking_type'] ?? 'service';
        $customerName = trim(($bookingData['first_name'] ?? '') . ' ' . ($bookingData['last_name'] ?? ''));
        $customerName = $customerName ?: 'Valued Customer';
        
        // Handle multiple pets
        $petNamesList = $bookingData['pet_names_list'] ?? [];
        $petCount = count($petNamesList);
        if ($petCount === 0) {
            $petName = $bookingData['pet_name'] ?? 'your pet';
        } else {
            $petName = $petNamesList[0]; // For singular reference
        }
        $allPetsDisplay = $bookingData['pet_name'] ?? 'your pet'; // Comma-separated list
        
        $serviceName = $bookingData['service_name'] ?? 'Service';
        $bookingReference = $bookingData['booking_reference'] ?? '';
        $totalAmount = number_format($bookingData['total_amount'] ?? 0, 2);
        $paymentMethod = $bookingData['payment_method'] ?? 'Not specified';
        
        $subject = "✅ Booking Confirmation - {$bookingReference} | Catswell Pet Care";
        
        if ($bookingType === 'hotel') {
            $body = $this->generateHotelBookingEmail($bookingData, $customerName, $petName, $allPetsDisplay, $petCount, $serviceName, $bookingReference, $totalAmount, $paymentMethod);
        } else {
            $body = $this->generateServiceBookingEmail($bookingData, $customerName, $petName, $allPetsDisplay, $petCount, $serviceName, $bookingReference, $totalAmount, $paymentMethod);
        }
        
        return ['subject' => $subject, 'body' => $body];
    }
    
    private function generateHotelBookingEmail($bookingData, $customerName, $petName, $allPetsDisplay, $petCount, $serviceName, $bookingReference, $totalAmount, $paymentMethod) {
        $checkinDate = $this->formatDate($bookingData['checkin_date'] ?? '[Check-in Date]');
        $checkoutDate = $this->formatDate($bookingData['checkout_date'] ?? '[Check-out Date]');
        $checkinTime = $this->formatTime($bookingData['checkin_time'] ?? '[Check-in Time]');
        $checkoutTime = $this->formatTime($bookingData['checkout_time'] ?? '[Check-out Time]');
        $roomCode = $bookingData['room_code'] ?? '[Room Code]';
        $roomCategory = $bookingData['room_category'] ?? '[Room Category]';
        $nightsCount = $bookingData['nights_count'] ?? '[Nights]';
        
        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 10px 10px 0 0; text-align: center;'>
                <h1 style='margin: 0; font-size: 28px;'>🏨 Catswell Pet Care</h1>
                <p style='margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;'>Cat Hotel Booking Confirmation</p>
            </div>
            
            <div style='background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>
                <div style='background: #d4edda; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;'>
                    <h3 style='margin: 0; color: #155724;'>✅ Booking Confirmed!</h3>
                    <p style='margin: 5px 0 0 0; color: #155724;'>Reference: <strong>{$bookingReference}</strong></p>
                </div>
                
                <h2 style='color: #333; margin-top: 0;'>Dear {$customerName},</h2>
                
                <p style='font-size: 16px; line-height: 1.6; color: #555;'>
                    Thank you for choosing Catswell Pet Care Centre for your pet's hotel stay! We're excited to welcome " . ($petCount > 1 ? "<strong>{$allPetsDisplay}</strong>" : "<strong>{$petName}</strong>") . " to our luxury cat hotel.
                </p>
                
                <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #667eea;'>
                    <h3 style='margin-top: 0; color: #333;'>🏨 Hotel Booking Details:</h3>
                    <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>
                        <div>
                            <p style='margin: 5px 0;'><strong>Booking Reference:</strong><br>{$bookingReference}</p>
                            <p style='margin: 5px 0;'><strong>" . ($petCount > 1 ? "Pets" : "Pet") . ":" . ($petCount > 1 ? " ({$petCount})" : "") . "</strong><br>{$allPetsDisplay}</p>
                            <p style='margin: 5px 0;'><strong>Room Code:</strong><br>{$roomCode}</p>
                            <p style='margin: 5px 0;'><strong>Room Category:</strong><br>{$roomCategory}</p>
                        </div>
                        <div>
                            <p style='margin: 5px 0;'><strong>Check-in Date:</strong><br>{$checkinDate}</p>
                            <p style='margin: 5px 0;'><strong>Check-in Time:</strong><br>{$checkinTime}</p>
                            <p style='margin: 5px 0;'><strong>Check-out Date:</strong><br>{$checkoutDate}</p>
                            <p style='margin: 5px 0;'><strong>Check-out Time:</strong><br>{$checkoutTime}</p>
                        </div>
                    </div>
                    <p style='margin: 10px 0 0 0;'><strong>Total Nights:</strong> {$nightsCount}</p>
                </div>
                
                <div style='background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                    <h3 style='margin-top: 0; color: #856404;'>💰 Payment Information:</h3>
                    <p style='margin: 5px 0;'><strong>Total Amount:</strong> RM {$totalAmount}</p>
                    <p style='margin: 5px 0;'><strong>Payment Method:</strong> {$paymentMethod}</p>
                    
                </div>
                
                <div style='background: #cce5ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #007bff;'>
                    <h4 style='margin-top: 0; color: #004085;'>📋 Important Information:</h4>
                    <ul style='color: #004085; margin: 0;'>
                        <li>Please arrive 15 minutes before check-in time</li>
                        <li>Bring your pet's vaccination records</li>
                        <li>Pack your pet's favorite toys and bedding</li>
                        <li>Inform us of any special dietary requirements</li>
                        <li>Emergency contact information will be collected at check-in</li>
                    </ul>
                </div>
                
                <div style='background: #d1ecf1; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #17a2b8;'>
                    <h4 style='margin-top: 0; color: #0c5460;'>🏨 What to Expect:</h4>
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
                    This is an automated confirmation from Catswell Pet Care.<br>
                    If you have any questions, please don't hesitate to contact us.
                </p>
            </div>
        </div>";
    }
    
    private function generateServiceBookingEmail($bookingData, $customerName, $petName, $allPetsDisplay, $petCount, $serviceName, $bookingReference, $totalAmount, $paymentMethod) {
        $bookingDate = $this->formatDate($bookingData['booking_date'] ?? '[Booking Date]');
        $bookingTime = $this->formatTime($bookingData['booking_time'] ?? '[Booking Time]');
        $serviceCategory = $bookingData['service_category'] ?? '';
        $additionalNotes = $bookingData['additional_notes'] ?? '';
        
        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 30px; border-radius: 10px 10px 0 0; text-align: center;'>
                <h1 style='margin: 0; font-size: 28px;'>🐾 Catswell Pet Care</h1>
                <p style='margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;'>Service Booking Confirmation</p>
            </div>
            
            <div style='background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>
                <div style='background: #d4edda; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;'>
                    <h3 style='margin: 0; color: #155724;'>✅ Booking Confirmed!</h3>
                    <p style='margin: 5px 0 0 0; color: #155724;'>Reference: <strong>{$bookingReference}</strong></p>
                </div>
                
                <h2 style='color: #333; margin-top: 0;'>Dear {$customerName},</h2>
                
                <p style='font-size: 16px; line-height: 1.6; color: #555;'>
                    Thank you for booking our <strong>{$serviceName}</strong> service for " . ($petCount > 1 ? "<strong>{$allPetsDisplay}</strong>" : "<strong>{$petName}</strong>") . "! We're excited to provide excellent care for " . ($petCount > 1 ? "your beloved pets" : "your beloved pet") . ".
                </p>
                
                <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;'>
                    <h3 style='margin-top: 0; color: #333;'>📅 Service Booking Details:</h3>
                    <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 15px;'>
                        <div>
                            <p style='margin: 5px 0;'><strong>Booking Reference:</strong><br>{$bookingReference}</p>
                            <p style='margin: 5px 0;'><strong>" . ($petCount > 1 ? "Pets" : "Pet") . ":" . ($petCount > 1 ? " ({$petCount})" : "") . "</strong><br>{$allPetsDisplay}</p>
                            <p style='margin: 5px 0;'><strong>Service:</strong><br>{$serviceName}</p>
                            <p style='margin: 5px 0;'><strong>Category:</strong><br>{$serviceCategory}</p>
                        </div>
                        <div>
                            <p style='margin: 5px 0;'><strong>Appointment Date:</strong><br>{$bookingDate}</p>
                            <p style='margin: 5px 0;'><strong>Appointment Time:</strong><br>{$bookingTime}</p>
                            <p style='margin: 5px 0;'><strong>Total Amount:</strong><br>RM {$totalAmount}</p>
                            <p style='margin: 5px 0;'><strong>Payment Method:</strong><br>{$paymentMethod}</p>
                        </div>
                    </div>
                </div>
                
                " . ($additionalNotes ? "
                <div style='background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                    <h4 style='margin-top: 0; color: #856404;'>📝 Additional Notes:</h4>
                    <p style='margin: 0; color: #856404;'>{$additionalNotes}</p>
                </div>
                " : "") . "
                
                <div style='background: #cce5ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #007bff;'>
                    <h4 style='margin-top: 0; color: #004085;'>📋 Important Reminders:</h4>
                    <ul style='color: #004085; margin: 0;'>
                        <li>Please arrive 10 minutes before your scheduled appointment</li>
                        <li>Bring your pet's vaccination records if applicable</li>
                        <li>Inform us of any special requirements or concerns</li>
                        <li>If you need to reschedule, please contact us at least 24 hours in advance</li>
                    </ul>
                </div>
                
                <div style='background: #d1ecf1; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #17a2b8;'>
                    <h4 style='margin-top: 0; color: #0c5460;'>🌟 What to Expect:</h4>
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
                    This is an automated confirmation from Catswell Pet Care.<br>
                    If you have any questions, please don't hesitate to contact us.
                </p>
            </div>
        </div>";
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
    
    private function formatTime($timeString) {
        // Handle placeholder values
        if (strpos($timeString, '[') !== false) {
            return $timeString;
        }
        
        // If already in 12-hour format (contains AM/PM), return as is
        if (stripos($timeString, 'AM') !== false || stripos($timeString, 'PM') !== false) {
            return $timeString;
        }
        
        // Try to parse the time and convert to 12-hour format
        try {
            // Handle different time formats
            $time = trim($timeString);
            
            // If it's in HH:MM:SS format, extract just HH:MM
            if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $matches)) {
                $hour24 = (int)$matches[1];
                $minute = $matches[2];
                
                // Convert to 12-hour format
                if ($hour24 == 0) {
                    $hour12 = 12;
                    $period = 'AM';
                } elseif ($hour24 < 12) {
                    $hour12 = $hour24;
                    $period = 'AM';
                } elseif ($hour24 == 12) {
                    $hour12 = 12;
                    $period = 'PM';
                } else {
                    $hour12 = $hour24 - 12;
                    $period = 'PM';
                }
                
                return $hour12 . ':' . $minute . ' ' . $period;
            }
            
            // Try to parse as DateTime object
            $dateTime = DateTime::createFromFormat('H:i:s', $time);
            if (!$dateTime) {
                $dateTime = DateTime::createFromFormat('H:i', $time);
            }
            
            if ($dateTime) {
                return $dateTime->format('g:i A');
            }
            
            // If parsing fails, return the original string
            return $timeString;
        } catch (Exception $e) {
            // If parsing fails, return the original string
            return $timeString;
        }
    }
}

// Function to send booking confirmation email
function sendBookingConfirmationEmail($bookingData) {
    $emailService = new BookingEmailService();
    return $emailService->sendBookingConfirmation($bookingData);
}
?>
