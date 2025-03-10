/**
 * WooCommerce Real-time Analytics Charts
 * Handles chart initialization and updates
 */
(function($) {
    'use strict';
    
    // Chart instances
    let conversionFunnelChart = null;
    let trafficOverTimeChart = null;
    
    // Initialize charts on the dashboard
    const initCharts = function(data) {
        if (!data || typeof Chart === 'undefined') {
            console.warn('WC Realtime Analytics: Chart.js or data not available');
            return;
        }
        
        try {
            initConversionFunnelChart(data);
        } catch (e) {
            console.error('WC Realtime Analytics: Error initializing charts', e);
        }
    };
    
    // Initialize conversion funnel chart
    const initConversionFunnelChart = function(data) {
        if (!data || !data.store) {
            return;
        }
        
        const ctx = document.getElementById('conversion-chart');
        
        if (!ctx) {
            return;
        }
        
        // Ensure all values are numbers
        const visitors = parseInt(data.store.visitors || 0, 10);
        const addToCart = parseInt(data.store.add_to_cart || 0, 10);
        const checkouts = parseInt(data.store.checkouts || 0, 10);
        const purchases = parseInt(data.store.purchases || 0, 10);
        
        const chartData = {
            labels: [
                wcRealtimeAdmin.locale.visitors || 'Visitors',
                wcRealtimeAdmin.locale.add_to_cart || 'Add to Cart',
                wcRealtimeAdmin.locale.checkout || 'Checkout',
                wcRealtimeAdmin.locale.purchase || 'Purchase'
            ],
            datasets: [{
                label: wcRealtimeAdmin.locale.visitors || 'Visitors',
                data: [visitors, addToCart, checkouts, purchases],
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
        if (conversionFunnelChart) {
            conversionFunnelChart.destroy();
        }
        
        // Create new chart
        conversionFunnelChart = new Chart(ctx, {
            type: 'bar',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: wcRealtimeAdmin.locale.conversion_funnel || 'Conversion Funnel'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    };
    
    // Update conversion funnel chart with new data
    const updateConversionFunnelChart = function(data) {
        if (!conversionFunnelChart || !data || !data.store) {
            return;
        }
        
        // Ensure all values are numbers
        const visitors = parseInt(data.store.visitors || 0, 10);
        const addToCart = parseInt(data.store.add_to_cart || 0, 10);
        const checkouts = parseInt(data.store.checkouts || 0, 10);
        const purchases = parseInt(data.store.purchases || 0, 10);
        
        try {
            conversionFunnelChart.data.datasets[0].data = [visitors, addToCart, checkouts, purchases];
            conversionFunnelChart.update();
        } catch (e) {
            console.error('WC Realtime Analytics: Error updating conversion funnel chart', e);
        }
    };
    
    // Initialize traffic over time chart
    const initTrafficOverTimeChart = function(data) {
        // Implementation for future use
    };
    
    // Update traffic over time chart with new data
    const updateTrafficOverTimeChart = function(data) {
        // Implementation for future use
    };
    
    // Validate chart data for security
    const validateChartData = function(data) {
        if (!data || typeof data !== 'object') {
            return false;
        }
        
        // Check for required properties
        if (!data.store || typeof data.store !== 'object') {
            return false;
        }
        
        return true;
    };
    
    // Helper function to safely parse integers
    const safeParseInt = function(value, defaultValue = 0) {
        const parsed = parseInt(value, 10);
        return isNaN(parsed) ? defaultValue : parsed;
    };
    
    // Public API
    window.WCRealtimeCharts = {
        init: function(data) {
            if (validateChartData(data)) {
                initCharts(data);
            } else {
                console.warn('WC Realtime Analytics: Invalid chart data');
            }
        },
        updateConversionFunnel: function(data) {
            if (validateChartData(data)) {
                updateConversionFunnelChart(data);
            } else {
                console.warn('WC Realtime Analytics: Invalid chart data for update');
            }
        }
    };
    
})(jQuery);