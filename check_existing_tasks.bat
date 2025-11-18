@echo off
echo ============================================
echo Checking Existing Task Scheduler Tasks
echo ============================================
echo.

echo Checking "CATSWELL" task...
schtasks /query /tn "CATSWELL" 2>nul
if %errorlevel% equ 0 (
    echo.
    echo Task "CATSWELL" exists! Showing details:
    schtasks /query /tn "CATSWELL" /fo LIST /v
    echo.
) else (
    echo Task "CATSWELL" not found.
    echo.
)

echo ============================================
echo.

echo Checking "CATSWELL PET CARE REMINDER" task...
schtasks /query /tn "CATSWELL PET CARE REMINDER" 2>nul
if %errorlevel% equ 0 (
    echo.
    echo Task "CATSWELL PET CARE REMINDER" exists! Showing details:
    schtasks /query /tn "CATSWELL PET CARE REMINDER" /fo LIST /v
    echo.
) else (
    echo Task "CATSWELL PET CARE REMINDER" not found.
    echo.
)

echo ============================================
echo.
echo To see ALL your scheduled tasks:
echo schtasks /query /fo LIST | findstr /i "catswell"
echo.
pause





