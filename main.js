document.addEventListener('DOMContentLoaded', function() {
    const userProfile = document.querySelector('.user-profile');
    const loginModal = document.getElementById('loginModal');
    
    
    if (userProfile && loginModal && !userProfile.hasAttribute('data-modal-handler-added')) {
        userProfile.setAttribute('data-modal-handler-added', 'true');
        userProfile.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); 
            
            
            if (typeof openLoginModal === 'function') {
                openLoginModal();
            } else {
                // Fallback to basic modal opening
                loginModal.classList.add('active');
                loginModal.style.cssText = `
                    position: fixed !important;
                    top: 0 !important;
                    left: 0 !important;
                    width: 100% !important;
                    height: 100% !important;
                    display: flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                    z-index: 9999 !important;
                    visibility: visible !important;
                    opacity: 1 !important;
                    backdrop-filter: none !important;
                    filter: none !important;
                `;
                
                const modalContent = loginModal.querySelector('.modal__content');
                if (modalContent) {
                    modalContent.style.cssText = `
                        display: block !important;
                        visibility: visible !important;
                        opacity: 1 !important;
                        z-index: 10000 !important;
                        position: relative !important;
                    `;
                }
                
                document.body.style.overflow = 'hidden';
                document.body.classList.add('modal-open');
            }
        });
    }
    
    // Close modal
    const modalClose = document.getElementById('modalClose');
    const modalOverlay = document.getElementById('modalOverlay');
    
    function closeModal() {
        // Use the enhanced closeLoginModal function if available, otherwise fallback to basic modal closing
        if (typeof closeLoginModal === 'function') {
            closeLoginModal();
        } else {
            // Fallback to basic modal closing
            loginModal.style.display = 'none';
            loginModal.classList.remove('active');
            document.body.style.overflow = 'auto';
            document.body.classList.remove('modal-open');
        }
    }
    
    if (modalClose) modalClose.addEventListener('click', closeModal);
    if (modalOverlay) {
        modalOverlay.addEventListener('click', function(e) {
            // Only close if clicking the overlay itself, not the modal content or form elements
            if (e.target === modalOverlay && !e.target.closest('.modal__content')) {
                closeModal();
            }
        });
    }
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && loginModal.style.display !== 'none') {
            closeModal();
        }
    });
});

// ScrollReveal Animations
document.addEventListener('DOMContentLoaded', function() {
    // Check if ScrollReveal is available
    if (typeof ScrollReveal !== 'undefined') {
        const sr = ScrollReveal({
            origin: 'top',
            distance: '60px',
            duration: 2500,
            delay: 400,
            reset: true
        });

        // Header animations
        sr.reveal('.header__content h4', { delay: 200 });
        sr.reveal('.header__content h1', { delay: 300 });
        sr.reveal('.header__content h2', { delay: 400 });
        sr.reveal('.header__content p', { delay: 500 });
        sr.reveal('.header__btn', { delay: 600 });
        sr.reveal('.header__image', { delay: 700, origin: 'right' });

        // Intro section animations
        sr.reveal('.intro__card', { 
            delay: 200,
            interval: 200,
            origin: 'bottom'
        });

        // About section animations
        sr.reveal('.about__row', { 
            delay: 200,
            interval: 300,
            origin: 'left'
        });

        // Product section animations
        sr.reveal('.product__card', { 
            delay: 200,
            interval: 200,
            origin: 'bottom'
        });

        // Service section animations
        sr.reveal('.service__card', { 
            delay: 200,
            interval: 100,
            origin: 'bottom'
        });

        const swiper = new Swiper(".swiper", {
            slidesPerView: 3,
            spaceBetween: 20,
            loop: true,
          });

        // Instagram section animations
        sr.reveal('.instagram__grid img', { 
            delay: 200,
            interval: 100,
            origin: 'bottom'
        });

        // Footer animations
        sr.reveal('.footer__col', { 
            delay: 200,
            interval: 200,
            origin: 'bottom'
        });
    }
});
