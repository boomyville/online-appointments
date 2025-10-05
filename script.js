// Timeline functionality for admin panel
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('selectedDate')) {
        initializeTimeline();
    }
    startAutoRefresh();
});

// CSRF token management
let csrfToken = null;

async function getCSRFToken() {
    if (!csrfToken) {
        try {
            const response = await fetch('logic.php?action=get_csrf_token');
            const data = await response.json();
            csrfToken = data.token;
        } catch (error) {
            console.error('Failed to get CSRF token:', error);
        }
    }
    return csrfToken;
}

function initializeTimeline() {
    const today = new Date();
    const dateInput = document.getElementById('selectedDate');
    dateInput.value = today.toISOString().split('T')[0];
    loadTimeline(dateInput.value);
}

async function loadTimeline(date) {
    // Validate date format
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
        console.error('Invalid date format:', date);
        return;
    }

    try {
        const response = await fetch(`logic.php?action=get_timeline&date=${encodeURIComponent(date)}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.error) {
            throw new Error(data.error);
        }
        
        updateTimeline(data, date);
    } catch (error) {
        console.error('Error loading timeline:', error);
        showError('Failed to load timeline. Please try again.');
    }
}

function updateTimeline(appointments, date) {
    const container = document.querySelector('.appt-timeline');
    
    if (!container) {
        console.error('Timeline container not found');
        return;
    }
    
    if (appointments.length === 0) {
        container.innerHTML = '<div class="no-appointments">No appointments scheduled for this date</div>';
        return;
    }
    
    // Group appointments by hour
    const hourlyData = {};
    appointments.forEach(appt => {
        try {
            const hour = new Date(appt.appointment_time).getHours();
            if (isNaN(hour)) {
                console.warn('Invalid appointment time:', appt.appointment_time);
                return;
            }
            if (!hourlyData[hour]) {
                hourlyData[hour] = [];
            }
            hourlyData[hour].push(appt);
        } catch (error) {
            console.warn('Error processing appointment:', appt, error);
        }
    });
    
    // Generate timeline HTML
    let timelineHTML = '<div class="timeline-visualization">';
    
    // Create hourly blocks from 8 AM to 6 PM
    for (let hour = 8; hour <= 18; hour++) {
        const hourDisplay = hour > 12 ? `${hour - 12}:00 PM` : `${hour}:00 AM`;
        timelineHTML += `
            <div class="hour-block">
                <div class="hour-label">${escapeHtml(hourDisplay)}</div>
                <div class="hour-slots">
        `;
        
        // Create 15-minute slots for each hour
        for (let quarter = 0; quarter < 4; quarter++) {
            const minutes = quarter * 15;
            const slotTime = `${hour.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}`;
            const slotDateTime = `${date} ${slotTime}:00`;
            
            // Check if there's an appointment in this slot
            const appointment = hourlyData[hour]?.find(appt => {
                try {
                    const apptTime = new Date(appt.appointment_time);
                    return apptTime.getMinutes() === minutes;
                } catch (error) {
                    console.warn('Error parsing appointment time:', appt.appointment_time);
                    return false;
                }
            });
            
            let slotClass = 'slot-empty';
            let slotContent = `<div class="slot-time">${escapeHtml(slotTime)}</div>`;
            
            if (appointment) {
                slotClass = appointment.status === 'available' ? 'slot-available' : 'slot-booked';
                slotContent = `
                    <div class="slot-time">${escapeHtml(slotTime)}</div>
                    <div class="slot-duration">${escapeHtml(appointment.duration)} min</div>
                    ${appointment.customer_name ? `<div class="slot-customer">${escapeHtml(appointment.customer_name)}</div>` : ''}
                    <div class="slot-status">${escapeHtml(appointment.status)}</div>
                    <button class="slot-delete" onclick="deleteAppointment(${parseInt(appointment.id)})" title="Delete appointment" aria-label="Delete appointment">×</button>
                `;
            }
            
            timelineHTML += `
                <div class="time-slot ${slotClass}" data-time="${escapeHtml(slotDateTime)}">
                    ${slotContent}
                </div>
            `;
        }
        
        timelineHTML += `
                </div>
            </div>
        `;
    }
    
    timelineHTML += '</div>';
    container.innerHTML = timelineHTML;
}

function changeDate(direction) {
    const dateInput = document.getElementById('selectedDate');
    if (!dateInput) {
        console.error('Date input not found');
        return;
    }
    
    try {
        const currentDate = new Date(dateInput.value);
        if (isNaN(currentDate.getTime())) {
            throw new Error('Invalid date');
        }
        
        currentDate.setDate(currentDate.getDate() + direction);
        dateInput.value = currentDate.toISOString().split('T')[0];
        loadTimeline(dateInput.value);
    } catch (error) {
        console.error('Error changing date:', error);
        showError('Error changing date');
    }
}

function goToToday() {
    const today = new Date();
    const dateInput = document.getElementById('selectedDate');
    if (!dateInput) {
        console.error('Date input not found');
        return;
    }
    
    dateInput.value = today.toISOString().split('T')[0];
    loadTimeline(dateInput.value);
}

async function deleteAppointment(appointmentId) {
    if (!Number.isInteger(appointmentId) || appointmentId <= 0) {
        console.error('Invalid appointment ID:', appointmentId);
        showError('Invalid appointment ID');
        return;
    }
    
    if (!confirm('Are you sure you want to delete this appointment?')) {
        return;
    }
    
    try {
        const token = await getCSRFToken();
        if (!token) {
            throw new Error('Failed to get security token');
        }
        
        const response = await fetch('logic.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': token
            },
            body: `action=delete_appointment&id=${encodeURIComponent(appointmentId)}&csrf_token=${encodeURIComponent(token)}`
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            const dateInput = document.getElementById('selectedDate');
            if (dateInput) {
                await loadTimeline(dateInput.value);
            }
            showSuccess('Appointment deleted successfully');
        } else {
            throw new Error(data.message || 'Unknown error');
        }
    } catch (error) {
        console.error('Error deleting appointment:', error);
        showError('Error deleting appointment: ' + error.message);
    }
}

// Form validation for registration
function validateRegistration() {
    const username = document.getElementById('username')?.value?.trim();
    const password = document.getElementById('password')?.value;
    const confirmPassword = document.getElementById('confirmPassword')?.value;
    
    if (!username || username.length < 3) {
        showError('Username must be at least 3 characters long');
        return false;
    }
    
    // Check for valid username characters
    if (!/^[a-zA-Z0-9_-]+$/.test(username)) {
        showError('Username can only contain letters, numbers, underscores, and hyphens');
        return false;
    }
    
    if (!password || password.length < 8) {
        showError('Password must be at least 8 characters long');
        return false;
    }
    
    // Check password strength
    if (!/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(password)) {
        showError('Password must contain at least one lowercase letter, one uppercase letter, and one number');
        return false;
    }
    
    if (password !== confirmPassword) {
        showError('Passwords do not match');
        return false;
    }
    
    return true;
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showError(message) {
    // Create or update error message element
    let errorElement = document.getElementById('error-message');
    if (!errorElement) {
        errorElement = document.createElement('div');
        errorElement.id = 'error-message';
        errorElement.className = 'error-message';
        document.body.appendChild(errorElement);
    }
    
    errorElement.textContent = message;
    errorElement.style.display = 'block';
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        errorElement.style.display = 'none';
    }, 5000);
}

function showSuccess(message) {
    // Create or update success message element
    let successElement = document.getElementById('success-message');
    if (!successElement) {
        successElement = document.createElement('div');
        successElement.id = 'success-message';
        successElement.className = 'success-message';
        document.body.appendChild(successElement);
    }
    
    successElement.textContent = message;
    successElement.style.display = 'block';
    
    // Auto-hide after 3 seconds
    setTimeout(() => {
        successElement.style.display = 'none';
    }, 3000);
}

// Improved auto-refresh functionality
let refreshInterval = null;
const REFRESH_INTERVAL = 30000; // 30 seconds

function startAutoRefresh() {
    // Clear existing interval if any
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
    
    refreshInterval = setInterval(() => {
        const dateInput = document.getElementById('selectedDate');
        if (dateInput && document.visibilityState === 'visible') {
            loadTimeline(dateInput.value);
        }
    }, REFRESH_INTERVAL);
}

function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
}

// Pause refresh when page is not visible
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        startAutoRefresh();
    } else {
        stopAutoRefresh();
    }
});

// Clean up on page unload
window.addEventListener('beforeunload', function() {
    stopAutoRefresh();
});