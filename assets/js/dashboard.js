/**
 * WooCommerce Real-time Analytics Dashboard
 * Handles the admin dashboard interface and real-time updates
 */
(function($) {
    'use strict';
    
    // Chart instance
    let conversionChart = null;
    
    // Pusher instance
    let pusher = null;
    
    // Timeframe state
    let currentTimeframe = 'today';
    let fromDate = '';
    let toDate = '';
    
    // Load initial events from server
    const loadInitialEvents = function() {
        $.ajax({
            url: wcRealtimeAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_realtime_get_initial_events',
                nonce: wcRealtimeAdmin.nonce,
                limit: 20 // Số lượng sự kiện ban đầu
            },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data && response.data.events) {
                    // Xóa trạng thái rỗng
                    $('#live-events-container .wc-realtime-empty-state').remove();
                    
                    // Thêm các sự kiện từ database
                    response.data.events.reverse().forEach(function(event) {
                        addLiveEvent(event.event_type, event);
                    });
                }
            },
            error: function() {
                console.warn('WC Realtime Analytics: Failed to load initial events');
            }
        });
    };
    
    // Initialize the dashboard
    const initDashboard = function() {
        // Initialize date pickers for custom range
        initDatePickers();
        
        // Set up event handlers
        setupEventHandlers();
        
        // Load initial statistics
        loadStats('today');
        
        // Load initial events
        loadInitialEvents();
        
        // Load saved events if available
        if (typeof wcRealtimeAdmin !== 'undefined' && wcRealtimeAdmin.last_events && wcRealtimeAdmin.last_events.length > 0) {
            loadSavedEvents(wcRealtimeAdmin.last_events);
        }
        
        // Initialize Pusher if configured
        if (typeof wcRealtimeAdmin !== 'undefined' && wcRealtimeAdmin.is_pusher_configured) {
            console.log('WC Realtime Analytics: Initializing Pusher connection');
            initPusher();
        } else {
            console.warn('WC Realtime Analytics: Pusher is not configured. Real-time updates will not work.');
            $('#live-events-container .wc-realtime-empty-state')
                .text('Real-time updates not available. Please configure Pusher in settings.')
                .css('color', '#F44336');
        }
    };
// Load saved events from database
const loadSavedEvents = function(events) {
    if (!events || !events.length) {
        return;
    }
    
    console.log('WC Realtime Analytics: Loading ' + events.length + ' saved events');
    
    // Clear any existing empty state
    const $container = $('#live-events-container');
    $container.find('.wc-realtime-empty-state').remove();
    
    // Process events in reverse order (oldest first)
    for (let i = events.length - 1; i >= 0; i--) {
        const event = events[i];
        
        // Format the event time
        const eventDate = new Date(event.created_at);
        const timeString = 
            ('0' + eventDate.getHours()).slice(-2) + ':' + 
            ('0' + eventDate.getMinutes()).slice(-2) + ':' + 
            ('0' + eventDate.getSeconds()).slice(-2);
        
        // Create event element based on event type
        let eventText = '';
        let eventClass = '';
        
        switch (event.event_type) {
            case 'visitor':
                eventText = event.product_id > 0 
                    ? 'Viewing product' + (event.product_name ? ': ' + escapeHtml(event.product_name) : (' #' + event.product_id))
                    : 'Page view';
                eventClass = 'visitor';
                break;
                
            case 'add_to_cart':
                eventText = 'Added to cart: ' + (event.product_name ? escapeHtml(event.product_name) : 'Product #' + event.product_id);
                eventClass = 'add-to-cart';
                break;
                
            case 'checkout':
                eventText = 'Started checkout';
                eventClass = 'checkout';
                break;
                
            case 'purchase':
                eventText = 'Completed purchase';
                if (event.order_id) {
                    eventText += ': Order #' + event.order_id;
                }
                eventClass = 'purchase';
                break;
                
            default:
                eventText = 'Unknown event: ' + event.event_type;
                eventClass = 'unknown';
                break;
        }
        
        // Add country info if available
        if (event.country_name) {
            eventText += ' from ' + escapeHtml(event.country_name);
        } else if (event.country_code) {
            eventText += ' from ' + escapeHtml(event.country_code);
        }
        
        // Create the event HTML
        const eventHtml = `
            <div class="wc-realtime-event wc-realtime-event-${eventClass}">
                <span class="wc-realtime-event-time">${timeString}</span>
                <span class="wc-realtime-event-icon"></span>
                <span class="wc-realtime-event-text">${eventText}</span>
            </div>
        `;
        
        // Add to container
        $container.prepend(eventHtml);
    }
};

