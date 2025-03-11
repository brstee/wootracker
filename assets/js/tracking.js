/**
 * WooCommerce Real-time Analytics Tracking Script
 * This script handles client-side tracking that works even with cached pages
 */
(function($) {
    'use strict';
    
    // Initialize Pusher
    let pusher = null;
    
    // Initialize tracking variables
    let hasTrackedPageView = false;
    let attemptedTrackEvent = false;
    let trackingInProgress = false;
    let trackedEvents = {
        visitor: false,
        add_to_cart: {},
        checkout: false,
        purchase: false
    };
    
    // Initialize tracking
    const initTracking = function() {
        if (typeof wcRealtimeConfig === 'undefined') {
            console.warn('WC Realtime Analytics: Configuration missing');
            return;
        }
        
        // Initialize Pusher if configured
        if (wcRealtimeConfig.pusher_key && wcRealtimeConfig.pusher_cluster) {
            try {
                pusher = new Pusher(wcRealtimeConfig.pusher_key, {
                    cluster: wcRealtimeConfig.pusher_cluster,
                    encrypted: true
                });
                
                // Check for connection errors
                pusher.connection.bind('error', function(err) {
                    console.error('WC Realtime Analytics: Pusher connection error', err);
                });
            } catch (e) {
                console.error('WC Realtime Analytics: Error initializing Pusher', e);
            }
        }
        
        // Track initial page view (only once per session)
        trackPageView();
        
        // Set up event listeners
        setupEventListeners();
    };
    
    // Track page view
    const trackPageView = function() {
        if (hasTrackedPageView || trackingInProgress || trackedEvents.visitor) {
            return;
        }
        
        trackingInProgress = true;
        
        const data = {
            action: 'wc_realtime_track',
            nonce: wcRealtimeConfig.nonce,
            event_type: 'visitor',
            session_id: wcRealtimeConfig.session_id
        };
        
        // If on product page, include product ID
        if (wcRealtimeConfig.is_product && wcRealtimeConfig.product_id > 0) {
            data.product_id = parseInt(wcRealtimeConfig.product_id, 10);
        }
        
        $.ajax({
            url: wcRealtimeConfig.ajax_url,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                hasTrackedPageView = true;
                trackingInProgress = false;
                
                // Mark visitor as tracked
                if (response.success) {
                    trackedEvents.visitor = true;
                }
            },
            error: function(xhr, status, error) {
                console.error('WC Realtime Analytics: Error tracking page view', status, error);
                trackingInProgress = false;
                
                // Retry once if tracking fails
                if (!attemptedTrackEvent) {
                    attemptedTrackEvent = true;
                    setTimeout(trackPageView, 2000);
                }
            }
        });
    };
    
    // Set up event listeners for user actions
    const setupEventListeners = function() {
        // Track add to cart button clicks with a flag to avoid double tracking
        let addToCartInProgress = false;
        
        $(document.body).on('click', '.add_to_cart_button, .single_add_to_cart_button', function(e) {
            // Ngăn chặn tracking trùng lặp
            if (addToCartInProgress) {
                return;
            }
            
            addToCartInProgress = true;
            setTimeout(function() { addToCartInProgress = false; }, 2000); // reset flag after 2 seconds
            
            const $button = $(this);
            let productId = $button.data('product_id');
            
            // On single product pages, get product ID from form
            if (!productId && $('.single_add_to_cart_button').length) {
                productId = $('input[name="product_id"]').val();
                if (!productId) {
                    productId = $('.single_add_to_cart_button').val();
                }
            }
            
            if (productId) {
                // Convert to integer to ensure it's a valid ID
                productId = parseInt(productId, 10);
                
                if (productId > 0) {
 // Check if this product has already been tracked in this session
 if (trackedEvents.add_to_cart[productId]) {
    addToCartInProgress = false;
    return;
}

// Handle tracking immediately for AJAX add to cart
trackEvent('add_to_cart', productId);
}
}
});

// Add a handler for WooCommerce AJAX complete to prevent duplicate tracking
$(document).ajaxComplete(function(event, xhr, settings) {
if (settings.url && settings.url.indexOf('wc-ajax=add_to_cart') > -1) {
// This is handled by our click handler already
addToCartInProgress = false;
}
});
};

// Generic event tracker
const trackEvent = function(eventType, productId) {
if (trackingInProgress) {
return;
}

// Check if this event has already been tracked
if (eventType === 'checkout' && trackedEvents.checkout) {
return;
} else if (eventType === 'purchase' && trackedEvents.purchase) {
return;
} else if (eventType === 'add_to_cart' && productId > 0 && trackedEvents.add_to_cart[productId]) {
return;
}

trackingInProgress = true;

// Validate event type
const validEvents = ['visitor', 'add_to_cart', 'checkout', 'purchase'];
if (validEvents.indexOf(eventType) === -1) {
console.error('WC Realtime Analytics: Invalid event type', eventType);
trackingInProgress = false;
return;
}

// Ensure product ID is an integer
if (productId) {
productId = parseInt(productId, 10);
} else {
productId = 0;
}

const data = {
action: 'wc_realtime_track',
nonce: wcRealtimeConfig.nonce,
event_type: eventType,
session_id: wcRealtimeConfig.session_id,
product_id: productId
};

$.ajax({
url: wcRealtimeConfig.ajax_url,
type: 'POST',
data: data,
dataType: 'json',
success: function(response) {
trackingInProgress = false;

// Mark as tracked if successful
if (response.success) {
if (eventType === 'checkout') {
    trackedEvents.checkout = true;
} else if (eventType === 'purchase') {
    trackedEvents.purchase = true;
} else if (eventType === 'add_to_cart' && productId > 0) {
    trackedEvents.add_to_cart[productId] = true;
}
}
},
error: function(xhr, status, error) {
console.error('WC Realtime Analytics: Error tracking event', eventType, status, error);
trackingInProgress = false;
}
});
};

// Initialize when document is ready
$(document).ready(function() {
// Delay initialization slightly to ensure page is fully loaded
setTimeout(initTracking, 100);
});

})(jQuery);