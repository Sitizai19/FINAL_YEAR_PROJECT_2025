// Format pet category for display
function formatPetCategory(category) {
    if (!category) return '';
    
    // Handle custom categories
    if (category === 'custom') return 'Custom Category';
    
    // Replace underscores with spaces and capitalize each word
    return category
        .split('_')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

// Dashboard JavaScript - MySQL Integration

// Helper function to convert age based on pet category
function convertAgeForStorage(ageValue, categoryValue) {
    if (!ageValue || !categoryValue) return ageValue;
    
    const category = categoryValue.trim().toUpperCase();
    const age = parseFloat(ageValue);
    
    if (isNaN(age)) return ageValue;
    
    if (category === 'KITTEN') {
        // Store months directly for kittens (no conversion needed)
        return age.toString();
    } else {
        // Keep years as-is for adults
        return age.toString();
    }
}

// Helper function to convert age for display based on pet category
function convertAgeForDisplay(ageValue, categoryValue) {
    if (!ageValue || !categoryValue) return ageValue;
    
    const category = categoryValue.trim().toUpperCase();
    const age = parseFloat(ageValue);
    
    if (isNaN(age)) return ageValue;
    
    if (category === 'KITTEN') {
        // Age is already stored in months, no conversion needed
        return age.toString();
    } else {
        // Keep years as-is for adults
        return age.toString();
    }
}

// Helper function to format age for display with proper units
function formatPetAge(ageValue, categoryValue) {
    if (!ageValue) return 'Unknown';
    
    const category = categoryValue ? categoryValue.trim().toUpperCase() : '';
    const age = parseFloat(ageValue);
    
    if (isNaN(age)) return ageValue;
    
    if (category === 'KITTEN') {
        // Age is already stored in months, display as months
        return `${age} month${age !== 1 ? 's' : ''} old`;
    } else {
        // Keep years as-is for adults
        return `${age} year${age !== 1 ? 's' : ''} old`;
    }
}

// Validate pet form
function validatePetForm() {
    const requiredFields = [
        { id: 'petName', name: 'Pet Name' },
        { id: 'petCategory', name: 'Pet Category' },
        { id: 'petAge', name: 'Pet Age' },
        { id: 'petEventType', name: 'Medical Event Type' },
        { id: 'petMedicalCondition', name: 'Medical Condition' }
    ];
    
    const missingFields = [];
    
    // Check text/number fields
    requiredFields.forEach(field => {
        const element = document.getElementById(field.id);
        if (!element || !element.value.trim()) {
            missingFields.push(field.name);
        }
    });
    
    // Check gender selection
    const genderSelected = document.querySelector('input[name="petGender"]:checked');
    if (!genderSelected) {
        missingFields.push('Pet Gender');
    }
    
    // Check spayed/neutered selection
    const spayedSelected = document.querySelector('input[name="petSpayed"]:checked');
    if (!spayedSelected) {
        missingFields.push('Spayed/Neutered Status');
    }
    
    // Check custom category if "custom" is selected
    const petCategoryField = document.getElementById('petCategory');
    if (petCategoryField && petCategoryField.value === 'custom') {
        const customCategoryField = document.getElementById('customCategory');
        if (!customCategoryField || !customCategoryField.value.trim()) {
            missingFields.push('Custom Category Name');
        }
    }
    
    if (missingFields.length > 0) {
        alert('Please fill in all required fields:\n• ' + missingFields.join('\n• '));
        return false;
    }
    
    return true;
}

// Show file name only (no image preview) - Global function
window.showFileName = function(input) {
    if (input.files && input.files[0]) {
        const fileName = input.files[0].name;
        const fileNameElement = document.getElementById('fileName');
        if (fileNameElement) {
            fileNameElement.textContent = fileName;
        }
    }
};

// Global variables
let currentUser = null;
let userPets = [];
let userBookings = [];
// Track notified status changes to prevent duplicate notifications
let notifiedStatusChanges = new Set();
// Track if initial bookings have been loaded (to prevent notifications on first load)
let initialBookingsLoaded = false;
// Track currently showing notifications to prevent duplicates
let showingNotifications = new Set();


let tabSessionId = sessionStorage.getItem('tab_session_id');
if (!tabSessionId) {
    tabSessionId = 'tab_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    sessionStorage.setItem('tab_session_id', tabSessionId);
}

// Make it available globally
window.tabSessionId = tabSessionId;

// Initialize dashboard when DOM is loaded
document.addEventListener('DOMContentLoaded', async function() {
    console.log('DOM loaded, initializing dashboard...');
    
    // Ensure body scroll is unlocked on page load
    document.body.classList.remove('modal-open');
    document.body.style.overflow = 'auto';
    
    // Load user data first
    console.log('Calling loadUserData...');
    await loadUserData();
    
    // Load pets and bookings
    console.log('Calling loadPets...');
    await loadPets();
    console.log('Calling loadBookings...');
    await loadBookings();
    
    // Initialize tab functionality
    initializeTabs();
    
    // Initialize modals
    initializeModals();
    
    // Initialize booking updates
    initializeBookingUpdates();
    
});

// Load user data and update welcome message
async function loadUserData() {
    try {
        console.log('Loading user data...');
        console.log('Tab session ID:', tabSessionId);
        
        const response = await fetch('auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_current_user&tab_session_id=' + encodeURIComponent(tabSessionId)
        });
        
        const result = await response.json();
        console.log('Auth response:', result);
        
        if (result.success && result.user) {
            currentUser = result.user;
            console.log('User loaded:', currentUser);
            
            // Update welcome message with first name
            updateWelcomeMessage(currentUser.first_name || currentUser.email);
            
            // Update user name in navigation
            const firstName = currentUser.first_name || 'User';
            const loginItem = document.querySelector('.nav__login-item');
            if (loginItem) {
                loginItem.innerHTML = `
                    <div aria-label="User Profile" tabindex="0" role="button" class="user-profile" style="width:auto;" onclick="openProfileSidebar()">
                        <div class="user-profile-inner" style="width:auto; padding:0 12px; white-space:nowrap; max-width:none;">
                            <i class="ri-user-3-fill"></i>
                            <span>Welcome, ${firstName}</span>
                        </div>
                    </div>
                `;
            }
            
        } else {
            // User not logged in, redirect to login
            console.log('User not logged in, redirecting to login page');
            alert('Please log in to access the dashboard');
            window.location.href = 'index.html';
        }
    } catch (error) {
        console.error('Error loading user data:', error);
        // Redirect to login on error
        alert('Error loading user data. Redirecting to login...');
        window.location.href = 'index.html';
    }
}

// Update welcome message
function updateWelcomeMessage(firstName) {
    const welcomeTitle = document.querySelector('.welcome__content h1');
    if (welcomeTitle) {
        if (firstName && firstName !== 'Guest') {
            welcomeTitle.textContent = `Welcome back, ${firstName}!`;
            } else {
            welcomeTitle.textContent = 'Welcome to CatsWell Dashboard!';
        }
    }
}

// Load pets from database
async function loadPets() {
    try {
        
        const response = await fetch('auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_pets&tab_session_id=' + encodeURIComponent(tabSessionId)
        });
        const result = await response.json();
        
        
        if (result.success) {
            userPets = result.pets || [];
            
            // Update pet count
            updatePetCount(userPets.length);
            
            // Display pets
            displayPets(userPets);
        } else {
            console.error('Failed to load pets:', result.message);
            userPets = [];
            updatePetCount(0);
            displayPets([]);
        }
    } catch (error) {
        console.error('Error loading pets:', error);
        userPets = [];
        updatePetCount(0);
        displayPets([]);
    }
}

// Update pet count in stats
function updatePetCount(count) {
    const totalPetsElement = document.getElementById('total-pets');
    if (totalPetsElement) {
        totalPetsElement.textContent = count;
    }
}

// Display pets in the grid
function displayPets(pets) {
    const petsGrid = document.getElementById('pets-grid');
    if (!petsGrid) return;

    petsGrid.innerHTML = '';
    
    if (pets.length === 0) {
        petsGrid.innerHTML = `
            <div class="empty__state">
                <i class="ri-pet-line"></i>
                <h3>No pets added yet</h3>
                <p>Add your first pet to get started!</p>
                <button class="btn__primary" onclick="openAddPetModal()">Add Pet</button>
            </div>
        `;
        return;
    }

    pets.forEach(pet => {
        
        const petCard = document.createElement('div');
        petCard.className = 'pet__card';
        petCard.innerHTML = `
            ${pet.photo_path && pet.photo_path.trim() !== '' ? 
                `<div class="pet__photo">
                    <img src="${pet.photo_path}" alt="${pet.name}" style="width: 100%; height: 200px; object-fit: cover; border-radius: 0.5rem;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="pet__photo__placeholder" style="display: none;">
                        <i class="ri-pet-line"></i>
                        <span>${pet.name}</span>
                    </div>
                </div>` : 
                `<div class="pet__photo__placeholder">
                    <i class="ri-pet-line"></i>
                    <span>${pet.name}</span>
                </div>`
            }
            <h3>${pet.name}</h3>
            <p class="pet__category">${formatPetCategory(pet.category || pet.pet_category) || 'Unknown Category'}</p>
            ${pet.breed ? `<p class="pet__breed">${pet.breed}</p>` : ''}
            <div class="pet__details">
                <span class="pet__age">${formatPetAge(pet.age, pet.category || pet.pet_category)}</span>
                <span class="pet__gender">${pet.gender}</span>
                ${pet.medical_type && pet.medical_type !== 'none' ? `<span class="pet__medical">${pet.medical_type.charAt(0).toUpperCase() + pet.medical_type.slice(1)}</span>` : ''}
                ${pet.vaccinations && pet.vaccinations.length > 0 ? `<span class="pet__vaccine">${pet.vaccinations.length} vaccine${pet.vaccinations.length > 1 ? 's' : ''}</span>` : (pet.vaccine_name && pet.vaccine_name !== '' && pet.vaccine_name !== 'NULL' ? `<span class="pet__vaccine">${pet.vaccine_name}</span>` : '')}
            </div>
                <div class="pet__actions">
                <button class="btn__secondary" onclick="viewPetDetails(${pet.id})">
                    <i class="ri-eye-line"></i> View
                    </button>
                <button class="btn__secondary" onclick="editPet(${pet.id})">
                    <i class="ri-edit-line"></i> Edit
                    </button>
                <button class="btn__danger" onclick="deletePet(${pet.id})">
                    <i class="ri-delete-bin-line"></i> Delete
                    </button>
                </div>
        `;
        petsGrid.appendChild(petCard);
    });
}

// Load bookings from database
async function loadBookings() {
    try {
        
        const response = await fetch('auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_bookings&tab_session_id=' + encodeURIComponent(tabSessionId)
        });
        const result = await response.json();
        
        
        if (result.success) {
            // Mark initial bookings as loaded after first successful load
            if (!initialBookingsLoaded) {
                initialBookingsLoaded = true;
            }
            
            userBookings = result.data || [];
            
            // Update booking counts
            updateBookingCounts(userBookings);
            
            // Display bookings in both tabs
            displayPendingBookings(userBookings);
            displayAllBookings(userBookings);
        } else {
            console.error('Failed to load bookings:', result.message);
            userBookings = [];
            updateBookingCounts([]);
            displayPendingBookings([]);
            displayAllBookings([]);
        }
    } catch (error) {
        console.error('Error loading bookings:', error);
        userBookings = [];
        updateBookingCounts([]);
        displayPendingBookings([]);
        displayAllBookings([]);
    }
}

// Update booking counts in stats
function updateBookingCounts(bookings) {
    const now = new Date();
    
    // Filter out user_deleted bookings
    const activeBookings = bookings.filter(booking => {
        const userDeleted = booking.user_deleted === 1 || booking.user_deleted === true;
        return !userDeleted;
    });
    
    // Count upcoming bookings (confirmed status only - regardless of date)
    const upcomingCount = activeBookings.filter(booking => {
        const status = (booking.booking_status || booking.status || 'pending').toLowerCase();
        return status === 'confirmed';
    }).length;
    
    // Count pending bookings (waiting for admin approval)
    const pendingCount = activeBookings.filter(booking => {
        const status = (booking.booking_status || booking.status || 'pending').toLowerCase();
        return status === 'pending';
    }).length;
    
    const totalBookingsElement = document.getElementById('total-bookings');
    const upcomingAppointmentsElement = document.getElementById('upcoming-appointments');
    
    if (totalBookingsElement) {
        totalBookingsElement.textContent = activeBookings.length;
    }
    
    if (upcomingAppointmentsElement) {
        upcomingAppointmentsElement.textContent = upcomingCount;
    }
    
    // Calculate total spent from completed bookings
    const totalSpent = activeBookings
        .filter(booking => {
            const status = booking.booking_status || booking.status || 'pending';
            return status === 'completed';
        })
        .reduce((sum, booking) => sum + (parseFloat(booking.total_amount) || 0), 0);
    
    const totalSpentElement = document.getElementById('total-spent');
    if (totalSpentElement) {
        totalSpentElement.textContent = `RM ${totalSpent.toFixed(2)}`;
    }
}

