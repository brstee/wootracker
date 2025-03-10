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
    
    // Initialize the dashboard
    const initDashboard = function() {
        // Initialize date pickers for custom range
        initDatePickers();
        
        // Set up event handlers
        setupEventHandlers();
        
        // Load initial statistics
        loadStats('today');
        
        // Initialize Pusher if configured
        if (typeof wcRealtimeAdmin !== 'undefined' && wcRealtimeAdmin.is_pusher_configured) {
            initPusher();
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
            console.warn('WC Realtime Analytics: Pusher not available');
            return;
        }
        
        try {
            // Create Pusher instance
            pusher = new Pusher(wcRealtimeAdmin.pusher_key, {
                cluster: wcRealtimeAdmin.pusher_cluster,
                encrypted: true
            });
            
            // Subscribe to analytics channel
            const channel = pusher.subscribe('wc-analytics');
            
            // Check for successful connection
            pusher.connection.bind('connected', function() {
                console.log('WC Realtime Analytics: Connected to Pusher');
            });
            
            // Check for connection errors
            pusher.connection.bind('error', function(err) {
                console.error('WC Realtime Analytics: Pusher connection error', err);
            });
            
            // Listen for events
            channel.bind('visitor', function(data) {
                addLiveEvent('visitor', data);
                incrementCounter('store-visitors');
            });
            
            channel.bind('add_to_cart', function(data) {
                addLiveEvent('add_to_cart', data);
                incrementCounter('store-add-to-cart');
            });
            
            channel.bind('checkout', function(data) {
                addLiveEvent('checkout', data);
                incrementCounter('store-checkout');
            });
            
            channel.bind('purchase', function(data) {
                addLiveEvent('purchase', data);
                incrementCounter('store-purchase');
            });
        } catch (e) {
            console.error('WC Realtime Analytics: Error initializing Pusher', e);
        }
    };
    
    // Add a live event to the dashboard
    const addLiveEvent = function(eventType, data) {
        if (!data) {
            return;
        }
        
        const $container = $('#live-events-container');
        
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
        
        switch (eventType) {
            case 'visitor':
                eventText = data.product_id > 0 
                    ? 'Product view' + (data.product_name ? ': ' + escapeHtml(data.product_name) : '')
                    : 'Page view';
                eventClass = 'visitor';
                break;
                
            case 'add_to_cart':
                eventText = 'Add to cart: ' + (data.product_name ? escapeHtml(data.product_name) : 'Product #' + data.product_id);
                eventClass = 'add-to-cart';
                break;
                
            case 'checkout':
                eventText = 'Checkout started';
                eventClass = 'checkout';
                break;
                
            case 'purchase':
                eventText = 'Purchase completed: Order #' + (data.order_id || '');
                eventClass = 'purchase';
                break;
                
            default:
                eventText = 'Unknown event';
                eventClass = 'unknown';
        }
        
        // Add country info if available
        if (data.country_name) {
            eventText += ' from ' + escapeHtml(data.country_name);
        }
        
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
        
        // Remove new class after animation
        setTimeout(function() {
            $container.find('.wc-realtime-event-new').removeClass('wc-realtime-event-new');
        }, 2000);
        
        // Limit to 20 events
        if ($container.find('.wc-realtime-event').length > 20) {
            $container.find('.wc-realtime-event').last().remove();
        }
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
        initDashboard();
    });
    
})(jQuery);