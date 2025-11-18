@echo off
echo Checking if PetCare Booking Reminders task exists...
echo.
schtasks /query /tn "PetCare Booking Reminders" 2>nul
if %errorlevel% equ 0 (
    echo.
    echo ✓ Task found! It's set up correctly.
    echo.
    echo Showing task details:
    schtasks /query /tn "PetCare Booking Reminders" /fo LIST /v
) else (
    echo.
    echo ✗ Task not found. You may need to create it.
    echo.
    echo To check all tasks with "reminder" in the name:
    schtasks /query /fo LIST | findstr /i "reminder"
)
echo.
pause