// Display pending bookings in the bookings tab
function displayPendingBookings(bookings) {
    const pendingBookingsContent = document.getElementById('pending-bookings-content');
    
    if (!pendingBookingsContent) return;
    
    // Filter for pending and confirmed status (active bookings), and not user_deleted
    const pendingBookings = bookings.filter(booking => {
        const status = (booking.booking_status || booking.status || 'pending').toLowerCase();
        const userDeleted = booking.user_deleted === 1 || booking.user_deleted === true;
        return (status === 'pending' || status === 'confirmed') && !userDeleted;
    });
    
    if (pendingBookings.length === 0) {
        pendingBookingsContent.innerHTML = `
            <div class="empty__state">
                <i class="ri-calendar-line"></i>
                <h3>No active bookings</h3>
                <button class="btn__primary" onclick="window.location.href='services.html'">Book a Service</button>
            </div>
        `;
        return;
    }
    
    // Sort bookings by date (newest first)
    const sortedBookings = pendingBookings.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    
    pendingBookingsContent.innerHTML = sortedBookings.map(booking => renderBookingCard(booking)).join('');
}

// Display all bookings (completed & cancelled) in the all bookings tab
function displayAllBookings(bookings) {
    const allBookingsContent = document.getElementById('all-bookings-content');
    
    if (!allBookingsContent) return;
    
    // Filter for completed and cancelled status only, and not user_deleted
    const completedCancelledBookings = bookings.filter(booking => {
        const status = (booking.booking_status || booking.status || 'pending').toLowerCase();
        const userDeleted = booking.user_deleted === 1 || booking.user_deleted === true;
        return (status === 'completed' || status === 'cancelled') && !userDeleted;
    });
    
    if (completedCancelledBookings.length === 0) {
        allBookingsContent.innerHTML = `
            <div class="empty__state">
                <i class="ri-calendar-line"></i>
                <h3>No bookings yet</h3>
                <button class="btn__primary" onclick="window.location.href='services.html'">Book a Service</button>
            </div>
        `;
        return;
    }
    
    // Sort bookings by date (newest first)
    const sortedBookings = completedCancelledBookings.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    
    allBookingsContent.innerHTML = sortedBookings.map(booking => renderBookingCard(booking, true)).join('');
}

