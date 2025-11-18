@echo off
REM Batch file to run booking reminders on Windows
REM This can be scheduled in Windows Task Scheduler

cd /d "C:\laragon\www\petcarewebapp"

REM Use Laragon's PHP which has proper configuration loaded
REM This ensures PDO MySQL extension is available
"C:\laragon\bin\php\php-8.2.29-Win32-vs16-x64\php.exe" send_booking_reminders.php

REM Log execution time (optional)
echo [%date% %time%] Reminder script executed >> logs\reminder_scheduler_log.txt 2>&1