// Initialize date pickers
const initDatePickers = function() {
    if (typeof $.datepicker === 'undefined') {
        console.warn('WC Realtime Analytics: jQuery datepicker not available');
        return;
    }
    
    try {
        $('#wc-realtime-date-from, #wc-realtime-date-to').datepicker({
            dateFormat: 'yy-mm-dd',
            maxDate: 0, // Can't select future dates
            changeMonth: true,
            changeYear: true
        });
        
        // Set default values to today
        const today = new Date();
        const formattedDate = today.getFullYear() + '-' + 
                            (('0' + (today.getMonth() + 1)).slice(-2)) + '-' + 
                            ('0' + today.getDate()).slice(-2);
        
        $('#wc-realtime-date-from, #wc-realtime-date-to').val(formattedDate);
    } catch (e) {
        console.error('WC Realtime Analytics: Error initializing datepicker', e);
    }
};

// Set up event handlers
const setupEventHandlers = function() {
    // Handle timeframe changes
    $('#wc-realtime-timeframe').on('change', function() {
        const timeframe = $(this).val();
        currentTimeframe = timeframe;
        
        // Show/hide custom date range inputs
        if (timeframe === 'custom') {
            $('#wc-realtime-custom-range').show();
        } else {
            $('#wc-realtime-custom-range').hide();
            loadStats(timeframe);
        }
    });
    
    // Handle custom range apply button
    $('#wc-realtime-apply-range').on('click', function() {
        fromDate = $('#wc-realtime-date-from').val();
        toDate = $('#wc-realtime-date-to').val();
        
        // Validate dates
        if (!fromDate || !toDate) {
            alert(wcRealtimeAdmin.locale.error_missing_dates || 'Please select both start and end dates');
            return;
        }
        
        // Validate date format
        const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
        if (!dateRegex.test(fromDate) || !dateRegex.test(toDate)) {
            alert(wcRealtimeAdmin.locale.error_invalid_date_format || 'Invalid date format. Please use YYYY-MM-DD');
            return;
        }
        
        // Check if from date is before to date
        if (new Date(fromDate) > new Date(toDate)) {
            alert(wcRealtimeAdmin.locale.error_date_range || 'Start date must be before end date');
            return;
        }
        
        loadStats('custom', fromDate, toDate);
    });
};