// Helper function to render booking card HTML
function renderBookingCard(booking, showDeleteButton = false) {
    const bookingDate = booking.booking_date ? new Date(booking.booking_date) : null;
    const bookingTime = booking.booking_time || booking.checkin_time || booking.checkout_time || booking.time;
    const status = booking.booking_status || booking.status || 'pending';
    const petPhoto = booking.pet_photo_path || booking.pet_photo || '';
    
    return `
            <div class="booking__item ${status}">
                <div class="booking__header">
                    <div class="booking__title__section">
                        <h3 class="booking__title">${booking.service_name || 'Service'}</h3>
                        <p class="booking__reference">Reference: #${booking.booking_reference || booking.id}</p>
                        ${booking.created_at ? `<p class="booking__created" style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;"><i class="ri-time-line" style="margin-right: 0.25rem;"></i>Created: ${new Date(booking.created_at).toLocaleString('en-MY', { dateStyle: 'medium', timeStyle: 'short' })}</p>` : ''}
                        </div>
                    <span class="status__badge ${status}">${formatStatus(status)}</span>
                </div>
                
                <div class="booking__main__content">
                    <!-- Pet Information Section - Display All Pets in Mini Cards -->
                    <div class="booking__pet__section" style="display: flex; flex-wrap: wrap; gap: 1rem; justify-content: center;">
                        ${(booking.all_pets && booking.all_pets.length > 0) ? booking.all_pets.map((pet, index) => {
                            const petPhoto = pet.photo_path || '';
                            return `
                            <div class="pet__mini__card" style="background: white; border-radius: 12px; padding: 0.75rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); min-width: 140px; flex: 1 1 auto; max-width: 160px;">
                                <div class="pet__photo__container" style="width: 70px; height: 70px; margin: 0 auto 0.5rem; border-radius: 12px; overflow: hidden;">
                                    ${petPhoto ? 
                                        `<img src="${petPhoto}" alt="${pet.name}" class="pet__photo" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjQiIGhlaWdodD0iNjQiIHZpZXdCb3g9IjAgMCA2NCA2NCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iMzIiIGZpbGw9IiNGM0Y0RjYiLz4KPHBhdGggZD0iTTMyIDIwQzM2LjQxODMgMjAgNDAgMjMuNTgxNyA0MCAyOEM0MCAzMi40MTgzIDM2LjQxODMgMzYgMzIgMzZDMjcuNTgxNyAzNiAyNCAzMi40MTgzIDI0IDI4QzI0IDIzLjU4MTcgMjcuNTgxNyAyMCAzMiAyMFoiIGZpbGw9IiM5Q0EzQUYiLz4KPHBhdGggZD0iTTE2IDQ4QzE2IDQwLjI2ODcgMjIuMjY4NyAzNCAzMCAzNEgzNEM0MS43MzEzIDM0IDQ4IDQwLjI2ODcgNDggNDhWNDhIMTZaIiBmaWxsPSIjOUNBM0FGIi8+Cjwvc3ZnPgo='">` :
                                        `<div class="pet__photo__placeholder" style="width: 100%; height: 100%; background: #f3f4f6; display: flex; align-items: center; justify-content: center;">
                                            <i class="ri-pet-line" style="font-size: 2rem; color: #9ca3af;"></i>
                                        </div>`
                                    }
                                </div>
                                <div class="pet__details" style="text-align: center;">
                                    <h4 class="pet__name" style="font-size: 0.9rem; font-weight: 600; margin: 0 0 0.25rem 0; color: #111827;">${pet.name || 'N/A'}</h4>
                                    <p class="pet__breed" style="font-size: 0.75rem; margin: 0; color: #6b7280;">${pet.breed || 'Unknown Breed'}</p>
                                    <p class="pet__category" style="font-size: 0.7rem; margin: 0.25rem 0 0 0; color: #9ca3af;">${formatCategoryName(pet.pet_category || booking.service_category)}</p>
                                </div>
                            </div>
                            `;
                        }).join('') : `
                            <div class="pet__mini__card" style="background: white; border-radius: 12px; padding: 0.75rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); min-width: 140px; flex: 1 1 auto; max-width: 160px;">
                                <div class="pet__photo__container" style="width: 70px; height: 70px; margin: 0 auto 0.5rem; border-radius: 12px; overflow: hidden;">
                                    ${booking.pet_name ? 
                                        `<img src="${booking.pet_photo || ''}" alt="${booking.pet_name}" class="pet__photo" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjQiIGhlaWdodD0iNjQiIHZpZXdCb3g9IjAgMCA2NCA2NCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iMzIiIGZpbGw9IiNGM0Y0RjYiLz4KPHBhdGggZD0iTTMyIDIwQzM2LjQxODMgMjAgNDAgMjMuNTgxNyA0MCAyOEM0MCAzMi40MTgzIDM2LjQxODMgMzYgMzIgMzZDMjcuNTgxNyAzNiAyNCAzMi40MTgzIDI0IDI4QzI0IDIzLjU4MTcgMjcuNTgxNyAyMCAzMiAyMFoiIGZpbGw9IiM5Q0EzQUYiLz4KPHBhdGggZD0iTTE2IDQ4QzE2IDQwLjI2ODcgMjIuMjY4NyAzNCAzMCAzNEgzNEM0MS43MzEzIDM0IDQ4IDQwLjI2ODcgNDggNDhWNDhIMTZaIiBmaWxsPSIjOUNBM0FGIi8+Cjwvc3ZnPgo='">` :
                                        `<div class="pet__photo__placeholder" style="width: 100%; height: 100%; background: #f3f4f6; display: flex; align-items: center; justify-content: center;">
                                            <i class="ri-pet-line" style="font-size: 2rem; color: #9ca3af;"></i>
                                        </div>`
                                    }
                                </div>
                                <div class="pet__details" style="text-align: center;">
                                    <h4 class="pet__name" style="font-size: 0.9rem; font-weight: 600; margin: 0 0 0.25rem 0; color: #111827;">${booking.pet_name || 'N/A'}</h4>
                                    <p class="pet__breed" style="font-size: 0.75rem; margin: 0; color: #6b7280;">${booking.pet_breed || booking.breed || 'Unknown Breed'}</p>
                                    <p class="pet__category" style="font-size: 0.7rem; margin: 0.25rem 0 0 0; color: #9ca3af;">${formatCategoryName(booking.pet_category || booking.service_category)}</p>
                                </div>
                            </div>
                        `}
                    </div>
                    
                    <!-- Booking Details Grid -->
                    <div class="booking__details__grid">
                        ${booking.booking_type !== 'hotel' ? `
                        <div class="booking__detail__card">
                            <div class="detail__icon">
                                <i class="ri-calendar-line"></i>
                            </div>
                            <div class="detail__content">
                                <span class="detail__label">Booking Date</span>
                                <span class="detail__value">${bookingDate ? bookingDate.toLocaleDateString('en-MY') : 'N/A'}</span>
                            </div>
                        </div>
                        ` : ''}
                        
                        ${booking.booking_type !== 'hotel' && booking.service_category !== 'CAT HOTEL' ? `
                        <div class="booking__detail__card">
                            <div class="detail__icon">
                                <i class="ri-time-line"></i>
                            </div>
                            <div class="detail__content">
                                <span class="detail__label">Time</span>
                                <span class="detail__value">${formatTime(bookingTime)}</span>
                            </div>
                        </div>
                        ` : ''}
                        
                        <div class="booking__detail__card">
                            <div class="detail__icon">
                                <i class="ri-money-dollar-circle-line"></i>
                            </div>
                            <div class="detail__content">
                                <span class="detail__label">Total Price</span>
                                <span class="detail__value price">RM ${parseFloat(booking.total_amount || 0).toFixed(2)}</span>
                            </div>
                        </div>
                        
                        <div class="booking__detail__card">
                            <div class="detail__icon">
                                <i class="ri-bank-card-line"></i>
                            </div>
                            <div class="detail__content">
                                <span class="detail__label">Payment Method</span>
                                <span class="detail__value">${formatPaymentMethod(booking.payment_method)}</span>
                            </div>
                        </div>
                        
                        ${booking.checkin_date ? `
                        <div class="booking__detail__card">
                            <div class="detail__icon">
                                <i class="ri-login-box-line"></i>
                            </div>
                            <div class="detail__content">
                                <span class="detail__label">Check-in</span>
                                <span class="detail__value">${new Date(booking.checkin_date).toLocaleDateString('en-MY')} ${booking.checkin_time ? formatTime(booking.checkin_time) : ''}</span>
                            </div>
                        </div>
                        ` : ''}
                        
                        ${booking.checkout_date ? `
                        <div class="booking__detail__card">
                            <div class="detail__icon">
                                <i class="ri-logout-box-line"></i>
                            </div>
                            <div class="detail__content">
                                <span class="detail__label">Check-out</span>
                                <span class="detail__value">${new Date(booking.checkout_date).toLocaleDateString('en-MY')} ${booking.checkout_time ? formatTime(booking.checkout_time) : ''}</span>
                            </div>
                        </div>
                        ` : ''}
                        
                        ${booking.nights_count ? `
                        <div class="booking__detail__card">
                            <div class="detail__icon">
                                <i class="ri-moon-line"></i>
                            </div>
                            <div class="detail__content">
                                <span class="detail__label">Nights</span>
                                <span class="detail__value">${booking.nights_count}</span>
                            </div>
                        </div>
                        ` : ''}
                        
                        ${booking.room_code ? `
                        <div class="booking__detail__card">
                            <div class="detail__icon">
                                <i class="ri-hotel-bed-line"></i>
                            </div>
                            <div class="detail__content">
                                <span class="detail__label">Room</span>
                                <span class="detail__value">${booking.room_code} (${booking.room_category || 'Hotel Room'})</span>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                    
                    ${booking.additional_notes ? `
                    <div class="booking__notes">
                        <div class="notes__header">
                            <i class="ri-sticky-note-line"></i>
                            <span>Additional Notes</span>
                        </div>
                        <p class="notes__content">${booking.additional_notes}</p>
                    </div>
                    ` : ''}
                </div>
                
                <div class="booking__actions">
                    ${status === 'pending' ? `
                    <button class="booking__action__btn danger" onclick="cancelBooking(${booking.id}, '${booking.booking_type || 'service'}', '${booking.booking_reference || booking.id}')">
                        <i class="ri-close-circle-line"></i> Cancel Booking
                    </button>
                    ` : ''}
                    
                    <button class="booking__action__btn notes" onclick="viewBookingNotes(${booking.id}, '${booking.booking_type || 'service'}')">
                        <i class="ri-sticky-note-line"></i> View Notes
                    </button>
                    
                    ${booking.receipt_file_path ? `
                    <button class="booking__action__btn secondary" onclick="downloadReceipt('${booking.receipt_file_path}')">
                        <i class="ri-download-line"></i> Download Receipt
                    </button>
                    ` : ''}
                    
                    ${showDeleteButton ? `
                    <button class="booking__action__btn danger" onclick="deleteBooking(${booking.id}, '${booking.booking_type || 'service'}')" style="background: #dc2626; color: white;">
                        <i class="ri-delete-bin-line"></i> Delete
                    </button>
                    ` : ''}
                </div>
            </div>
        `;
}

// Format status for display
function formatStatus(status) {
    const statusMap = {
        'pending': 'Pending',
        'confirmed': 'Confirmed', 
        'completed': 'Completed',
        'cancelled': 'Cancelled'
    };
    return statusMap[status] || status;
}

// Format payment method for display
function formatPaymentMethod(method) {
    if (!method) return 'N/A';
    return method.charAt(0).toUpperCase() + method.slice(1).replace('_', ' ');
}

// Format time for display - STANDARDIZED FUNCTION
// Converts 24-hour format to 12-hour format with AM/PM consistently across all pages
function formatTime(timeString) {
    if (!timeString) return 'N/A';
    
    // Normalize the string
    let timeStr = String(timeString).trim();
    
    // Handle invalid formats like "14:00 PM" by removing AM/PM and re-converting
    if (timeStr.includes('AM') || timeStr.includes('PM') || timeStr.includes('am') || timeStr.includes('pm')) {
        // Extract the time part and check if hour is > 12 (invalid for 12-hour format)
        const timeMatch = timeStr.match(/(\d{1,2}):(\d{2})(:\d{2})?\s*(AM|PM|am|pm)?/i);
        if (timeMatch) {
            const hour24 = parseInt(timeMatch[1]);
            // If hour is > 12 and has AM/PM, this is invalid (like "14:00 PM")
            // Remove AM/PM and convert properly
            if (hour24 > 12) {
                timeStr = `${hour24}:${timeMatch[2]}${timeMatch[3] || ''}`;
                // Continue with conversion below
            } else {
                // Valid 12-hour format, return as is
                return timeStr;
            }
        } else {
            return timeStr; // Return as is if we can't parse it
        }
    }
    
    // Handle 24-hour format (HH:MM or HH:MM:SS) - convert to 12-hour
    // Match patterns like "14:00:00", "14:00", "2:00", etc.
    const timeMatch = timeStr.match(/^(\d{1,2}):(\d{2})(:\d{2})?$/);
    if (timeMatch) {
        const hour24 = parseInt(timeMatch[1]);
        const minute = parseInt(timeMatch[2]);
        
        // Convert to 12-hour format
        let hour12;
        let ampm;
        
        if (hour24 === 0) {
            hour12 = 12; // Midnight: 00:00 -> 12:00 AM
            ampm = 'AM';
        } else if (hour24 === 12) {
            hour12 = 12; // Noon: 12:00 -> 12:00 PM
            ampm = 'PM';
        } else if (hour24 > 12) {
            hour12 = hour24 - 12; // PM times: 14:00 -> 2:00 PM, 23:00 -> 11:00 PM
            ampm = 'PM';
        } else {
            // Hours 1-11: Could be AM or PM depending on context
            // Based on booking slots, 1-5 are commonly PM in bookings
            if (hour24 >= 1 && hour24 <= 5) {
                hour12 = hour24;
                ampm = 'PM'; // Likely PM based on booking slots
            } else {
                hour12 = hour24; // 6-11 are typically AM
                ampm = 'AM';
            }
        }
        
        // Format as "H:MM AM/PM" (single digit hour without leading zero)
        return `${hour12}:${String(minute).padStart(2, '0')} ${ampm}`;
    }
    
    // If it's a full datetime string, extract and format the time part
    if (timeStr.includes('T') || (timeStr.includes(' ') && timeStr.includes(':'))) {
        try {
            // Try to parse as a full datetime first
            const time = new Date(timeStr);
            if (!isNaN(time.getTime())) {
                // Extract hours and minutes and convert manually
                const hours = time.getHours();
                const minutes = time.getMinutes();
                
                let hour12;
                let ampm;
                
                if (hours === 0) {
                    hour12 = 12;
                    ampm = 'AM';
                } else if (hours === 12) {
                    hour12 = 12;
                    ampm = 'PM';
                } else if (hours > 12) {
                    hour12 = hours - 12;
                    ampm = 'PM';
                } else {
                    hour12 = hours;
                    ampm = 'AM';
                }
                
                return `${hour12}:${String(minutes).padStart(2, '0')} ${ampm}`;
            }
        } catch (e) {
            // If parsing fails, try to extract time part
            const timeMatch = timeStr.match(/(\d{1,2}):(\d{2})(:\d{2})?/);
            if (timeMatch) {
                const hour24 = parseInt(timeMatch[1]);
                const minute = parseInt(timeMatch[2]);
                let hour12;
                let ampm;
                
                if (hour24 === 0) {
                    hour12 = 12;
                    ampm = 'AM';
                } else if (hour24 === 12) {
                    hour12 = 12;
                    ampm = 'PM';
                } else if (hour24 > 12) {
                    hour12 = hour24 - 12;
                    ampm = 'PM';
                } else {
                    if (hour24 >= 1 && hour24 <= 5) {
                        hour12 = hour24;
                        ampm = 'PM';
                    } else {
                        hour12 = hour24;
                        ampm = 'AM';
                    }
                }
                
                return `${hour12}:${String(minute).padStart(2, '0')} ${ampm}`;
            }
        }
    }
    
    // If all else fails, return the original string
    return timeStr;
}

// Format category name for display
function formatCategoryName(category) {
    if (!category) return 'N/A';
    return category.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

// View booking details
function viewBookingDetails(bookingId) {
    // Find the booking in the current data
    const booking = userBookings.find(b => b.id == bookingId);
    if (!booking) {
        alert('Booking not found');
        return;
    }
    
    // Add body scroll lock
    document.body.classList.add('modal-open');
    
    // Create a detailed modal WITH overlay
    const modal = document.createElement('div');
    modal.className = 'modal booking-details-modal';
    modal.style.display = 'flex';
    modal.innerHTML = `
        <div class="modal__overlay" onclick="closeBookingDetailsModal()"></div>
        <div class="modal__content booking-details-modal__content" style="max-width: 700px; background: #ffffff !important; color: #000000 !important;">
            <div class="modal__header">
                <h3>Booking Details - #${booking.booking_reference || booking.id}</h3>
                <button class="modal__close" onclick="closeBookingDetailsModal()">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="modal__body">
                <div class="booking__details">
                    <div class="booking__detail">
                        <span class="booking__detail__label">Service</span>
                        <span class="booking__detail__value">${booking.service_name || 'N/A'}</span>
                    </div>
                    <div class="booking__detail">
                        <span class="booking__detail__label">Pet</span>
                        <span class="booking__detail__value">${booking.pet_name || 'N/A'}</span>
                    </div>
                    ${booking.booking_type !== 'hotel' ? `
                    <div class="booking__detail">
                        <span class="booking__detail__label">Booking Date</span>
                        <span class="booking__detail__value">${booking.booking_date ? new Date(booking.booking_date).toLocaleDateString('en-MY') : 'N/A'}</span>
                    </div>
                    <div class="booking__detail">
                        <span class="booking__detail__label">Booking Time</span>
                        <span class="booking__detail__value">${booking.booking_time ? formatTime(booking.booking_time) : 'N/A'}</span>
                    </div>
                    ` : ''}
                    ${booking.booking_type === 'hotel' ? `
                    <div class="booking__detail">
                        <span class="booking__detail__label">Check-in Date</span>
                        <span class="booking__detail__value">${booking.checkin_date ? new Date(booking.checkin_date).toLocaleDateString('en-MY') : 'N/A'}</span>
                    </div>
                    <div class="booking__detail">
                        <span class="booking__detail__label">Check-in Time</span>
                        <span class="booking__detail__value">${booking.checkin_time ? formatTime(booking.checkin_time) : 'N/A'}</span>
                    </div>
                    <div class="booking__detail">
                        <span class="booking__detail__label">Check-out Date</span>
                        <span class="booking__detail__value">${booking.checkout_date ? new Date(booking.checkout_date).toLocaleDateString('en-MY') : 'N/A'}</span>
                    </div>
                    <div class="booking__detail">
                        <span class="booking__detail__label">Check-out Time</span>
                        <span class="booking__detail__value">${booking.checkout_time ? formatTime(booking.checkout_time) : 'N/A'}</span>
                    </div>
                    <div class="booking__detail">
                        <span class="booking__detail__label">Nights</span>
                        <span class="booking__detail__value">${booking.nights_count || 'N/A'}</span>
                    </div>
                    <div class="booking__detail">
                        <span class="booking__detail__label">Room</span>
                        <span class="booking__detail__value">${booking.room_code ? `${booking.room_code} (${booking.room_category || 'Hotel Room'})` : 'N/A'}</span>
                    </div>
                    ` : ''}
                    ${booking.booking_type !== 'hotel' ? `
                    <div class="booking__detail">
                        <span class="booking__detail__label">Status</span>
                        <span class="status__badge ${booking.booking_status || booking.status}">${formatStatus(booking.booking_status || booking.status)}</span>
                    </div>
                    ` : ''}
                    <div class="booking__detail">
                        <span class="booking__detail__label">Total Amount</span>
                        <span class="booking__detail__value">RM ${parseFloat(booking.total_amount || 0).toFixed(2)}</span>
                    </div>
                    <div class="booking__detail">
                        <span class="booking__detail__label">Payment Method</span>
                        <span class="booking__detail__value">${formatPaymentMethod(booking.payment_method)}</span>
                    </div>
                    <div class="booking__detail">
                        <span class="booking__detail__label">Created</span>
                        <span class="booking__detail__value">${new Date(booking.created_at).toLocaleString('en-MY')}</span>
                    </div>
                    ${booking.booking_type !== 'hotel' && booking.additional_notes ? `
                    <div class="booking__detail">
                        <span class="booking__detail__label">Notes</span>
                        <span class="booking__detail__value">${booking.additional_notes}</span>
                    </div>
                    ` : ''}
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    
    // Add escape key listener
    const handleEscape = (e) => {
        if (e.key === 'Escape') {
            closeBookingDetailsModal();
        }
    };
    document.addEventListener('keydown', handleEscape);
    
    // Add click outside to close
    const handleClickOutside = (e) => {
        if (e.target === modal) {
            closeBookingDetailsModal();
        }
    };
    modal.addEventListener('click', handleClickOutside);
    
    // Store the handlers for cleanup
    modal._escapeHandler = handleEscape;
    modal._clickHandler = handleClickOutside;
}

// Close booking details modal function
function closeBookingDetailsModal() {
    const modal = document.querySelector('.booking-details-modal');
    if (modal) {
        // Remove event listeners
        if (modal._escapeHandler) {
            document.removeEventListener('keydown', modal._escapeHandler);
        }
        if (modal._clickHandler) {
            modal.removeEventListener('click', modal._clickHandler);
        }
        // Remove body scroll lock
        document.body.classList.remove('modal-open');
        // Remove modal
        modal.remove();
    }
}

// Cancel booking
async function cancelBooking(bookingId) {
    if (!confirm('Are you sure you want to cancel this booking?')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'cancel_booking');
        formData.append('booking_id', bookingId);
        formData.append('tab_session_id', tabSessionId);
        
        const response = await fetch('bookings.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Mark this cancellation as already notified to prevent duplicate notifications
            const booking = userBookings.find(b => b.id == bookingId);
            if (booking) {
                const oldStatus = (booking.booking_status || booking.status || 'pending').toLowerCase().trim();
                const newStatus = 'cancelled';
                const statusChangeKey = `${bookingId}_${oldStatus}_${newStatus}`;
                notifiedStatusChanges.add(statusChangeKey);
                const notificationKey = `${bookingId}_${newStatus}`;
                showingNotifications.add(notificationKey);
                setTimeout(() => {
                    showingNotifications.delete(notificationKey);
                }, 5000);
            }
            
            alert('Booking cancelled successfully');
            await loadBookings(); // Reload bookings
    } else {
            alert('Failed to cancel booking: ' + result.message);
        }
    } catch (error) {
        console.error('Error cancelling booking:', error);
        alert('Error cancelling booking');
    }
}

// Delete booking (user-side deletion - admin can still see it)
async function deleteBooking(bookingId, bookingType) {
    if (!confirm('Are you sure you want to delete this booking? This action cannot be undone.')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_booking');
        formData.append('booking_id', bookingId);
        formData.append('booking_type', bookingType);
        formData.append('tab_session_id', tabSessionId);
        
        const response = await fetch('bookings.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Booking deleted successfully');
            await loadBookings(); // Reload bookings
        } else {
            alert('Failed to delete booking: ' + result.message);
        }
    } catch (error) {
        console.error('Error deleting booking:', error);
        alert('Error deleting booking');
    }
}

