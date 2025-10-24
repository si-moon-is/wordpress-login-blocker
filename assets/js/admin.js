/**
 * Login Blocker - Skrypty administracyjne
 * 
 * @package Login Blocker
 * @version 1.0.0
 */

jQuery(document).ready(function($) {
    'use strict';

    // Dynamiczne dostosowywanie wysokości kart
    function adjustHeights() {
        $('.card').css('min-height', 'auto');
        
        $('div[style*="grid-template-columns"]').each(function() {
            var $cards = $(this).find('.card');
            var maxHeight = 0;
            
            $cards.each(function() {
                var height = $(this).outerHeight();
                if (height > maxHeight) {
                    maxHeight = height;
                }
            });
            
            $cards.css('min-height', maxHeight + 'px');
        });
    }

    // Inicjalizacja
    function init() {
        adjustHeights();
        bindEvents();
        initCharts();
    }

    // Podpięcie eventów
    function bindEvents() {
        // Testowanie emaila
        $(document).on('click', '#test-email-btn', handleTestEmail);
        
        // Szybkie odblokowywanie IP
        $(document).on('click', '.quick-unblock', handleQuickUnblock);
        
        // Auto-odświeżanie statystyk
        if ($('#login-blocker-live-stats').length) {
            initLiveStats();
        }
        
        // Eksport danych
        $(document).on('click', '#export-data', handleExport);
        
        // Wyszukiwanie w czasie rzeczywistym
        $(document).on('input', '#ip-search', handleSearch);
        
        // Potwierdzenia akcji
        $(document).on('click', '.button-danger', confirmAction);
        
        // Zmiana rozmiaru okna
        $(window).on('resize', adjustHeights);
        
        // Zmiana okresu dla wykresów
        $(document).on('change', '#period', handlePeriodChange);
    }

    // Testowanie konfiguracji email
    function handleTestEmail(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var $result = $('#test-email-result');
        
        $btn.prop('disabled', true).text(loginBlocker.texts.sending);
        $result.html('');
        
        $.post(loginBlocker.ajax_url, {
            action: 'test_email_config',
            nonce: loginBlocker.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('Wyślij testowego emaila');
            
            if (response.success) {
                $result.html('<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ' + response.data);
            } else {
                $result.html('<span class="dashicons dashicons-warning" style="color: red;"></span> ' + response.data);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Wyślij testowego emaila');
            $result.html('<span class="dashicons dashicons-warning" style="color: red;"></span> ' + loginBlocker.texts.error);
        });
    }

    // Szybkie odblokowywanie IP
    function handleQuickUnblock(e) {
        e.preventDefault();
        
        var $button = $(this);
        var ip = $button.data('ip');
        var $row = $button.closest('tr');
        
        if (!confirm(loginBlocker.texts.confirm_unblock + ' ' + ip)) {
            return;
        }
        
        $button.prop('disabled', true).text(loginBlocker.texts.sending);
        
        $.post(loginBlocker.ajax_url, {
            action: 'unblock_ip',
            ip: ip,
            nonce: loginBlocker.nonce
        }, function(response) {
            if (response.success) {
                $row.fadeOut(300, function() {
                    $(this).remove();
                    showNotice(response.data, 'success');
                    updateStatsIfNeeded();
                });
            } else {
                showNotice(response.data, 'error');
                $button.prop('disabled', false).text('Odblokuj');
            }
        }).fail(function() {
            showNotice(loginBlocker.texts.error, 'error');
            $button.prop('disabled', false).text('Odblokuj');
        });
    }

    // Auto-odświeżanie statystyk
    function initLiveStats() {
        function updateStats() {
            $.get(loginBlocker.ajax_url, {
                action: 'get_live_stats',
                nonce: loginBlocker.nonce
            }, function(response) {
                if (response.success) {
                    $('#blocked-count').text(response.data.blocked);
                    $('#attempts-count').text(response.data.attempts);
                }
            });
        }
        
        // Odśwież od razu i co 30 sekund
        updateStats();
        setInterval(updateStats, 30000);
    }

    // Eksport danych
    function handleExport(e) {
        e.preventDefault();
        
        var format = $('#export-format').val();
        var period = $('#export-period').val();
        
        if (!format || !period) {
            showNotice('Wybierz format i okres eksportu', 'warning');
            return;
        }
        
        window.location.href = loginBlocker.ajax_url + 
            '?action=export_data&format=' + format + 
            '&period=' + period + '&nonce=' + loginBlocker.nonce;
    }

    // Wyszukiwanie w czasie rzeczywistym
    var searchTimer;
    function handleSearch() {
        clearTimeout(searchTimer);
        var searchTerm = $(this).val();
        
        searchTimer = setTimeout(function() {
            if (searchTerm.length >= 2 || searchTerm.length === 0) {
                $('form#ip-search-form').submit();
            }
        }, 500);
    }

    // Zmiana okresu dla wykresów
    function handlePeriodChange() {
        var period = $(this).val();
        loadChartData(period);
    }

    // Potwierdzenie niebezpiecznych akcji
    function confirmAction(e) {
        var message = $(this).hasClass('delete-action') ? 
            loginBlocker.texts.confirm_delete : 
            loginBlocker.texts.confirm_unblock;
            
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    }

    /**
     * UZUPEŁNIONA FUNKCJA: Inicjalizacja wykresów
     */
    function initCharts() {
        if (typeof Chart === 'undefined' || !$('#attemptsChart').length) {
            return;
        }
        
        var ctx = document.getElementById('attemptsChart').getContext('2d');
        
        // Sprawdź czy mamy dane w globalnej zmiennej
        if (typeof loginBlockerChartData !== 'undefined') {
            createChart(ctx, loginBlockerChartData);
            return;
        }
        
        // Lub załaduj dane przez AJAX
        var period = $('#period').val() || 30;
        loadChartData(period);
    }

    /**
     * UZUPEŁNIONA FUNKCJA: Tworzenie wykresu
     */
    function createChart(ctx, chartData) {
        if (!chartData || !chartData.dates || chartData.dates.length === 0) {
            $('#attemptsChart').closest('.card').append('<p>Brak danych dla wybranego okresu.</p>');
            return;
        }
        
        var chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.dates,
                datasets: [
                    {
                        label: "Wszystkie próby",
                        data: chartData.attempts,
                        borderColor: "#0073aa",
                        backgroundColor: "rgba(0, 115, 170, 0.1)",
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: "Zablokowane",
                        data: chartData.blocked,
                        borderColor: "#d63638",
                        backgroundColor: "rgba(214, 54, 56, 0.1)",
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: "Unikalne IP",
                        data: chartData.unique_ips,
                        borderColor: "#00a32a",
                        backgroundColor: "rgba(0, 163, 42, 0.1)",
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 20
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });
        
        return chart;
    }

    /**
     * DODANA FUNKCJA: Ładowanie danych wykresu przez AJAX
     */
    function loadChartData(period) {
        if (!$('#attemptsChart').length) return;
        
        $.get(loginBlocker.ajax_url, {
            action: 'get_chart_data',
            period: period,
            nonce: loginBlocker.nonce
        }, function(response) {
            if (response.success) {
                var ctx = document.getElementById('attemptsChart').getContext('2d');
                createChart(ctx, response.data);
            } else {
                $('#attemptsChart').closest('.card').append('<p>Błąd ładowania danych wykresu.</p>');
            }
        }).fail(function() {
            $('#attemptsChart').closest('.card').append('<p>Błąd połączenia z serwerem.</p>');
        });
    }

    // Wyświetlanie powiadomień
    function showNotice(message, type) {
        var cssClass = type === 'success' ? 'notice-success' : 
                      type === 'error' ? 'notice-error' : 'notice-warning';
        
        var notice = $('<div class="notice ' + cssClass + ' is-dismissible"><p>' + message + '</p></div>');
        
        $('.wrap h1').after(notice);
        
        // Auto-ukrywanie po 5 sekundach
        setTimeout(function() {
            notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
        
        // Obsługa ręcznego zamykania
        notice.on('click', '.notice-dismiss', function() {
            notice.remove();
        });
    }

    // Aktualizacja statystyk jeśli potrzeba
    function updateStatsIfNeeded() {
        if (typeof updateStats === 'function') {
            updateStats();
        }
    }

    // Start
    init();
});
