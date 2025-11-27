(function($) {
    'use strict';
    
    /**
     * Collect all user field values from the form
     */
    function collectUserFieldsData(calendarId) {
        const wrapper = document.querySelector(`.codo-calendar-wrapper[data-calendar-id="${calendarId}"]`);
        if (!wrapper) return {};
        
        const container = wrapper.closest('.codo-calendar-container, .codo-single-calendar');
        const fieldsWrapper = container ? container.querySelector('.codobuf-user-fields-wrapper') : null;
        
        if (!fieldsWrapper) return {};
        
        const data = {};
        const inputs = fieldsWrapper.querySelectorAll('input, select, textarea');
        
        inputs.forEach(input => {
            const name = input.name;
            if (!name || !name.startsWith('codobuf_')) return;
            
            if (input.type === 'checkbox') {
                data[name] = input.checked ? '1' : '0';
            } else if (input.type === 'radio') {
                if (input.checked) {
                    data[name] = input.value;
                }
            } else {
                data[name] = input.value;
            }
        });
        
        return data;
    }
    
    /**
     * Clear all user field inputs
     */
    function clearUserFields(calendarId) {
        const wrapper = calendarId ? 
            document.querySelector(`.codo-calendar-wrapper[data-calendar-id="${calendarId}"]`) :
            document.querySelector('.codo-calendar-wrapper');
            
        if (!wrapper) return;
        
        const container = wrapper.closest('.codo-calendar-container, .codo-single-calendar');
        const fieldsWrapper = container ? container.querySelector('.codobuf-user-fields-wrapper') : null;
        
        if (!fieldsWrapper) return;
        
        const inputs = fieldsWrapper.querySelectorAll('input, select, textarea');
        
        inputs.forEach(input => {
            if (input.type === 'checkbox' || input.type === 'radio') {
                input.checked = false;
            } else if (input.tagName === 'SELECT') {
                input.selectedIndex = 0;
            } else {
                input.value = '';
            }
        });
    }
    
    /**
     * Validate required fields
     */
    function validateUserFields(calendarId) {
        const wrapper = document.querySelector(`.codo-calendar-wrapper[data-calendar-id="${calendarId}"]`);
        if (!wrapper) return { valid: true, message: '' };
        
        const container = wrapper.closest('.codo-calendar-container, .codo-single-calendar');
        const fieldsWrapper = container ? container.querySelector('.codobuf-user-fields-wrapper') : null;
        
        if (!fieldsWrapper) return { valid: true, message: '' };
        
        const requiredInputs = fieldsWrapper.querySelectorAll('[required]');
        
        for (let input of requiredInputs) {
            if (input.type === 'checkbox') {
                if (!input.checked) {
                    const label = input.closest('.codobuf-field')?.querySelector('label')?.textContent || 'A required field';
                    return { valid: false, message: `${label.trim()} is required.` };
                }
            } else if (input.type === 'radio') {
                const name = input.name;
                const checked = fieldsWrapper.querySelector(`input[name="${name}"]:checked`);
                if (!checked) {
                    const label = input.closest('.codobuf-field')?.querySelector('label')?.textContent || 'A required field';
                    return { valid: false, message: `${label.trim()} is required.` };
                }
            } else {
                if (!input.value.trim()) {
                    const label = input.closest('.codobuf-field')?.querySelector('label')?.textContent || 'A required field';
                    return { valid: false, message: `${label.trim()} is required.` };
                }
            }
        }
        
        return { valid: true, message: '' };
    }
    
    /**
     * Initialize when CodoBookings is ready
     */
    function init() {
        if (!window.CodoBookings || !window.CodoBookings.registerHook) {
            setTimeout(init, 100);
            return;
        }

        // ✅ Use the hook system - much cleaner!
        window.CodoBookings.registerHook('beforeCreateBooking', function(payload, formData) {
            // Validate
            const validation = validateUserFields(payload.calendar_id);
            if (!validation.valid) {
                alert(validation.message);
                throw new Error(validation.message);
            }
            
            // Collect and append user fields
            const userFieldsData = collectUserFieldsData(payload.calendar_id);
            Object.keys(userFieldsData).forEach(key => {
                formData.append(key, userFieldsData[key]);
            });
        });

        // ✅ Clear fields after calendar reloads
        window.CodoBookings.registerHook('afterCalendarReload', function(calendarRoot) {
            clearUserFields(calendarRoot.dataset.calendarId);
        });
    }
    
    // Start initialization
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})(jQuery);