// Download receipt
function downloadReceipt(receiptPath) {
    if (!receiptPath) {
        alert('No receipt available');
        return;
    }
    
    // Create a temporary link to download the file
    const link = document.createElement('a');
    link.href = receiptPath;
    link.download = `receipt_${Date.now()}.pdf`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Show notification function
function showNotification(type, title, message) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification--${type}`;
    notification.innerHTML = `
        <div class="notification__content">
            <div class="notification__icon">
                <i class="ri-${type === 'success' ? 'check-line' : 'error-warning-line'}"></i>
            </div>
            <div class="notification__text">
                <h4>${title}</h4>
                <p>${message}</p>
            </div>
        </div>
    `;
    
    // Add to page
    document.body.appendChild(notification);
    
    // Show notification
    setTimeout(() => {
        notification.classList.add('notification--show');
    }, 100);
    
    // Remove notification after 5 seconds
    setTimeout(() => {
        notification.classList.remove('notification--show');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 5000);
}

// View receipt in modal
function viewReceipt(receiptPath) {
    if (!receiptPath) {
        alert('No receipt available');
        return;
    }
    
    // Add body scroll lock
    document.body.classList.add('modal-open');
    
    // Determine file type based on extension
    const fileExtension = receiptPath.split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(fileExtension);
    const isPDF = fileExtension === 'pdf';
    
    // Create a modal to display the receipt WITH overlay
    const modal = document.createElement('div');
    modal.className = 'modal receipt-modal';
    modal.style.display = 'flex';
    
    let viewerContent = '';
    
    if (isPDF) {
        // PDF viewer using iframe
        viewerContent = `
            <div class="receipt__viewer" style="height: 70vh;">
                <iframe src="${receiptPath}" 
                        style="width: 100%; height: 100%; border: none;" 
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                </iframe>
                <div style="display: none; padding: 2rem; text-align: center; color: #6b7280;">
                    <i class="ri-file-pdf-line" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                    <p>Unable to display PDF preview</p>
                    <p style="font-size: 0.9rem; margin-top: 0.5rem;">Your browser may not support PDF preview</p>
                    <button onclick="downloadReceipt('${receiptPath}')" style="margin-top: 1rem; padding: 0.5rem 1rem; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer;">
                        <i class="ri-download-line"></i> Download Receipt
                    </button>
                </div>
            </div>
        `;
    } else if (isImage) {
        // Image viewer
        viewerContent = `
            <div class="receipt__viewer">
                <img src="${receiptPath}" alt="Payment Receipt" style="width: 100%; height: auto; max-height: 70vh; object-fit: contain;" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <div style="display: none; padding: 2rem; text-align: center; color: #6b7280;">
                    <i class="ri-file-damage-line" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                    <p>Unable to display receipt image</p>
                    <button onclick="downloadReceipt('${receiptPath}')" style="margin-top: 1rem; padding: 0.5rem 1rem; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer;">
                        <i class="ri-download-line"></i> Download Receipt
                    </button>
                </div>
            </div>
        `;
    } else {
        // Unknown file type - show download option
        viewerContent = `
            <div class="receipt__viewer" style="padding: 2rem; text-align: center; color: #6b7280;">
                <i class="ri-file-line" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                <p>Receipt file format not supported for preview</p>
                <p style="font-size: 0.9rem; margin-top: 0.5rem;">File type: ${fileExtension.toUpperCase()}</p>
                <button onclick="downloadReceipt('${receiptPath}')" style="margin-top: 1rem; padding: 0.5rem 1rem; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer;">
                    <i class="ri-download-line"></i> Download Receipt
                </button>
            </div>
        `;
    }
    
    modal.innerHTML = `
        <div class="modal__overlay" onclick="closeReceiptModal()"></div>
        <div class="modal__content receipt-modal__content" style="max-width: 800px; max-height: 90vh; background: #ffffff !important; color: #000000 !important;">
            <div class="modal__header">
                <h3>Payment Receipt</h3>
                <button class="modal__close" onclick="closeReceiptModal()">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="modal__body" style="padding: 0;">
                ${viewerContent}
            </div>
            <div class="receipt__actions" style="padding: 1rem; border-top: 1px solid #e5e7eb; display: flex; gap: 1rem; justify-content: flex-end;">
                <button onclick="downloadReceipt('${receiptPath}')" style="padding: 0.5rem 1rem; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer;">
                    <i class="ri-download-line"></i> Download
                </button>
                <button onclick="closeReceiptModal()" style="padding: 0.5rem 1rem; background: #f3f4f6; color: #374151; border: 1px solid #e2e8f0; border-radius: 6px; cursor: pointer;">
                    Close
                </button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    
    // FORCE LIGHT BACKGROUND - Ultimate override for receipt modal
    setTimeout(() => {
        const modalContent = modal.querySelector('.modal__content');
        if (modalContent) {
            modalContent.style.setProperty('background', '#ffffff', 'important');
            modalContent.style.setProperty('background-color', '#ffffff', 'important');
            modalContent.style.setProperty('color', '#000000', 'important');
        }
        
        // Also force any receipt viewer content
        const receiptViewer = modal.querySelector('.receipt__viewer');
        if (receiptViewer) {
            receiptViewer.style.setProperty('background', '#ffffff', 'important');
            receiptViewer.style.setProperty('background-color', '#ffffff', 'important');
        }
    }, 10);
    
    // Add escape key listener
    const handleEscape = (e) => {
        if (e.key === 'Escape') {
            closeReceiptModal();
        }
    };
    document.addEventListener('keydown', handleEscape);
    
    // Add click outside to close
    const handleClickOutside = (e) => {
        if (e.target === modal) {
            closeReceiptModal();
        }
    };
    modal.addEventListener('click', handleClickOutside);
    
    // Store the handlers for cleanup
    modal._escapeHandler = handleEscape;
    modal._clickHandler = handleClickOutside;
}

// Download receipt directly
function downloadReceipt(receiptPath) {
    if (!receiptPath) {
        alert('No receipt available');
        return;
    }
    
    try {
        // Create a temporary link element to trigger download
        const link = document.createElement('a');
        link.href = receiptPath;
        link.download = receiptPath.split('/').pop() || 'receipt';
        link.target = '_blank';
        
        // Append to body, click, and remove
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // No success message - silent download
    } catch (error) {
        console.error('Error downloading receipt:', error);
        alert('Error downloading receipt. Please try again.');
    }
}

// View booking notes
function viewBookingNotes(bookingId, bookingType = 'service') {
    if (!bookingId) {
        alert('Booking ID not found');
        return;
    }
    
    // Add body scroll lock
    document.body.classList.add('modal-open');
    
    // Create modal for notes
    const modal = document.createElement('div');
    modal.className = 'modal notes-modal';
    modal.style.display = 'flex';
    
    modal.innerHTML = `
        <div class="modal__overlay" onclick="closeNotesModal()"></div>
        <div class="modal__content" style="max-width: 800px; background: white; color: #000;">
            <div class="modal__header" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; border-bottom: 1px solid #e5e7eb;">
                <h3 style="margin: 0; color: #1f2937;">
                    <i class="ri-sticky-note-line"></i> Booking Notes
                </h3>
                <button onclick="closeNotesModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6b7280;">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="modal__body" style="padding: 1rem;">
                <div id="notesContent" style="text-align: center; padding: 2rem; color: #6b7280;">
                    <i class="ri-loader-4-line" style="font-size: 2rem; animation: spin 1s linear infinite;"></i>
                    <p style="margin-top: 1rem;">Loading notes...</p>
                </div>
            </div>
        </div>
    `;
    
    // Add spin animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    `;
    document.head.appendChild(style);
    
    document.body.appendChild(modal);
    
    // Load notes from server
    loadBookingNotes(bookingId, bookingType);
}

// Load booking notes from server
async function loadBookingNotes(bookingId, bookingType = 'service') {
    try {
        const response = await fetch('booking_notes.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=get_notes&booking_id=${bookingId}&booking_type=${bookingType}`
        });
        
        const result = await response.json();
        const notesContent = document.getElementById('notesContent');
        
        if (result.success) {
            const data = result.data;
            
            if (!data.notes && (!data.images || data.images.length === 0)) {
                notesContent.innerHTML = `
                    <i class="ri-sticky-note-line" style="font-size: 3rem; color: #d1d5db; margin-bottom: 1rem;"></i>
                    <p style="color: #6b7280;">No notes or images available for this booking</p>
                `;
                return;
            }
            
            let content = '';
            
            // Display notes text
            if (data.notes) {
                content += `
                    <div style="margin-bottom: 2rem; text-align: left;">
                        <h4 style="color: #374151; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="ri-file-text-line"></i> Notes
                        </h4>
                        <div style="background: #f9fafb; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #d69e2e; min-height: 120px; max-height: 300px; overflow-y: auto;">
                            <p style="margin: 0; color: #374151; line-height: 1.8; font-size: 16px; word-wrap: break-word; white-space: pre-wrap;">${data.notes}</p>
                        </div>
                    </div>
                `;
            }
            
            // Display images
            if (data.images && data.images.length > 0) {
                content += `
                    <div style="text-align: left;">
                        <h4 style="color: #374151; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="ri-image-line"></i> Images (${data.images.length})
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem;">
                `;
                
                data.images.forEach(image => {
                    content += `
                        <div style="position: relative; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                            <img src="${image.image_path}" alt="Booking image" 
                                 style="width: 100%; height: 150px; object-fit: cover; cursor: pointer;"
                                 onclick="window.open('${image.image_path}', '_blank')">
                            <div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.7); color: white; padding: 0.5rem; font-size: 0.8rem;">
                                Click to view full size
                            </div>
                        </div>
                    `;
                });
                
                content += `
                        </div>
                    </div>
                `;
            }
            
            notesContent.innerHTML = content;
            
        } else {
            notesContent.innerHTML = `
                <i class="ri-error-warning-line" style="font-size: 3rem; color: #ef4444; margin-bottom: 1rem;"></i>
                <p style="color: #6b7280;">Error loading notes: ${result.message}</p>
            `;
        }
    } catch (error) {
        console.error('Error loading notes:', error);
        const notesContent = document.getElementById('notesContent');
        notesContent.innerHTML = `
            <i class="ri-error-warning-line" style="font-size: 3rem; color: #ef4444; margin-bottom: 1rem;"></i>
            <p style="color: #6b7280;">Error loading notes. Please try again.</p>
        `;
    }
}

// Close notes modal
function closeNotesModal() {
    const modal = document.querySelector('.notes-modal');
    if (modal) {
        document.body.classList.remove('modal-open');
        modal.remove();
    }
}

