// Check if jQuery is loaded
if (typeof jQuery === 'undefined') {
    console.error('jQuery is not loaded!');
} else {
    console.log('jQuery version:', jQuery.fn.jquery);
}

jQuery(document).ready(function($) {
    console.log('WP OTel Admin JS loaded');
    console.log('wpotel_admin object:', typeof wpotel_admin !== 'undefined' ? wpotel_admin : 'Not loaded');
    
    // Check if ajaxurl is available
    if (typeof ajaxurl === 'undefined') {
        console.error('ajaxurl is not defined!');
        // Use wpotel_admin.ajax_url as fallback
        window.ajaxurl = wpotel_admin ? wpotel_admin.ajax_url : '/wp-admin/admin-ajax.php';
    }
    
    // Debug: Check if elements exist
    console.log('Nav tabs found:', $('.nav-tab').length);
    console.log('Tab content found:', $('.wpotel-tab-content').length);
    console.log('License button found:', $('#activate-license').length);
    console.log('License input found:', $('#wpotel_license_key').length);
    
    // Tab navigation
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        console.log('Tab clicked:', $(this).attr('href'));
        
        // Remove active class from all tabs
        $('.nav-tab').removeClass('nav-tab-active');
        
        // Add active class to clicked tab
        $(this).addClass('nav-tab-active');
        
        // Hide all tab content
        $('.wpotel-tab-content').hide();
        
        // Show the target tab content
        var target = $(this).attr('href');
        console.log('Showing tab:', target);
        $(target).show();
        
        // Update URL hash without jumping
        if (history.pushState) {
            history.pushState(null, null, target);
        } else {
            location.hash = target;
        }
    });
    
    // Debug: Check initial state
    console.log('Current hash:', window.location.hash);
    
    // Show initial tab based on URL hash or default to first tab
    var hash = window.location.hash || '#settings';
    console.log('Initial tab:', hash);
    
    // Manually trigger initial tab display
    if (!hash || hash === '') {
        $('#settings').show();
        $('.nav-tab[href="#settings"]').addClass('nav-tab-active');
    } else {
        $('.wpotel-tab-content').hide();
        $(hash).show();
        $('.nav-tab[href="' + hash + '"]').addClass('nav-tab-active');
    }
    
    // License activation - try both event handlers
    $('#activate-license').on('click', function(e) {
        console.log('Direct click handler fired');
        e.preventDefault();
        activateLicense();
    });
    
    $(document).on('click', '#activate-license', function(e) {
        console.log('Delegated click handler fired');
        e.preventDefault();
        activateLicense();
    });
    
    function activateLicense() {
        console.log('Activate license function called');
        var button = $('#activate-license');
        var key = $('#wpotel_license_key').val();
        var result = $('#license-result');
        
        console.log('License key:', key);
        console.log('Ajax URL:', wpotel_admin.ajax_url);
        console.log('Nonce:', wpotel_admin.nonce);
        
        if (!key) {
            result.html('<span style="color: red;">Please enter a license key</span>');
            return;
        }
        
        button.prop('disabled', true);
        result.html('<span style="color: blue;">Activating...</span>');
        
        $.ajax({
            url: wpotel_admin.ajax_url || ajaxurl,
            type: 'POST',
            data: {
                action: 'wpotel_activate_license',
                license_key: key,
                _ajax_nonce: wpotel_admin.nonce
            },
            success: function(response) {
                console.log('License activation response:', response);
                if (response.success) {
                    result.html('<span style="color: green;">License activated!</span>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    result.html('<span style="color: red;">' + response.data + '</span>');
                }
                button.prop('disabled', false);
            },
            error: function(xhr, status, error) {
                console.error('License activation failed:', error);
                console.error('Status:', status);
                console.error('Response:', xhr.responseText);
                result.html('<span style="color: red;">Error: ' + error + '</span>');
                button.prop('disabled', false);
            }
        });
    }
});