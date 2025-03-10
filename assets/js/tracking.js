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
        
        // Track initial page view
        trackPageView();
        
        // Set up event listeners
        setupEventListeners();
    };
    
    // Track page view
    const trackPageView = function() {
        if (hasTrackedPageView || trackingInProgress) {
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
        // Track add to cart button clicks
        $(document.body).on('click', '.add_to_cart_button, .single_add_to_cart_button', function(e) {
            // We don't need to track this event via JS since it will be tracked server-side
            // This is just a backup in case server-side tracking fails
            
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
                    // Delay tracking to allow WooCommerce's own handlers to fire first
                    setTimeout(function() {
                        trackEvent('add_to_cart', productId);
                    }, 500);
                }
            }
        });
        
        // Track checkout button clicks
        $(document.body).on('click', '.checkout-button, .wc-proceed-to-checkout a', function(e) {
            // Just a backup for the server-side tracking
            trackEvent('checkout', 0);
        });
        
        // Track events from WooCommerce's AJAX handlers
        $(document.body).on('added_to_cart', function(event, fragments, cart_hash, $button) {
            // This event is triggered by WooCommerce after an item is added to the cart
            if ($button && $button.data('product_id')) {
                const productId = parseInt($button.data('product_id'), 10);
                if (productId > 0) {
                    trackEvent('add_to_cart', productId);
                }
            }
        });
    };
    
    // Generic event tracker
    const trackEvent = function(eventType, productId) {
        if (trackingInProgress) {
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