// Close receipt modal function
function closeReceiptModal() {
    const modal = document.querySelector('.receipt-modal');
    if (modal) {
        // Remove event listeners
        if (modal._escapeHandler) {
            document.removeEventListener('keydown', modal._escapeHandler);
        }
        if (modal._clickHandler) {
            modal.removeEventListener('click', modal._clickHandler);
        }
        // Remove body scroll lock
        document.body.classList.remove('modal-open');
        // Remove modal
        modal.remove();
    }
}

// Show notification
function showNotification(type, title, message, duration = 5000) {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <div class="notification__header">
            <h4 class="notification__title">${title}</h4>
            <button class="notification__close" onclick="this.parentElement.parentElement.remove()">
                <i class="ri-close-line"></i>
            </button>
        </div>
        <p class="notification__message">${message}</p>
    `;
    
    document.body.appendChild(notification);
    
    // Show notification
    setTimeout(() => {
        notification.classList.add('show');
    }, 100);
    
    // Auto remove after duration
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 300);
    }, duration);
}

// Check for booking status updates
async function checkBookingUpdates() {
    try {
        const response = await fetch('auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_bookings&tab_session_id=' + encodeURIComponent(tabSessionId)
        });
        
        const result = await response.json();
        
        if (result.success && result.data) {
            const newBookings = result.data || [];
            
            // Only check for status changes if initial bookings have been loaded
            // This prevents notifications when bookings are first loaded
            if (!initialBookingsLoaded) {
                // Update userBookings with new data but don't check for changes yet
                userBookings = newBookings;
                return;
            }
            
            // Check for status changes
            newBookings.forEach(newBooking => {
                // Skip deleted bookings
                if (newBooking.user_deleted === 1 || newBooking.user_deleted === true) {
                    return;
                }
                
                const oldBooking = userBookings.find(b => b.id === newBooking.id);
                
                // Normalize status values (convert to lowercase for comparison)
                const normalizeStatus = (status) => {
                    if (!status) return 'pending';
                    return String(status).toLowerCase().trim();
                };
                
                if (oldBooking) {
                    const oldStatus = normalizeStatus(oldBooking.booking_status || oldBooking.status);
                    const newStatus = normalizeStatus(newBooking.booking_status || newBooking.status);
                    
                    // Only process if status actually changed
                    if (oldStatus !== newStatus) {
                        // Create unique key for this status change
                        const statusChangeKey = `${newBooking.id}_${oldStatus}_${newStatus}`;
                        
                        // Only show notification if we haven't already notified for this specific status change
                        // AND we're not currently showing a notification for this booking
                        const notificationKey = `${newBooking.id}_${newStatus}`;
                        
                        if (!notifiedStatusChanges.has(statusChangeKey) && !showingNotifications.has(notificationKey)) {
                            // Mark as showing to prevent duplicates
                            showingNotifications.add(notificationKey);
                            
                            // Status changed - show notification
                            if (newStatus === 'confirmed') {
                                showNotification('success', 'Booking Confirmed!', 
                                    `Your booking for ${newBooking.service_name} has been confirmed by our team.`);
                                notifiedStatusChanges.add(statusChangeKey);
                            } else if (newStatus === 'completed') {
                                showNotification('info', 'Service Completed', 
                                    `Your ${newBooking.service_name} service has been completed. Thank you!`);
                                notifiedStatusChanges.add(statusChangeKey);
                            } else if (newStatus === 'cancelled') {
                                showNotification('warning', 'Booking Cancelled', 
                                    `Your booking for ${newBooking.service_name} has been cancelled.`);
                                notifiedStatusChanges.add(statusChangeKey);
                            }
                            
                            // Remove from showing set after 5 seconds (notification duration)
                            setTimeout(() => {
                                showingNotifications.delete(notificationKey);
                            }, 5000);
                        }
                    }
                }
            });
            
            // Update userBookings with new data
            userBookings = newBookings;
        }
    } catch (error) {
        console.error('Error checking booking updates:', error);
    }
}

// Initialize booking update checking
function initializeBookingUpdates() {
    // Check for updates every 30 seconds
    setInterval(checkBookingUpdates, 30000);
    
    // Also check when user switches to bookings tab
    const bookingsTab = document.querySelector('[data-tab="bookings"]');
    if (bookingsTab) {
        bookingsTab.addEventListener('click', () => {
            setTimeout(checkBookingUpdates, 1000);
        });
}
}

// Initialize tab functionality
function initializeTabs() {
    const tabButtons = document.querySelectorAll('.tab__button');
    const tabContents = document.querySelectorAll('.tab__content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const targetTab = button.getAttribute('data-tab');
            
            // Remove active class from all tabs and contents
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Add active class to clicked tab and corresponding content
            button.classList.add('active');
            const targetContent = document.getElementById(targetTab);
            if (targetContent) {
                targetContent.classList.add('active');
            }
            
        });
    });
}

// Initialize modals
function initializeModals() {
    // Add Pet Modal functions
// Edit pet function
window.editPet = function(petId) {
    
    // Find the pet data
    const pet = userPets.find(p => p.id == petId);
    if (!pet) {
        console.error('Pet not found with ID:', petId);
        return;
    }
    
    
    // Open the add pet modal
    openAddPetModal();
    
    // Populate the form with existing pet data
    setTimeout(() => {
        populateEditForm(pet);
    }, 100); // Small delay to ensure modal is open
};

// Populate the add pet form with existing pet data for editing
function populateEditForm(pet) {
    
    // Basic pet information
    const petNameField = document.getElementById('petName');
    const petCategoryField = document.getElementById('petCategory');
    const petBreedField = document.getElementById('petBreed');
    const petAgeField = document.getElementById('petAge');
    const petWeightField = document.getElementById('petWeight');
    const petGenderField = document.getElementById('petGender');
    const spayedNeuteredField = document.getElementById('spayedNeutered');
    const specialNotesField = document.getElementById('petNotes');
    
    if (petNameField) petNameField.value = pet.name || '';
    if (petCategoryField) petCategoryField.value = pet.category || pet.breed || '';
    if (petBreedField) petBreedField.value = pet.breed || '';
    if (petAgeField) petAgeField.value = pet.age || '';
    if (petWeightField) petWeightField.value = pet.weight || '';
    
    // Trigger category change to update age field
    if (petCategoryField) {
        handlePetCategoryChange();
        
        // Age is already in correct format for display (months for kittens, years for adults)
        if (petAgeField && pet.age) {
            petAgeField.value = pet.age;
        }
    }
    
    // Handle custom category
    if ((pet.category === 'custom' || pet.breed === 'custom') && pet.custom_category) {
        const customCategoryInput = document.getElementById('customCategory');
        if (customCategoryInput) {
            customCategoryInput.value = pet.custom_category;
            const customCategoryDiv = document.querySelector('.custom-category');
            if (customCategoryDiv) {
                customCategoryDiv.style.display = 'block';
            }
        }
    } else {
        // Hide custom category if not custom
        const customCategoryDiv = document.querySelector('.custom-category');
        if (customCategoryDiv) {
            customCategoryDiv.style.display = 'none';
        }
    }
    
    // Set gender selection
    if (pet.gender) {
        const genderField = document.querySelector(`input[name="petGender"][value="${pet.gender}"]`);
        if (genderField) {
            genderField.checked = true;
        }
    }
    
    // Set spayed/neutered selection
    if (pet.spayed_neutered) {
        const spayedField = document.querySelector(`input[name="petSpayed"][value="${pet.spayed_neutered}"]`);
        if (spayedField) {
            spayedField.checked = true;
        }
    }
    
    if (specialNotesField) {
        specialNotesField.value = pet.special_notes || '';
    } else {
    }
    
    
    // Medical conditions
    const medicalTypeField = document.getElementById('petEventType');
    const medicalConditionField = document.getElementById('petMedicalCondition');
    
    if (pet.medical_type && pet.medical_type !== 'none') {
        if (medicalTypeField) {
            medicalTypeField.value = pet.medical_type;
            updateMedicalConditions(pet.medical_type);
        }
        
        if (medicalConditionField) {
            medicalConditionField.value = pet.medical_condition || '';
        }
    } else {
        // Reset medical conditions if none
        if (medicalTypeField) {
            medicalTypeField.value = 'none';
            updateMedicalConditions('none');
        }
        if (medicalConditionField) {
            medicalConditionField.value = '';
        }
    }
    
    
    // Vaccinations - Reset all first, then set the correct ones
    const vaccineNames = [
        'fvrcp', 'rabies', 'felv', 'fiv', 'bordetella', 
        'chlamydophila', 'fip', 'giardia', 'ringworm', 'others'
    ];
    
    // First, uncheck all vaccine checkboxes and clear dates
    vaccineNames.forEach(vaccine => {
        const checkbox = document.querySelector(`input[name="${vaccine}"]`);
        if (checkbox) {
            checkbox.checked = false;
            toggleVaccinationDate(checkbox); // This will disable and clear the date field
        }
    });
    
    // Clear others vaccine field
    const otherVaccineField = document.querySelector('input[name="other_vaccine"]');
    if (otherVaccineField) {
        otherVaccineField.value = '';
    }
    
    // Then set the correct vaccines if they exist
    if (pet.vaccinations && pet.vaccinations.length > 0) {
        // Handle multiple vaccinations
        pet.vaccinations.forEach(vaccination => {
            const vaccineName = vaccination.vaccine_name.toLowerCase();
            
            // Handle "others" vaccine specially
            if (vaccineName === 'others' || !['fvrcp', 'rabies', 'felv', 'fiv', 'bordetella', 'chlamydophila', 'fip', 'giardia', 'ringworm'].includes(vaccineName)) {
                const othersCheckbox = document.querySelector('input[name="others"]');
                const otherVaccineField = document.querySelector('input[name="other_vaccine"]');
                
                if (othersCheckbox) {
                    othersCheckbox.checked = true;
                    toggleVaccinationDate(othersCheckbox);
                }
                
                if (otherVaccineField) {
                    otherVaccineField.value = vaccination.vaccine_name;
                }
                
                // Set date
                const othersDateField = document.querySelector('input[name="other_vaccine_date"]');
                if (othersDateField && vaccination.vaccination_date) {
                    othersDateField.value = vaccination.vaccination_date;
                }
            } else {
                // Regular vaccine
                const vaccineCheckbox = document.querySelector(`input[name="${vaccineName}"]`);
                if (vaccineCheckbox) {
                    vaccineCheckbox.checked = true;
                    toggleVaccinationDate(vaccineCheckbox);
                    
                    // Set date
                    const dateField = document.querySelector(`input[name="${vaccineName}_date"]`);
                    if (dateField && vaccination.vaccination_date) {
                        dateField.value = vaccination.vaccination_date;
                    }
                }
            }
        });
    } else if (pet.vaccine_name) {
        // Fallback to old single vaccine format
        const vaccineName = pet.vaccine_name.toLowerCase();
        
        // Handle "others" vaccine specially
        if (vaccineName === 'others' || !['fvrcp', 'rabies', 'felv', 'fiv', 'bordetella', 'chlamydophila', 'fip', 'giardia', 'ringworm'].includes(vaccineName)) {
            const othersCheckbox = document.querySelector('input[name="others"]');
            const otherVaccineField = document.querySelector('input[name="other_vaccine"]');
            
            if (othersCheckbox) {
                othersCheckbox.checked = true;
                toggleVaccinationDate(othersCheckbox);
            }
            
            if (otherVaccineField) {
                otherVaccineField.value = pet.vaccine_name;
            }
            
            // Set date
            if (pet.vaccine_date) {
                const othersDateField = document.querySelector('input[name="other_vaccine_date"]');
                if (othersDateField) {
                    othersDateField.value = pet.vaccine_date;
                }
            }
        } else {
            // Regular vaccine
            const vaccineCheckbox = document.querySelector(`input[name="${vaccineName}"]`);
            if (vaccineCheckbox) {
                vaccineCheckbox.checked = true;
                toggleVaccinationDate(vaccineCheckbox);
                
                // Set date
                if (pet.vaccine_date) {
                    const dateField = document.querySelector(`input[name="${vaccineName}_date"]`);
                    if (dateField) {
                        dateField.value = pet.vaccine_date;
                    }
                }
            }
        }
    }
    
    
    // Display pet photo if available
    if (pet.photo_path && pet.photo_path.trim() !== '') {
        
        // Show the image preview with existing photo
        const preview = document.getElementById('petImagePreview');
        const previewImg = document.getElementById('petPreviewImg');
        const uploadArea = document.getElementById('petImageUploadArea');
        const fileName = document.getElementById('petImageFileName');
        const fileSize = document.getElementById('petImageFileSize');
        
        if (preview && previewImg && uploadArea) {
            previewImg.src = pet.photo_path;
            preview.style.display = 'block';
            uploadArea.style.display = 'none';
            
            // Show file info
            if (fileName) {
                const fileNameFromPath = pet.photo_path.split('/').pop();
                fileName.textContent = fileNameFromPath || 'Current photo loaded';
            }
            if (fileSize) {
                fileSize.textContent = 'Existing photo';
            }
        }
    } else {
        
        // Hide preview and show upload area
        const preview = document.getElementById('petImagePreview');
        const uploadArea = document.getElementById('petImageUploadArea');
        const fileName = document.getElementById('petImageFileName');
        const fileSize = document.getElementById('petImageFileSize');
        
        if (preview && uploadArea) {
            preview.style.display = 'none';
            uploadArea.style.display = 'block';
        }
        
        if (fileName) fileName.textContent = '';
        if (fileSize) fileSize.textContent = '';
    }
    
    // Update the form title and submit button
    const modalTitle = document.querySelector('#add-pet-modal .modal__title');
    const submitButton = document.getElementById('next');
    
    if (modalTitle) modalTitle.textContent = 'Edit Pet Profile';
    if (submitButton) {
        submitButton.textContent = 'Save Changes';
        submitButton.type = 'button'; // Change to button to prevent form submission
        submitButton.onclick = (e) => {
            e.preventDefault(); // Prevent default form submission
            updatePet(pet.id);
        };
    }
    
    // Store the pet ID for the update operation
    window.editingPetId = pet.id;
    
}

// Update pet function
async function updatePet(petId) {
    
    // Validate required fields
    if (!validatePetForm()) {
        return;
    }
    
    try {
        // Collect form data
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('id', petId);
        formData.append('tab_session_id', tabSessionId);
        
        // Basic pet information - use safe field access
        const petNameField = document.getElementById('petName');
        const petCategoryField = document.getElementById('petCategory');
        const customCategoryField = document.getElementById('customCategory');
        const petAgeField = document.getElementById('petAge');
        const petWeightField = document.getElementById('petWeight');
        const petGenderField = document.querySelector('input[name="petGender"]:checked');
        const spayedNeuteredField = document.querySelector('input[name="petSpayed"]:checked');
        const specialNotesField = document.getElementById('petNotes');
        
        formData.append('petName', petNameField ? petNameField.value : '');
        formData.append('petCategory', petCategoryField ? petCategoryField.value : '');
        if (petCategoryField && petCategoryField.value === 'custom' && customCategoryField) {
            formData.append('customCategory', customCategoryField.value);
        }
        
        // Add breed field
        const petBreedField = document.getElementById('petBreed');
        formData.append('petBreed', petBreedField ? petBreedField.value : '');
        
        formData.append('petAge', petAgeField ? convertAgeForStorage(petAgeField.value, petCategoryField.value) : '');
        formData.append('petWeight', petWeightField ? petWeightField.value : '');
        formData.append('petGender', petGenderField ? petGenderField.value : 'unknown');
        formData.append('petSpayed', spayedNeuteredField ? spayedNeuteredField.value : 'unknown');
        formData.append('petNotes', specialNotesField ? specialNotesField.value : '');
        
        // Medical conditions
        const medicalTypeField = document.getElementById('petEventType');
        const medicalConditionField = document.getElementById('petMedicalCondition');
        
        const medicalType = medicalTypeField ? medicalTypeField.value : 'none';
        formData.append('petEventType', medicalType);
        formData.append('petMedicalCondition', medicalConditionField ? medicalConditionField.value : '');
        
        // Vaccinations - collect ALL selected vaccines
        const vaccineNames = [
            'fvrcp', 'rabies', 'felv', 'fiv', 'bordetella', 
            'chlamydophila', 'fip', 'giardia', 'ringworm', 'others'
        ];
        
        let vaccineNamesList = [];
        let vaccineDatesList = [];
        
        vaccineNames.forEach(vaccine => {
            const checkbox = document.querySelector(`input[name="${vaccine}"]`);
            if (checkbox && checkbox.checked) {
                let vaccineName = '';
                if (vaccine === 'others') {
                    const otherVaccineField = document.querySelector('input[name="other_vaccine"]');
                    vaccineName = otherVaccineField ? otherVaccineField.value : 'Others';
                } else {
                    vaccineName = vaccine.charAt(0).toUpperCase() + vaccine.slice(1);
                }
                
                // Handle special case for 'others' vaccine date
                const dateFieldName = vaccine === 'others' ? 'other_vaccine_date' : vaccine + '_date';
                const dateField = document.querySelector(`input[name="${dateFieldName}"]`);
                
                if (dateField && dateField.value) {
                    vaccineNamesList.push(vaccineName);
                    vaccineDatesList.push(dateField.value);
                }
            }
        });
        
        // Convert arrays to comma-separated strings
        const vaccineName = vaccineNamesList.join(', ');
        const vaccineDate = vaccineDatesList.join(', ');
        
        // Debug logging
        console.log('=== VACCINATION DEBUG ===');
        console.log('Selected vaccines:', vaccineNamesList);
        console.log('Selected dates:', vaccineDatesList);
        console.log('Final vaccine name string:', vaccineName);
        console.log('Final vaccine date string:', vaccineDate);
        console.log('========================');
        
        formData.append('vaccine_name', vaccineName);
        formData.append('vaccine_date', vaccineDate);
        
        // Handle photo upload if a new file is selected
        const photoInput = document.getElementById('petPhoto');
        if (photoInput && photoInput.files && photoInput.files[0] && photoInput.files[0].size > 0) {
            formData.append('photo', photoInput.files[0]);
        } else {
            // Preserve existing photo path
            const originalPet = userPets.find(p => p.id == petId);
            if (originalPet && originalPet.photo_path) {
                formData.append('photo_url', originalPet.photo_path);
            }
        }
        
        
        // Also log the original pet data for comparison
        const originalPet = userPets.find(p => p.id == petId);
        
        const response = await fetch('pets.php?v=20250113-vaccine-fix-debug', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Pet updated successfully!');
            closeAddPetModal();
            await loadPets(); // Reload pets
        } else {
            alert('Error updating pet: ' + result.message);
        }
        
    } catch (error) {
        console.error('Error updating pet:', error);
        alert('Error updating pet');
    }
}

// Reset form to add mode
function resetAddPetForm() {
    // Clear all form fields
    const form = document.getElementById('addPetForm');
    if (form) form.reset();
    
    // Reset image upload area
    const preview = document.getElementById('petImagePreview');
    const uploadArea = document.getElementById('petImageUploadArea');
    const fileName = document.getElementById('petImageFileName');
    const fileSize = document.getElementById('petImageFileSize');
    
    if (preview && uploadArea) {
        preview.style.display = 'none';
        uploadArea.style.display = 'block';
    }
    
    if (fileName) fileName.textContent = '';
    if (fileSize) fileSize.textContent = '';
    
    // Reset medical conditions
    updateMedicalConditions('none');
    
    // Reset vaccinations
    const vaccineNames = [
        'fvrcp', 'rabies', 'felv', 'fiv', 'bordetella', 
        'chlamydophila', 'fip', 'giardia', 'ringworm', 'others'
    ];
    
    vaccineNames.forEach(vaccine => {
        const checkbox = document.getElementById(vaccine);
        if (checkbox) checkbox.checked = false;
        
        const dateField = document.getElementById(vaccine + 'Date');
        if (dateField) dateField.value = '';
    });
    
    // Reset form title and submit button
    const modalTitle = document.querySelector('#add-pet-modal .modal__title');
    const submitButton = document.getElementById('next');
    
    if (modalTitle) modalTitle.textContent = 'Add New Pet';
    if (submitButton) {
        submitButton.textContent = 'Add Pet';
        submitButton.type = 'submit'; // Let the form handle submission
        submitButton.onclick = null; // Remove any onclick handler
    }
    
    // Clear editing state
    window.editingPetId = null;
}

    window.openAddPetModal = function() {
        resetAddPetForm(); // Reset form to add mode first
        const modal = document.getElementById('add-pet-modal');
        if (modal) {
            modal.style.display = 'flex';
            
            // Lock body scroll
            document.body.classList.add('modal-open');
            
            // Restore normal layout for add mode
            const leftContainer = modal.querySelector('.left-container');
            if (leftContainer) {
                leftContainer.style.display = 'flex';
            }
            
            const rightContainer = modal.querySelector('.right-container');
            if (rightContainer) {
                rightContainer.style.width = '';
                rightContainer.style.minWidth = '';
            }
            
            // Reset modal title and button text
            const modalTitle = document.querySelector('#add-pet-modal h1');
            if (modalTitle) {
                modalTitle.textContent = 'Add Your Pet! Ensure your pet gets the best care!';
            }
            
            const submitButton = document.querySelector('#addPetForm button[type="submit"]');
            if (submitButton) {
                submitButton.textContent = 'Add Pet';
            }
            
            // Reset form for add mode
            resetAddPetForm();
        }
    };
    
    window.closeAddPetModal = function() {
        const modal = document.getElementById('add-pet-modal');
        if (modal) {
            modal.style.display = 'none';
            // Unlock body scroll
            document.body.classList.remove('modal-open');
        }
    };
    
    // Pet Details Modal functions
    window.viewPetDetails = function(petId) {
        const pet = userPets.find(p => p.id == petId);
        if (!pet) return;
        
        // Debug: Log the pet data to see what fields are available
        
    const modal = document.getElementById('pet-modal');
        if (modal) {
            // Update modal content
            document.getElementById('pet-modal-title').textContent = pet.name;
            document.getElementById('pet-category-display').textContent = formatPetCategory(pet.category || pet.pet_category) || 'Unknown Category';
            document.getElementById('pet-breed-display').textContent = pet.breed || 'Unknown Breed';
            document.getElementById('pet-age-display').textContent = pet.age ? formatPetAge(pet.age, pet.category || pet.pet_category) : 'Unknown';
            document.getElementById('pet-gender-display').textContent = pet.gender || 'Unknown';
            document.getElementById('pet-weight-display').textContent = pet.weight ? `${pet.weight} kg` : 'Unknown';
            // Update medical type display
            let medicalTypeText = 'Not specified';
            if (pet.medical_type === 'none') {
                medicalTypeText = 'None';
            } else if (pet.medical_type) {
                medicalTypeText = pet.medical_type.charAt(0).toUpperCase() + pet.medical_type.slice(1);
            }
            document.getElementById('pet-event-type-display').textContent = medicalTypeText;
            
            // Update medical condition display
            let medicalConditionText = 'Not specified';
            if (pet.medical_condition === 'none') {
                medicalConditionText = 'No Medical Conditions';
            } else if (pet.medical_condition) {
                // Format medical condition by replacing underscores with spaces and capitalizing
                medicalConditionText = pet.medical_condition
                    .replace(/_/g, ' ')
                    .replace(/\b\w/g, l => l.toUpperCase());
            }
            document.getElementById('pet-medical-condition-display').textContent = medicalConditionText;
            
            // Update vaccinations display
            let vaccinationText = 'No vaccinations recorded';
            
            // Debug logging
            console.log('Pet vaccination data:', {
                vaccinations: pet.vaccinations,
                vaccine_name: pet.vaccine_name,
                vaccine_date: pet.vaccine_date
            });
            
            if (pet.vaccinations && pet.vaccinations.length > 0) {
                // Display multiple vaccinations
                vaccinationText = pet.vaccinations.map(vaccine => {
                    let dateText = 'No date';
                    if (vaccine.vaccination_date && vaccine.vaccination_date !== '0000-00-00' && vaccine.vaccination_date !== '') {
                        try {
                            dateText = new Date(vaccine.vaccination_date).toLocaleDateString();
                        } catch (e) {
                            dateText = vaccine.vaccination_date;
                        }
                    }
                    return `${vaccine.vaccine_name} (${dateText})`;
                }).join(', ');
            } else if (pet.vaccine_name && pet.vaccine_name !== '' && pet.vaccine_name !== 'NULL') {
                // Fallback to old single vaccine format
                let dateText = 'No date';
                if (pet.vaccine_date && pet.vaccine_date !== '0000-00-00' && pet.vaccine_date !== '') {
                    try {
                        dateText = new Date(pet.vaccine_date).toLocaleDateString();
                    } catch (e) {
                        dateText = pet.vaccine_date;
                    }
                }
                vaccinationText = `${pet.vaccine_name} (${dateText})`;
            }
            document.getElementById('pet-vaccinations-display').textContent = vaccinationText;
            
            document.getElementById('pet-spayed-display').textContent = pet.spayed_neutered || 'Unknown';
            document.getElementById('pet-notes-display').textContent = pet.special_notes || 'No notes available';
            
            // Update photo - show actual image if available
            const photoDisplay = document.getElementById('pet-photo-display');
            const photoPlaceholder = document.getElementById('pet-photo-placeholder');
            
            
            if (pet.photo_path && pet.photo_path.trim() !== '') {
                if (photoDisplay) {
                    photoDisplay.src = pet.photo_path;
                    photoDisplay.style.display = 'block';
                    photoDisplay.onload = function() {
                        if (photoPlaceholder) photoPlaceholder.style.display = 'none';
                    };
                    photoDisplay.onerror = function() {
                        this.style.display = 'none';
                        if (photoPlaceholder) photoPlaceholder.style.display = 'flex';
                    };
                }
                if (photoPlaceholder) photoPlaceholder.style.display = 'none';
        } else {
                if (photoDisplay) photoDisplay.style.display = 'none';
                if (photoPlaceholder) photoPlaceholder.style.display = 'flex';
    }

    modal.style.display = 'flex';
    
    // Lock body scroll
    document.body.classList.add('modal-open');
}
    };
    
    window.closeAllModals = function() {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.style.display = 'none';
        });
        // Unlock body scroll
        document.body.classList.remove('modal-open');
    };
    
    // Handle Escape key to close modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const addPetModal = document.getElementById('add-pet-modal');
            const petModal = document.querySelector('.pet__modal');
            
            if (addPetModal && addPetModal.style.display !== 'none') {
                closeAddPetModal();
            } else if (petModal && petModal.style.display !== 'none') {
                closeAllModals();
            }
        }
    });
    
    // Delete pet function
    window.deletePet = async function(petId) {
    if (!confirm('Are you sure you want to delete this pet?')) return;

    try {
        const formData = new FormData();
        formData.append('action', 'delete');
            formData.append('id', petId);

            const response = await fetch('pets.php?v=20250112-fixed', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
                alert('Pet deleted successfully');
                await loadPets(); // Reload pets
        } else {
                alert('Failed to delete pet: ' + result.message);
        }
    } catch (error) {
        console.error('Error deleting pet:', error);
            alert('Error deleting pet');
        }
    };
}

// Handle Navigation for Logged-in Users
document.addEventListener('DOMContentLoaded', function() {
    // Enable all navigation links for logged-in users
    const navLinks = document.querySelectorAll('.nav__links a');
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // Allow navigation to all pages
            // No need to prevent default - let users navigate freely
        });
    });
    
    // Add active state management
    const currentPage = window.location.pathname.split('/').pop();
    navLinks.forEach(link => {
        const linkPage = link.href.split('/').pop();
        if (linkPage === currentPage || 
            (currentPage === 'cust-dashboard.html' && linkPage.includes('cust-dashboard.html'))) {
            link.classList.add('nav-active');
        }
    });
});

// Handle Add Pet Form Submission
document.addEventListener('DOMContentLoaded', function() {
    const addPetForm = document.getElementById('addPetForm');
    if (addPetForm) {
        // Set pet name field to uppercase
        const petNameField = document.getElementById('petName');
        if (petNameField) {
            petNameField.addEventListener('input', function(e) {
                e.target.value = e.target.value.toUpperCase();
            });
            
            // Also handle paste events
            petNameField.addEventListener('paste', function(e) {
                setTimeout(() => {
                    e.target.value = e.target.value.toUpperCase();
                }, 10);
            });
        }
        addPetForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Validate required fields
            if (!validatePetForm()) {
                return;
            }
            
            try {
                const formData = new FormData(this);
                formData.append('action', 'create');
                
                // Ensure breed field is included
                const petBreedField = document.getElementById('petBreed');
                if (petBreedField) {
                    formData.set('petBreed', petBreedField.value);
                }
                
                // Debug: Log form data
                console.log('Breed field value:', petBreedField ? petBreedField.value : 'NOT FOUND');
                console.log('Form data entries:');
                for (let [key, value] of formData.entries()) {
                    console.log(key + ': ' + value);
                }
                
                // Handle image upload
                const petPhotoFile = document.getElementById('petPhoto').files[0];
                let photoPath = '';
                
                if (petPhotoFile) {
                    // Upload the image first
                    const uploadFormData = new FormData();
                    uploadFormData.append('action', 'upload_image');
                    uploadFormData.append('image', petPhotoFile);
                    
                    try {
                        const uploadResponse = await fetch('upload.php?v=20250112-fixed', {
                            method: 'POST',
                            body: uploadFormData
                        });
                        
                        const uploadResult = await uploadResponse.json();
                        if (uploadResult.success) {
                            photoPath = uploadResult.url;
                        } else {
                            console.warn('Image upload failed:', uploadResult.message);
                        }
                    } catch (uploadError) {
                        console.warn('Image upload error:', uploadError);
                    }
                }
                
                formData.append('photo_url', photoPath);
                
                // Handle vaccination data - collect ALL selected vaccines
                const vaccineNames = [
                    'fvrcp', 'rabies', 'felv', 'fiv', 'bordetella', 
                    'chlamydophila', 'fip', 'giardia', 'ringworm', 'others'
                ];
                
                let vaccineNamesList = [];
                let vaccineDatesList = [];
                
                vaccineNames.forEach(vaccine => {
                    const checkbox = document.querySelector(`input[name="${vaccine}"]`);
                    if (checkbox && checkbox.checked) {
                        let vaccineName = '';
                        if (vaccine === 'others') {
                            const otherVaccineField = document.querySelector('input[name="other_vaccine"]');
                            vaccineName = otherVaccineField ? otherVaccineField.value : 'Others';
                        } else {
                            vaccineName = vaccine.charAt(0).toUpperCase() + vaccine.slice(1);
                        }
                        
                        // Handle special case for 'others' vaccine date
                        const dateFieldName = vaccine === 'others' ? 'other_vaccine_date' : vaccine + '_date';
                        const dateField = document.querySelector(`input[name="${dateFieldName}"]`);
                        
                        if (dateField && dateField.value) {
                            vaccineNamesList.push(vaccineName);
                            vaccineDatesList.push(dateField.value);
                        }
                    }
                });
                
                // Convert arrays to comma-separated strings
                const vaccineName = vaccineNamesList.join(', ');
                const vaccineDate = vaccineDatesList.join(', ');
                
                // Debug logging
                console.log('=== ADD PET VACCINATION DEBUG ===');
                console.log('Selected vaccines:', vaccineNamesList);
                console.log('Selected dates:', vaccineDatesList);
                console.log('Final vaccine name string:', vaccineName);
                console.log('Final vaccine date string:', vaccineDate);
                console.log('==================================');
                
                formData.append('vaccine_name', vaccineName);
                formData.append('vaccine_date', vaccineDate);
                
                const response = await fetch('pets.php?v=20250113-vaccine-fix-debug', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Pet added successfully!');
                    closeAddPetModal();
                    await loadPets(); // Reload pets
                } else {
                    alert('Failed to add pet: ' + result.message);
                }
            } catch (error) {
                console.error('Error adding pet:', error);
                alert('Error adding pet');
            }
        });
    }
});

// Edit Pet Functions
function editPet(petId) {
    alert('Edit functionality has been removed. This button is for display only.');
}

function previewEditImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const photoPreview = document.getElementById('editPhotoPreview');
            photoPreview.innerHTML = `
                <img src="${e.target.result}" alt="Preview" style="width: 100%; height: 100%; object-fit: cover; border-radius: 0.5rem;">
                <span style="position: absolute; bottom: 5px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.7); color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px;">Click to change</span>
            `;
        };
        reader.readAsDataURL(input.files[0]);
    }
}


// Add Pet Photo Preview Function (keeping for compatibility but not used)
function previewImage(input) {
    if (input.files && input.files[0]) {
        const fileName = input.files[0].name;
        const photoPreview = document.getElementById('photoPreview');
        photoPreview.innerHTML = `
            <button id="pets-upload" type="button" onclick="document.getElementById('petPhoto').click()" style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: var(--primary-color); border: none; border-radius: 50%; width: 50px; height: 50px; color: white; font-size: 24px; cursor: pointer;">
                📷
            </button>
            <input id="petPhoto" name="petPhoto" type="file" accept="image/*" style="display: none;" onchange="previewImage(this)">
            <span style="font-size: 12px; color: #666; margin-top: 5px; word-break: break-all;">${fileName}</span>
        `;
    }
}

// Update pet function
async function updatePet(petId) {
    
    // Validate required fields
    if (!validatePetForm()) {
        return;
    }
    
    try {
        // Collect form data
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('id', petId);
        formData.append('tab_session_id', tabSessionId);
        
        // Basic pet information - use safe field access
        const petNameField = document.getElementById('petName');
        const petCategoryField = document.getElementById('petCategory');
        const customCategoryField = document.getElementById('customCategory');
        const petAgeField = document.getElementById('petAge');
        const petWeightField = document.getElementById('petWeight');
        const petGenderField = document.querySelector('input[name="petGender"]:checked');
        const spayedNeuteredField = document.querySelector('input[name="petSpayed"]:checked');
        const specialNotesField = document.getElementById('petNotes');
        
        formData.append('petName', petNameField ? petNameField.value : '');
        formData.append('petCategory', petCategoryField ? petCategoryField.value : '');
        if (petCategoryField && petCategoryField.value === 'custom' && customCategoryField) {
            formData.append('customCategory', customCategoryField.value);
        }
        
        // Add breed field
        const petBreedField = document.getElementById('petBreed');
        formData.append('petBreed', petBreedField ? petBreedField.value : '');
        
        formData.append('petAge', petAgeField ? convertAgeForStorage(petAgeField.value, petCategoryField.value) : '');
        formData.append('petWeight', petWeightField ? petWeightField.value : '');
        formData.append('petGender', petGenderField ? petGenderField.value : 'unknown');
        formData.append('petSpayed', spayedNeuteredField ? spayedNeuteredField.value : 'unknown');
        formData.append('petNotes', specialNotesField ? specialNotesField.value : '');
        
        // Medical conditions
        const medicalTypeField = document.getElementById('petEventType');
        const medicalConditionField = document.getElementById('petMedicalCondition');
        
        const medicalType = medicalTypeField ? medicalTypeField.value : 'none';
        formData.append('petEventType', medicalType);
        formData.append('petMedicalCondition', medicalConditionField ? medicalConditionField.value : '');
        
        // Vaccinations - collect ALL selected vaccines
        const vaccineNames = [
            'fvrcp', 'rabies', 'felv', 'fiv', 'bordetella', 
            'chlamydophila', 'fip', 'giardia', 'ringworm', 'others'
        ];
        
        let vaccineNamesList = [];
        let vaccineDatesList = [];
        
        vaccineNames.forEach(vaccine => {
            const checkbox = document.querySelector(`input[name="${vaccine}"]`);
            if (checkbox && checkbox.checked) {
                let vaccineName = '';
                if (vaccine === 'others') {
                    const otherVaccineField = document.querySelector('input[name="other_vaccine"]');
                    vaccineName = otherVaccineField ? otherVaccineField.value : 'Others';
                } else {
                    vaccineName = vaccine.charAt(0).toUpperCase() + vaccine.slice(1);
                }
                
                // Handle special case for 'others' vaccine date
                const dateFieldName = vaccine === 'others' ? 'other_vaccine_date' : vaccine + '_date';
                const dateField = document.querySelector(`input[name="${dateFieldName}"]`);
                
                if (dateField && dateField.value) {
                    vaccineNamesList.push(vaccineName);
                    vaccineDatesList.push(dateField.value);
                }
            }
        });
        
        // Convert arrays to comma-separated strings
        const vaccineName = vaccineNamesList.join(', ');
        const vaccineDate = vaccineDatesList.join(', ');
        
        // Debug logging
        console.log('=== VACCINATION DEBUG ===');
        console.log('Selected vaccines:', vaccineNamesList);
        console.log('Selected dates:', vaccineDatesList);
        console.log('Final vaccine name string:', vaccineName);
        console.log('Final vaccine date string:', vaccineDate);
        console.log('========================');
        
        formData.append('vaccine_name', vaccineName);
        formData.append('vaccine_date', vaccineDate);
        
        // Handle photo upload if a new file is selected
        const photoInput = document.getElementById('petPhoto');
        if (photoInput && photoInput.files && photoInput.files[0] && photoInput.files[0].size > 0) {
            formData.append('photo', photoInput.files[0]);
        } else {
            // Preserve existing photo path
            const originalPet = userPets.find(p => p.id == petId);
            if (originalPet && originalPet.photo_path) {
                formData.append('photo_url', originalPet.photo_path);
            }
        }
        
        
        // Also log the original pet data for comparison
        const originalPet = userPets.find(p => p.id == petId);
        
        const response = await fetch('pets.php?v=20250113-vaccine-fix-debug', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Pet updated successfully!');
            closeAddPetModal();
            await loadPets(); // Reload pets
        } else {
            alert('Error updating pet: ' + result.message);
        }
        
    } catch (error) {
        console.error('Error updating pet:', error);
        alert('Error updating pet');
    }
}

// Reset form to add mode
function resetAddPetForm() {
    // Clear all form fields
    const form = document.getElementById('addPetForm');
    if (form) form.reset();
    
    // Reset image upload area
    const preview = document.getElementById('petImagePreview');
    const uploadArea = document.getElementById('petImageUploadArea');
    const fileName = document.getElementById('petImageFileName');
    const fileSize = document.getElementById('petImageFileSize');
    
    if (preview && uploadArea) {
        preview.style.display = 'none';
        uploadArea.style.display = 'block';
    }
    
    if (fileName) fileName.textContent = '';
    if (fileSize) fileSize.textContent = '';
    
    // Reset medical conditions
    updateMedicalConditions('none');
    
    // Reset vaccinations
    const vaccineNames = [
        'fvrcp', 'rabies', 'felv', 'fiv', 'bordetella', 
        'chlamydophila', 'fip', 'giardia', 'ringworm', 'others'
    ];
    
    vaccineNames.forEach(vaccine => {
        const checkbox = document.getElementById(vaccine);
        if (checkbox) checkbox.checked = false;
        
        const dateField = document.getElementById(vaccine + 'Date');
        if (dateField) dateField.value = '';
    });
    
    // Reset form title and submit button
    const modalTitle = document.querySelector('#add-pet-modal .modal__title');
    const submitButton = document.getElementById('next');
    
    if (modalTitle) modalTitle.textContent = 'Add New Pet';
    if (submitButton) {
        submitButton.textContent = 'Add Pet';
        submitButton.type = 'submit'; // Let the form handle submission
        submitButton.onclick = null; // Remove any onclick handler
    }
    
    // Clear editing state
    window.editingPetId = null;
}

    window.openAddPetModal = function() {
        resetAddPetForm(); // Reset form to add mode first
        const modal = document.getElementById('add-pet-modal');
        if (modal) {
            modal.style.display = 'flex';
            
            // Lock body scroll
            document.body.classList.add('modal-open');
            
            // Restore normal layout for add mode
            const leftContainer = modal.querySelector('.left-container');
            if (leftContainer) {
                leftContainer.style.display = 'flex';
            }
            
            const rightContainer = modal.querySelector('.right-container');
            if (rightContainer) {
                rightContainer.style.width = '';
                rightContainer.style.minWidth = '';
            }
            
            // Reset modal title and button text
            const modalTitle = document.querySelector('#add-pet-modal h1');
            if (modalTitle) {
                modalTitle.textContent = 'Add Your Pet! Ensure your pet gets the best care!';
            }
            
            const submitButton = document.querySelector('#addPetForm button[type="submit"]');
            if (submitButton) {
                submitButton.textContent = 'Add Pet';
            }
            
            // Reset form for add mode
            resetAddPetForm();
        }
    };
    
    window.closeAddPetModal = function() {
        const modal = document.getElementById('add-pet-modal');
        if (modal) {
            modal.style.display = 'none';
            // Unlock body scroll
            document.body.classList.remove('modal-open');
        }
    };
    
    // Pet Details Modal functions
    window.viewPetDetails = function(petId) {
        const pet = userPets.find(p => p.id == petId);
        if (!pet) return;
        
        // Debug: Log the pet data to see what fields are available
        
    const modal = document.getElementById('pet-modal');
        if (modal) {
            // Update modal content
            document.getElementById('pet-modal-title').textContent = pet.name;
            document.getElementById('pet-category-display').textContent = formatPetCategory(pet.category || pet.pet_category) || 'Unknown Category';
            document.getElementById('pet-breed-display').textContent = pet.breed || 'Unknown Breed';
            document.getElementById('pet-age-display').textContent = pet.age ? formatPetAge(pet.age, pet.category || pet.pet_category) : 'Unknown';
            document.getElementById('pet-gender-display').textContent = pet.gender || 'Unknown';
            document.getElementById('pet-weight-display').textContent = pet.weight ? `${pet.weight} kg` : 'Unknown';
            // Update medical type display
            let medicalTypeText = 'Not specified';
            if (pet.medical_type === 'none') {
                medicalTypeText = 'None';
            } else if (pet.medical_type) {
                medicalTypeText = pet.medical_type.charAt(0).toUpperCase() + pet.medical_type.slice(1);
            }
            document.getElementById('pet-event-type-display').textContent = medicalTypeText;
            
            // Update medical condition display
            let medicalConditionText = 'Not specified';
            if (pet.medical_condition === 'none') {
                medicalConditionText = 'No Medical Conditions';
            } else if (pet.medical_condition) {
                // Format medical condition by replacing underscores with spaces and capitalizing
                medicalConditionText = pet.medical_condition
                    .replace(/_/g, ' ')
                    .replace(/\b\w/g, l => l.toUpperCase());
            }
            document.getElementById('pet-medical-condition-display').textContent = medicalConditionText;
            
            // Update vaccinations display
            let vaccinationText = 'No vaccinations recorded';
            
            // Debug logging
            console.log('Pet vaccination data:', {
                vaccinations: pet.vaccinations,
                vaccine_name: pet.vaccine_name,
                vaccine_date: pet.vaccine_date
            });
            
            if (pet.vaccinations && pet.vaccinations.length > 0) {
                // Display multiple vaccinations
                vaccinationText = pet.vaccinations.map(vaccine => {
                    let dateText = 'No date';
                    if (vaccine.vaccination_date && vaccine.vaccination_date !== '0000-00-00' && vaccine.vaccination_date !== '') {
                        try {
                            dateText = new Date(vaccine.vaccination_date).toLocaleDateString();
                        } catch (e) {
                            dateText = vaccine.vaccination_date;
                        }
                    }
                    return `${vaccine.vaccine_name} (${dateText})`;
                }).join(', ');
            } else if (pet.vaccine_name && pet.vaccine_name !== '' && pet.vaccine_name !== 'NULL') {
                // Fallback to old single vaccine format
                let dateText = 'No date';
                if (pet.vaccine_date && pet.vaccine_date !== '0000-00-00' && pet.vaccine_date !== '') {
                    try {
                        dateText = new Date(pet.vaccine_date).toLocaleDateString();
                    } catch (e) {
                        dateText = pet.vaccine_date;
                    }
                }
                vaccinationText = `${pet.vaccine_name} (${dateText})`;
            }
            document.getElementById('pet-vaccinations-display').textContent = vaccinationText;
            
            document.getElementById('pet-spayed-display').textContent = pet.spayed_neutered || 'Unknown';
            document.getElementById('pet-notes-display').textContent = pet.special_notes || 'No notes available';
            
            // Update photo - show actual image if available
            const photoDisplay = document.getElementById('pet-photo-display');
            const photoPlaceholder = document.getElementById('pet-photo-placeholder');
            
            
            if (pet.photo_path && pet.photo_path.trim() !== '') {
                if (photoDisplay) {
                    photoDisplay.src = pet.photo_path;
                    photoDisplay.style.display = 'block';
                    photoDisplay.onload = function() {
                        if (photoPlaceholder) photoPlaceholder.style.display = 'none';
                    };
                    photoDisplay.onerror = function() {
                        this.style.display = 'none';
                        if (photoPlaceholder) photoPlaceholder.style.display = 'flex';
                    };
                }
                if (photoPlaceholder) photoPlaceholder.style.display = 'none';
        } else {
                if (photoDisplay) photoDisplay.style.display = 'none';
                if (photoPlaceholder) photoPlaceholder.style.display = 'flex';
    }

    modal.style.display = 'flex';
    
    // Lock body scroll
    document.body.classList.add('modal-open');
}
    };
    
    window.closeAllModals = function() {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.style.display = 'none';
        });
        // Unlock body scroll
        document.body.classList.remove('modal-open');
    };
    
    // Handle Escape key to close modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const addPetModal = document.getElementById('add-pet-modal');
            const petModal = document.querySelector('.pet__modal');
            
            if (addPetModal && addPetModal.style.display !== 'none') {
                closeAddPetModal();
            } else if (petModal && petModal.style.display !== 'none') {
                closeAllModals();
            }
        }
    });

// Handle Navigation for Logged-in Users
// Edit Pet Functions
function editPet(petId) {
    alert('Edit functionality has been removed. This button is for display only.');
}

function previewEditImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const photoPreview = document.getElementById('editPhotoPreview');
            photoPreview.innerHTML = `
                <img src="${e.target.result}" alt="Preview" style="width: 100%; height: 100%; object-fit: cover; border-radius: 0.5rem;">
                <span style="position: absolute; bottom: 5px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.7); color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px;">Click to change</span>
            `;
        };
        reader.readAsDataURL(input.files[0]);
    }
}


// Add Pet Photo Preview Function (keeping for compatibility but not used)
function previewImage(input) {
    if (input.files && input.files[0]) {
        const fileName = input.files[0].name;
        const photoPreview = document.getElementById('photoPreview');
        photoPreview.innerHTML = `
            <button id="pets-upload" type="button" onclick="document.getElementById('petPhoto').click()" style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: var(--primary-color); border: none; border-radius: 50%; width: 50px; height: 50px; color: white; font-size: 24px; cursor: pointer;">
                📷
            </button>
            <input id="petPhoto" name="petPhoto" type="file" accept="image/*" style="display: none;" onchange="previewImage(this)">
            <span style="font-size: 12px; color: #666; margin-top: 5px; word-break: break-all;">${fileName}</span>
        `;
    }
}
// Cancel booking function
window.cancelBooking = async function(bookingId, bookingType, bookingReference) {
    // Confirm cancellation
    const confirmed = confirm(
        `Are you sure you want to cancel this booking?\n\n` +
        `Reference: #${bookingReference}\n\n` +
        `This action cannot be undone.`
    );
    
    if (!confirmed) return;
    
    try {
        // Show loading state
        const btn = event.target.closest('.booking__action__btn');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="ri-loader-4-line"></i> Cancelling...';
        btn.disabled = true;
        
        // Send cancellation request
        const response = await fetch('auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=cancel_booking&booking_id=${bookingId}&booking_type=${bookingType}&tab_session_id=${encodeURIComponent(window.tabSessionId || '')}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            
        
            const booking = userBookings.find(b => b.id == bookingId);
            if (booking) {
                const oldStatus = (booking.booking_status || booking.status || 'pending').toLowerCase().trim();
                const newStatus = 'cancelled';
                const statusChangeKey = `${bookingId}_${oldStatus}_${newStatus}`;
                notifiedStatusChanges.add(statusChangeKey);
                const notificationKey = `${bookingId}_${newStatus}`;
                showingNotifications.add(notificationKey);
                // Remove from showing set after 5 seconds
                setTimeout(() => {
                    showingNotifications.delete(notificationKey);
                }, 5000);
            }
            
            // Show success notification
            alert('SUCCESS! Booking cancelled successfully!');
            
            // Reload bookings to reflect the cancellation
            await loadBookings();
        } else {
            alert('ERROR: Unable to cancel booking - ' + result.message);
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    } catch (error) {
        console.error('Error cancelling booking:', error);
        alert('ERROR: Unable to cancel booking. Please try again.');
        const btn = event.target.closest('.booking__action__btn');
        if (btn) {
            btn.disabled = false;
        }
    }
};