// Initialize Pusher for real-time updates
const initPusher = function() {
    if (typeof Pusher === 'undefined') {
        console.warn('WC Realtime Analytics: Pusher library not loaded');
        $('#live-events-container .wc-realtime-empty-state')
            .text('Real-time updates not available. Pusher library not loaded.')
            .css('color', '#F44336');
        return;
    }
    
    try {
        console.log('WC Realtime Analytics: Attempting to connect to Pusher with key:', wcRealtimeAdmin.pusher_key, 'cluster:', wcRealtimeAdmin.pusher_cluster);
        
        // Create Pusher instance with debug enabled
        pusher = new Pusher(wcRealtimeAdmin.pusher_key, {
            cluster: wcRealtimeAdmin.pusher_cluster,
            encrypted: true,
            enabledTransports: ['ws', 'wss'],
            debug: true
        });
        
        // Subscribe to analytics channel
        const channel = pusher.subscribe('wc-analytics');
        
        // Log channel subscription
        console.log('WC Realtime Analytics: Subscribed to channel wc-analytics');
        
        // Check for successful connection
        pusher.connection.bind('connected', function() {
            console.log('WC Realtime Analytics: Connected to Pusher successfully');
            // Show connection status message if no events are displayed yet
            if ($('#live-events-container .wc-realtime-event').length === 0) {
                $('#live-events-container .wc-realtime-empty-state')
                    .text('Connected! Waiting for events...')
                    .css('color', '#4CAF50');
            }
        });
        
        // Check for connection errors
        pusher.connection.bind('error', function(err) {
            console.error('WC Realtime Analytics: Pusher connection error', err);
            // Show error message
            $('#live-events-container .wc-realtime-empty-state')
                .text('Error connecting to real-time service: ' + (err.message || err.data?.message || 'Unknown error'))
                .css('color', '#F44336');
        });
        
        // Log all events for debugging
        pusher.connection.bind('state_change', function(states) {
            console.log('WC Realtime Analytics: Pusher connection state changed from', 
                states.previous, 'to', states.current);
        });
        
        // Listen for visitor events
        channel.bind('visitor', function(data) {
            console.log('WC Realtime Analytics: Received visitor event', data);
            addLiveEvent('visitor', data);
            incrementCounter('store-visitors');
        });
        
        // Listen for add_to_cart events
        channel.bind('add_to_cart', function(data) {
            console.log('WC Realtime Analytics: Received add_to_cart event', data);
            addLiveEvent('add_to_cart', data);
            incrementCounter('store-add-to-cart');
        });
        
        // Listen for checkout events
        channel.bind('checkout', function(data) {
            console.log('WC Realtime Analytics: Received checkout event', data);
            addLiveEvent('checkout', data);
            incrementCounter('store-checkout');
        });
        
        // Listen for purchase events
        channel.bind('purchase', function(data) {
            console.log('WC Realtime Analytics: Received purchase event', data);
            addLiveEvent('purchase', data);
            incrementCounter('store-purchase');
        });
        
        // Listen for test events
        channel.bind('test', function(data) {
            console.log('WC Realtime Analytics: Received test event', data);
            addLiveEvent('test', data);
        });
        
        // Log when an event binding is successful
        channel.bind('pusher:subscription_succeeded', function() {
            console.log('WC Realtime Analytics: Successfully subscribed to channel events');
        });
        
        // Log subscription errors
        channel.bind('pusher:subscription_error', function(error) {
            console.error('WC Realtime Analytics: Channel subscription error', error);
        });
        
    } catch (e) {
        console.error('WC Realtime Analytics: Error initializing Pusher', e);
        // Show error message
        $('#live-events-container .wc-realtime-empty-state')
            .text('Error initializing real-time service: ' + e.message)
            .css('color', '#F44336');
    }
};

