/**
 * WooCommerce Subscriptions Troubleshooter Admin Scripts
 */

( function( $ ) {
    'use strict';

    const WCST = {
        currentSubscriptionId: null,
        analysisData: null,

        init: function() {
            this.bindEvents();
            this.initializeInterface();
        },

        bindEvents: function() {
            // Search input events
            $( '#wcst-subscription-search' )
                .on( 'input', this.handleSearchInput.bind( this ) )
                .on( 'keypress', this.handleSearchKeypress.bind( this ) );
            $( document ).on( 'click', '.wcst-search-result-item', this.handleSearchResultClick.bind( this ) );
            
            // Enhanced detection tab switching
            $( document ).on( 'click', '.wcst-enhanced-tab', this.handleEnhancedTabClick.bind( this ) );
            
            // Main analysis tab switching
            $( document ).on( 'click', '.wcst-main-tab', this.handleMainTabClick.bind( this ) );
        },

        initializeInterface: function() {
            // Initialize any default states
            $( '#wcst-results' ).hide();
            $( '#wcst-progress' ).hide();
            
            // Check if we should auto-analyze a subscription
            if ( wcst_ajax.auto_analyze_id ) {
                this.autoAnalyzeSubscription( wcst_ajax.auto_analyze_id );
            }
        },

        autoAnalyzeSubscription: function( subscriptionId ) {
            // Pre-fill the search input
            $( '#wcst-subscription-search' ).val( subscriptionId );
            
            // Show progress indicator
            $( '#wcst-progress' ).show();
            
            // Start analysis automatically
            this.analyzeSubscription( subscriptionId );
        },



        handleSearchInput: function( e ) {
            const searchTerm = $( e.target ).val().trim();
            
            if ( searchTerm.length < 2 ) {
                $( '#wcst-search-results' ).hide();
                return;
            }
            
            // Debounce search
            clearTimeout( this.searchTimeout );
            this.searchTimeout = setTimeout( () => {
                this.searchSubscriptions( searchTerm );
            }, 300 );
        },

        handleSearchKeypress: function( e ) {
            if ( 13 === e.which ) { // Enter key
                const subscriptionId = $( '#wcst-subscription-search' ).val().trim();
                if ( subscriptionId ) {
                    this.analyzeSubscription( subscriptionId );
                }
            }
        },

        handleSearchResultClick: function( e ) {
            const subscriptionId = $( e.currentTarget ).data( 'id' );
            $( '#wcst-subscription-search' ).val( subscriptionId );
            $( '#wcst-search-results' ).hide();
            // Automatically start analysis when clicking a search result
            this.analyzeSubscription( subscriptionId );
        },

        handleEnhancedTabClick: function( e ) {
            e.preventDefault();
            
            const $tab = $( e.currentTarget );
            const tabId = $tab.data( 'tab' );
            
            // Update active tab
            $( '.wcst-enhanced-tab' ).removeClass( 'active' );
            $tab.addClass( 'active' );
            
            // Update active panel
            $( '.wcst-enhanced-tab-panel' ).removeClass( 'active' );
            $( '#' + tabId ).addClass( 'active' );
        },

        handleMainTabClick: function( e ) {
            e.preventDefault();
            
            const $tab = $( e.currentTarget );
            const tabId = $tab.data( 'tab' );
            
            // Update active tab
            $( '.wcst-main-tab' ).removeClass( 'active' );
            $tab.addClass( 'active' );
            
            // Update active panel
            $( '.wcst-main-tab-panel' ).removeClass( 'active' );
            $( '#' + tabId ).addClass( 'active' );
        },

        

        analyzeSubscription: function( subscriptionId ) {
            this.showProgress();
            this.currentSubscriptionId = subscriptionId;
            
            $.ajax( {
                url: wcst_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcst_analyze_subscription',
                    subscription_id: subscriptionId,
                    nonce: wcst_ajax.nonce
                },
                success: ( response ) => {
                    if ( response.success ) {
                        this.analysisData = response.data;
                        this.displayResults( response.data );
                    } else {
                        // Handle detailed error response.
                        let errorMessage = 'Analysis failed. Please try again.';
                        if ( response.data ) {
                            if ( typeof response.data === 'string' ) {
                                errorMessage = response.data;
                            } else if ( response.data.message ) {
                                errorMessage = response.data.message;
                                // Include debug info if available.
                                if ( response.data.debug && window.console ) {
                                    console.error( 'Doctor Subs Error:', response.data.debug );
                                }
                            }
                        }
                        this.showError( errorMessage );
                        // Log to console for debugging.
                        if ( window.console ) {
                            console.error( 'Doctor Subs Analysis Error:', response );
                        }
                    }
                },
                error: ( xhr, status, error ) => {
                    let errorMessage = 'Analysis failed. Please try again.';
                    // Try to parse error response.
                    if ( xhr.responseJSON && xhr.responseJSON.data ) {
                        if ( typeof xhr.responseJSON.data === 'string' ) {
                            errorMessage = xhr.responseJSON.data;
                        } else if ( xhr.responseJSON.data.message ) {
                            errorMessage = xhr.responseJSON.data.message;
                        }
                    }
                    this.showError( errorMessage );
                    // Log full error to console for debugging.
                    if ( window.console ) {
                        console.error( 'Doctor Subs AJAX Error:', {
                            status: status,
                            error: error,
                            response: xhr.responseText,
                            xhr: xhr
                        } );
                    }
                },
                complete: () => {
                    this.hideProgress();
                }
            } );
        },

        searchSubscriptions: function( searchTerm ) {
            $.ajax( {
                url: wcst_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcst_search_subscriptions',
                    search_term: searchTerm,
                    nonce: wcst_ajax.nonce
                },
                success: ( response ) => {
                    if ( response.success ) {
                        this.displaySearchResults( response.data );
                    }
                }
            } );
        },

        displaySearchResults: function( results ) {
            const $container = $( '#wcst-search-results' );
            
            if ( 0 === results.length ) {
                $container.html( '<div class="wcst-search-result-item">No subscriptions found.</div>' );
            } else {
                let html = '<div class="wcst-search-results-header">Click a subscription to analyze:</div>';
                results.forEach( ( result ) => {
                    html += '<div class="wcst-search-result-item" data-id="' + result.id + '">';
                    html += '<strong>Subscription #' + result.id + '</strong>';
                    
                    // Build customer details with fallbacks
                    let customerInfo = '';
                    if ( result.customer && result.customer.trim() ) {
                        customerInfo = result.customer;
                    } else if ( result.email ) {
                        customerInfo = result.email;
                    } else {
                        customerInfo = 'Customer #' + ( result.customer_id || 'N/A' );
                    }
                    
                    html += '<div class="wcst-result-details">Status: ' + result.status + ' | Customer: ' + customerInfo + '</div>';
                    html += '</div>';
                } );
                $container.html( html );
            }
            
            $container.show();
        },

        displayResults: function( data ) {
            this.displayAnatomy( data.anatomy );
            this.displayExpectedBehavior( data.expected );
            this.displayTimeline( data.timeline );
            this.displaySummary( data.summary );
            
            // Display enhanced detection if available
            if ( data.enhanced ) {
                this.displayEnhancedDetection( data.enhanced );
            }
            
            $( '#wcst-results' ).show();
            this.updateProgressComplete();
        },

        displayAnatomy: function( anatomy ) {
            const html = this.renderAnatomyContent( anatomy );
            $( '#wcst-anatomy-content' ).html( html );
        },

        displayExpectedBehavior: function( expected ) {
            const html = this.renderExpectedBehaviorContent( expected );
            $( '#wcst-expected-content' ).html( html );
        },

        displayTimeline: function( timeline ) {
            const html = this.renderTimelineContent( timeline );
            $( '#wcst-timeline-content' ).html( html );
        },

        displaySummary: function( summary ) {
            const html = this.renderSummaryContent( summary );
            $( '#wcst-summary-content' ).html( html );
        },

        renderAnatomyContent: function( anatomy ) {
            let html = '';
            
            // Basic Info Summary Panel
            html += '<div class="wcst-summary-panel">';
            html += '<div class="wcst-summary-card">';
            html += '<h3>Status</h3>';
            html += '<div class="wcst-summary-value">';
            html += '<span class="wcst-status-badge ' + this.getStatusClass( anatomy.basic_info.status ) + '">' + anatomy.basic_info.status + '</span>';
            html += '</div>';
            html += '</div>';
            
            html += '<div class="wcst-summary-card">';
            html += '<h3>Customer</h3>';
            html += '<div class="wcst-summary-value">' + anatomy.basic_info.customer_id + '</div>';
            html += '<small>ID: ' + anatomy.basic_info.customer_id + '</small>';
            html += '</div>';
            
            html += '<div class="wcst-summary-card">';
            html += '<h3>Total</h3>';
            html += '<div class="wcst-summary-value">' + anatomy.basic_info.total + ' ' + anatomy.basic_info.currency + '</div>';
            html += '</div>';
            
            html += '<div class="wcst-summary-card">';
            html += '<h3>Next Payment</h3>';
            html += '<div class="wcst-summary-value">';
            if ( anatomy.billing_schedule.next_payment ) {
                html += this.formatDate( anatomy.billing_schedule.next_payment );
            } else {
                html += 'N/A';
            }
            html += '</div>';
            html += '</div>';
            html += '</div>';
            
            // Payment Method Details
            html += '<h3>Payment Method</h3>';
            html += '<table class="wcst-data-table">';
            html += '<tr><th>Gateway</th><th>Type</th><th>Status</th></tr>';
            html += '<tr>';
            html += '<td>' + ( anatomy.payment_method.title || 'N/A' ) + '</td>';
            html += '<td>' + ( anatomy.payment_method.requires_manual ? 'Manual' : 'Automatic' ) + '</td>';
            html += '<td>';
            if ( anatomy.payment_method.status.is_valid ) {
                html += '<span class="wcst-status-badge success">Valid</span>';
            } else {
                html += '<span class="wcst-status-badge error">Issues Found</span>';
            }
            html += '</td>';
            html += '</tr>';
            html += '</table>';
            
            // Warnings
            if ( anatomy.payment_method.status.warnings && anatomy.payment_method.status.warnings.length > 0 ) {
                html += '<div class="wcst-warning">';
                html += '<strong>Payment Method Warnings:</strong><br>';
                html += anatomy.payment_method.status.warnings.join( '<br>' );
                html += '</div>';
            }
            
            // Billing Schedule
            html += '<h3>Billing Schedule</h3>';
            html += '<table class="wcst-data-table">';
            html += '<tr><th>Property</th><th>Value</th></tr>';
            html += '<tr><td>Billing Interval</td><td>' + anatomy.billing_schedule.interval + ' ' + anatomy.billing_schedule.period + '</td></tr>';
            html += '<tr><td>Start Date</td><td>' + this.formatDate( anatomy.billing_schedule.start_date ) + '</td></tr>';
            html += '<tr><td>Next Payment</td><td>' + this.formatDate( anatomy.billing_schedule.next_payment ) + '</td></tr>';
            html += '<tr><td>End Date</td><td>' + this.formatDate( anatomy.billing_schedule.end_date ) + '</td></tr>';
            html += '<tr><td>Editable</td><td>' + ( anatomy.billing_schedule.is_editable ? 'Yes' : 'No' ) + '</td></tr>';
            html += '</table>';
            
            return html;
        },

        renderExpectedBehaviorContent: function( expected ) {
            let html = '';
            
            // Payment Gateway Behavior
            html += '<h3>Payment Gateway Capabilities</h3>';
            if ( expected.payment_gateway_behavior && ! expected.payment_gateway_behavior.error ) {
                // Gateway Mode Information
                if ( expected.payment_gateway_behavior.gateway_mode ) {
                    const mode = expected.payment_gateway_behavior.gateway_mode;
                    const modeClass = mode.is_test ? 'warning' : 'success';
                    html += '<div class="wcst-gateway-mode">';
                    html += '<strong>Gateway Mode:</strong> ';
                    html += '<span class="wcst-status-badge ' + modeClass + '">' + mode.description + '</span>';
                    html += '</div>';
                }
                
                html += '<table class="wcst-data-table">';
                html += '<tr><th>Feature</th><th>Supported</th></tr>';
                html += '<tr><td>Subscriptions</td><td>' + this.renderSupportIcon( expected.payment_gateway_behavior.supports_subscriptions ) + '</td></tr>';
                html += '<tr><td>Cancellation</td><td>' + this.renderSupportIcon( expected.payment_gateway_behavior.supports_subscription_cancellation ) + '</td></tr>';
                html += '<tr><td>Suspension</td><td>' + this.renderSupportIcon( expected.payment_gateway_behavior.supports_subscription_suspension ) + '</td></tr>';
                html += '<tr><td>Amount Changes</td><td>' + this.renderSupportIcon( expected.payment_gateway_behavior.supports_subscription_amount_changes ) + '</td></tr>';
                html += '<tr><td>Date Changes</td><td>' + this.renderSupportIcon( expected.payment_gateway_behavior.supports_subscription_date_changes ) + '</td></tr>';
                html += '</table>';
            } else {
                html += '<div class="wcst-error">Payment gateway information not available.</div>';
            }
            
            // Renewal Expectations
            html += '<h3>Renewal Process</h3>';
            if ( expected.renewal_expectations ) {
                html += '<div class="wcst-info">';
                html += '<strong>Type:</strong> ' + expected.renewal_expectations.type + '<br>';
                html += '<strong>Description:</strong> ' + expected.renewal_expectations.description;
                html += '</div>';
                
                if ( expected.renewal_expectations.next_action ) {
                    html += '<p><strong>Next Action:</strong> ' + expected.renewal_expectations.next_action + '</p>';
                }
            }
            
            return html;
        },

        renderTimelineContent: function( timeline ) {
            let html = '';
            
            if ( timeline.events && timeline.events.length > 0 ) {
                html += '<div class="wcst-timeline">';
                timeline.events.forEach( ( event ) => {
                    html += '<div class="wcst-timeline-event ' + event.status + '">';
                    html += '<div class="wcst-timeline-header">';
                    html += '<span class="wcst-timeline-date">' + this.formatDate( event.timestamp ) + '</span>';
                    html += '<span class="wcst-timeline-type">' + event.type + '</span>';
                    html += '</div>';
                    html += '<div class="wcst-timeline-description">' + event.title + '</div>';
                    if ( event.description !== event.title ) {
                        html += '<div style="font-size: 12px; color: #666; margin-top: 5px;">' + event.description + '</div>';
                    }
                    html += '</div>';
                } );
                html += '</div>';
            } else {
                html += '<p>No timeline events found.</p>';
            }
            
            return html;
        },

        renderSummaryContent: function( summary ) {
            let html = '';
            
            // Summary Statistics
            if ( summary.statistics ) {
                html += '<h3>Summary Statistics</h3>';
                html += '<div class="wcst-summary-cards">';
                html += '<div class="wcst-summary-card">';
                html += '<h4>Total Issues</h4>';
                html += '<div class="value">' + summary.statistics.total_issues + '</div>';
                html += '</div>';
                html += '<div class="wcst-summary-card">';
                html += '<h4>Critical</h4>';
                html += '<div class="value">' + summary.statistics.critical + '</div>';
                html += '</div>';
                html += '<div class="wcst-summary-card">';
                html += '<h4>Warnings</h4>';
                html += '<div class="value">' + summary.statistics.warnings + '</div>';
                html += '</div>';
                html += '</div>';
            }
            
            // Issues List
            if ( summary.issues && summary.issues.length > 0 ) {
                html += '<h3>Issues Detected</h3>';
                html += '<div class="wcst-issues-list">';
                summary.issues.forEach( ( issue ) => {
                    // Map severity levels to CSS classes
                    let severityClass = 'info';
                    if ( 'critical' === issue.severity || 'error' === issue.severity || 'high' === issue.severity ) {
                        severityClass = 'error'; // Red
                    } else if ( 'warning' === issue.severity || 'medium' === issue.severity ) {
                        severityClass = 'warning'; // Yellow
                    } else {
                        severityClass = 'info'; // Blue/Gray
                    }
                    html += '<div class="wcst-issue-item wcst-status-' + severityClass + '">';
                    html += '<h4>' + issue.title + '</h4>';
                    html += '<p>' + issue.description + '</p>';
                    html += '</div>';
                } );
                html += '</div>';
            } else {
                html += '<div class="wcst-no-issues">';
                html += '<h3>No Issues Detected</h3>';
                html += '<p>This subscription appears to be functioning normally.</p>';
                html += '</div>';
            }
            
            return html;
        },

        

        showProgress: function() {
            $( '#wcst-progress' ).show();
            $( '#wcst-results' ).hide();
            this.updateProgress( 1 );
        },

        hideProgress: function() {
            $( '#wcst-progress' ).hide();
        },

        updateProgress: function( step ) {
            $( '.wcst-step' ).removeClass( 'active completed' );
            
            for ( let i = 1; i <= step; i++ ) {
                const $step = $( '.wcst-step[data-step="' + i + '"]' );
                if ( i === step ) {
                    $step.addClass( 'active' );
                } else {
                    $step.addClass( 'completed' );
                }
            }
        },

        updateProgressComplete: function() {
            $( '.wcst-step' ).removeClass( 'active' ).addClass( 'completed' );
        },

        showError: function( message ) {
            // Show error in results area.
            const html = '<div class="wcst-error">' + message + '</div>';
            $( '#wcst-results' ).html( html ).show();
            $( '#wcst-progress' ).hide();
            
            // Also show error near search input for better visibility.
            $( '#wcst-search-results' ).html( '<div class="wcst-error" style="margin-top: 10px; padding: 10px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px;">' + message + '</div>' ).show();
            
            // Log to console for debugging.
            if ( window.console ) {
                console.error( 'Doctor Subs Error:', message );
            }
        },

        // Helper functions
        getStatusClass: function( status ) {
            const statusMap = {
                'active': 'success',
                'on-hold': 'warning',
                'cancelled': 'error',
                'expired': 'error',
                'pending': 'warning'
            };
            return statusMap[ status ] || 'info';
        },

        renderSupportIcon: function( supported ) {
            return supported ? '✅ Yes' : '❌ No';
        },

        formatDate: function( dateString ) {
            if ( ! dateString || 'N/A' === dateString ) {
                return 'N/A';
            }
            
            try {
                const date = new Date( dateString );
                const now = new Date();
                const diffTime = date.getTime() - now.getTime();
                const diffDays = Math.ceil( diffTime / ( 1000 * 60 * 60 * 24 ) );
                
                // If it's a future date, show relative time
                if ( diffDays > 0 ) {
                    if ( diffDays === 1 ) {
                        return 'Tomorrow';
                    } else if ( diffDays <= 7 ) {
                        return `In ${ diffDays } days`;
                    } else if ( diffDays <= 30 ) {
                        const weeks = Math.ceil( diffDays / 7 );
                        return `In ${ weeks } week${ weeks > 1 ? 's' : '' }`;
                    } else {
                        return date.toLocaleDateString();
                    }
                } else if ( diffDays < 0 ) {
                    // Past date
                    const absDays = Math.abs( diffDays );
                    if ( absDays === 1 ) {
                        return 'Yesterday';
                    } else if ( absDays <= 7 ) {
                        return `${ absDays } days ago`;
                    } else {
                        return date.toLocaleDateString();
                    }
                } else {
                    return 'Today';
                }
            } catch ( e ) {
                return dateString;
            }
        },



        displayManualCompletions: function( completions ) {
            const container = $( '#wcst-manual-completions-content' );
            
            if ( completions.length === 0 ) {
                container.html( '<p class="wcst-status-healthy">No manual completions detected.</p>' );
                return;
            }
            
            let html = '<div class="wcst-issues-list">';
            completions.forEach( completion => {
                html += `
                    <div class="wcst-issue-item wcst-status-${ completion.severity }">
                        <h4>${ completion.description }</h4>
                        <p><strong>Details:</strong> ${ JSON.stringify( completion.details ) }</p>
                        <p><strong>Recommendation:</strong> ${ completion.recommendation }</p>
                    </div>
                `;
            } );
            html += '</div>';
            
            container.html( html );
        },

        displayStatusMismatches: function( mismatches ) {
            const container = $( '#wcst-status-mismatches-content' );
            
            if ( mismatches.length === 0 ) {
                container.html( '<p class="wcst-status-healthy">No status mismatches detected.</p>' );
                return;
            }
            
            let html = '<div class="wcst-issues-list">';
            mismatches.forEach( mismatch => {
                html += `
                    <div class="wcst-issue-item wcst-status-${ mismatch.severity }">
                        <h4>${ mismatch.description }</h4>
                        <p><strong>Details:</strong> ${ JSON.stringify( mismatch.details ) }</p>
                        <p><strong>Recommendation:</strong> ${ mismatch.recommendation }</p>
                    </div>
                `;
            } );
            html += '</div>';
            
            container.html( html );
        },

        displayActionScheduler: function( scheduler ) {
            const container = $( '#wcst-action-scheduler-content' );
            
            if ( scheduler.length === 0 ) {
                container.html( '<p class="wcst-status-healthy">No Action Scheduler issues detected.</p>' );
                return;
            }
            
            let html = '<div class="wcst-issues-list">';
            scheduler.forEach( action => {
                html += `
                    <div class="wcst-issue-item wcst-status-${ action.severity }">
                        <h4>${ action.description }</h4>
                        <p><strong>Details:</strong> ${ JSON.stringify( action.details ) }</p>
                        <p><strong>Recommendation:</strong> ${ action.recommendation }</p>
                    </div>
                `;
            } );
            html += '</div>';
            
            container.html( html );
        },

        displayYearOverYear: function( analysis ) {
            const container = $( '#wcst-year-over-year-content' );
            
            // Check if analysis is an array and has items
            if ( ! Array.isArray( analysis ) || analysis.length === 0 ) {
                container.html( '<p class="wcst-status-healthy">✅ No year-over-year issues detected</p>' );
                return;
            }
            
            let html = '<div class="wcst-issues-list">';
            analysis.forEach( year => {
                if ( typeof year === 'object' && year.type ) {
                    html += `
                        <div class="wcst-issue-item wcst-status-${ year.severity || 'warning' }">
                            <p><strong>⚠️ ${ year.description }</strong></p>
                            <p><small>${ year.recommendation }</small></p>
                        </div>
                    `;
                }
            } );
            html += '</div>';
            
            container.html( html );
        },

        // Bulk reports
        handleGenerateReportClick: function( e ) {
            e.preventDefault();
            
            const reportType = $( '#wcst-report-type' ).val();
            const statusFilter = $( '#wcst-status-filter' ).val();
            const limit = $( '#wcst-limit' ).val();
            
            const filters = {
                report_type: reportType,
                status: statusFilter,
                limit: limit
            };
            
            this.generateBulkReport( filters );
        },

        generateBulkReport: function( filters ) {
            this.showProgress( 'Generating bulk report...' );
            
            $.ajax( {
                url: wcst_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcst_generate_bulk_report',
                    filters: filters,
                    nonce: wcst_ajax.nonce
                },
                success: ( response ) => {
                    if ( response.success ) {
                        this.displayBulkReport( response.data );
                        this.currentCSVData = response.data.csv_content;
                        $( '#wcst-export-csv-btn' ).show();
                    } else {
                        this.showError( response.data || 'Bulk report generation failed.' );
                    }
                },
                error: () => {
                    this.showError( 'Bulk report generation failed. Please try again.' );
                },
                complete: () => {
                    $( '#wcst-progress' ).hide();
                }
            } );
        },

        displayBulkReport: function( data ) {
            $( '#wcst-bulk-results' ).show();
            
            const container = $( '#wcst-bulk-report-content' );
            let html = `<h4>Report Summary</h4>`;
            html += `<p>Total subscriptions analyzed: ${ data.total_count }</p>`;
            
            if ( data.report_data && data.report_data.length > 0 ) {
                html += '<table class="wp-list-table widefat fixed striped">';
                html += '<thead><tr>';
                html += '<th>Subscription ID</th>';
                html += '<th>Customer</th>';
                html += '<th>Last Renewal</th>';
                html += '<th>Expected Next</th>';
                html += '<th>Actual Next</th>';
                html += '<th>Status</th>';
                html += '<th>Issues</th>';
                html += '</tr></thead>';
                html += '<tbody>';
                
                data.report_data.forEach( item => {
                    html += '<tr>';
                    html += `<td>${ item.subscription_id }</td>`;
                    html += `<td>${ item.customer_email }</td>`;
                    html += `<td>${ item.last_renewal || 'N/A' }</td>`;
                    html += `<td>${ item.expected_next || 'N/A' }</td>`;
                    html += `<td>${ item.actual_next || 'N/A' }</td>`;
                    html += `<td>${ item.status }</td>`;
                    html += `<td>${ item.issues.join( ', ' ) }</td>`;
                    html += '</tr>';
                } );
                
                html += '</tbody></table>';
            }
            
            container.html( html );
        },

        handleExportCSVClick: function( e ) {
            e.preventDefault();
            
            if ( ! this.currentCSVData ) {
                this.showError( 'No CSV data available for export.' );
                return;
            }
            
            const filename = 'doctor-subs-report-' + new Date().toISOString().split( 'T' )[0] + '.csv';
            
            // Create form and submit for download
            const form = $( '<form>' )
                .attr( 'method', 'POST' )
                .attr( 'action', wcst_ajax.ajax_url )
                .append( $( '<input>' ).attr( 'type', 'hidden' ).attr( 'name', 'action' ).val( 'wcst_export_csv' ) )
                .append( $( '<input>' ).attr( 'type', 'hidden' ).attr( 'name', 'nonce' ).val( wcst_ajax.nonce ) )
                .append( $( '<input>' ).attr( 'type', 'hidden' ).attr( 'name', 'csv_data' ).val( this.currentCSVData ) )
                .append( $( '<input>' ).attr( 'type', 'hidden' ).attr( 'name', 'filename' ).val( filename ) );
            
            $( 'body' ).append( form );
            form.submit();
            form.remove();
        },

        // Fixing tools
        handlePreviewFixClick: function( e ) {
            e.preventDefault();
            
            const subscriptionId = $( '#wcst-fix-search' ).val().trim();
            
            if ( ! subscriptionId ) {
                this.showError( 'Please enter a subscription ID.' );
                return;
            }
            
            this.previewFix( subscriptionId );
        },

        previewFix: function( subscriptionId ) {
            this.showProgress( 'Previewing fix...' );
            
            $.ajax( {
                url: wcst_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcst_fix_subscription',
                    subscription_id: subscriptionId,
                    fix_type: 'payment_date',
                    dry_run: true,
                    nonce: wcst_ajax.nonce
                },
                success: ( response ) => {
                    if ( response.success ) {
                        this.displayFixPreview( response.data );
                    } else {
                        this.showError( response.data || 'Fix preview failed.' );
                    }
                },
                error: () => {
                    this.showError( 'Fix preview failed. Please try again.' );
                },
                complete: () => {
                    $( '#wcst-progress' ).hide();
                }
            } );
        },

        displayFixPreview: function( data ) {
            $( '#wcst-fix-preview' ).show();
            
            const container = $( '.wcst-fix-preview-content' );
            let html = '<h4>Fix Preview</h4>';
            html += `<p><strong>Message:</strong> ${ data.message }</p>`;
            
            if ( data.current_next_payment ) {
                html += `<p><strong>Current Next Payment:</strong> ${ data.current_next_payment }</p>`;
            }
            
            if ( data.suggested_next_payment ) {
                html += `<p><strong>Suggested Next Payment:</strong> ${ data.suggested_next_payment }</p>`;
            }
            
            container.html( html );
        },

        handleApplyFixClick: function( e ) {
            e.preventDefault();
            
            const subscriptionId = $( '#wcst-fix-search' ).val().trim();
            
            if ( ! subscriptionId ) {
                this.showError( 'Please enter a subscription ID.' );
                return;
            }
            
            if ( confirm( 'Are you sure you want to apply this fix? This action cannot be undone.' ) ) {
                this.applyFix( subscriptionId, false );
            }
        },

        handleDryRunClick: function( e ) {
            e.preventDefault();
            
            const subscriptionId = $( '#wcst-fix-search' ).val().trim();
            
            if ( ! subscriptionId ) {
                this.showError( 'Please enter a subscription ID.' );
                return;
            }
            
            this.applyFix( subscriptionId, true );
        },

        applyFix: function( subscriptionId, dryRun ) {
            this.showProgress( dryRun ? 'Running dry run...' : 'Applying fix...' );
            
            $.ajax( {
                url: wcst_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcst_fix_subscription',
                    subscription_id: subscriptionId,
                    fix_type: 'payment_date',
                    dry_run: dryRun,
                    nonce: wcst_ajax.nonce
                },
                success: ( response ) => {
                    if ( response.success ) {
                        this.showSuccess( response.data.message );
                        if ( ! dryRun ) {
                            $( '#wcst-fix-preview' ).hide();
                        }
                    } else {
                        this.showError( response.data || 'Fix application failed.' );
                    }
                },
                error: () => {
                    this.showError( 'Fix application failed. Please try again.' );
                },
                complete: () => {
                    $( '#wcst-progress' ).hide();
                }
            } );
        },

        // Batch fixing
        handleBatchPreviewClick: function( e ) {
            e.preventDefault();
            
            const subscriptionIds = this.getBatchSubscriptionIds();
            const fixTypes = $( '#wcst-batch-fix-type' ).val();
            
            if ( ! subscriptionIds.length ) {
                this.showError( 'Please enter subscription IDs.' );
                return;
            }
            
            this.batchFix( subscriptionIds, fixTypes, true );
        },

        handleBatchDryRunClick: function( e ) {
            e.preventDefault();
            
            const subscriptionIds = this.getBatchSubscriptionIds();
            const fixTypes = $( '#wcst-batch-fix-type' ).val();
            
            if ( ! subscriptionIds.length ) {
                this.showError( 'Please enter subscription IDs.' );
                return;
            }
            
            this.batchFix( subscriptionIds, fixTypes, true );
        },

        handleBatchApplyClick: function( e ) {
            e.preventDefault();
            
            const subscriptionIds = this.getBatchSubscriptionIds();
            const fixTypes = $( '#wcst-batch-fix-type' ).val();
            
            if ( ! subscriptionIds.length ) {
                this.showError( 'Please enter subscription IDs.' );
                return;
            }
            
            if ( confirm( 'Are you sure you want to apply batch fixes? This action cannot be undone.' ) ) {
                this.batchFix( subscriptionIds, fixTypes, false );
            }
        },

        getBatchSubscriptionIds: function() {
            const idsText = $( '#wcst-batch-subscription-ids' ).val().trim();
            return idsText.split( ',' ).map( id => parseInt( id.trim() ) ).filter( id => id > 0 );
        },

        batchFix: function( subscriptionIds, fixTypes, dryRun ) {
            this.showProgress( dryRun ? 'Running batch dry run...' : 'Applying batch fixes...' );
            
            $.ajax( {
                url: wcst_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcst_batch_fix_subscriptions',
                    subscription_ids: subscriptionIds,
                    fix_types: fixTypes,
                    dry_run: dryRun,
                    nonce: wcst_ajax.nonce
                },
                success: ( response ) => {
                    if ( response.success ) {
                        this.displayBatchResults( response.data );
                        if ( ! dryRun ) {
                            this.showSuccess( `Batch fix completed: ${ response.data.successful } successful, ${ response.data.failed } failed` );
                        }
                    } else {
                        this.showError( response.data || 'Batch fix failed.' );
                    }
                },
                error: () => {
                    this.showError( 'Batch fix failed. Please try again.' );
                },
                complete: () => {
                    $( '#wcst-progress' ).hide();
                }
            } );
        },

        displayBatchResults: function( data ) {
            $( '#wcst-batch-results' ).show();
            
            const container = $( '#wcst-batch-results' );
            let html = '<h4>Batch Fix Results</h4>';
            html += `<p><strong>Successful:</strong> ${ data.successful }</p>`;
            html += `<p><strong>Failed:</strong> ${ data.failed }</p>`;
            
            if ( data.details && data.details.length > 0 ) {
                html += '<h5>Details:</h5>';
                html += '<ul>';
                data.details.forEach( detail => {
                    html += `<li>Subscription ${ detail.subscription_id }: ${ detail.fixes_applied.length > 0 ? 'Fixed' : 'Failed' }</li>`;
                } );
                html += '</ul>';
            }
            
            container.html( html );
        },

        // Developer tools
        handleDevAnalyzeClick: function( e ) {
            e.preventDefault();
            
            const subscriptionId = $( '#wcst-dev-search' ).val().trim();
            const debugType = $( '#wcst-debug-type' ).val();
            
            if ( ! subscriptionId ) {
                this.showError( 'Please enter a subscription ID.' );
                return;
            }
            
            this.runDevAnalysis( subscriptionId, debugType );
        },

        runDevAnalysis: function( subscriptionId, debugType ) {
            this.showProgress( 'Running debug analysis...' );
            
            $.ajax( {
                url: wcst_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcst_get_developer_debug',
                    subscription_id: subscriptionId,
                    debug_type: debugType,
                    nonce: wcst_ajax.nonce
                },
                success: ( response ) => {
                    if ( response.success ) {
                        this.displayDevResults( response.data, debugType );
                    } else {
                        this.showError( response.data || 'Debug analysis failed.' );
                    }
                },
                error: () => {
                    this.showError( 'Debug analysis failed. Please try again.' );
                },
                complete: () => {
                    $( '#wcst-progress' ).hide();
                }
            } );
        },

        displayDevResults: function( data, debugType ) {
            $( '#wcst-dev-results' ).show();
            
            const container = $( '#wcst-dev-content' );
            let html = `<h4>Debug Information (${ debugType })</h4>`;
            
            html += '<pre>' + JSON.stringify( data, null, 2 ) + '</pre>';
            
            container.html( html );
        },

        displayEnhancedDetection: function( enhancedData ) {
            // Display skipped cycles
            this.displaySkippedCycles( enhancedData.skipped_cycles || [] );
            
            // Display manual completions
            this.displayManualCompletions( enhancedData.manual_completions || [] );
            
            // Display status mismatches
            this.displayStatusMismatches( enhancedData.status_mismatches || [] );
            
            // Display action scheduler audit
            this.displayActionScheduler( enhancedData.action_scheduler || [] );
            
            // Display year-over-year analysis
            this.displayYearOverYear( enhancedData.year_over_year || [] );
        },

        displaySkippedCycles: function( skippedCycles ) {
            const container = $( '#wcst-skipped-cycles-content' );
            
            if ( ! Array.isArray( skippedCycles ) || skippedCycles.length === 0 ) {
                container.html( '<p class="wcst-status-healthy">✅ No skipped cycles detected</p>' );
                return;
            }
            
            let html = '<div class="wcst-issues-list">';
            skippedCycles.forEach( cycle => {
                html += `
                    <div class="wcst-issue-item wcst-status-warning">
                        <p><strong>⚠️ ${ cycle.description }</strong></p>
                        <p><small>${ cycle.recommendation }</small></p>
                    </div>
                `;
            } );
            html += '</div>';
            
            container.html( html );
        },

        displayManualCompletions: function( completions ) {
            const container = $( '#wcst-manual-completions-content' );
            
            if ( completions.length === 0 ) {
                container.html( '<p class="wcst-status-healthy">No manual completions detected.</p>' );
                return;
            }
            
            let html = '<div class="wcst-issues-list">';
            completions.forEach( completion => {
                html += `
                    <div class="wcst-issue-item wcst-status-${ completion.severity }">
                        <h4>${ completion.description }</h4>
                        <p><strong>Details:</strong> ${ JSON.stringify( completion.details ) }</p>
                        <p><strong>Recommendation:</strong> ${ completion.recommendation }</p>
                    </div>
                `;
            } );
            html += '</div>';
            
            container.html( html );
        },

        displayStatusMismatches: function( mismatches ) {
            const container = $( '#wcst-status-mismatches-content' );
            
            if ( mismatches.length === 0 ) {
                container.html( '<p class="wcst-status-healthy">No status mismatches detected.</p>' );
                return;
            }
            
            let html = '<div class="wcst-issues-list">';
            mismatches.forEach( mismatch => {
                html += `
                    <div class="wcst-issue-item wcst-status-${ mismatch.severity }">
                        <h4>${ mismatch.description }</h4>
                        <p><strong>Details:</strong> ${ JSON.stringify( mismatch.details ) }</p>
                        <p><strong>Recommendation:</strong> ${ mismatch.recommendation }</p>
                    </div>
                `;
            } );
            html += '</div>';
            
            container.html( html );
        },

        displayActionScheduler: function( scheduler ) {
            const container = $( '#wcst-action-scheduler-content' );
            
            if ( scheduler.length === 0 ) {
                container.html( '<p class="wcst-status-healthy">No Action Scheduler issues detected.</p>' );
                return;
            }
            
            let html = '<div class="wcst-issues-list">';
            scheduler.forEach( action => {
                html += `
                    <div class="wcst-issue-item wcst-status-${ action.severity }">
                        <h4>${ action.description }</h4>
                        <p><strong>Details:</strong> ${ JSON.stringify( action.details ) }</p>
                        <p><strong>Recommendation:</strong> ${ action.recommendation }</p>
                    </div>
                `;
            } );
            html += '</div>';
            
            container.html( html );
        },

    };

    // Initialize when document is ready
    $( document ).ready( function() {
        WCST.init();
    } );

} )( jQuery );