// Add a live event to the dashboard
const addLiveEvent = function(eventType, data) {
    if (!data) {
        console.warn('WC Realtime Analytics: Empty event data received');
        return;
    }
    
    const $container = $('#live-events-container');
    console.log('WC Realtime Analytics: Adding event to container', $container.length ? 'found' : 'not found');
    
    // Remove empty state if present
    $container.find('.wc-realtime-empty-state').remove();
    
    // Format the event time
    const now = new Date();
    const timeString = 
        ('0' + now.getHours()).slice(-2) + ':' + 
        ('0' + now.getMinutes()).slice(-2) + ':' + 
        ('0' + now.getSeconds()).slice(-2);
    
    // Create event element based on event type
    let eventText = '';
    let eventClass = '';
    
    console.log('WC Realtime Analytics: Processing event type', eventType);
    
    switch (eventType) {
        case 'visitor':
            eventText = data.product_id > 0 
                ? 'Viewing product' + (data.product_name ? ': ' + escapeHtml(data.product_name) : (' #' + data.product_id))
                : 'Page view';
            eventClass = 'visitor';
            break;
            
        case 'add_to_cart':
            eventText = 'Added to cart: ' + (data.product_name ? escapeHtml(data.product_name) : 'Product #' + data.product_id);
            if (data.quantity && data.quantity > 1) {
                eventText += ' (Qty: ' + data.quantity + ')';
            }
            eventClass = 'add-to-cart';
            break;
            
        case 'checkout':
            eventText = 'Started checkout';
            if (data.items && data.items.length) {
                eventText += ' with ' + data.items.length + ' products';
            }
            eventClass = 'checkout';
            break;
            
        case 'purchase':
            eventText = 'Completed purchase';
            if (data.order_id) {
                eventText += ': Order #' + data.order_id;
            }
            if (data.order_total) {
                eventText += ' - ' + formatMoney(data.order_total);
            }
            eventClass = 'purchase';
            break;
            
        case 'test':
            eventText = 'Test event';
            if (data.message) {
                eventText += ': ' + escapeHtml(data.message);
            }
            eventClass = 'test';
            break;
            
        default:
            eventText = 'Unknown event: ' + eventType;
            eventClass = 'unknown';
            break;
    }
    
    // Add country info if available
    if (data.country_name) {
        eventText += ' from ' + escapeHtml(data.country_name);
    } else if (data.country_code) {
        eventText += ' from ' + escapeHtml(data.country_code);
    }
    
    console.log('WC Realtime Analytics: Event text prepared', eventText);
    
    // Create the event HTML
    const eventHtml = `
        <div class="wc-realtime-event wc-realtime-event-${eventClass} wc-realtime-event-new">
            <span class="wc-realtime-event-time">${timeString}</span>
            <span class="wc-realtime-event-icon"></span>
            <span class="wc-realtime-event-text">${eventText}</span>
        </div>
    `;
    
    // Add to container
    $container.prepend(eventHtml);
    console.log('WC Realtime Analytics: Event added to container');
    
    // Remove new class after animation
    setTimeout(function() {
        $container.find('.wc-realtime-event-new').removeClass('wc-realtime-event-new');
    }, 2000);
    
    // Limit to 20 events
    if ($container.find('.wc-realtime-event').length > 20) {
        $container.find('.wc-realtime-event').last().remove();
    }
};

// Helper function to format money values
const formatMoney = function(amount) {
    // Check if wcRealtimeAdmin.currency_format exists, otherwise use a default format
    if (typeof wcRealtimeAdmin !== 'undefined' && wcRealtimeAdmin.currency_format) {
        return wcRealtimeAdmin.currency_format.replace('%s', amount);
    }
    return '$' + parseFloat(amount).toFixed(2);
};

// Increment a counter on the dashboard
const incrementCounter = function(counterId) {
    const $counter = $('#' + counterId);
    if (!$counter.length) {
        return;
    }
    
    let currentValue = parseInt($counter.text(), 10);
    if (isNaN(currentValue)) {
        currentValue = 0;
    }
    
    $counter.text(currentValue + 1);
    
    // Animate the counter
    $counter.addClass('wc-realtime-counter-updated');
        setTimeout(function() {
            $counter.removeClass('wc-realtime-counter-updated');
        }, 1000);
        
        // Recalculate rates
        if (counterId !== 'store-visitors') {
            calculateRates();
        }
    };
    
    // Calculate conversion rates
    const calculateRates = function() {
        const visitors = getCounterValue('store-visitors');
        const addToCart = getCounterValue('store-add-to-cart');
        const checkout = getCounterValue('store-checkout');
        const purchase = getCounterValue('store-purchase');
        
        // Calculate rates
        const atcRate = visitors > 0 ? ((addToCart / visitors) * 100).toFixed(2) : '0.00';
        const checkoutRate = addToCart > 0 ? ((checkout / addToCart) * 100).toFixed(2) : '0.00';
        const purchaseRate = checkout > 0 ? ((purchase / checkout) * 100).toFixed(2) : '0.00';
        
        // Update displayed rates
        $('#store-atc-rate').text(atcRate + '%');
        $('#store-checkout-rate').text(checkoutRate + '%');
        $('#store-purchase-rate').text(purchaseRate + '%');
        
        // Update chart if it exists
        if (conversionChart) {
            conversionChart.data.datasets[0].data = [visitors, addToCart, checkout, purchase];
            conversionChart.update();
        }
    };
    
    // Helper function to get counter value
    const getCounterValue = function(counterId) {
        const $counter = $('#' + counterId);
        if (!$counter.length) {
            return 0;
        }
        
        const value = parseInt($counter.text(), 10);
        return isNaN(value) ? 0 : value;
    };
    
    // Load statistics from the server
    const loadStats = function(timeframe, fromDate, toDate) {
        // Show loading indicators
        showLoading();
        
        // Prepare request data
        const data = {
            action: 'wc_realtime_get_stats',
            nonce: wcRealtimeAdmin.nonce,
            timeframe: timeframe
        };
        
        // Add custom date range if provided
        if (timeframe === 'custom' && fromDate && toDate) {
            data.from_date = fromDate;
            data.to_date = toDate;
        }
        
        // Make AJAX request
        $.ajax({
            url: wcRealtimeAdmin.ajax_url,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    updateDashboard(response.data);
                } else {
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : wcRealtimeAdmin.locale.error || 'An error occurred';
                    showError(errorMsg);
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = wcRealtimeAdmin.locale.error || 'Failed to connect to the server';
                
                // Try to get more specific error message
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                
                showError(errorMsg);
                console.error('WC Realtime Analytics: AJAX Error', status, error);
            }
        });
    };
    
    // Show loading indicators
    const showLoading = function() {
        $('#products-table tbody, #countries-table tbody').html(
            '<tr><td colspan="6" class="wc-realtime-loading">' + 
            (wcRealtimeAdmin.locale.loading || 'Loading...') + 
            '</td></tr>'
        );
    };
    
    // Show error message
    const showError = function(message) {
        $('#products-table tbody, #countries-table tbody').html(
            '<tr><td colspan="6" class="wc-realtime-error">' + escapeHtml(message) + '</td></tr>'
        );
    };
    
    // Update dashboard with new statistics
    const updateDashboard = function(data) {
        if (!data) {
            showError(wcRealtimeAdmin.locale.error || 'Invalid data received');
            return;
        }
        
        // Update store overview
        updateStoreOverview(data.store);
        
        // Update products table
        updateProductsTable(data.products);
        
        // Update countries table
        updateCountriesTable(data.countries);
        
        // Update conversion funnel chart
        updateConversionChart(data.store);
    };
    
    // Update store overview section
    const updateStoreOverview = function(storeData) {
        if (!storeData) {
            return;
        }
        
        // Update counters
        $('#store-visitors').text(parseInt(storeData.visitors || 0, 10));
        $('#store-add-to-cart').text(parseInt(storeData.add_to_cart || 0, 10));
        $('#store-checkout').text(parseInt(storeData.checkouts || 0, 10));
        $('#store-purchase').text(parseInt(storeData.purchases || 0, 10));
        
        // Update rates
        $('#store-atc-rate').text((parseFloat(storeData.atc_rate || 0)).toFixed(2) + '%');
        $('#store-checkout-rate').text((parseFloat(storeData.checkout_rate || 0)).toFixed(2) + '%');
        $('#store-purchase-rate').text((parseFloat(storeData.purchase_rate || 0)).toFixed(2) + '%');
    };
    
    // Update products table
    const updateProductsTable = function(productsData) {
        const $tbody = $('#products-table tbody');
        
        // Clear table
        $tbody.empty();
        
        // Check if we have data
        if (!productsData || productsData.length === 0) {
            $tbody.html('<tr><td colspan="6" class="wc-realtime-empty">' + 
                       (wcRealtimeAdmin.locale.no_data || 'No data available for this period') + 
                       '</td></tr>');
            return;
        }
        
        // Add rows for each product
        $.each(productsData, function(index, product) {
            const productName = product.name ? escapeHtml(product.name) : 'Product #' + product.product_id;
            
            const row = `
                <tr>
                    <td>${parseInt(product.product_id || 0, 10)}</td>
                    <td>${productName}</td>
                    <td>${parseInt(product.visitors || 0, 10)}</td>
                    <td>${parseInt(product.add_to_cart || 0, 10)}</td>
                    <td>${parseInt(product.checkouts || 0, 10)}</td>
                    <td>${parseInt(product.purchases || 0, 10)}</td>
                </tr>
            `;
            
            $tbody.append(row);
        });
    };
    
    // Update countries table
    const updateCountriesTable = function(countriesData) {
        const $tbody = $('#countries-table tbody');
        
        // Clear table
        $tbody.empty();
        
        // Check if we have data
        if (!countriesData || countriesData.length === 0) {
            $tbody.html('<tr><td colspan="6" class="wc-realtime-empty">' + 
                       (wcRealtimeAdmin.locale.no_data || 'No data available for this period') + 
                       '</td></tr>');
            return;
        }
        
        // Add rows for each country
        $.each(countriesData, function(index, country) {
            const countryName = country.country_name 
                ? escapeHtml(country.country_name) 
                : 'Unknown';
            
            const countryCode = country.country_code 
                ? ' (' + escapeHtml(country.country_code) + ')' 
                : '';
            
            const row = `
                <tr>
                    <td>${index + 1}</td>
                    <td>${countryName}${countryCode}</td>
                    <td>${parseInt(country.visitors || 0, 10)}</td>
                    <td>${parseInt(country.add_to_cart || 0, 10)}</td>
                    <td>${parseInt(country.checkouts || 0, 10)}</td>
                    <td>${parseInt(country.purchases || 0, 10)}</td>
                </tr>
            `;
            
            $tbody.append(row);
        });
    };
    
    // Update conversion funnel chart
    const updateConversionChart = function(storeData) {
        const ctx = document.getElementById('conversion-chart');
        
        if (!ctx || !storeData) {
            return;
        }
        
        // Prepare chart data
        const chartData = {
            labels: [
                wcRealtimeAdmin.locale.visitors || 'Visitors',
                wcRealtimeAdmin.locale.add_to_cart || 'Add to Cart',
                wcRealtimeAdmin.locale.checkout || 'Checkout',
                wcRealtimeAdmin.locale.purchase || 'Purchase'
            ],
            datasets: [{
                label: wcRealtimeAdmin.locale.visitors || 'Visitors',
                data: [
                    parseInt(storeData.visitors || 0, 10),
                    parseInt(storeData.add_to_cart || 0, 10),
                    parseInt(storeData.checkouts || 0, 10),
                    parseInt(storeData.purchases || 0, 10)
                ],
                backgroundColor: [
                    'rgba(54, 162, 235, 0.6)',
                    'rgba(255, 159, 64, 0.6)',
                    'rgba(75, 192, 192, 0.6)',
                    'rgba(153, 102, 255, 0.6)'
                ],
                borderColor: [
                    'rgba(54, 162, 235, 1)',
                    'rgba(255, 159, 64, 1)',
                    'rgba(75, 192, 192, 1)',
                    'rgba(153, 102, 255, 1)'
                ],
                borderWidth: 1
            }]
        };
        
        // Destroy existing chart if it exists
        if (conversionChart) {
            conversionChart.destroy();
        }
        
        // Create new chart
        try {
            conversionChart = new Chart(ctx, {
                type: 'bar',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        } catch (e) {
            console.error('WC Realtime Analytics: Error creating chart', e);
        }
    };
    
    // Helper function to escape HTML
    const escapeHtml = function(str) {
        if (!str) {
            return '';
        }
        
        const entityMap = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
            '/': '&#x2F;',
            '`': '&#x60;',
            '=': '&#x3D;'
        };
        
        return String(str).replace(/[&<>"'`=\/]/g, function(s) {
            return entityMap[s];
        });
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        console.log('WC Realtime Analytics: Document ready, initializing dashboard');
        initDashboard();
    });
    
})(jQuery);